<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\Dto;

/** A reference is opaque. PAN and CVV are intentionally not modelled here. */
final class StoredCard
{
    public function __construct(
        public readonly string $id,
        public readonly string $customerId,
        public readonly ?string $brand = null,
        public readonly ?string $lastFour = null,
        public readonly ?int $expiryMonth = null,
        public readonly ?int $expiryYear = null,
        public readonly bool $active = true,
    ) {
    }
}
