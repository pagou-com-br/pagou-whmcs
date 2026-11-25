<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pagou\Whmcs\Application\Async\{HandlerOutcome, JobPriority, OperationJob, OperationResult, OperationType};
use Pagou\Whmcs\Application\Runtime\{AddonSettings, PaymentAttemptStore, RefusedBoletoReissue, WhmcsRuntime};
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Payment\Boleto\Domain\{BoletoArtifacts, BoletoAttempt, BoletoCharge};
use Pagou\Whmcs\Payment\Boleto\Infrastructure\PdoBoletoAttemptRepository;
use Pagou\Whmcs\Tests\Support\Runtime\RecordingPdo;
use PHPUnit\Framework\TestCase;

/**
 * On 2026-11-22 the bank behind Pagou refused 13 boletos and left another unregistered for
 * two days, while the module asked Pagou about it every minute and the customer saw it being
 * prepared forever. These tests hold the recovery: spaced checks, a deadline, and a new boleto
 * for the customer without waiting for the invoice to be opened.
 */
final class RefusedBoletoRecoveryTest extends TestCase
{
    public function testAnUnregisteredBoletoIsCheckedEveryMinuteAtFirstThenEveryQuarterHour(): void
    {
        foreach (['5 minutes' => null, '20 minutes' => 900, '55 minutes' => 300] as $age => $next) {
            $pdo = $this->database();
            $this->awaiting($pdo, '-' . $age);
            $outcome = $this->reconcile($this->runtime($pdo, $this->lookup('pending')));
            self::assertSame(OperationResult::Pending, $outcome->result, $age);
            self::assertSame('boleto_registration_pending', $outcome->reason, $age);
            if ($next === null) {
                self::assertNull($outcome->retryAt, $age);
            } else {
                self::assertEqualsWithDelta(time() + $next, $outcome->retryAt?->getTimestamp(), 5, $age);
            }
            self::assertSame('awaiting_registration', $this->attemptStatus($pdo, 'attempt'), $age);
        }
    }

    public function testAnUnregisteredBoletoIsGivenUpAfterAnHourAndNeverReopened(): void
    {
        $pdo = $this->database();
        $this->awaiting($pdo, '-61 minutes');
        $outcome = $this->reconcile($this->runtime($pdo, $this->lookup('pending')));
        self::assertSame(OperationResult::Succeeded, $outcome->result);
        self::assertSame('boleto_registration_timeout', $outcome->reason);
        self::assertSame('failed', $this->attemptStatus($pdo, 'attempt'));
        self::assertSame('boleto_registration_timeout', $this->failure($pdo, 'attempt'));
        self::assertSame('failed', (new PaymentAttemptStore($pdo))->displayForInvoice(10, 'boleto')['state']);
        // Its cancellation is asked, so the bank cannot register it later.
        $cancel = $pdo->query("SELECT payload_json FROM pagou_payment_operations WHERE operation_type = 'cancel_boleto'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1, $cancel);
        self::assertSame('remote', json_decode((string) $cancel[0], true)['remote_id']);

        // A late registration reported by Pagou does not reopen it, and asks nothing again.
        $outcome = $this->reconcile($this->runtime($pdo, $this->lookup('active', new BoletoArtifacts('line', 'barcode'))));
        self::assertSame(OperationResult::Succeeded, $outcome->result);
        self::assertSame('failed', $this->attemptStatus($pdo, 'attempt'));
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM pagou_payment_operations WHERE operation_type = 'cancel_boleto'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM pagou_payment_operations WHERE operation_type = 'fetch_boleto_pdf'")->fetchColumn());
    }

    public function testAChargeAlreadyPaidIsNeverGivenUp(): void
    {
        $pdo = $this->database();
        $this->awaiting($pdo, '-2 hours');
        // Paid through the embedded Pix: the boleto charge itself may stay unregistered.
        $pdo->exec("UPDATE pagou_payment_attempts SET status = 'paid'");
        $this->reconcile($this->runtime($pdo, $this->lookup('pending')));
        self::assertSame('paid', $this->attemptStatus($pdo, 'attempt'));
        self::assertNull($this->failure($pdo, 'attempt'));
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM pagou_payment_operations WHERE operation_type = 'cancel_boleto'")->fetchColumn());
    }

    public function testABankRefusalIsRecordedAndANewBoletoReachesTheCustomerAfterHalfAnHour(): void
    {
        $pdo = $this->database();
        $this->invoice($pdo, 10);
        $this->awaiting($pdo, '-2 minutes');
        $requested = [];
        $runtime = $this->runtime($pdo, $this->lookup('rejected'), $this->whmcs($requested));
        self::assertSame(OperationResult::Succeeded, $this->reconcile($runtime)->result);
        self::assertSame('failed', $this->attemptStatus($pdo, 'attempt'));
        self::assertSame('boleto_bank_rejected', $this->failure($pdo, 'attempt'));

        $reissue = new \ReflectionMethod($runtime, 'reissueRefusedBoletos');
        $reissue->invoke($runtime, $this->now());
        self::assertSame([], $requested, 'Not before the wait.');
        $reissue->invoke($runtime, $this->now()->modify('+31 minutes'));
        self::assertSame([10], $requested);
        $new = $pdo->query("SELECT id, status FROM pagou_payment_attempts WHERE id <> 'attempt'")->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $new);
        self::assertSame('queued', $new[0]['status']);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM pagou_payment_operations WHERE operation_type = 'issue_boleto'")->fetchColumn());
        // A run that overlaps finds the new boleto and issues nothing more.
        $reissue->invoke($runtime, $this->now()->modify('+31 minutes'));
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM pagou_payment_attempts')->fetchColumn());
    }

    public function testTheWaitGrowsWithEachRefusalInARow(): void
    {
        $pdo = $this->database();
        $this->invoice($pdo, 10);
        $this->attempt($pdo, 'first', 10, 'failed', 'r1', '-5 hours', '-5 hours');
        $this->attempt($pdo, 'second', 10, 'failed', 'r2', '-3 hours', '-119 minutes');
        $due = new RefusedBoletoReissue($pdo);
        self::assertSame([], $due->due($this->now())['reissue'], 'The second refusal waits two hours.');
        self::assertSame([10], $due->due($this->now()->modify('+2 minutes'))['reissue']);

        // A boleto registered in between starts the count again.
        $this->attempt($pdo, 'ready', 10, 'cancelled', 'r3', '-2 hours', '-2 hours');
        $this->attempt($pdo, 'third', 10, 'failed', 'r4', '-1 hour', '-31 minutes');
        self::assertSame([10], $due->due($this->now())['reissue']);
    }

    public function testOnlyOpenBoletoInvoicesWhoseBoletoPagouCreatedAreIssuedAgain(): void
    {
        $pdo = $this->database();
        $cases = [
            11 => ['Unpaid', 'pagou_boleto', 'failed', null],
            12 => ['Paid', 'pagou_boleto', 'failed', 'r12'],
            13 => ['Unpaid', 'pagou_pix', 'failed', 'r13'],
            14 => ['Cancelled', 'pagou_boleto', 'failed', 'r14'],
            15 => ['Unpaid', 'pagou_boleto', 'failed', 'r15'],
        ];
        foreach ($cases as $invoice => [$status, $gateway, $attempt, $remote]) {
            $this->invoice($pdo, $invoice, $status, $gateway);
            $this->attempt($pdo, 'a' . $invoice, $invoice, $attempt, $remote, '-2 hours', '-1 hour');
        }
        // Refused, then issued again: only the newest boleto of an invoice counts.
        $this->invoice($pdo, 16);
        $this->attempt($pdo, 'a16', 16, 'failed', 'r16', '-2 hours', '-1 hour');
        $this->attempt($pdo, 'b16', 16, 'ready', 'r16b', '-50 minutes', '-50 minutes');
        // Older than three days.
        $this->invoice($pdo, 17);
        $this->attempt($pdo, 'a17', 17, 'failed', 'r17', '-4 days', '-4 days');

        self::assertSame(['reissue' => [15], 'exhausted' => []], (new RefusedBoletoReissue($pdo))->due($this->now()));
    }

    public function testTheFifthRefusalInARowListsTheInvoiceInsteadOfIssuingAnother(): void
    {
        $pdo = $this->database();
        $this->invoice($pdo, 10);
        for ($i = 1; $i <= RefusedBoletoReissue::ATTEMPTS; $i++) {
            $this->attempt($pdo, 'refused-' . $i, 10, 'failed', 'r' . $i, '-' . (40 - $i) . ' hours', '-' . (40 - $i) . ' hours');
        }
        $requested = [];
        $runtime = $this->runtime($pdo, $this->lookup('rejected'), $this->whmcs($requested));
        (new \ReflectionMethod($runtime, 'reissueRefusedBoletos'))->invoke($runtime, $this->now());
        (new \ReflectionMethod($runtime, 'reissueRefusedBoletos'))->invoke($runtime, $this->now());
        self::assertSame([], $requested);
        $findings = $pdo->query('SELECT attempt_id, finding_type, severity, status FROM pagou_reconciliation_findings')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([['attempt_id' => 'refused-5', 'finding_type' => 'charge_issuance_refused', 'severity' => 'high', 'status' => 'open']], $findings);
    }

    public function testInvoicesAreReadOnlyByIdInShortStatements(): void
    {
        $pdo = new RecordingPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->migrate($pdo);
        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, status TEXT, paymentmethod TEXT)');
        for ($invoice = 1; $invoice <= 450; $invoice++) {
            $this->invoice($pdo, $invoice);
            $this->attempt($pdo, 'a' . $invoice, $invoice, 'failed', 'r' . $invoice, '-2 hours', '-1 hour');
        }
        $pdo->statements = [];
        $due = (new RefusedBoletoReissue($pdo))->due($this->now());
        self::assertCount(10, $due['reissue']);
        $reads = $pdo->touching('tblinvoices');
        self::assertCount(3, $reads);
        foreach ($reads as $sql) {
            self::assertStringStartsWith('SELECT id, status, paymentmethod FROM tblinvoices WHERE id IN (', $sql);
            self::assertLessThanOrEqual(200, substr_count($sql, '?'));
        }
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->migrate($pdo);
        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, status TEXT, paymentmethod TEXT)');

        return $pdo;
    }

    private function migrate(PDO $pdo): void
    {
        (new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** A boleto created at Pagou some time ago that the bank has not registered. */
    private function awaiting(PDO $pdo, string $age): void
    {
        (new PdoBoletoAttemptRepository($pdo, 20))->save(new BoletoAttempt('attempt', '10', 1, 1200, '2026-12-07', 'idem', BoletoAttempt::AWAITING_REGISTRATION, 'remote', new BoletoArtifacts()));
        $pdo->prepare('UPDATE pagou_payment_attempts SET created_at = ?')->execute([$this->now()->modify($age)->format('Y-m-d H:i:s.u')]);
    }

    private function attempt(PDO $pdo, string $id, int $invoice, string $status, ?string $remote, string $created, string $updated): void
    {
        $pdo->prepare(
            'INSERT INTO pagou_payment_attempts (id, invoice_id, client_id, gateway, method, status, amount_cents, currency, remote_id, '
            . "idempotency_key, economic_key, created_at, updated_at) VALUES (?, ?, 20, 'pagou', 'boleto', ?, 1200, 'BRL', ?, ?, ?, ?, ?)"
        )->execute([
            $id, $invoice, $status, $remote, hash('sha256', 'i' . $id), hash('sha256', 'e' . $id),
            $this->now()->modify($created)->format('Y-m-d H:i:s.u'), $this->now()->modify($updated)->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function invoice(PDO $pdo, int $id, string $status = 'Unpaid', string $gateway = 'pagou_boleto'): void
    {
        $pdo->prepare('INSERT INTO tblinvoices (id, status, paymentmethod) VALUES (?, ?, ?)')->execute([$id, $status, $gateway]);
    }

    private function attemptStatus(PDO $pdo, string $id): string
    {
        $statement = $pdo->prepare('SELECT status FROM pagou_payment_attempts WHERE id = ?');
        $statement->execute([$id]);

        return (string) $statement->fetchColumn();
    }

    private function failure(PDO $pdo, string $id): ?string
    {
        $statement = $pdo->prepare('SELECT response_json FROM pagou_payment_attempts WHERE id = ?');
        $statement->execute([$id]);

        return json_decode((string) $statement->fetchColumn(), true)['failure'] ?? null;
    }

    private function lookup(string $status, ?BoletoArtifacts $artifacts = null): \Closure
    {
        return static fn (): BoletoCharge => new BoletoCharge('remote', $status, 1200, $artifacts ?? new BoletoArtifacts(), '2026-12-07', null);
    }

    /**
     * The WHMCS API of an open boleto invoice, recording which invoices were read.
     * @param list<int> $requested
     */
    private function whmcs(array &$requested): \Closure
    {
        return static function (string $command, array $parameters) use (&$requested): array {
            if ($command !== 'GetInvoice') {
                return ['result' => 'success'];
            }
            $requested[] = (int) $parameters['invoiceid'];

            return [
                'result' => 'success', 'invoiceid' => (int) $parameters['invoiceid'], 'userid' => 20, 'status' => 'Unpaid',
                'paymentmethod' => 'pagou_boleto', 'total' => '12.00', 'balance' => '12.00', 'duedate' => '2026-12-07',
            ];
        };
    }

    private function runtime(PDO $pdo, \Closure $lookup, ?\Closure $whmcs = null): WhmcsRuntime
    {
        return new WhmcsRuntime($pdo, new AddonSettings([]), new PdoOperationOutbox($pdo), $whmcs ?? static function (): array {
            self::fail('A registration check must not call WHMCS.');
        }, $lookup);
    }

    private function reconcile(WhmcsRuntime $runtime): HandlerOutcome
    {
        $now = new DateTimeImmutable('now');
        $job = new OperationJob('refresh', OperationType::ReconcilePayment, 'refresh', JobPriority::PaymentConfirmation, ['attempt_id' => 'attempt'], $now, $now);

        return (new \ReflectionMethod($runtime, 'reconcileOperation'))->invoke($runtime, $job);
    }
}
