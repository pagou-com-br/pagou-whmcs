<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit;

use Pagou\Whmcs\Support\BankText;
use PHPUnit\Framework\TestCase;

final class BankTextTest extends TestCase
{
    public function testTheNamesPagouCouldNotRegisterBecomePlainLatinText(): void
    {
        // As WHMCS keeps them: the ampersand as an HTML entity, the dash typographic.
        self::assertSame('DJL Holding e Investimentos Ltda', BankText::clean('DJL Holding &amp; Investimentos Ltda'));
        self::assertSame('Dec9 Marketing e tecnologia', BankText::clean('Dec9 Marketing & tecnologia'));
        self::assertSame('HIGILESP - Higiene Leste Paulista Dedetizadora Ltda', BankText::clean("HIGILESP \u{2013} Higiene Leste Paulista Dedetizadora Ltda"));
    }

    public function testAccentsAndCommonAddressMarksAreKeptAndTheRestDropped(): void
    {
        self::assertSame('João D\'Ávila Nº 12, apto. 3/B', BankText::clean("João D\u{2019}Ávila Nº 12, apto. 3/B"));
        self::assertSame('Loja do Zé', BankText::clean("Loja \u{1F600} do\u{00A0}Zé"));
        self::assertSame('Rua "A"', BankText::clean("Rua \u{201C}A\u{201D}"));
        self::assertSame('', BankText::clean("\u{1F600}"));
    }
}
