<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Persistence;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Additive indexes that can resume after MySQL implicitly commits part of a migration. */
final class IndexMigration implements Migration
{
    /** @param array<string, array{table:string, columns:list<string>}> $indexes */
    public function __construct(
        private readonly string $migrationVersion,
        private readonly string $migrationDescription,
        private readonly array $indexes,
    ) {
        foreach ($indexes as $name => $index) {
            foreach ([$name, $index['table']] as $identifier) {
                if (preg_match('/^pagou_[a-z0-9_]+$/D', $identifier) !== 1) {
                    throw new InvalidArgumentException('Index migrations are restricted to module identifiers.');
                }
            }
            if ($index['columns'] === []) {
                throw new InvalidArgumentException('An index must have columns.');
            }
            foreach ($index['columns'] as $column) {
                if (preg_match('/^[a-z][a-z0-9_]*$/D', $column) !== 1) {
                    throw new InvalidArgumentException('Invalid index column.');
                }
            }
        }
    }

    public function version(): string
    {
        return $this->migrationVersion;
    }

    public function description(): string
    {
        return $this->migrationDescription;
    }

    /** @return list<string> */
    public function statements(string $driver): array
    {
        return array_values($this->indexedStatements($driver));
    }

    public function apply(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pending = $this->indexedStatements($driver);
        // Validate every existing name before executing any DDL. Never replace a conflicting index.
        foreach ($this->indexes as $name => $index) {
            $existing = $this->existingColumns($pdo, $driver, $index['table'], $name);
            if ($existing === null) {
                continue;
            }
            if ($existing !== $index['columns']) {
                throw new RuntimeException('Unexpected definition for index ' . $name . '.');
            }
            unset($pending[$name]);
        }
        if ($pending === []) {
            return;
        }
        $previousTimeout = null;
        if ($driver === 'mysql') {
            $previousTimeout = (int) $pdo->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn();
            $pdo->exec('SET SESSION lock_wait_timeout = ' . min(5, $previousTimeout));
        }
        try {
            foreach ($pending as $sql) {
                $pdo->exec($sql);
            }
        } finally {
            if ($previousTimeout !== null) {
                $pdo->exec('SET SESSION lock_wait_timeout = ' . $previousTimeout);
            }
        }
    }

    /** @return array<string, string> */
    private function indexedStatements(string $driver): array
    {
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }
        $statements = [];
        foreach ($this->indexes as $name => $index) {
            $columns = '`' . implode('`, `', $index['columns']) . '`';
            $statements[$name] = 'CREATE INDEX `' . $name . '` ON `' . $index['table'] . '` (' . $columns . ')'
                . ($driver === 'mysql' ? ' ALGORITHM=INPLACE LOCK=NONE' : '');
        }
        return $statements;
    }

    /** @return list<string>|null */
    private function existingColumns(PDO $pdo, string $driver, string $table, string $name): ?array
    {
        if ($driver === 'mysql') {
            $query = $pdo->prepare('SELECT COLUMN_NAME, NON_UNIQUE, SUB_PART, COLLATION, INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name ORDER BY SEQ_IN_INDEX');
            $query->execute(['table_name' => $table, 'index_name' => $name]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if ((int) $row['NON_UNIQUE'] !== 1 || $row['SUB_PART'] !== null || $row['COLLATION'] !== 'A' || $row['INDEX_TYPE'] !== 'BTREE') {
                    throw new RuntimeException('Unexpected definition for index ' . $name . '.');
                }
            }
            return $rows === [] ? null : array_values(array_map(static fn (array $row): string => (string) $row['COLUMN_NAME'], $rows));
        }
        foreach ($pdo->query('PRAGMA index_list(`' . $table . '`)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['name'] !== $name) {
                continue;
            }
            if ((int) $row['unique'] !== 0 || (int) $row['partial'] !== 0) {
                throw new RuntimeException('Unexpected definition for index ' . $name . '.');
            }
            $columns = [];
            foreach ($pdo->query('PRAGMA index_xinfo(`' . $name . '`)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if ((int) $column['key'] !== 1) {
                    continue;
                }
                if ((int) $column['desc'] !== 0 || $column['coll'] !== 'BINARY') {
                    throw new RuntimeException('Unexpected definition for index ' . $name . '.');
                }
                $columns[] = (string) $column['name'];
            }
            return $columns;
        }
        return null;
    }
}
