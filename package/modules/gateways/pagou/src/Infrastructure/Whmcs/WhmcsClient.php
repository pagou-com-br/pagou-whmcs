<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Whmcs;

final class WhmcsClient
{
    public function __construct(
        public readonly int $id,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly ?string $companyName = null,
    ) {
    }

    public function displayName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }
}
