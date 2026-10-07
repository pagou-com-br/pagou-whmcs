<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\Dto;

use InvalidArgumentException;

/**
 * Card data can only enter as a provider-issued opaque token/card reference.
 * This type intentionally has no PAN, CVV or expiry fields.
 */
final class CardChargeRequest
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public readonly int $amountCentavos,
        public readonly string $customerId,
        public readonly string $cardReference,
        public readonly string $merchantReference,
        public readonly int $installments = 1,
        public readonly bool $capture = true,
        public readonly array $metadata = [],
        public readonly ?string $payday = null,
        public readonly ?string $softDescriptor = null,
    ) {
        if ($amountCentavos <= 0 || $installments < 1 || trim($customerId) === '' || trim($cardReference) === '' || trim($merchantReference) === '') {
            throw new InvalidArgumentException('Invalid card charge request.');
        }
        if ($installments > 12 || ($payday !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $payday) !== 1)) {
            throw new InvalidArgumentException('Invalid card installments or payday.');
        }
        if ($softDescriptor !== null && (mb_strlen($softDescriptor) > 22 || preg_match('/^[A-Z0-9 .-]+$/', $softDescriptor) !== 1)) {
            throw new InvalidArgumentException('Invalid card soft descriptor.');
        }
    }
}
