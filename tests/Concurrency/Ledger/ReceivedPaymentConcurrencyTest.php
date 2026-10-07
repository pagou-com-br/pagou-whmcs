<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Concurrency\Ledger;

use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\ReceivedPayment;
use Pagou\Whmcs\Payment\Ledger\ReceivedPaymentApplier;
use Pagou\Whmcs\Tests\Unit\Ledger\InMemoryFollowUpQueue;
use Pagou\Whmcs\Tests\Unit\Ledger\InMemoryLedgerRepository;
use Pagou\Whmcs\Tests\Unit\Ledger\InMemoryWhmcsFinancialPort;
use PHPUnit\Framework\TestCase;

final class ReceivedPaymentConcurrencyTest extends TestCase
{
    public function testCompetingDeliveriesHaveOneNativeApplication(): void
    {
        $ledger = new InMemoryLedgerRepository();
        $port = new InMemoryWhmcsFinancialPort([42 => 500]);
        $service = new ReceivedPaymentApplier($ledger, $port, new InMemoryFollowUpQueue());
        $payment = new ReceivedPayment(EconomicPaymentKey::fromRemotePayment(42, 'pagou', 'concurrent'), 'evt-concurrent', 42, 500, 'pagou', 'concurrent', new \DateTimeImmutable('now'), 'pix');
        $service->apply($payment);
        $service->apply($payment);
        self::assertSame(500, $port->paid(42));
    }
}
