<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit;

use Pagou\Whmcs\Application\Runtime\InvoiceProgressToken;
use PHPUnit\Framework\TestCase;

final class InvoiceProgressTokenTest extends TestCase
{
    public function testTokenIsSessionAndInvoiceBoundAndBounded(): void
    {
        unset($_SESSION['pagou_invoice_progress']);
        $token = InvoiceProgressToken::issue(10);
        self::assertTrue(InvoiceProgressToken::valid(10, $token));
        self::assertSame($token, InvoiceProgressToken::issue(10));
        self::assertFalse(InvoiceProgressToken::valid(11, $token));
        self::assertFalse(InvoiceProgressToken::valid(10, ['invalid']));
        self::assertFalse(InvoiceProgressToken::valid(10, 'invalid'));
        for ($i = 20; $i < 80; $i++) {
            InvoiceProgressToken::issue($i);
        }
        self::assertCount(50, $_SESSION['pagou_invoice_progress']);
        self::assertFalse(InvoiceProgressToken::valid(10, $token));
        unset($_SESSION['pagou_invoice_progress']);
    }
}
