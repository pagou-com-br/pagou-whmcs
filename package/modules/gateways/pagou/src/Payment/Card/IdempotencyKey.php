<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card;

use InvalidArgumentException;

final class IdempotencyKey
{
    private function __construct(private readonly string $value)
    {
    }

    public static function create(string $scope, string $stableReference): self
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,40}$/', $scope) || trim($stableReference) === '') {
            throw new InvalidArgumentException('Invalid idempotency key components.');
        }

        return new self('whmcs-' . $scope . '-' . hash('sha256', $stableReference));
    }

    public static function fromString(string $value): self
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{16,255}$/', $value)) {
            throw new InvalidArgumentException('Invalid idempotency key.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
    public function __toString(): string
    {
        return $this->value;
    }
}
