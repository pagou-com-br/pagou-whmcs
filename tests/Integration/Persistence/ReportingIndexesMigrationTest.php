<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Persistence;

use PDO;
use Pagou\Whmcs\Infrastructure\Persistence\Migration;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReportingIndexesMigrationTest extends TestCase
{
    public function testUpgradePreservesDataAndOriginalIndexesAndCanRepeat(): void
    {
        [$pdo, $migrations] = $this->existingInstallation();
        $pdo->exec("CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, total TEXT, status TEXT)");
        $pdo->exec("INSERT INTO tblinvoices VALUES (41, '149.90', 'Paid')");
        $pdo->exec("INSERT INTO pagou_payment_attempts (id, invoice_id, client_id, gateway, method, status, amount_cents, idempotency_key, economic_key, created_at, updated_at) VALUES ('attempt-41',41,10,'pagou_pix','pix','paid',14990,'unique-41','economic-41','2026-09-01','2026-09-02')");
        $dataBefore = $this->data($pdo);
        $indexesBefore = $this->indexes($pdo);

        $runner = new MigrationRunner($pdo);
        $runner->migrate($migrations);
        $runner->migrate($migrations);

        self::assertSame($dataBefore, $this->data($pdo));
        $indexesAfter = $this->indexes($pdo);
        foreach ($indexesBefore as $name => $definition) {
            self::assertSame($definition, $indexesAfter[$name]);
        }
        self::assertCount(count($indexesBefore) + 5, $indexesAfter);
        self::assertSame(['entry_type', 'currency', 'payment_at_utc'], $this->columns($pdo, 'pagou_ledger_payment_date_idx'));
        self::assertSame(['created_at'], $this->columns($pdo, 'pagou_attempt_created_idx'));
        self::assertSame(['updated_at'], $this->columns($pdo, 'pagou_attempt_updated_idx'));
        self::assertSame(['updated_at'], $this->columns($pdo, 'pagou_operation_updated_idx'));
        self::assertSame(['received_at_utc'], $this->columns($pdo, 'pagou_webhook_received_idx'));
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM pagou_schema_migrations WHERE version = '014'")->fetchColumn());
    }

    public function testResumeAfterSomeIndexesWereCommittedWithoutTheMigrationRecord(): void
    {
        [$pdo, $migrations] = $this->existingInstallation();
        $statements = $migrations[13]->statements('sqlite');
        $pdo->exec($statements[0]);
        $pdo->exec($statements[1]);
        $runner = new MigrationRunner($pdo);
        $runner->migrate($migrations);
        $runner->migrate($migrations);

        self::assertSame(14, (int) $pdo->query('SELECT COUNT(*) FROM pagou_schema_migrations')->fetchColumn());
        self::assertSame(['received_at_utc'], $this->columns($pdo, 'pagou_webhook_received_idx'));
    }

    public function testAConflictingNameStopsBeforeAnyNewIndexIsCreated(): void
    {
        [$pdo, $migrations] = $this->existingInstallation();
        $pdo->exec('CREATE INDEX pagou_webhook_received_idx ON pagou_webhook_deliveries(processing_status)');
        $before = $this->indexes($pdo);
        try {
            (new MigrationRunner($pdo))->migrate($migrations);
            self::fail('A conflicting definition must not be accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Unexpected definition for index pagou_webhook_received_idx', $exception->getMessage());
        }
        self::assertSame($before, $this->indexes($pdo));
        self::assertSame(13, (int) $pdo->query('SELECT COUNT(*) FROM pagou_schema_migrations')->fetchColumn());
    }

    /** @return array{PDO, list<Migration>} */
    private function existingInstallation(): array
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate(array_slice($migrations, 0, 13));
        return [$pdo, array_slice($migrations, 0, 14)];
    }

    /** @return array<string, mixed> */
    private function data(PDO $pdo): array
    {
        $data = [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name <> 'pagou_schema_migrations' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $data[(string) $table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        }
        return $data;
    }

    /** @return array<string, mixed> */
    private function indexes(PDO $pdo): array
    {
        return $pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'index' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @return list<string> */
    private function columns(PDO $pdo, string $index): array
    {
        return array_column($pdo->query('PRAGMA index_info(' . $index . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
    }
}
