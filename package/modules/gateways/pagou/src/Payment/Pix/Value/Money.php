<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Value;

/** Amounts cross the Pagou boundary as integer cents, never floating point. */
final class Money
{
    public function __construct(public readonly int $cents, public readonly string $currency = 'BRL')
    {
        if ($cents < 1) {
            throw new \InvalidArgumentException('The Pix amount must be at least one cent.');
        }

        if ($currency !== 'BRL') {
            throw new \InvalidArgumentException('Pix R1 supports BRL only.');
        }
    }

    public function decimal(): string
    {
        return sprintf('%d.%02d', intdiv($this->cents, 100), $this->cents % 100);
    }
}
