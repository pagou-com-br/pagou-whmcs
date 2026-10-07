<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Dto;

use Pagou\Whmcs\Payment\Pix\Value\Money;

final class CreatePixRequest
{
    /**
     * @param array<string, mixed> $payer
     * @param array<string, scalar> $metadata
     */
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $attemptId,
        public readonly string $idempotencyKey,
        public readonly Money $amount,
        public readonly array $payer,
        public readonly string $description,
        public readonly int $expiration,
        public readonly ?string $customerCode = null,
        public readonly array $metadata = [],
        public readonly ?string $notificationUrl = null,
    ) {
        self::assertBase($invoiceId, $attemptId, $idempotencyKey, $payer, $description);
        if ($expiration < 60) {
            throw new \InvalidArgumentException('Immediate Pix expiration must be at least 60 seconds.');
        }
    }

    /** @param array<string, mixed> $payer */
    private static function assertBase(string $invoiceId, string $attemptId, string $idempotencyKey, array $payer, string $description): void
    {
        if ($invoiceId === '' || $attemptId === '' || $idempotencyKey === '' || trim($description) === '') {
            throw new \InvalidArgumentException('Invoice, attempt, idempotency key and description are required.');
        }
        if (!is_string($payer['name'] ?? null) || trim($payer['name']) === '' || !is_string($payer['document'] ?? null) || trim($payer['document']) === '') {
            throw new \InvalidArgumentException('Pix payer name and document are required.');
        }
    }
}
