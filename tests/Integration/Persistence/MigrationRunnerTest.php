<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Persistence;

use PDO;
use PDOStatement;
use Pagou\Whmcs\Infrastructure\Persistence\AbstractMigration;
use Pagou\Whmcs\Infrastructure\Persistence\GenericRepository;
use Pagou\Whmcs\Infrastructure\Persistence\LeaseRepository;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Infrastructure\Persistence\OptimisticLockException;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    public function testAllMigrationsAreIdempotentAndCreateExpectedTables(): void
    {
        $pdo = $this->pdo();
        $runner = new MigrationRunner($pdo);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';

        $runner->migrate($migrations);
        $runner->migrate($migrations);

        self::assertSame(16, (int) $pdo->query('SELECT COUNT(*) FROM pagou_schema_migrations')->fetchColumn());
        foreach (['pagou_payment_attempts', 'pagou_payment_operations', 'pagou_payment_artifacts', 'pagou_webhook_deliveries', 'pagou_ledger_entries', 'pagou_card_transactions', 'pagou_card_refunds', 'pagou_card_disputes', 'pagou_card_customers', 'pagou_card_remote_input_sessions'] as $table) {
            self::assertSame($table, $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = '{$table}'")->fetchColumn());
        }
    }

    public function testMysqlDdlDoesNotUseAnExplicitTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $select = $this->createMock(PDOStatement::class);
        $insert = $this->createMock(PDOStatement::class);

        $pdo->method('setAttribute')->willReturn(true);
        $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('mysql');
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::exactly(2))->method('exec');
        $pdo->method('prepare')->willReturnCallback(
            static fn (string $sql): PDOStatement => str_starts_with($sql, 'SELECT') ? $select : $insert,
        );
        $select->expects(self::once())->method('execute')->with(['version' => '001']);
        $select->method('fetchColumn')->willReturn(false);
        $insert->expects(self::once())->method('execute')->with(self::callback(
            static fn (array $bindings): bool => $bindings['version'] === '001'
                && $bindings['description'] === 'Create table',
        ));

        (new MigrationRunner($pdo))->migrate([
            new AbstractMigration(
                '001',
                'Create table',
                ['CREATE TABLE example (id INT)'],
                ['CREATE TABLE example (id INTEGER)'],
            ),
        ]);
    }

    public function testVersionedUpdatesRejectStaleWrites(): void
    {
        $pdo = $this->migratedPdo();
        $repository = new GenericRepository($pdo, 'pagou_payment_attempts');
        $now = '2026-08-22 12:00:00.000000';
        $repository->insert([
            'id' => 'attempt-1', 'invoice_id' => 1, 'client_id' => 1, 'gateway' => 'pagou', 'method' => 'pix', 'status' => 'pending',
            'amount_cents' => 1234, 'currency' => 'BRL', 'idempotency_key' => str_repeat('a', 64), 'economic_key' => str_repeat('b', 64),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        self::assertSame(2, $repository->updateVersioned('attempt-1', 1, ['status' => 'paid']));
        $this->expectException(OptimisticLockException::class);
        $repository->updateVersioned('attempt-1', 1, ['status' => 'cancelled']);
    }

    public function testOnlyOneWorkerCanHoldAnActiveLease(): void
    {
        $pdo = $this->migratedPdo();
        $leases = new LeaseRepository($pdo);
        self::assertTrue($leases->acquire('invoice:1', 'worker-a', 30));
        self::assertFalse($leases->acquire('invoice:1', 'worker-b', 30));
        self::assertFalse($leases->release('invoice:1', 'worker-b'));
        self::assertTrue($leases->release('invoice:1', 'worker-a'));
        self::assertTrue($leases->acquire('invoice:1', 'worker-b', 30));
    }

    private function migratedPdo(): PDO
    {
        $pdo = $this->pdo();
        (new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        return $pdo;
    }

    private function pdo(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
