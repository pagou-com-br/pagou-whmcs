<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\Contracts;

interface CardReconciliationScheduler
{
    /** @param array<string,mixed> $context */
    public function schedule(string $operation, string $idempotencyKey, array $context): void;
}
