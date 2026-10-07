<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Configuration;

/**
 * Immediate Pix validity as the administrator thinks of it (minutes, hours or
 * days), stored in seconds as the Pagou API expects.
 */
final class PixValidity
{
    public const MINIMUM = 60;
    public const MAXIMUM = 2592000;

    /** Largest unit first, so a stored value is shown with the simplest amount. */
    private const UNITS = ['days' => 86400, 'hours' => 3600, 'minutes' => 60, 'seconds' => 1];

    public static function seconds(string $amount, string $unit): string
    {
        $amount = trim($amount);
        if (!isset(self::UNITS[$unit])) {
            throw new \InvalidArgumentException('Selecione a unidade da validade do Pix imediato.');
        }
        if (preg_match('/^[1-9]\d{0,7}$/D', $amount) !== 1) {
            throw new \InvalidArgumentException('Informe a validade do Pix imediato com um número inteiro.');
        }
        $seconds = (int) $amount * self::UNITS[$unit];
        if ($seconds < self::MINIMUM || $seconds > self::MAXIMUM) {
            throw new \InvalidArgumentException('A validade do Pix imediato deve ficar entre 1 minuto e 30 dias.');
        }

        return (string) $seconds;
    }

    /** @return array{amount:int,unit:string} */
    public static function display(int $seconds): array
    {
        foreach (self::UNITS as $unit => $factor) {
            if ($seconds >= $factor && $seconds % $factor === 0) {
                return ['amount' => intdiv($seconds, $factor), 'unit' => $unit];
            }
        }

        return ['amount' => max(1, $seconds), 'unit' => 'seconds'];
    }
}
