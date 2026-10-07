<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Http;

final class RetryAfter
{
    public static function parse(?string $value, ?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        $value = trim($value ?? '');
        if ($value === '') {
            return null;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $seconds = filter_var(ctype_digit($value) ? (ltrim($value, '0') ?: '0') : $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
        if ($seconds !== false) {
            return $now->modify('+' . $seconds . ' seconds');
        }
        $date = \DateTimeImmutable::createFromFormat(DATE_RFC7231, $value);
        return $date !== false && $date > $now ? $date : null;
    }
}
