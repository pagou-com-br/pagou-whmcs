<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Contracts;

interface OperationJournal
{
    /** @param array<string, mixed> $context
     * @return array{status:string,result:array<string,mixed>} claimed means this caller owns the mutation.
     */
    public function started(string $operation, string $idempotencyKey, array $context): array;

    public function rejected(string $operation, string $idempotencyKey, int $httpStatus): void;

    /** @param array<string, mixed> $result */
    public function succeeded(string $operation, string $idempotencyKey, array $result): void;

    /** @param array<string, mixed> $context */
    public function uncertain(string $operation, string $idempotencyKey, array $context): void;
}
