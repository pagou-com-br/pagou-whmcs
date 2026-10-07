<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Value;

/** A calendar date. It deliberately has no timezone or time of day. */
final class CivilDate
{
    private function __construct(public readonly string $value)
    {
    }

    public static function fromIso(string $value): self
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Expected a civil date in YYYY-MM-DD format.');
        }

        return new self($value);
    }
}
