<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\Contracts;

interface CardOperationJournal
{
    /** @param array<string,mixed> $context */
    public function started(string $operation, string $idempotencyKey, array $context): void;

    /** @param array<string,mixed> $result */
    public function succeeded(string $operation, string $idempotencyKey, array $result): void;

    /** @param array<string,mixed> $context */
    public function uncertain(string $operation, string $idempotencyKey, array $context): void;

    /** @param array<string,mixed> $context */
    public function failed(string $operation, string $idempotencyKey, array $context): void;
}
