<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit;

use Pagou\Whmcs\Application\Runtime\EmailPaymentView;
use PHPUnit\Framework\TestCase;

final class EmailPaymentViewTest extends TestCase
{
    public function testPixCodeRemainsCompleteAndEscapedWithoutBrowserControls(): void
    {
        $code = str_repeat('000201BR.GOV.BCB.PIX', 20) . '<test>&';
        $html = EmailPaymentView::render('pix', ['amount' => '12,00', 'copyPaste' => $code, 'qrCodeImageUrl' => 'data:image/png;base64,broken'], 'https://example.test/viewinvoice.php?id=10', '');
        // The box carries the exact code, character for character, once its markup is removed.
        preg_match('#<p style="margin:0 0 16px;padding:12px;[^"]*">(.*?)</p>#s', $html, $box);
        self::assertSame($code, html_entity_decode(strip_tags($box[1]), ENT_QUOTES, 'UTF-8'));
        self::assertStringContainsString('word-break:break-all', $html);
        self::assertStringContainsString('Pix copia e cola', $html);
        foreach (['<input', '<button', '<script', '<img', 'data:image', '<test>'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
    }

    public function testNoTextOfThePixCodeLooksLikeAnAddressThatGmailWouldLink(): void
    {
        $code = '00020101021226910014br.gov.bcb.pix2569qrcode.example.test/pixqrcode/v2/00000000000000000000000000000000000053039865802BR5915LOJA EXEMPLO6009Sao Paulo62070503***630462C5';
        $html = EmailPaymentView::render('pix', ['copyPaste' => $code], 'https://whmcs.test/viewinvoice.php?id=1', '');
        preg_match('#<p style="margin:0 0 16px;padding:12px;[^"]*">(.*?)</p>#s', $html, $box);
        self::assertSame($code, html_entity_decode(strip_tags($box[1]), ENT_QUOTES, 'UTF-8'));
        // Every text run between tags is free of a dot or slash followed by more text.
        foreach (preg_split('#<[^>]+>#', $box[1]) as $text) {
            self::assertDoesNotMatchRegularExpression('#[A-Za-z0-9][./][A-Za-z0-9]#', $text);
        }
        self::assertStringNotContainsString("\u{200B}", $html);
        self::assertStringNotContainsString('&#8203;', $html);
        // The boleto line is digits and stays a single piece of text, centred and grouped as on the boleto.
        $boleto = EmailPaymentView::render('boleto', ['digitableLine' => '50990000010000000000000162686406116070000001154'], 'https://whmcs.test/viewinvoice.php?id=1', '');
        self::assertStringContainsString('text-align:center;', $boleto);
        self::assertStringContainsString('>50990.00001 00000.000000 00162.686406 1 16070000001154</p>', $boleto);
        // A line in another shape is shown as it came.
        self::assertStringContainsString('>23793.38128 60082.123456</p>', EmailPaymentView::render('boleto', ['digitableLine' => '23793.38128 60082.123456'], 'https://whmcs.test/viewinvoice.php?id=1', ''));
    }

    public function testPixShowsOnlyAQrServedOverHttpsAndBoletoOpensThePagouPage(): void
    {
        $qr = 'https://whmcs.test/modules/addons/pagou_payments/qr.php?a=x&e=1&s=y';
        $html = EmailPaymentView::render('pix', ['copyPaste' => 'code', 'qrCodeImageUrl' => 'data:image/png;base64,AAAA'], 'https://whmcs.test/viewinvoice.php?id=1', '', $qr);
        self::assertStringContainsString('<img src="' . htmlspecialchars($qr, ENT_QUOTES, 'UTF-8') . '" width="200" height="200" alt="QR Code Pix"', $html);
        self::assertStringContainsString('escaneie o QR Code', $html);
        self::assertStringNotContainsString('data:image', $html);
        foreach (['http://whmcs.test/qr.php', 'data:image/png;base64,AAAA', 'javascript:alert(1)'] as $unsafe) {
            self::assertStringNotContainsString('<img', EmailPaymentView::render('pix', ['copyPaste' => 'code'], 'https://whmcs.test/viewinvoice.php?id=1', '', $unsafe));
        }

        $icon = 'https://whmcs.test/modules/addons/pagou_payments/assets/email/barcode-white.png';
        $boleto = EmailPaymentView::render('boleto', ['digitableLine' => '123'], 'https://whmcs.test/viewinvoice.php?id=1', 'https://whmcs.test/download.php', '', 'https://fatura.pagou.com.br/boleto/b-1', $icon);
        self::assertStringContainsString('href="https://fatura.pagou.com.br/boleto/b-1"', $boleto);
        self::assertStringContainsString('<span style="vertical-align:middle;">Ver boleto</span></a>', $boleto);
        self::assertStringContainsString('<table role="presentation" align="center"', $boleto);
        // The icon is a hosted image; inline SVG never reaches Gmail or Outlook.
        self::assertStringContainsString('<img src="' . $icon . '" width="22" height="22" alt=""', $boleto);
        self::assertStringNotContainsString('<svg', $boleto);
        self::assertStringNotContainsString('Acessar fatura', $boleto);
        self::assertStringNotContainsString('<img', EmailPaymentView::render('boleto', ['digitableLine' => '123'], 'https://whmcs.test/viewinvoice.php?id=1', '', '', 'https://fatura.pagou.com.br/boleto/b-1'));
        // Without the Pagou page, the reader still reaches the PDF through the WHMCS login.
        self::assertStringContainsString('Acessar fatura', EmailPaymentView::render('boleto', ['digitableLine' => '123'], 'https://whmcs.test/viewinvoice.php?id=1', 'https://whmcs.test/download.php'));
    }
}
