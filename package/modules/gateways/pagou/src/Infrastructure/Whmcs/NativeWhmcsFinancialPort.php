<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Whmcs;

use Closure;
use PDO;
use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\NativePaymentReceipt;
use Pagou\Whmcs\Payment\Ledger\ReceivedPayment;
use Pagou\Whmcs\Payment\Ledger\WhmcsFinancialPort;

final class NativeWhmcsFinancialPort implements WhmcsFinancialPort
{
    /** @var Closure(string, array<string, mixed>): array<string, mixed> */
    private readonly Closure $localApi;

    /** @param callable(string, array<string, mixed>): array<string, mixed> $localApi */
    public function __construct(private readonly PDO $pdo, callable $localApi)
    {
        $this->localApi = Closure::fromCallable($localApi);
    }

    public const LATE_CHARGE_ITEM = '[Pagou] Multa e juros por atraso';

    public function applyInvoicePayment(ReceivedPayment $payment): NativePaymentReceipt
    {
        $transactionId = self::nativeTransactionId($payment);
        $this->addLateCharges($payment, $transactionId);
        $balanceBefore = $this->invoiceBalanceInCents($payment->invoiceId);
        $fee = $payment->feeInCents !== null && $payment->feeInCents > 0 && $payment->feeInCents < $payment->amountInCents
            ? $payment->feeInCents : null;
        $result = ($this->localApi)('AddInvoicePayment', [
            'invoiceid' => $payment->invoiceId,
            'transid' => $transactionId,
            'amount' => number_format($payment->amountInCents / 100, 2, '.', ''),
            'gateway' => 'pagou_' . ($payment->method === 'card' ? 'creditcard' : $payment->method),
            // WHMCS stores transaction dates in the installation's local time, not UTC.
            'date' => self::localDate($payment->paidAt),
        ] + ($fee === null ? [] : ['fees' => number_format($fee / 100, 2, '.', '')]));
        if (($result['result'] ?? null) !== 'success') {
            throw new \RuntimeException('WHMCS rejected the native invoice payment operation.');
        }

        $statement = $this->pdo->prepare('SELECT status FROM tblinvoices WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $payment->invoiceId]);
        $status = strtolower((string) $statement->fetchColumn());

        return new NativePaymentReceipt(
            $payment->invoiceId,
            $payment->amountInCents,
            max(0, $payment->amountInCents - $balanceBefore),
            $status === 'paid',
            $transactionId,
        );
    }

    /**
     * A charge paid late carries Pagou fine and interest above its nominal amount.
     * They are booked as an invoice item first, so the payment settles the invoice
     * exactly instead of turning that revenue into client credit. The item is
     * added once per receipt and never exceeds what the invoice lacks.
     */
    private function addLateCharges(ReceivedPayment $payment, string $transactionId): void
    {
        $nominal = $payment->chargeAmountCents;
        if ($nominal === null || $nominal < 1 || $payment->amountInCents <= $nominal) {
            return;
        }
        $description = self::LATE_CHARGE_ITEM . ' (' . $transactionId . ')';
        $existing = $this->pdo->prepare('SELECT 1 FROM tblinvoiceitems WHERE invoiceid = :id AND description = :description LIMIT 1');
        $existing->execute(['id' => $payment->invoiceId, 'description' => $description]);
        if ($existing->fetchColumn() !== false) {
            return;
        }
        $missing = $payment->amountInCents - $this->invoiceBalanceInCents($payment->invoiceId);
        $late = min($payment->amountInCents - $nominal, $missing);
        if ($late < 1) {
            return;
        }
        $result = ($this->localApi)('UpdateInvoice', [
            'invoiceid' => $payment->invoiceId,
            'newitemdescription' => [$description],
            'newitemamount' => [number_format($late / 100, 2, '.', '')],
            'newitemtaxed' => [false],
        ]);
        if (($result['result'] ?? null) !== 'success') {
            throw new \RuntimeException('WHMCS rejected the late charge invoice item.');
        }
    }

    public static function nativeTransactionId(ReceivedPayment $payment): string
    {
        $native = trim((string) $payment->nativeTransactionId);

        return $native !== '' ? $native : $payment->remotePaymentId;
    }

    /** Converts a provider instant to the WHMCS server time zone, as the native tables expect. */
    public static function localDate(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    private function invoiceBalanceInCents(int $invoiceId): int
    {
        $invoice = $this->pdo->prepare('SELECT total, credit FROM tblinvoices WHERE id = :id LIMIT 1');
        $invoice->execute(['id' => $invoiceId]);
        $row = $invoice->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \OutOfBoundsException('WHMCS invoice was not found.');
        }
        $payments = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amountin - amountout), 0) FROM tblaccounts WHERE invoiceid = :id'
        );
        $payments->execute(['id' => $invoiceId]);
        $paid = $payments->fetchColumn();
        $total = $this->decimalInCents((string) $row['total']);
        $credit = $this->decimalInCents((string) $row['credit']);
        $paidCents = $this->decimalInCents(is_scalar($paid) ? (string) $paid : '0');

        return max(0, $total - $credit - $paidCents);
    }

    private function decimalInCents(string $amount): int
    {
        $normalized = str_replace(',', '.', trim($amount));
        if (preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized) !== 1) {
            throw new \UnexpectedValueException('WHMCS returned an invalid monetary value.');
        }
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    public function paymentAlreadyApplied(EconomicPaymentKey $key, ?string $nativeTransactionId = null): bool
    {
        // Older native captures used the ledger hash, later ones the provider
        // payment identifier and current Pix receipts the Pix txid. All three
        // identify the same economic payment; the remote components are compared
        // case-insensitively while the hash keeps exact matching.
        $native = strtolower(trim((string) $nativeTransactionId));
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM tblaccounts WHERE invoiceid = :invoice_id '
            . "AND gateway IN ('pagou_pix', 'pagou_boleto', 'pagou_creditcard') "
            . 'AND (transid = :economic_key OR LOWER(transid) = :remote_id OR LOWER(transid) = :native_id) AND amountin > 0'
        );
        $statement->execute([
            'invoice_id' => $key->invoiceId,
            'economic_key' => $key->value,
            'remote_id' => $key->remotePaymentId,
            // An empty native identifier must never match an empty WHMCS transaction ID.
            'native_id' => $native !== '' ? $native : $key->remotePaymentId,
        ]);
        $matches = (int) $statement->fetchColumn();
        if ($matches > 1) {
            throw new \LogicException('WHMCS has ambiguous native payment receipts for this economic payment.');
        }

        return $matches === 1;
    }
}
