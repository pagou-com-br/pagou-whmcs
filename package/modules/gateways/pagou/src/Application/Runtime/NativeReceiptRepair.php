<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use Closure;
use PDO;
use Pagou\Whmcs\Infrastructure\Whmcs\NativeWhmcsFinancialPort;

/**
 * One-time correction of native receipts booked by releases up to 0.2.46-dev:
 * their date was written in UTC instead of the WHMCS local time, and the Pix
 * fee was not recorded. Only rows matching a module receipt exactly are
 * touched; amounts, identifiers and links are never changed.
 */
final class NativeReceiptRepair
{
    public const DONE = 'native_receipt_repair_v1_utc';

    /** @var Closure(string, array<string, mixed>): array<string, mixed> */
    private readonly Closure $localApi;
    /** @var Closure(string): ?int */
    private readonly Closure $pixFee;

    /**
     * @param callable(string, array<string, mixed>): array<string, mixed> $localApi
     * @param callable(string): ?int $pixFee Fee in cents of a Pix charge, null when unknown.
     */
    public function __construct(private readonly PDO $pdo, callable $localApi, callable $pixFee)
    {
        $this->localApi = Closure::fromCallable($localApi);
        $this->pixFee = Closure::fromCallable($pixFee);
    }

    /** @return array{dates:int, fees:int, complete:bool} */
    public function run(int $limit = 40): array
    {
        $settings = new OperationalSettings($this->pdo);
        if ($settings->get(self::DONE) !== null) {
            return ['dates' => 0, 'fees' => 0, 'complete' => true];
        }
        $dates = 0;
        $fees = 0;
        $complete = true;
        $entries = $this->pdo->query(
            "SELECT idempotency_key, invoice_id, payment_at_utc, metadata_json FROM pagou_ledger_entries "
            . "WHERE entry_type = 'received_payment' AND currency = 'BRL' ORDER BY payment_at_utc ASC"
        );
        foreach ($entries === false ? [] : $entries->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            if ($limit < 1) {
                $complete = false;
                break;
            }
            $metadata = json_decode((string) $entry['metadata_json'], true);
            if (!is_array($metadata)) {
                continue;
            }
            $row = $this->nativeRow((int) $entry['invoice_id'], (string) $entry['idempotency_key'], $metadata);
            if ($row === null) {
                continue;
            }
            $paidAt = new \DateTimeImmutable((string) $entry['payment_at_utc'], new \DateTimeZone('UTC'));
            $local = NativeWhmcsFinancialPort::localDate($paidAt);
            if ((string) $row['date'] === $paidAt->format('Y-m-d H:i:s') && $local !== (string) $row['date']) {
                // The documented UpdateTransaction keeps only the day; the receipt time is preserved here.
                $update = $this->pdo->prepare('UPDATE tblaccounts SET date = :local WHERE id = :id AND date = :utc');
                $update->execute(['local' => $local, 'id' => $row['id'], 'utc' => (string) $row['date']]);
                $dates += $update->rowCount();
            }
            $charge = (string) ($metadata['remote_charge_id'] ?? '');
            if (($metadata['method'] ?? '') === 'pix' && $charge !== '' && (float) $row['fees'] === 0.0) {
                $limit--;
                try {
                    $fee = ($this->pixFee)($charge);
                } catch (\Throwable) {
                    // An unavailable lookup is retried on a later worker run.
                    $complete = false;
                    continue;
                }
                $amount = (int) round((float) $row['amountin'] * 100);
                if ($fee !== null && $fee > 0 && $fee < $amount) {
                    $result = ($this->localApi)('UpdateTransaction', [
                        'transactionid' => (int) $row['id'],
                        'fees' => number_format($fee / 100, 2, '.', ''),
                    ]);
                    if (($result['result'] ?? '') === 'success') {
                        $fees++;
                    } else {
                        $complete = false;
                    }
                }
            }
        }
        if ($complete) {
            $settings->set(self::DONE, gmdate('Y-m-d H:i:s'));
        }

        return ['dates' => $dates, 'fees' => $fees, 'complete' => $complete];
    }

    /**
     * The single positive native row of this module receipt, by any identifier it was booked with.
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>|null
     */
    private function nativeRow(int $invoiceId, string $economicKey, array $metadata): ?array
    {
        $identifiers = array_values(array_unique(array_filter([
            strtolower((string) ($metadata['remote_payment_id'] ?? '')),
            strtolower((string) ($metadata['native_transaction_id'] ?? '')),
        ], static fn (string $value): bool => $value !== '')));
        if ($identifiers === []) {
            return null;
        }
        $marks = implode(',', array_fill(0, count($identifiers), '?'));
        $query = $this->pdo->prepare(
            'SELECT id, date, fees, amountin FROM tblaccounts WHERE invoiceid = ? AND amountin > 0 '
            . "AND gateway IN ('pagou_pix', 'pagou_boleto', 'pagou_creditcard') "
            . 'AND (transid = ? OR LOWER(transid) IN (' . $marks . '))'
        );
        $query->execute([$invoiceId, $economicKey, ...$identifiers]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        return count($rows) === 1 ? $rows[0] : null;
    }
}
