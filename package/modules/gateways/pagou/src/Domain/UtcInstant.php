<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Stringable;

/** Immutable instant normalized to UTC. */
final class UtcInstant implements Stringable
{
    private const FORMAT = 'Y-m-d\\TH:i:s.u\\Z';

    private function __construct(private readonly DateTimeImmutable $dateTime)
    {
    }

    public static function now(): self
    {
        return new self(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    public static function fromDateTime(DateTimeInterface $dateTime): self
    {
        return new self(DateTimeImmutable::createFromInterface($dateTime)->setTimezone(new DateTimeZone('UTC')));
    }

    public static function fromString(string $value): self
    {
        try {
            $dateTime = new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Invalid instant.', previous: $exception);
        }

        return self::fromDateTime($dateTime);
    }

    public function dateTime(): DateTimeImmutable
    {
        return $this->dateTime;
    }

    public function toTimezone(DateTimeZone $timezone): DateTimeImmutable
    {
        return $this->dateTime->setTimezone($timezone);
    }

    public function compareTo(self $other): int
    {
        return $this->dateTime <=> $other->dateTime;
    }

    public function __toString(): string
    {
        return $this->dateTime->format(self::FORMAT);
    }
}
