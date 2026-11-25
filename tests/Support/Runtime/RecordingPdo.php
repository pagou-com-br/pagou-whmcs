<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Support\Runtime;

use PDO;
use PDOStatement;

/** Records every statement and can act before one runs, as another process would meanwhile. */
final class RecordingPdo extends PDO
{
    /** @var list<string> */
    public array $statements = [];
    /** @var (\Closure(string): void)|null */
    public ?\Closure $before = null;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->record($query);
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->record($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->record($statement);
        return parent::exec($statement);
    }

    /** @return list<string> */
    public function touching(string $table): array
    {
        return array_values(array_filter($this->statements, static fn (string $sql): bool => str_contains($sql, $table)));
    }

    private function record(string $sql): void
    {
        $this->statements[] = $sql;
        if ($this->before !== null) {
            ($this->before)($sql);
        }
    }
}
