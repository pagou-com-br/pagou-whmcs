<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use DateTimeImmutable;
use PDO;
use Pagou\Whmcs\Application\Async\{JobPriority, OperationJob, OperationResult, OperationScheduler, OperationType, SystemAsyncClock};
use Pagou\Whmcs\Application\Runtime\{AddonSettings, BoletoIssuanceRecovery, OperationHousekeeping, PaymentAttemptStore, WhmcsRuntime};
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Payment\Boleto\Api\{BoletoListFilter, BoletoResponseMapper};
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;
use Pagou\Whmcs\Payment\Boleto\Infrastructure\PdoBoletoAttemptRepository;
use PHPUnit\Framework\TestCase;

final class BoletoIssuanceRecoveryTest extends TestCase
{
    private PDO $pdo;
    private string $attempt;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $attempt = (new PaymentAttemptStore($this->pdo))->ensureCurrent(407853, 7, 'boleto', 14299, '2026-10-20');
        $this->attempt = $attempt['id'];
        (new PdoBoletoAttemptRepository($this->pdo, 7))->save(new BoletoAttempt($attempt['id'], '407853', 1, 14299, '2026-10-20', $attempt['idempotency_key']));
    }

    public function testTheBoletoCreatedForThisIssuanceIsRecognisedByReferenceAndAmount(): void
    {
        $filters = [];
        $list = function (BoletoListFilter $filter) use (&$filters): array {
            $filters[] = $filter;
            return [
                self::charge('other-invoice', 'whmcs-invoice-407854', 142.99),
                self::charge('other-amount', 'whmcs-invoice-407853', 100.00),
                self::charge('created', 'whmcs-invoice-407853', 142.99),
            ];
        };
        $result = $this->recovery($list)->decide($this->row(), new DateTimeImmutable('2026-10-10T13:07:00Z'), new DateTimeImmutable('2026-10-10T13:30:00Z'));

        self::assertSame('adopt', $result['decision']);
        self::assertSame('created', $result['charge']->remoteId);
        // Boletos created from two minutes before the request until Pagou could have listed it.
        self::assertSame('2026-10-10T13:05:00Z', $filters[0]->createdAtFrom);
        self::assertSame('2026-10-10T13:22:00Z', $filters[0]->createdAtTo);
    }

    public function testWithNothingListedTheIssuanceWaitsForTheWindowAndThenRunsAgain(): void
    {
        $recovery = $this->recovery(static fn (BoletoListFilter $filter): array => [self::charge('replaced', 'whmcs-invoice-407853', 142.99)]);
        // A boleto the module already tracks for the invoice, such as a replaced one, is not this issuance.
        $this->pdo->prepare('UPDATE pagou_payment_attempts SET remote_id = ? WHERE id = ?')->execute(['replaced', (new PaymentAttemptStore($this->pdo))->ensureCurrent(407853, 7, 'boleto', 9999, '2026-10-20')['id']]);
        $requested = new DateTimeImmutable('2026-10-10T13:07:00Z');

        self::assertSame(['decision' => 'wait'], $recovery->decide($this->row(), $requested, new DateTimeImmutable('2026-10-10T13:15:00Z')));
        self::assertSame(['decision' => 'retry'], $recovery->decide($this->row(), $requested, new DateTimeImmutable('2026-10-10T13:22:00Z')));
    }

    public function testTwoCandidatesAreNeverGuessed(): void
    {
        $recovery = $this->recovery(static fn (BoletoListFilter $filter): array => [self::charge('a', 'whmcs-invoice-407853', 142.99), self::charge('b', 'whmcs-invoice-407853', 142.99)]);

        self::assertSame(['decision' => 'ambiguous'], $recovery->decide($this->row(), new DateTimeImmutable('2026-10-10T13:07:00Z'), new DateTimeImmutable('2026-10-10T13:30:00Z')));
    }

    public function testHousekeepingAsksOnceForEachUnansweredIssuance(): void
    {
        $this->issueOperation('-10 minutes');
        $housekeeping = new OperationHousekeeping($this->pdo, new OperationScheduler(new PdoOperationOutbox($this->pdo), new SystemAsyncClock()));
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        self::assertSame(1, $housekeeping->recoverIssuance($now));
        self::assertSame(0, $housekeeping->recoverIssuance($now));
        $payload = json_decode((string) $this->pdo->query("SELECT payload_json FROM pagou_payment_operations WHERE operation_type = 'reconcile_uncertain_operation'")->fetchColumn(), true);
        self::assertSame(['boleto.issue', $this->attempt, 407853, 'issue'], [$payload['operation'], $payload['attempt_id'], $payload['invoice_id'], $payload['issue_operation_id']]);
    }

    public function testTheWorkerAdoptsTheListedBoletoOrIssuesAgainWhenNoneExists(): void
    {
        $this->issueOperation('-30 minutes');
        $lookup = static fn (string $method, string $id) => (new BoletoResponseMapper())->charge(['id' => $id, 'amount' => 142.99, 'status' => 1, 'customer_code' => 'whmcs-invoice-407853']);
        $runtime = $this->runtime($lookup, static fn (BoletoListFilter $filter): array => [self::charge('created', 'whmcs-invoice-407853', 142.99)]);

        self::assertSame(OperationResult::Succeeded, $this->recover($runtime)->result);
        self::assertSame('created', (new PaymentAttemptStore($this->pdo))->find($this->attempt)['remote_id']);
        self::assertSame('succeeded', $this->pdo->query("SELECT status FROM pagou_payment_operations WHERE id = 'issue'")->fetchColumn());

        // Nothing was created: the original issuance is resumed, with the same charge.
        $this->setUp();
        $this->issueOperation('-30 minutes');
        $runtime = $this->runtime($lookup, static fn (BoletoListFilter $filter): array => []);
        self::assertSame(OperationResult::Succeeded, $this->recover($runtime)->result);
        self::assertSame('retrying', $this->pdo->query("SELECT status FROM pagou_payment_operations WHERE id = 'issue'")->fetchColumn());
        self::assertSame('queued', (new PaymentAttemptStore($this->pdo))->find($this->attempt)['status']);
    }

    public function testAnIssuancePagouNeverConfirmsStopsAfterThreeRetriesAndAsksForReview(): void
    {
        $this->issueOperation('-30 minutes');
        foreach ([1, 2, 3] as $index) {
            $this->pdo->prepare('INSERT INTO pagou_payment_operations (id,operation_type,status,deduplication_key,priority,payload_json,available_at,created_at,updated_at,attempt_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(['recovery-' . $index, 'reconcile_uncertain_operation', 'succeeded', hash('sha256', 'r' . $index), 90, '{}', '2026-10-10', '2026-10-10', '2026-10-10', $this->attempt]);
        }
        $lookup = static fn (string $method, string $id) => (new BoletoResponseMapper())->charge(['id' => $id, 'amount' => 142.99, 'status' => 1]);
        $outcome = $this->recover($this->runtime($lookup, static fn (BoletoListFilter $filter): array => []));

        self::assertSame(OperationResult::Uncertain, $outcome->result);
        self::assertSame('boleto_issue_recovery_exhausted', $outcome->reason);
        self::assertSame('uncertain', $this->pdo->query("SELECT status FROM pagou_payment_operations WHERE id = 'issue'")->fetchColumn());
        self::assertSame('boleto_issuance_requires_review', $this->pdo->query('SELECT finding_type FROM pagou_reconciliation_findings')->fetchColumn());
        // The reason lets the review close by itself once the charge is replaced.
        $details = json_decode((string) $this->pdo->query('SELECT details_json FROM pagou_reconciliation_findings')->fetchColumn(), true);
        self::assertSame('exhausted', $details['reason']);
        // The invoice card shows the guidance and stops preparing the boleto by itself.
        $this->pdo->exec("CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT, paymentmethod TEXT, total TEXT, credit TEXT)");
        $this->pdo->exec("INSERT INTO tblinvoices VALUES (407853, 7, 'Unpaid', 'pagou_boleto', '142.99', '0.00')");
        self::assertStringStartsWith('A Pagou não confirmou a emissão deste boleto após três tentativas.', $this->runtime($lookup, static fn (BoletoListFilter $filter): array => [])->adminSummary(407853)['review']);
    }

    private function recover(WhmcsRuntime $runtime): \Pagou\Whmcs\Application\Async\HandlerOutcome
    {
        $started = (string) $this->pdo->query("SELECT started_at FROM pagou_payment_operations WHERE id = 'issue'")->fetchColumn();
        $now = new DateTimeImmutable('now');
        $job = new OperationJob('recovery', OperationType::ReconcileUncertainOperation, 'recovery', JobPriority::FinancialRecovery, [
            'operation' => 'boleto.issue', 'attempt_id' => $this->attempt, 'invoice_id' => 407853, 'issue_operation_id' => 'issue', 'requested_at' => $started,
        ], $now, $now);

        return (new \ReflectionMethod($runtime, 'reconcileOperation'))->invoke($runtime, $job);
    }

    private function runtime(callable $lookup, callable $list): WhmcsRuntime
    {
        return new WhmcsRuntime($this->pdo, new AddonSettings([]), new PdoOperationOutbox($this->pdo), static fn (string $command, array $parameters): array => ['result' => 'success'], $lookup, $list);
    }

    private function issueOperation(string $age): void
    {
        $at = (new DateTimeImmutable($age, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->pdo->prepare('INSERT INTO pagou_payment_operations (id,operation_type,status,deduplication_key,priority,payload_json,available_at,created_at,updated_at,started_at,attempt_id,error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['issue', 'issue_boleto', 'uncertain', hash('sha256', 'issue'), 50, json_encode(['attempt_id' => $this->attempt, 'invoice_id' => 407853]), $at, $at, $at, $at, $this->attempt, 'boleto_remote_outcome_unknown']);
    }

    /** @param callable(BoletoListFilter): list<\Pagou\Whmcs\Payment\Boleto\Domain\BoletoCharge> $list */
    private function recovery(callable $list): BoletoIssuanceRecovery
    {
        return new BoletoIssuanceRecovery($this->pdo, \Closure::fromCallable($list));
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return (new PaymentAttemptStore($this->pdo))->find($this->attempt) ?? [];
    }

    private static function charge(string $id, string $reference, float $amount): \Pagou\Whmcs\Payment\Boleto\Domain\BoletoCharge
    {
        return (new BoletoResponseMapper())->charge(['id' => $id, 'amount' => $amount, 'status' => 2, 'customer_code' => $reference, 'payload' => ['line' => 'line', 'bar_code' => 'barcode']]);
    }
}
