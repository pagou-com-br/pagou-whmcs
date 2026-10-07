<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

use InvalidArgumentException;

/** Monotonically increasing snapshot of an invoice relevant to payment issuance. */
final class InvoiceRevision
{
    private function __construct(private readonly int $value)
    {
    }

    public static function initial(): self
    {
        return new self(1);
    }

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw new InvalidArgumentException('Invoice revision must be at least 1.');
        }

        return new self($value);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
