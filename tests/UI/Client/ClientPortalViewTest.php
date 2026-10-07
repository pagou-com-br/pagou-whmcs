<?php

declare(strict_types=1);

namespace Tests\UI\Client;

use Pagou\Whmcs\Presentation\ClientPortalView;
use PHPUnit\Framework\TestCase;

final class ClientPortalViewTest extends TestCase
{
    public function testItLimitsCustomerActionsToLocalUrls(): void
    {
        $payments = ClientPortalView::payments([[
            'invoiceNumber' => '<b>#42</b>',
            'method' => 'boleto',
            'status' => 'paid',
            'actionUrl' => 'https://other.example/invoice',
        ]]);

        self::assertSame('<b>#42</b>', $payments[0]['invoiceNumber']);
        self::assertSame('Boleto', $payments[0]['method']);
        self::assertSame('Pago', $payments[0]['status']);
        self::assertSame('', $payments[0]['actionUrl']);
    }

    public function testItAcceptsOnlyTheExpectedRelativeInvoicePath(): void
    {
        $payments = ClientPortalView::payments([[
            'invoiceNumber' => '#42',
            'actionUrl' => 'viewinvoice.php?id=42',
        ]]);

        self::assertSame('viewinvoice.php?id=42', $payments[0]['actionUrl']);
    }
}
