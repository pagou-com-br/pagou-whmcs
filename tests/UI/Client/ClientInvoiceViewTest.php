<?php

declare(strict_types=1);

namespace Tests\UI\Client;

use Pagou\Whmcs\Presentation\ClientInvoiceView;
use PHPUnit\Framework\TestCase;

final class ClientInvoiceViewTest extends TestCase
{
    public function testItKeepsUntrustedTextAsDataForSmartyEscaping(): void
    {
        $view = ClientInvoiceView::pix([
            'state' => 'PAID',
            'copyPaste' => '<script>alert(1)</script>',
            'timeline' => [['label' => '<img src=x onerror=alert(1)>']],
        ]);

        self::assertSame('paid', $view['state']);
        self::assertSame('<script>alert(1)</script>', $view['copyPaste']);
        self::assertSame('<img src=x onerror=alert(1)>', $view['timeline'][0]['label']);
        self::assertStringContainsString("|escape:'html'", (string) file_get_contents(__DIR__ . '/../../../package/modules/gateways/pagou/templates/invoice-pix.tpl'));
    }

    public function testItRejectsRemoteQrAndArtifactUrls(): void
    {
        $view = ClientInvoiceView::boleto([
            'qrCodeImageUrl' => 'https://unexpected.example/qr.png',
            'pdfUrl' => 'javascript:alert(1)',
        ]);

        self::assertSame('', $view['qrCodeImageUrl']);
        self::assertSame('', $view['pdfUrl']);
    }

    public function testItNeverAcceptsAnUnmaskedCardNumber(): void
    {
        $view = ClientInvoiceView::card(['maskedNumber' => '4111111111111111']);

        self::assertSame('', $view['maskedNumber']);
    }
}
