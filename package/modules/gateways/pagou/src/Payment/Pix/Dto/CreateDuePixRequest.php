<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Dto;

use Pagou\Whmcs\Payment\Pix\Value\CivilDate;
use Pagou\Whmcs\Payment\Pix\Value\Money;

final class CreateDuePixRequest
{
    /**
     * @param array<string, mixed> $payer
     * @param array<string, scalar> $metadata
     * @param array{type:string,amount:float}|null $fine
     * @param array{type:string,amount:float}|null $interest
     */
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $attemptId,
        public readonly string $idempotencyKey,
        public readonly Money $amount,
        public readonly CivilDate $dueDate,
        public readonly array $payer,
        public readonly string $description,
        public readonly int $expiration,
        public readonly array $metadata = [],
        public readonly ?string $notificationUrl = null,
        public readonly ?array $fine = null,
        public readonly ?array $interest = null,
    ) {
        if ($invoiceId === '' || $attemptId === '' || $idempotencyKey === '' || trim($description) === '') {
            throw new \InvalidArgumentException('Invoice, attempt, idempotency key and description are required.');
        }
        if ($expiration < 1) {
            throw new \InvalidArgumentException('Due Pix expiration must be at least one day.');
        }
        if (!is_string($payer['name'] ?? null) || trim($payer['name']) === '' || !is_string($payer['document'] ?? null) || trim($payer['document']) === '') {
            throw new \InvalidArgumentException('Pix payer name and document are required.');
        }
        \Pagou\Whmcs\Payment\LateChargeRules::pix($fine, false, $amount->cents);
        \Pagou\Whmcs\Payment\LateChargeRules::pix($interest, true, $amount->cents);
    }
}
