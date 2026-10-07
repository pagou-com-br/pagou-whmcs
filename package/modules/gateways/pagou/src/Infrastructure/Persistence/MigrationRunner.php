<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** @param iterable<Migration> $migrations */
    public function migrate(iterable $migrations): void
    {
        $this->createLedger();
        foreach ($migrations as $migration) {
            if ($this->isApplied($migration->version())) {
                continue;
            }
            $this->apply($migration);
        }
    }

    private function createLedger(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS pagou_schema_migrations (version TEXT PRIMARY KEY, description TEXT NOT NULL, applied_at TEXT NOT NULL)');
            return;
        }
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS pagou_schema_migrations (version VARCHAR(32) NOT NULL PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at DATETIME(6) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function isApplied(string $version): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM pagou_schema_migrations WHERE version = :version');
        $statement->execute(['version' => $version]);
        return $statement->fetchColumn() !== false;
    }

    private function apply(Migration $migration): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        // MySQL commits DDL statements implicitly. Opening an explicit
        // transaction around CREATE TABLE therefore leaves no transaction for
        // commit(), even though the table and migration ledger row were
        // created successfully. SQLite supports transactional DDL, so it keeps
        // the stronger all-or-nothing behaviour used by the test suite.
        $transactionalDdl = $driver === 'sqlite';

        try {
            if ($transactionalDdl) {
                $this->pdo->beginTransaction();
            }
            if ($migration instanceof IndexMigration) {
                $migration->apply($this->pdo);
            } else {
                foreach ($migration->statements($driver) as $sql) {
                    $this->pdo->exec($sql);
                }
            }
            $statement = $this->pdo->prepare('INSERT INTO pagou_schema_migrations (version, description, applied_at) VALUES (:version, :description, :applied_at)');
            $statement->execute([
                'version' => $migration->version(),
                'description' => $migration->description(),
                'applied_at' => gmdate('Y-m-d H:i:s.u'),
            ]);
            if ($transactionalDdl) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
