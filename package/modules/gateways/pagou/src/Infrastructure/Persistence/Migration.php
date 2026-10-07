<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Persistence;

use PDO;

interface Migration
{
    public function version(): string;

    public function description(): string;

    /** @return list<string> */
    public function statements(string $driver): array;
}
