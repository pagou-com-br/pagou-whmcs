<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Dto;

use Pagou\Whmcs\Payment\Pix\Value\Money;

final class RefundPixRequest
{
    public function __construct(
        public readonly string $pixId,
        public readonly string $attemptId,
        public readonly string $idempotencyKey,
        public readonly int $reason,
        public readonly Money $amount,
        public readonly string $description,
    ) {
        if ($pixId === '' || $attemptId === '' || $idempotencyKey === '' || trim($description) === '') {
            throw new \InvalidArgumentException('Pix, attempt, idempotency key and refund description are required.');
        }
        if ($reason < 1 || $reason > 4) {
            throw new \InvalidArgumentException('Pix refund reason must be between 1 and 4.');
        }
    }
}
