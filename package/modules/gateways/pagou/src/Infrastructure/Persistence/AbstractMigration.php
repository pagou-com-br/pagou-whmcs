<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Persistence;

final class AbstractMigration implements Migration
{
    /**
     * @param list<string> $mysql
     * @param list<string> $sqlite
     */
    public function __construct(
        private readonly string $migrationVersion,
        private readonly string $migrationDescription,
        private readonly array $mysql,
        private readonly array $sqlite,
    ) {
    }

    public function version(): string
    {
        return $this->migrationVersion;
    }
    public function description(): string
    {
        return $this->migrationDescription;
    }
    public function statements(string $driver): array
    {
        return $driver === 'sqlite' ? $this->sqlite : $this->mysql;
    }
}
