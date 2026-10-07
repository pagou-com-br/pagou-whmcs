<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Stringable;

/** A calendar date, deliberately independent from timezone and time of day. */
final class CivilDate implements Stringable
{
    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Civil date must use the YYYY-MM-DD format.');
        }

        return new self($value);
    }

    public static function today(DateTimeZone $timezone): self
    {
        return new self((new DateTimeImmutable('now', $timezone))->format('Y-m-d'));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function atStartOfDay(DateTimeZone $timezone): DateTimeImmutable
    {
        return new DateTimeImmutable($this->value . ' 00:00:00', $timezone);
    }

    public function compareTo(self $other): int
    {
        return $this->value <=> $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
