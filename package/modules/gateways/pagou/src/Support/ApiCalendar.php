<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Support;

use Pagou\Whmcs\Payment\Pix\Value\CivilDate;

final class ApiCalendar
{
    public static function today(?\DateTimeImmutable $now = null): string
    {
        return ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    public static function assertDueDate(string $date, ?\DateTimeImmutable $now = null): void
    {
        CivilDate::fromIso($date);
        if ($date < self::today($now)) {
            throw new \InvalidArgumentException('O vencimento é anterior ao dia aceito pela Pagou (UTC). Revise o vencimento da fatura antes de emitir a cobrança.');
        }
    }
}
