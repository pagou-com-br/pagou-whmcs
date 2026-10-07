<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Whmcs;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Pagou\Whmcs\Domain\CivilDate;
use Pagou\Whmcs\Domain\UtcInstant;

/** Keeps persistence in UTC while preserving WHMCS civil due dates. */
final class WhmcsClock
{
    private readonly DateTimeZone $timezone;

    public function __construct(string $timezone = 'America/Sao_Paulo', private readonly ?\Closure $now = null)
    {
        try {
            $this->timezone = new DateTimeZone($timezone);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Invalid WHMCS timezone.', previous: $exception);
        }
    }

    public function now(): UtcInstant
    {
        $now = $this->now === null ? new DateTimeImmutable('now', new DateTimeZone('UTC')) : ($this->now)();
        if (!$now instanceof DateTimeInterface) {
            throw new \RuntimeException('The clock callable must return a DateTimeInterface.');
        }
        return UtcInstant::fromDateTime($now);
    }

    public function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    public function local(UtcInstant $instant): DateTimeImmutable
    {
        return $instant->toTimezone($this->timezone);
    }

    public function today(): CivilDate
    {
        return CivilDate::fromString($this->local($this->now())->format('Y-m-d'));
    }

    public function dueAtStartOfDay(CivilDate $date): DateTimeImmutable
    {
        return $date->atStartOfDay($this->timezone);
    }
}
