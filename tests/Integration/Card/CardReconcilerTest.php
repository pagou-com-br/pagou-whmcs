<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Card;

use Pagou\Whmcs\Payment\Card\CardApiClient;
use Pagou\Whmcs\Payment\Card\CardReconciler;
use Pagou\Whmcs\Payment\Card\CardStatus;
use Pagou\Whmcs\Payment\Card\CardTransport;
use Pagou\Whmcs\Payment\Card\Dto\CardCharge;
use PHPUnit\Framework\TestCase;

final class CardReconcilerTest extends TestCase
{
    public function testOnlyOutstandingStatesArePolled(): void
    {
        $transport = new class implements CardTransport {
            public int $calls = 0;
            public function request(string $method, string $path, array $headers = [], ?array $body = null): array
            {
                $this->calls++;
                return ['id' => 'pending-1', 'status' => 'paid', 'amount' => 100];
            }
        };
        $reconciler = new CardReconciler(new CardApiClient($transport));
        $result = $reconciler->reconcileOutstanding([
            new CardCharge('paid-1', CardStatus::Paid, 100),
            new CardCharge('pending-1', CardStatus::Pending, 100),
        ]);
        self::assertCount(1, $result);
        self::assertSame(1, $transport->calls);
        self::assertSame(CardStatus::Paid, $result[0]->status);
    }
}
