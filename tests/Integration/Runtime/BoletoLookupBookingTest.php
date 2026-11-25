<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use DateTimeImmutable;
use PDO;
use Pagou\Whmcs\Application\Async\{JobPriority, OperationJob, OperationResult, OperationType};
use Pagou\Whmcs\Application\Runtime\{AddonSettings, ClientPaymentStatus, PaymentAttemptStore, PaymentIdentityStore, WhmcsRuntime};
use Pagou\Whmcs\Application\Webhook\{HmacWebhookVerifier, WebhookEndpointService, WebhookEventParser};
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\{LeaseRepository, MigrationRunner};
use Pagou\Whmcs\Infrastructure\Persistence\Webhook\{OutboxWebhookEventHandler, PdoWebhookInbox};
use Pagou\Whmcs\Payment\Boleto\Api\BoletoResponseMapper;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;
use Pagou\Whmcs\Payment\Boleto\Infrastructure\PdoBoletoAttemptRepository;
use Pagou\Whmcs\Payment\Pix\Mapper\PixResponseMapper;
use PHPUnit\Framework\TestCase;

/**
 * A boleto paid by barcode whose signed notification never arrives is booked
 * from the authenticated lookup of the charge, only when its amount is certain.
 */
final class BoletoLookupBookingTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $detail = [];
    /** @var list<array{string, array<string, mixed>}> */
    private array $calls = [];

    public function testPaidBoletoWithoutNotificationIsBookedOnceFromItsLookupAfterTheWait(): void
    {
        $pdo = $this->pdo();
        $attempt = $this->boleto($pdo);
        $runtime = $this->runtime($pdo);

        // Within the wait the payment stays in review, as before.
        $this->detail['paid_at'] = $this->ago(600);
        $outcome = $this->reconcile($runtime, $attempt);
        self::assertSame(OperationResult::Uncertain, $outcome->result);
        self::assertSame('payment_evidence_missing', $outcome->reason);
        self::assertSame([], $this->payments());
        self::assertSame('processing', (new PaymentAttemptStore($pdo))->displayForInvoice(19, 'boleto')['state']);

        // The next check after the wait books the charge amount with the charge id and fee.
        $this->detail['paid_at'] = $this->ago(7200);
        self::assertSame(OperationResult::Succeeded, $this->reconcile($runtime, $attempt)->result);
        $payments = $this->payments();
        self::assertCount(1, $payments);
        self::assertSame('boleto-19', $payments[0]['transid']);
        self::assertSame('pagou_boleto', $payments[0]['gateway']);
        self::assertSame('10.00', $payments[0]['amount']);
        self::assertSame('2.49', $payments[0]['fees']);
        self::assertSame('Paid', $pdo->query('SELECT status FROM tblinvoices WHERE id = 19')->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM pagou_reconciliation_findings WHERE status = 'open'")->fetchColumn());
        self::assertSame('resolved', $pdo->query("SELECT status FROM pagou_reconciliation_findings WHERE finding_type = 'payment_evidence_missing'")->fetchColumn());
        self::assertSame('paid', (new ClientPaymentStatus($pdo))->read(19, 'boleto', 7, false)['state']);
        // No late-charge item: the charge amount is the nominal amount.
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM tblinvoiceitems')->fetchColumn());

        // Another check is a replay.
        self::assertSame(OperationResult::Succeeded, $this->reconcile($runtime, $attempt)->result);
        self::assertCount(1, $this->payments());

        // The late notification of the same amount confirms the booking and books nothing.
        $this->receive($pdo, ['name' => 'charge.paid', 'data' => [
            'id' => 'boleto-19', 'transaction_id' => 'bank-receipt-19', 'amount' => ['paid' => 10, 'original' => 10],
            'paid_in' => '2026-11-03T09:40:24', 'payment_type' => 'BOLETO',
        ]]);
        $runtime->runWorker();
        self::assertCount(1, $this->payments());
        self::assertSame('succeeded', $pdo->query('SELECT processing_status FROM pagou_webhook_deliveries')->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM pagou_reconciliation_findings WHERE status = 'open'")->fetchColumn());
    }

    public function testLateNotificationOfAnotherAmountIsReviewedAndNeverBookedAgain(): void
    {
        $pdo = $this->pdo();
        $attempt = $this->boleto($pdo);
        $runtime = $this->runtime($pdo);
        self::assertSame(OperationResult::Succeeded, $this->reconcile($runtime, $attempt)->result);

        $this->receive($pdo, ['name' => 'charge.paid', 'data' => [
            'id' => 'boleto-19', 'transaction_id' => 'bank-receipt-19', 'amount' => ['paid' => 12.5, 'original' => 10],
            'paid_in' => '2026-11-03T09:40:24', 'payment_type' => 'BOLETO',
        ]]);
        $runtime->runWorker();
        self::assertCount(1, $this->payments());
        self::assertSame('payment_after_lookup_booking', $pdo->query("SELECT finding_type FROM pagou_reconciliation_findings WHERE status = 'open'")->fetchColumn());
        self::assertSame('uncertain', $pdo->query('SELECT processing_status FROM pagou_webhook_deliveries')->fetchColumn());
    }

    public function testEmbeddedPixReceiptAfterLookupBookingIsReviewedAndNeverBookedAgain(): void
    {
        $pdo = $this->pdo();
        $attempt = $this->boleto($pdo);
        $runtime = $this->runtime($pdo);
        self::assertSame(OperationResult::Succeeded, $this->reconcile($runtime, $attempt)->result);

        (new PaymentIdentityStore($pdo))->observeCharge($attempt['id'], 'boleto', ['payload' => ['qrcode_id' => 'pix-embedded']]);
        $this->receive($pdo, ['name' => 'qrcode.completed', 'data' => ['id' => 'pix-embedded', 'external_id' => 'kk1', 'transaction_id' => 'qr-payment-19', 'amount' => 10]]);
        $outcome = $this->reconcile($runtime, $attempt);
        self::assertSame(OperationResult::Uncertain, $outcome->result);
        self::assertSame('payment_after_lookup_booking', $outcome->reason);
        self::assertCount(1, $this->payments());
    }

    public function testAnotherRunBookingTheSameBoletoIsWaitedFor(): void
    {
        $pdo = $this->pdo();
        $attempt = $this->boleto($pdo);
        $runtime = $this->runtime($pdo);
        $leases = new LeaseRepository($pdo);
        self::assertTrue($leases->acquire('boleto-booking:' . $attempt['id'], 'other-run', 120));

        $outcome = $this->reconcile($runtime, $attempt);
        self::assertSame(OperationResult::RetryableFailure, $outcome->result);
        self::assertSame('boleto_booking_busy', $outcome->reason);
        self::assertSame([], $this->payments());

        self::assertTrue($leases->release('boleto-booking:' . $attempt['id'], 'other-run'));
        self::assertSame(OperationResult::Succeeded, $this->reconcile($runtime, $attempt)->result);
        self::assertCount(1, $this->payments());
        // The run releases its lease when it ends.
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM pagou_leases WHERE name LIKE 'boleto-booking:%'")->fetchColumn());
    }

    public function testReconciliationButtonReachesThePaymentInReviewFirst(): void
    {
        $pdo = $this->pdo();
        $attempt = $this->boleto($pdo);
        $runtime = $this->runtime($pdo);
        $this->detail['paid_at'] = $this->ago(600);
        self::assertSame('payment_evidence_missing', $this->reconcile($runtime, $attempt)->reason);
        $this->detail['paid_at'] = $this->ago(7200);

        // A charge settled long ago was updated earlier and used to take the only slot.
        $pdo->exec("INSERT INTO tblinvoices (id, userid, total, credit, status) VALUES (20, 8, '10.00', '0.00', 'Paid')");
        $settled = (new PaymentAttemptStore($pdo))->ensureCurrent(20, 8, 'boleto', 1000, '2026-09-20');
        $pdo->prepare("UPDATE pagou_payment_attempts SET remote_id = 'boleto-20', status = 'paid', updated_at = '2026-09-21 00:00:00' WHERE id = ?")->execute([$settled['id']]);

        self::assertSame(1, $runtime->schedulePendingReconciliation(1));
        $payload = json_decode((string) $pdo->query("SELECT payload_json FROM pagou_payment_operations WHERE operation_type = 'reconcile_payment'")->fetchColumn(), true);
        self::assertSame($attempt['id'], $payload['attempt_id']);

        // The worker runs the queued check and books the boleto from its lookup.
        $runtime->runWorker();
        $payments = $this->payments();
        self::assertCount(1, $payments);
        self::assertSame('boleto-19', $payments[0]['transid']);
        self::assertSame('Paid', $pdo->query('SELECT status FROM tblinvoices WHERE id = 19')->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM pagou_reconciliation_findings WHERE status = 'open'")->fetchColumn());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function uncertainAmounts(): array
    {
        return [
            'fine registered' => [['financial_charges' => ['fine' => 2]]],
            'interest registered' => [['financial_charges' => ['interest' => 1]]],
            'discount registered' => [['financial_charges' => ['type' => 'fixed', 'discount' => 1]]],
            'late charges unknown' => [['financial_charges' => null]],
            'paid with fine or interest' => [['status' => 9]],
            'paid with discount' => [['status' => 8]],
            'another amount' => [['amount' => 12]],
            'no payment date' => [['paid_at' => null]],
        ];
    }

    /** @param array<string, mixed> $detail */
    #[\PHPUnit\Framework\Attributes\DataProvider('uncertainAmounts')]
    public function testBoletoWhoseAmountIsNotCertainStaysInReview(array $detail): void
    {
        $pdo = $this->pdo();
        $attempt = $this->boleto($pdo);
        $runtime = $this->runtime($pdo);
        $this->detail = $detail + $this->detail;
        $outcome = $this->reconcile($runtime, $attempt);
        self::assertSame(OperationResult::Uncertain, $outcome->result);
        self::assertSame('payment_evidence_missing', $outcome->reason);
        self::assertSame([], $this->payments());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_ledger_entries')->fetchColumn());
    }

    /** @return array<string, mixed> */
    private function boleto(PDO $pdo): array
    {
        $attempts = new PaymentAttemptStore($pdo);
        $attempt = $attempts->ensureCurrent(19, 7, 'boleto', 1000, '2026-10-28');
        (new PdoBoletoAttemptRepository($pdo, 7))->save(new BoletoAttempt($attempt['id'], '19', 1, 1000, '2026-10-28', $attempt['idempotency_key'], remoteId: 'boleto-19'));
        $attempts->complete($attempt['id'], 'boleto-19', 'ready', ['state' => 'ready']);

        return $attempt;
    }

    private function runtime(PDO $pdo): WhmcsRuntime
    {
        // The detail a boleto issued by this module returns: zero late charges are listed as an empty object.
        $this->detail = [
            'amount' => 10, 'status' => 4, 'fee' => 2.49, 'paid_at' => $this->ago(7200), 'financial_charges' => [],
            'payload' => ['line' => 'line', 'bar_code' => 'barcode'],
        ];
        $this->calls = [];
        $calls = &$this->calls;
        $detail = &$this->detail;

        return new WhmcsRuntime($pdo, new AddonSettings(['worker_max_jobs' => '5']), new PdoOperationOutbox($pdo), static function (string $command, array $params) use ($pdo, &$calls): array {
            $calls[] = [$command, $params];
            if ($command === 'AddInvoicePayment') {
                $pdo->prepare('INSERT INTO tblaccounts (invoiceid, transid, gateway, amountin, amountout) VALUES (?, ?, ?, ?, 0)')->execute([$params['invoiceid'], $params['transid'], $params['gateway'], $params['amount']]);
                $pdo->exec("UPDATE tblinvoices SET status = 'Paid'");
            }
            return ['result' => 'success'];
        }, static function (string $method, string $id) use (&$detail) {
            return $method === 'boleto'
                ? (new BoletoResponseMapper())->charge(['id' => $id] + $detail)
                : (new PixResponseMapper())->charge(['id' => $id, 'amount' => 10, 'status' => 4, 'paid_at' => '2026-11-03T12:00:00Z']);
        });
    }

    /** @param array<string, mixed> $attempt */
    private function reconcile(WhmcsRuntime $runtime, array $attempt): \Pagou\Whmcs\Application\Async\HandlerOutcome
    {
        $now = new DateTimeImmutable('now');
        $job = new OperationJob('reconcile-' . bin2hex(random_bytes(4)), OperationType::ReconcilePayment, 'lookup', JobPriority::Maintenance, ['attempt_id' => $attempt['id']], $now, $now);

        return (new \ReflectionMethod($runtime, 'reconcileOperation'))->invoke($runtime, $job);
    }

    /** @return list<array<string, mixed>> */
    private function payments(): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call[1],
            array_filter($this->calls, static fn (array $call): bool => $call[0] === 'AddInvoicePayment'),
        ));
    }

    private function ago(int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', time() - $seconds);
    }

    /** @param array<string, mixed> $payload */
    private function receive(PDO $pdo, array $payload): void
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $time = (string) time();
        (new WebhookEndpointService(new HmacWebhookVerifier('synthetic-secret'), new WebhookEventParser(), new PdoWebhookInbox($pdo), new OutboxWebhookEventHandler($pdo, new PdoOperationOutbox($pdo))))
            ->receive(['X-Pagou-Timestamp' => $time, 'X-Pagou-Signature' => hash_hmac('sha256', $time . $body, 'synthetic-secret')], $body);
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, total TEXT, credit TEXT, status TEXT, duedate TEXT NULL)');
        $pdo->exec('CREATE TABLE tblaccounts (invoiceid INTEGER, transid TEXT UNIQUE, gateway TEXT NOT NULL, amountin TEXT, amountout TEXT)');
        $pdo->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INTEGER, description TEXT, amount TEXT)');
        $pdo->exec("INSERT INTO tblinvoices (id, userid, total, credit, status) VALUES (19, 7, '10.00', '0.00', 'Unpaid')");

        return $pdo;
    }
}
