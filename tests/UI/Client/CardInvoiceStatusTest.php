<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\UI\Client;

use Pagou\Whmcs\Presentation\CardInvoiceStatus;
use PHPUnit\Framework\TestCase;

final class CardInvoiceStatusTest extends TestCase
{
    public function testPendingCardUsesExistingBlueLoaderAndReadOnlyPolling(): void
    {
        $html = CardInvoiceStatus::render(19, ['status' => 'ok', 'state' => 'pending', 'revision' => 'r1', 'invoiceState' => 'unpaid']);
        self::assertStringContainsString('method=card', $html);
        self::assertStringContainsString('pagou-document-pixels', $html);
        self::assertSame(9, substr_count($html, '<span></span>'));
        self::assertStringNotContainsString('progress-token', $html);
        self::assertStringNotContainsString('<button', $html);
        self::assertStringContainsString('data-pagou-connection', $html);
        self::assertTrue(CardInvoiceStatus::blocksPayment('uncertain'));
        self::assertFalse(CardInvoiceStatus::blocksPayment('failed'));
    }

    public function testTerminalAndUncertainStatesDoNotShowEndlessLoading(): void
    {
        foreach (['uncertain', 'failed', 'refunded', 'charged_back', 'action_required', 'paid'] as $state) {
            $html = CardInvoiceStatus::render(19, ['status' => 'ok', 'state' => $state, 'revision' => '"><script>alert(1)</script>']);
            self::assertStringNotContainsString('pagou-document-pixels', $html);
            self::assertStringNotContainsString('<script>alert', $html);
            self::assertStringContainsString('data-pagou-status-url', $html);
        }
        self::assertSame('', CardInvoiceStatus::render(19, ['status' => 'forbidden']));
    }
}
