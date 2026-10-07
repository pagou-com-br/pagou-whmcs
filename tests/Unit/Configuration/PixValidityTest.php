<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Configuration;

use Pagou\Whmcs\Configuration\PixValidity;
use PHPUnit\Framework\TestCase;

final class PixValidityTest extends TestCase
{
    public function testConvertsAmountAndUnitToTheStoredSeconds(): void
    {
        self::assertSame('2592000', PixValidity::seconds('30', 'days'));
        self::assertSame('7200', PixValidity::seconds(' 2 ', 'hours'));
        self::assertSame('60', PixValidity::seconds('1', 'minutes'));
        self::assertSame('90', PixValidity::seconds('90', 'seconds'));
    }

    public function testRefusesValuesOutsideTheApiRangeInPlainWords(): void
    {
        foreach ([['31', 'days'], ['59', 'seconds'], ['721', 'hours']] as [$amount, $unit]) {
            try {
                PixValidity::seconds($amount, $unit);
                self::fail('Out of range: ' . $amount . ' ' . $unit);
            } catch (\InvalidArgumentException $error) {
                self::assertSame('A validade do Pix imediato deve ficar entre 1 minuto e 30 dias.', $error->getMessage());
            }
        }
        foreach ([['', 'days'], ['1.5', 'days'], ['0', 'hours'], ['-2', 'days']] as [$amount, $unit]) {
            try {
                PixValidity::seconds($amount, $unit);
                self::fail('Not an integer: ' . $amount);
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Informe a validade do Pix imediato com um número inteiro.', $error->getMessage());
            }
        }
        $this->expectExceptionMessage('Selecione a unidade da validade do Pix imediato.');
        PixValidity::seconds('1', 'weeks');
    }

    public function testShowsAStoredValueWithItsSimplestUnit(): void
    {
        self::assertSame(['amount' => 30, 'unit' => 'days'], PixValidity::display(2592000));
        self::assertSame(['amount' => 36, 'unit' => 'hours'], PixValidity::display(129600));
        self::assertSame(['amount' => 90, 'unit' => 'minutes'], PixValidity::display(5400));
        self::assertSame(['amount' => 90, 'unit' => 'seconds'], PixValidity::display(90));
    }
}
