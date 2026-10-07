<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Pix;

use Pagou\Whmcs\Payment\Pix\Value\CivilDate;
use Pagou\Whmcs\Payment\Pix\Value\Money;
use PHPUnit\Framework\TestCase;

final class ValueObjectsTest extends TestCase
{
    public function testMoneyUsesIntegerCentsAtTheBoundary(): void
    {
        self::assertSame('12.34', (new Money(1234))->decimal());
    }

    public function testCivilDateNeverConvertsTimezoneOrTimeOfDay(): void
    {
        self::assertSame('2026-08-22', CivilDate::fromIso('2026-08-22')->value);
    }

    public function testInvalidCivilDateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CivilDate::fromIso('2026-02-31');
    }
}
