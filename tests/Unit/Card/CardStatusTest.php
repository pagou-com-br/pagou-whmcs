<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Card;

use Pagou\Whmcs\Payment\Card\CardStatus;
use PHPUnit\Framework\TestCase;

final class CardStatusTest extends TestCase
{
    public function testUnknownProviderStateIsNeverSettledAutomatically(): void
    {
        $state = CardStatus::fromProvider('a_future_provider_status');
        self::assertSame(CardStatus::Unknown, $state);
        self::assertFalse($state->permitsAutomaticInvoiceSettlement());
        self::assertTrue($state->requiresReconciliation());
    }
}
