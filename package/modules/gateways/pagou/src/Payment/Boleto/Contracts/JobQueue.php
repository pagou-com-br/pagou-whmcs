<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Contracts;

interface JobQueue
{
    /** @param array<string, scalar|null> $payload */
    public function enqueue(string $name, array $payload, string $deduplicationKey): void;
}
