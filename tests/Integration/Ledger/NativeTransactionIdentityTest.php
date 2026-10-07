<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Ledger;

use PDO;
use Pagou\Whmcs\Infrastructure\Whmcs\NativeWhmcsFinancialPort;
use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\FollowUpQueue;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\PdoInvoiceClientResolver;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\PdoLedgerRepository;
use Pagou\Whmcs\Payment\Ledger\ReceivedPayment;
use Pagou\Whmcs\Payment\Ledger\ReceivedPaymentApplier;
use PHPUnit\Framework\TestCase;

final class NativeTransactionIdentityTest extends TestCase
{
    public function testNewNativePaymentUsesOriginalRemoteIdentifierAndGateway(): void
    {
        $pdo = $this->database();
        $calls = [];
        $port = new NativeWhmcsFinancialPort($pdo, static function (string $command, array $parameters) use ($pdo, &$calls): array {
            $calls[] = [$command, $parameters];
            $pdo->prepare(
                'INSERT INTO tblaccounts (invoiceid, transid, gateway, amountin, amountout) '
                . 'VALUES (:invoiceid, :transid, :gateway, :amount, 0)'
            )->execute([
                'invoiceid' => $parameters['invoiceid'],
                'transid' => $parameters['transid'],
                'gateway' => $parameters['gateway'],
                'amount' => $parameters['amount'],
            ]);
            $pdo->exec("UPDATE tblinvoices SET status = 'Paid' WHERE id = 19");

            return ['result' => 'success'];
        });
        $payment = $this->payment(19, 'Receipt-Mixed-19', 'pix');
        $economicKey = $payment->economicKey->value;

        $receipt = $port->applyInvoicePayment($payment);

        self::assertCount(1, $calls);
        self::assertSame('AddInvoicePayment', $calls[0][0]);
        self::assertSame('Receipt-Mixed-19', $calls[0][1]['transid']);
        self::assertSame('pagou_pix', $calls[0][1]['gateway']);
        self::assertSame('Receipt-Mixed-19', $receipt->transactionId);
        self::assertSame('Receipt-Mixed-19', $pdo->query('SELECT transid FROM tblaccounts')->fetchColumn());
        self::assertSame($economicKey, $payment->economicKey->value);
        self::assertNotSame($payment->remotePaymentId, $payment->economicKey->value);
    }

    public function testRecoveryRecognizesHistoricalHashAndCasedRemoteReceiptWithoutAnotherNativeWrite(): void
    {
        foreach (
            [
                ['invoiceId' => 19, 'remoteId' => 'legacy-receipt-19', 'nativeId' => 'economic', 'gateway' => 'pagou_pix'],
                ['invoiceId' => 20, 'remoteId' => 'Receipt-Mixed-20', 'nativeId' => 'remote', 'gateway' => 'pagou_boleto'],
            ] as $scenario
        ) {
            $pdo = $this->database();
            $payment = $this->payment($scenario['invoiceId'], $scenario['remoteId'], 'pix');
            $nativeId = $scenario['nativeId'] === 'economic' ? $payment->economicKey->value : $payment->remotePaymentId;
            $pdo->prepare(
                'INSERT INTO tblaccounts (invoiceid, transid, gateway, amountin, amountout) VALUES (?, ?, ?, ?, 0)'
            )->execute([$payment->invoiceId, $nativeId, $scenario['gateway'], '10.00']);
            $calls = [];
            $port = new NativeWhmcsFinancialPort($pdo, static function (string $command, array $parameters) use (&$calls): array {
                $calls[] = [$command, $parameters];
                self::fail('Recovery must not submit an already applied native payment.');
            });
            $ledger = new PdoLedgerRepository($pdo, new PdoInvoiceClientResolver($pdo));
            $ledger->claim($payment);
            self::assertNotNull($ledger->begin($payment->economicKey));
            $followUps = new class implements FollowUpQueue {
                /** @var list<string> */
                public array $keys = [];

                public function cancelSiblings(int $invoiceId, EconomicPaymentKey $paidKey): void
                {
                    $this->keys[] = $paidKey->value;
                }
            };

            $results = (new ReceivedPaymentApplier($ledger, $port, $followUps))->recover();

            self::assertCount(1, $results);
            self::assertSame('applied', $results[0]->outcome);
            self::assertSame([], $calls);
            self::assertSame([$payment->economicKey->value], $followUps->keys);
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn());
        }
    }

    public function testOnlyPagouGatewayCanSatisfyRecoveryAndAmbiguousReceiptsFailClosed(): void
    {
        $pdo = $this->database();
        $payment = $this->payment(19, 'Receipt-Ownership-19', 'pix');
        $port = new NativeWhmcsFinancialPort($pdo, static fn (): array => ['result' => 'success']);
        $insert = $pdo->prepare(
            'INSERT INTO tblaccounts (invoiceid, transid, gateway, amountin, amountout) VALUES (?, ?, ?, ?, 0)'
        );
        $insert->execute([19, $payment->remotePaymentId, 'bank_transfer', '10.00']);

        self::assertFalse($port->paymentAlreadyApplied($payment->economicKey));

        $insert->execute([19, $payment->economicKey->value, 'pagou_pix', '10.00']);
        $insert->execute([19, $payment->remotePaymentId, 'pagou_boleto', '10.00']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ambiguous native payment receipts');
        $port->paymentAlreadyApplied($payment->economicKey);
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, total TEXT, credit TEXT, status TEXT)');
        $pdo->exec('CREATE TABLE tblaccounts (invoiceid INTEGER, transid TEXT, gateway TEXT, amountin TEXT, amountout TEXT)');
        $pdo->exec('CREATE TABLE pagou_ledger_entries (id TEXT PRIMARY KEY, idempotency_key TEXT NOT NULL UNIQUE, invoice_id INTEGER NOT NULL, client_id INTEGER NOT NULL, attempt_id TEXT NULL, entry_type TEXT NOT NULL, amount_cents INTEGER NOT NULL, currency TEXT NOT NULL DEFAULT "BRL", payment_at_utc TEXT NULL, effective_at_utc TEXT NOT NULL, metadata_json TEXT NULL, created_at TEXT NOT NULL)');
        $pdo->exec("INSERT INTO tblinvoices VALUES (19, 7, '10.00', '0.00', 'Unpaid'), (20, 8, '10.00', '0.00', 'Unpaid')");

        return $pdo;
    }

    private function payment(int $invoiceId, string $remotePaymentId, string $method): ReceivedPayment
    {
        return new ReceivedPayment(
            EconomicPaymentKey::fromRemotePayment($invoiceId, 'pagou', $remotePaymentId),
            'event-' . $remotePaymentId,
            $invoiceId,
            1000,
            'pagou',
            $remotePaymentId,
            new \DateTimeImmutable('2026-10-06T12:00:00Z'),
            $method,
        );
    }
}
