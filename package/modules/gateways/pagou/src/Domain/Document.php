<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

use InvalidArgumentException;
use Stringable;

/** Brazilian taxpayer document normalized to digits and validated by check digits. */
final class Document implements Stringable
{
    private function __construct(private readonly string $digits, private readonly DocumentKind $kind)
    {
    }

    public static function fromString(string $value): self
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        return match (strlen($digits)) {
            11 => self::cpf($digits),
            14 => self::cnpj($digits),
            default => throw new InvalidArgumentException('Document must contain a valid CPF or CNPJ.'),
        };
    }

    public function digits(): string
    {
        return $this->digits;
    }

    public function kind(): DocumentKind
    {
        return $this->kind;
    }

    public function redacted(): string
    {
        return $this->kind === DocumentKind::Cpf
            ? sprintf('***.***.***-%s', substr($this->digits, -2))
            : sprintf('**.***.***/****-%s', substr($this->digits, -2));
    }

    public function __toString(): string
    {
        return $this->digits;
    }

    private static function cpf(string $digits): self
    {
        if (preg_match('/^(\d)\1{10}$/', $digits)) {
            throw new InvalidArgumentException('CPF cannot contain repeated digits.');
        }

        for ($position = 9; $position <= 10; $position++) {
            $sum = 0;
            for ($index = 0; $index < $position; $index++) {
                $sum += (int) $digits[$index] * (($position + 1) - $index);
            }
            $check = ($sum * 10) % 11;
            if ($check === 10) {
                $check = 0;
            }
            if ($check !== (int) $digits[$position]) {
                throw new InvalidArgumentException('CPF check digits are invalid.');
            }
        }

        return new self($digits, DocumentKind::Cpf);
    }

    private static function cnpj(string $digits): self
    {
        if (preg_match('/^(\d)\1{13}$/', $digits)) {
            throw new InvalidArgumentException('CNPJ cannot contain repeated digits.');
        }

        foreach ([12, 13] as $position) {
            $weights = $position === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $sum = 0;
            foreach ($weights as $index => $weight) {
                $sum += (int) $digits[$index] * $weight;
            }
            $remainder = $sum % 11;
            $check = $remainder < 2 ? 0 : 11 - $remainder;
            if ($check !== (int) $digits[$position]) {
                throw new InvalidArgumentException('CNPJ check digits are invalid.');
            }
        }

        return new self($digits, DocumentKind::Cnpj);
    }
}
