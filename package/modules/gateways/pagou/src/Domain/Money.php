<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

use InvalidArgumentException;
use JsonSerializable;

/** Immutable BRL amount represented as integer centavos. */
final class Money implements JsonSerializable
{
    private function __construct(private readonly int $centavos)
    {
    }

    public static function fromCentavos(int $centavos): self
    {
        return new self($centavos);
    }

    public static function fromDecimal(string $value): self
    {
        $value = trim($value);
        if (!preg_match('/^-?(?:0|[1-9]\d*)(?:[,.]\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException('BRL amount must have at most two decimal places.');
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        $parts = preg_split('/[,.]/', $value);
        if ($parts === false) {
            throw new InvalidArgumentException('BRL amount could not be parsed.');
        }
        [$whole, $fraction] = array_pad($parts, 2, '');
        $centavos = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return new self($negative ? -$centavos : $centavos);
    }

    public function centavos(): int
    {
        return $this->centavos;
    }

    public function isPositive(): bool
    {
        return $this->centavos > 0;
    }

    public function isNegative(): bool
    {
        return $this->centavos < 0;
    }

    public function isZero(): bool
    {
        return $this->centavos === 0;
    }

    public function plus(self $other): self
    {
        return new self($this->centavos + $other->centavos);
    }

    public function minus(self $other): self
    {
        return new self($this->centavos - $other->centavos);
    }

    public function absolute(): self
    {
        return new self(abs($this->centavos));
    }

    public function equals(self $other): bool
    {
        return $this->centavos === $other->centavos;
    }

    public function format(): string
    {
        return number_format($this->centavos / 100, 2, ',', '.');
    }

    public function jsonSerialize(): string
    {
        return number_format($this->centavos / 100, 2, '.', '');
    }
}
