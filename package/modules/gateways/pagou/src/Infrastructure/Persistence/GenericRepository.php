<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Persistence;

use InvalidArgumentException;
use PDO;

/** Minimal repository for module-owned tables. Table names are allow-listed by construction. */
final class GenericRepository
{
    public function __construct(private readonly PDO $pdo, private readonly string $table, private readonly string $primaryKey = 'id')
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $table) || !preg_match('/^[a-z][a-z0-9_]*$/', $primaryKey)) {
            throw new InvalidArgumentException('Invalid persistence identifier.');
        }
    }

    /** @return array<string,mixed>|null */
    public function find(string|int $id): ?array
    {
        $query = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id LIMIT 1");
        $query->execute(['id' => $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $values */
    public function insert(array $values): void
    {
        if ($values === []) {
            throw new InvalidArgumentException('Values cannot be empty.');
        }
        $columns = array_keys($values);
        $this->assertColumns($columns);
        $quoted = implode(', ', $columns);
        $params = implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns));
        $query = $this->pdo->prepare("INSERT INTO {$this->table} ({$quoted}) VALUES ({$params})");
        $query->execute($values);
    }

    /** @param array<string,mixed> $changes */
    public function updateVersioned(string|int $id, int $version, array $changes): int
    {
        if ($changes === []) {
            return $version;
        }
        $columns = array_keys($changes);
        $this->assertColumns($columns);
        $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = :{$column}", $columns));
        $query = $this->pdo->prepare("UPDATE {$this->table} SET {$assignments}, version = version + 1, updated_at = :updated_at WHERE {$this->primaryKey} = :__id AND version = :__version");
        $changes['__id'] = $id;
        $changes['__version'] = $version;
        $changes['updated_at'] = gmdate('Y-m-d H:i:s.u');
        $query->execute($changes);
        if ($query->rowCount() !== 1) {
            throw new OptimisticLockException('Record changed by another process.');
        }
        return $version + 1;
    }

    /** @param list<string> $columns */
    private function assertColumns(array $columns): void
    {
        foreach ($columns as $column) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $column)) {
                throw new InvalidArgumentException('Invalid persistence column.');
            }
        }
    }
}
