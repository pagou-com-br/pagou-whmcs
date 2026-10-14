<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use DateTimeImmutable;
use PDO;
use Pagou\Whmcs\Application\Async\OperationScheduler;
use Pagou\Whmcs\Application\Runtime\{AddonSettings, OperationHousekeeping, PaymentAttemptStore, WhmcsRuntime};
use Pagou\Whmcs\Application\Async\SystemAsyncClock;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\OutboxFollowUpQueue;
use PHPUnit\Framework\TestCase;

final class OperationHousekeepingTest extends TestCase
{
    private PDO $pdo;
    private PaymentAttemptStore $attempts;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->attempts = new PaymentAttemptStore($this->pdo);
    }

    public function testUncertainOperationsOfSettledChargesAreClosedAndOthersAreKept(): void
    {
        $cancelled = $this->charge(10, 'boleto', 'boleto-cancelled', 'cancelled');
        $cancelling = $this->charge(11, 'boleto', 'boleto-cancelling', 'cancel_requested');
        $paid = $this->charge(12, 'pix', 'pix-paid', 'paid');
        $this->receipt(12, 'pix-paid', 'receipt-12');
        $unbooked = $this->charge(13, 'pix', 'pix-unbooked', 'paid');
        $refund = $this->charge(14, 'pix', 'pix-refund', 'paid');
        $this->receipt(14, 'pix-refund', 'receipt-14');
        $this->operation('op-cancelled', 'cancel_boleto', $cancelled, 'boleto_cancel_unknown');
        $this->operation('op-cancelling', 'cancel_boleto', $cancelling, 'boleto_cancel_unknown');
        $this->operation('op-paid', 'reconcile_payment', $paid, 'payment_evidence_missing');
        $this->operation('op-unbooked', 'reconcile_payment', $unbooked, 'payment_evidence_missing');
        // A refund under review is never closed by housekeeping.
        $this->operation('op-refund', 'reconcile_payment', $refund, 'pix_refund_requires_review');
        $this->operation('op-recent', 'cancel_boleto', $cancelled, 'boleto_cancel_unknown', '-2 minutes');

        self::assertSame(2, $this->housekeeping()->settle(new DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        self::assertSame(['op-cancelled' => 'succeeded', 'op-cancelling' => 'uncertain', 'op-paid' => 'succeeded', 'op-recent' => 'uncertain', 'op-refund' => 'uncertain', 'op-unbooked' => 'uncertain'], $this->statuses());
        self::assertSame('settled_by_charge_state', $this->pdo->query("SELECT error_message FROM pagou_payment_operations WHERE id = 'op-paid'")->fetchColumn());
    }

    public function testAnOpenEvidenceFindingKeepsThePaymentCheckOpen(): void
    {
        $paid = $this->charge(12, 'pix', 'pix-paid', 'paid');
        $this->receipt(12, 'pix-paid', 'receipt-12');
        $this->operation('op-paid', 'reconcile_payment', $paid, 'payment_evidence_missing');
        $this->pdo->prepare("INSERT INTO pagou_reconciliation_findings (id, finding_key, attempt_id, severity, finding_type, status, details_json, detected_at_utc, created_at, updated_at) VALUES ('f', 'k', ?, 'high', 'payment_evidence_missing', 'open', '{}', '2026-10-10', '2026-10-10', '2026-10-10')")->execute([$paid]);

        self::assertSame(0, $this->housekeeping()->settle(new DateTimeImmutable('now', new \DateTimeZone('UTC'))));
    }

    public function testABoletoLeftBeingCancelledIsAskedAgainOnce(): void
    {
        $stuck = $this->charge(20, 'boleto', 'boleto-stuck', 'cancel_requested');
        $busy = $this->charge(21, 'boleto', 'boleto-busy', 'cancel_requested');
        $this->operation('op-busy', 'replace_boleto', $busy, '', '-1 hour', 'retrying');
        $this->pdo->exec("UPDATE pagou_payment_attempts SET updated_at = '2026-10-01 00:00:00.000000'");
        $housekeeping = $this->housekeeping();
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        self::assertSame(1, $housekeeping->repair($now));
        self::assertSame(0, $housekeeping->repair($now));
        $job = $this->pdo->query("SELECT payload_json FROM pagou_payment_operations WHERE operation_type = 'cancel_boleto'")->fetchColumn();
        self::assertSame(['attempt_id' => $stuck, 'invoice_id' => 20, 'remote_id' => 'boleto-stuck'], json_decode((string) $job, true));
    }

    public function testAnIssuanceReviewClosesOnceItsChargeIsReplacedUnlessPagouListedSeveral(): void
    {
        $replaced = $this->charge(30, 'boleto', '', 'superseded');
        $waiting = $this->charge(31, 'boleto', '', 'queued');
        $several = $this->charge(32, 'boleto', '', 'superseded');
        $this->review('f-replaced', $replaced, 'exhausted');
        $this->review('f-waiting', $waiting, 'exhausted');
        $this->review('f-several', $several, 'ambiguous');
        $housekeeping = $this->housekeeping();
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        self::assertSame(1, $housekeeping->resolve($now));
        self::assertSame(0, $housekeeping->resolve($now));
        $states = $this->pdo->query('SELECT id, status FROM pagou_reconciliation_findings ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(['f-replaced' => 'resolved', 'f-several' => 'open', 'f-waiting' => 'open'], $states);
        $resolution = json_decode((string) $this->pdo->query("SELECT resolution_json FROM pagou_reconciliation_findings WHERE id = 'f-replaced'")->fetchColumn(), true);
        self::assertSame(['reason' => 'charge_closed', 'attempt_status' => 'superseded'], $resolution);
    }

    public function testAnUnansweredIssuanceLeavesTheQueueOnceItsChargeIsReplaced(): void
    {
        $replaced = $this->charge(30, 'boleto', '', 'superseded');
        $waiting = $this->charge(31, 'boleto', '', 'queued');
        $this->operation('op-issue-replaced', 'issue_boleto', $replaced, 'boleto_remote_outcome_unknown:outcome_unknown');
        $this->operation('op-lookup-replaced', 'reconcile_uncertain_operation', $replaced, 'boleto_issue_recovery_exhausted');
        $this->operation('op-issue-waiting', 'issue_boleto', $waiting, 'boleto_remote_outcome_unknown:outcome_unknown');
        $this->operation('op-lookup-waiting', 'reconcile_uncertain_operation', $waiting, 'boleto_issue_recovery_exhausted');

        self::assertSame(2, $this->housekeeping()->settle(new DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        self::assertSame([
            'op-issue-replaced' => 'succeeded', 'op-issue-waiting' => 'uncertain',
            'op-lookup-replaced' => 'succeeded', 'op-lookup-waiting' => 'uncertain',
        ], $this->statuses());
    }

    /** @return list<array{bool}> */
    public static function orders(): array
    {
        return [[true], [false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('orders')]
    public function testAPaidInvoiceAsksOnceToCancelItsOtherChargesWhicheverRunsFirst(bool $hookFirst): void
    {
        $pdo = $this->pdo;
        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT, paymentmethod TEXT)');
        $pdo->exec("INSERT INTO tblinvoices VALUES (30, 7, 'Paid', 'pagou_pix')");
        $pix = $this->charge(30, 'pix', 'pix-paid', 'ready');
        $boleto = $this->charge(30, 'boleto', 'boleto-open', 'ready');
        // The Pix is being booked: its receipt exists before either follow-up runs.
        $this->receipt(30, 'pix-paid', 'receipt-30');
        $runtime = new WhmcsRuntime($pdo, new AddonSettings([]), new PdoOperationOutbox($pdo), static fn (string $command, array $parameters): array => ['result' => 'success']);
        $siblings = new OutboxFollowUpQueue($pdo, new PdoOperationOutbox($pdo));
        $key = EconomicPaymentKey::fromRemotePayment(30, 'pagou', 'receipt-30');
        if ($hookFirst) {
            $runtime->closePaidInvoice(30);
            $siblings->cancelSiblings(30, $key);
        } else {
            $siblings->cancelSiblings(30, $key);
            $runtime->closePaidInvoice(30);
        }

        $jobs = $pdo->query("SELECT operation_type, payload_json FROM pagou_payment_operations WHERE operation_type LIKE 'cancel_%'")->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $jobs);
        self::assertSame('cancel_boleto', $jobs[0]['operation_type']);
        self::assertSame(30, json_decode((string) $jobs[0]['payload_json'], true)['invoice_id']);
        self::assertSame('cancel_requested', $this->attempts->find($boleto)['status']);
        // The charge that received the payment is never asked to be cancelled.
        self::assertSame('ready', $this->attempts->find($pix)['status']);
    }

    private function housekeeping(): OperationHousekeeping
    {
        return new OperationHousekeeping($this->pdo, new OperationScheduler(new PdoOperationOutbox($this->pdo), new SystemAsyncClock()));
    }

    private function charge(int $invoice, string $method, string $remote, string $status): string
    {
        $id = $this->attempts->ensureCurrent($invoice, 7, $method, 1000, '2026-10-20')['id'];
        $this->pdo->prepare('UPDATE pagou_payment_attempts SET remote_id = ?, status = ? WHERE id = ?')->execute([$remote === '' ? null : $remote, $status, $id]);

        return $id;
    }

    private function receipt(int $invoice, string $remote, string $name): void
    {
        $key = hash('sha256', $name);
        $insert = $this->pdo->prepare('INSERT INTO pagou_ledger_entries (id,idempotency_key,invoice_id,client_id,entry_type,amount_cents,currency,payment_at_utc,effective_at_utc,metadata_json,created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$name, $key, $invoice, 7, 'received_payment', 1000, 'BRL', '2026-10-10', '2026-10-10', json_encode(['economic_key' => $key, 'method' => 'pix', 'remote_payment_id' => $name, 'remote_charge_id' => $remote]), '2026-10-10']);
        $insert->execute([$name . '-applied', hash('sha256', 'pagou-ledger-transition-v1|' . $key . '|applied'), $invoice, 7, 'received_payment_transition', 1000, 'BRL', '2026-10-10', '2026-10-10', json_encode(['economic_key' => $key, 'status' => 'applied']), '2026-10-10']);
    }

    private function operation(string $id, string $type, string $attempt, string $error, string $age = '-1 hour', string $status = 'uncertain'): void
    {
        $at = (new DateTimeImmutable($age, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->pdo->prepare('INSERT INTO pagou_payment_operations (id,operation_type,status,deduplication_key,priority,payload_json,available_at,created_at,updated_at,attempt_id,error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $type, $status, hash('sha256', $id), 100, '{}', $at, $at, $at, $attempt, $error]);
    }

    private function review(string $id, string $attempt, string $reason): void
    {
        $this->pdo->prepare("INSERT INTO pagou_reconciliation_findings (id, finding_key, attempt_id, severity, finding_type, status, details_json, detected_at_utc, created_at, updated_at) VALUES (?, ?, ?, 'high', 'boleto_issuance_requires_review', 'open', ?, '2026-10-10', '2026-10-10', '2026-10-10')")
            ->execute([$id, hash('sha256', $id), $attempt, json_encode(['message' => 'Confira.', 'reason' => $reason])]);
    }

    /** @return array<string,string> */
    private function statuses(): array
    {
        $rows = $this->pdo->query("SELECT id, status FROM pagou_payment_operations WHERE id LIKE 'op-%' ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);

        return array_map('strval', $rows);
    }
}
