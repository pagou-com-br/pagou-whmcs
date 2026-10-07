<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Domain;

use InvalidArgumentException;
use Pagou\Whmcs\Domain\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testItUsesIntegerCentavosWithoutFloatArithmetic(): void
    {
        $amount = Money::fromDecimal('10,50')->plus(Money::fromCentavos(25));

        self::assertSame(1075, $amount->centavos());
        self::assertSame('10.75', $amount->jsonSerialize());
        self::assertSame('10,75', $amount->format());
    }

    public function testItRejectsMoreThanTwoDecimalPlaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimal('10.123');
    }
}
