<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Ledger\Persistence;

use PDO;
use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\InvoiceClientResolver;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\PdoLedgerRepository;
use Pagou\Whmcs\Payment\Ledger\LedgerEntry;
use Pagou\Whmcs\Payment\Ledger\ReceivedPayment;
use PHPUnit\Framework\TestCase;

final class PdoLedgerRepositoryTest extends TestCase
{
    public function testEconomicKeyClaimIsUniqueAndSurvivesReplays(): void
    {
        $repository = new PdoLedgerRepository($this->database(), $this->clients());
        $payment = $this->payment('remote-one');

        $first = $repository->claim($payment);
        $second = $repository->claim($payment);

        self::assertSame($first->key->value, $second->key->value);
        self::assertSame(LedgerEntry::RECEIVED, $second->status);
    }

    public function testRecoverySeesApplyingRowAfterProcessCrash(): void
    {
        $repository = new PdoLedgerRepository($this->database(), $this->clients());
        $payment = $this->payment('remote-crash');
        $repository->claim($payment);
        self::assertNotNull($repository->begin($payment->economicKey));

        $recoverable = $repository->recoverable();

        self::assertCount(1, $recoverable);
        self::assertSame(LedgerEntry::APPLYING, $recoverable[0]->status);
        self::assertSame($payment->economicKey->value, $recoverable[0]->key->value);
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE pagou_ledger_entries (id TEXT PRIMARY KEY, idempotency_key TEXT NOT NULL UNIQUE, invoice_id INTEGER NOT NULL, client_id INTEGER NOT NULL, attempt_id TEXT NULL, entry_type TEXT NOT NULL, amount_cents INTEGER NOT NULL, currency TEXT NOT NULL DEFAULT "BRL", payment_at_utc TEXT NULL, effective_at_utc TEXT NOT NULL, metadata_json TEXT NULL, created_at TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE pagou_reconciliation_findings (id TEXT PRIMARY KEY, finding_key TEXT NOT NULL UNIQUE, attempt_id TEXT NULL, severity TEXT NOT NULL, finding_type TEXT NOT NULL, status TEXT NOT NULL, details_json TEXT NOT NULL, detected_at_utc TEXT NOT NULL, resolved_at_utc TEXT NULL, resolved_by INTEGER NULL, resolution_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1)');
        return $pdo;
    }

    private function clients(): InvoiceClientResolver
    {
        return new class implements InvoiceClientResolver {
            public function clientIdForInvoice(int $invoiceId): int
            {
                return 9;
            }
        };
    }

    private function payment(string $remoteId): ReceivedPayment
    {
        return new ReceivedPayment(
            EconomicPaymentKey::fromRemotePayment(7, 'pagou', $remoteId),
            'event-' . $remoteId,
            7,
            500,
            'pagou',
            $remoteId,
            new \DateTimeImmutable('2026-08-22T12:00:00Z'),
            'pix',
        );
    }
}
