<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\Dto;

final class CardCustomer
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public readonly string $id,
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        public readonly array $metadata = [],
    ) {
    }
}
