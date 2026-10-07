<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Async\{OperationScheduler, SystemAsyncClock};
use Pagou\Whmcs\Application\Runtime\{OperationalReadModel, PaymentAttemptStore, PeriodicReconciliation};
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\{LeaseRepository, MigrationRunner};
use PHPUnit\Framework\TestCase;

final class AuditRecoveryTest extends TestCase
{
    private PDO $pdo;
    private PaymentAttemptStore $store;
    private PeriodicReconciliation $scheduler;
    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, duedate TEXT, status TEXT)');
        $this->store = new PaymentAttemptStore($this->pdo);
        $this->scheduler = new PeriodicReconciliation($this->pdo, new OperationScheduler(new PdoOperationOutbox($this->pdo), new SystemAsyncClock()));
    }

    public function testPortalPreservesBothOwnershipChecksAndFormatsAllFinalStates(): void
    {
        foreach (['refunded' => 'Estornado', 'superseded' => 'Substituída', 'canceled' => 'Cancelado', 'action_required' => 'Autenticação necessária', 'settled' => 'Liquidado', 'authorized' => 'Autorizado', 'charged_back' => 'Contestado', 'completed' => 'Pago', 'rejected' => 'Recusado'] as $state => $label) {
            $this->pdo->exec('DELETE FROM pagou_payment_read_models');
            $attempt = $this->store->ensureCurrent(10, 20, 'pix', 100, '2026-10-06');
            $this->store->complete($attempt['id'], 'remote-' . $state, $state, ['state' => $state]);
            $this->pdo->exec("REPLACE INTO tblinvoices VALUES (10, 20, '2026-10-06', 'Unpaid')");
            $model = new OperationalReadModel($this->pdo);
            self::assertSame($label, $model->clientPayments(20)[0]['status']);
            self::assertSame('06/10/2026', $model->clientPayments(20)[0]['dueDate']);
            self::assertSame([], $model->clientPayments(21));
            $this->pdo->exec('UPDATE tblinvoices SET userid = 21');
            self::assertSame([], $model->clientPayments(20));
            self::assertSame([], $model->clientPayments(21));
        }
    }

    public function testPortalPaginationDoesNotSkipOrRepeatInvoices(): void
    {
        for ($i = 1; $i <= 52; $i++) {
            $this->pdo->exec("INSERT INTO tblinvoices VALUES ($i, 20, '2026-10-06', 'Unpaid')");
            $this->attempt($i, true);
        }
        $model = new OperationalReadModel($this->pdo);
        $first = array_slice($model->clientPayments(20), 0, 50);
        $second = $model->clientPayments(20, 2);
        self::assertCount(2, $second);
        self::assertCount(52, array_unique(array_column(array_merge($first, $second), 'invoiceNumber')));
    }

    public function testInvalidFiltersNeverBroadenTheQuery(): void
    {
        $this->attempt(1, true);
        foreach ([['invoice' => 'abc'], ['invoice' => '-1'], ['invoice' => '999999999999999999'], ['method' => 'unsupported'], ['status' => 'anything'], ['period' => 'bad'], ['page' => '0']] as $filter) {
            foreach (['payments', 'operations'] as $page) {
                $data = (new OperationalReadModel($this->pdo))->admin($page, $filter);
                self::assertNotEmpty($data['filterErrors']);
                self::assertSame([], $data[$page]);
                self::assertFalse($data['hasNext']);
            }
        }
    }

    public function testPeriodicRecoveryIsBoundedFairAndSkipsUnissuedAttempts(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $this->attempt($i, $i > 10);
        }
        self::assertSame(10, $this->scheduler->schedule());
        self::assertSame(0, $this->scheduler->schedule());
        $this->expireLease();
        self::assertSame(10, $this->scheduler->schedule());
        $this->expireLease();
        self::assertSame(5, $this->scheduler->schedule());
        $this->expireLease();
        self::assertSame(0, $this->scheduler->schedule());
        self::assertSame(25, (int) $this->pdo->query('SELECT COUNT(DISTINCT attempt_id) FROM pagou_payment_operations')->fetchColumn());
        self::assertSame(['eligible' => 30, 'checked' => 0, 'stale' => 30], $this->scheduler->coverage());
        $this->pdo->exec("UPDATE pagou_payment_operations SET status = 'succeeded', finished_at = '2099-01-01 00:00:00'");
        $this->expireLease();
        self::assertSame(5, $this->scheduler->schedule());
        self::assertSame(25, $this->scheduler->coverage()['checked']);
    }

    public function testPendingBackoffAndCompletedChecksAreNotDuplicated(): void
    {
        $this->attempt(1, true);
        self::assertSame(1, $this->scheduler->schedule());
        $this->pdo->exec("UPDATE pagou_payment_operations SET status = 'retrying', created_at = '2000-01-01', available_at = '2099-01-01'");
        $this->expireLease();
        self::assertSame(0, $this->scheduler->schedule());
        $this->pdo->exec("UPDATE pagou_payment_operations SET status = 'succeeded'");
        $this->expireLease();
        self::assertSame(1, $this->scheduler->schedule(10, new \DateTimeImmutable('+16 minutes')));
    }

    public function testCoverageUsesRealOutboxCompletionAndSupportsLegacySuccesses(): void
    {
        $this->attempt(1, true);
        self::assertSame(1, $this->scheduler->schedule());
        $outbox = new PdoOperationOutbox($this->pdo);
        $lease = $outbox->claim('coverage', new \DateTimeImmutable('now'), new \DateInterval('PT60S'));
        self::assertNotNull($lease);
        self::assertFalse($outbox->complete($lease->job->id, 'wrong-owner'));
        self::assertSame(0, $this->scheduler->coverage()['checked']);
        self::assertTrue($outbox->complete($lease->job->id, $lease->token));
        $row = $this->pdo->query('SELECT * FROM pagou_payment_operations')->fetch(PDO::FETCH_ASSOC);
        self::assertNotEmpty($row['finished_at']);
        self::assertSame(['eligible' => 1, 'checked' => 1, 'stale' => 0], $this->scheduler->coverage());

        $this->pdo->exec('UPDATE pagou_payment_operations SET finished_at = NULL');
        self::assertSame(1, $this->scheduler->coverage()['checked']);
        $row['finished_at'] = null;
        self::assertStringContainsString('Última execução:', \Pagou\Whmcs\Presentation\OperationDetails::describe($row));
        $this->pdo->exec("UPDATE pagou_payment_operations SET updated_at = '2000-01-01'");
        self::assertSame(0, $this->scheduler->coverage()['checked']);
    }

    public function testTelemetryCannotWriteThroughAnExpiredLeaseOrExposePayloads(): void
    {
        $this->attempt(1, true);
        $this->scheduler->schedule();
        $outbox = new PdoOperationOutbox($this->pdo);
        $lease = $outbox->claim('test', new \DateTimeImmutable('now'), new \DateInterval('PT60S'));
        self::assertNotNull($lease);
        $recorder = new \Pagou\Whmcs\Application\Runtime\OperationTelemetry($this->pdo);
        $recorder->apiTime($lease->job, 12.34);
        $row = $this->pdo->query('SELECT * FROM pagou_payment_operations')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['api_last_ms' => 12.3], json_decode($row['response_json'], true));
        $row['error_message'] = 'reconciliation_failure:secret-token';
        $row['request_json'] = 'secret-request';
        $text = \Pagou\Whmcs\Presentation\OperationDetails::describe($row);
        self::assertStringContainsString('12,3 ms', $text);
        self::assertStringNotContainsString('secret', $text);
        $this->pdo->exec("UPDATE pagou_payment_operations SET lease_token = 'new-owner'");
        $recorder->apiTime($lease->job, 999.0);
        self::assertSame($row['response_json'], $this->pdo->query('SELECT response_json FROM pagou_payment_operations')->fetchColumn());
        $this->pdo->exec('DROP TABLE pagou_payment_operations');
        $recorder->apiTime($lease->job, 1.0);
        self::assertTrue(true, 'Optional telemetry errors never escape into payment processing.');
    }

    public function testOnlyOneLeaseOwnerWinsAndExpiredLeaseIsReusable(): void
    {
        $lease = new LeaseRepository($this->pdo);
        self::assertTrue($lease->acquire('check', 'one', 60));
        self::assertFalse($lease->acquire('check', 'two', 60));
        $this->pdo->exec("UPDATE pagou_leases SET lease_until = '2000-01-01'");
        self::assertTrue($lease->acquire('check', 'two', 60));
        self::assertFalse($lease->release('check', 'one'));
        self::assertTrue($lease->release('check', 'two'));
    }

    private function attempt(int $invoice, bool $remote): void
    {
        $attempt = $this->store->ensureCurrent($invoice, 20, 'pix', 100, '2026-10-06');
        if ($remote) {
            $this->store->complete($attempt['id'], 'remote-' . $invoice, 'ready', ['state' => 'ready']);
        }
    }

    private function expireLease(): void
    {
        $this->pdo->exec("UPDATE pagou_leases SET lease_until = '2000-01-01'");
    }
}
