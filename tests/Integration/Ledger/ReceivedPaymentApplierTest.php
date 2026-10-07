<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Ledger;

use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\ReceivedPayment;
use Pagou\Whmcs\Payment\Ledger\ReceivedPaymentApplier;
use Pagou\Whmcs\Tests\Unit\Ledger\InMemoryFollowUpQueue;
use Pagou\Whmcs\Tests\Unit\Ledger\InMemoryLedgerRepository;
use Pagou\Whmcs\Tests\Unit\Ledger\InMemoryWhmcsFinancialPort;
use PHPUnit\Framework\TestCase;

final class ReceivedPaymentApplierTest extends TestCase
{
    public function testTenReplaysAreAppliedOnce(): void
    {
        [$service, $port] = $this->service(1000);
        $payment = $this->payment(1, 'payment-a', 1000);
        for ($index = 0; $index < 10; ++$index) {
            $service->apply($payment);
        }
        self::assertSame(1000, $port->paid(1));
    }

    public function testTwoRealPaymentsUseNativeOverpaymentCredit(): void
    {
        [$service, $port] = $this->service(1000);
        $service->apply($this->payment(1, 'payment-a', 1000));
        $service->apply($this->payment(1, 'payment-b', 300));
        self::assertSame(1300, $port->paid(1));
        self::assertSame(300, $port->credit(1));
        self::assertTrue($port->invoicePaid(1));
    }

    public function testRecoveryAfterCrashBetweenNativeApplyAndLedgerCommitDoesNotPayTwice(): void
    {
        $ledger = new InMemoryLedgerRepository();
        $port = new InMemoryWhmcsFinancialPort([1 => 1000]);
        $queue = new InMemoryFollowUpQueue();
        $service = new ReceivedPaymentApplier($ledger, $port, $queue);
        $payment = $this->payment(1, 'payment-crash', 1000);

        // This represents a process death after WHMCS commits and before the
        // ledger transaction can be marked applied.
        $entry = $ledger->claim($payment);
        $ledger->begin($entry->key);
        $port->applyInvoicePayment($payment);

        $service->recover();
        self::assertSame(1000, $port->paid(1));
        self::assertCount(1, $queue->cancellations);
    }

    public function testAReplayedJobSettlesABookingInterruptedAfterWhmcsRecordedIt(): void
    {
        $ledger = new InMemoryLedgerRepository();
        $port = new InMemoryWhmcsFinancialPort([1 => 1000]);
        $queue = new InMemoryFollowUpQueue();
        $service = new ReceivedPaymentApplier($ledger, $port, $queue);
        $payment = $this->payment(1, 'payment-interrupted', 1000);
        $ledger->begin($ledger->claim($payment)->key);
        $port->applyInvoicePayment($payment);

        self::assertSame('applied', $service->apply($payment)->outcome);
        self::assertSame(\Pagou\Whmcs\Payment\Ledger\LedgerEntry::APPLIED, $ledger->get($payment->economicKey)->status);
        self::assertSame(1000, $port->paid(1));
        self::assertCount(1, $queue->cancellations);
        // Without the native record, a booking in progress is never repeated.
        $other = $this->payment(1, 'payment-in-progress', 500);
        $ledger->begin($ledger->claim($other)->key);
        self::assertSame('replay', $service->apply($other)->outcome);
        self::assertSame(1000, $port->paid(1));
    }

    public function testBoletoAndItsEmbeddedPixUseTheSameEconomicPaymentIdentity(): void
    {
        [$service, $port] = $this->service(1000);
        $service->apply($this->payment(1, 'payment-boleto', 1000, 'boleto'));
        $service->apply($this->payment(1, 'payment-boleto', 1000, 'pix_embedded_in_boleto'));

        self::assertSame(1000, $port->paid(1));
        self::assertSame(0, $port->credit(1));
    }

    /** @return array{ReceivedPaymentApplier, InMemoryWhmcsFinancialPort} */
    private function service(int $due): array
    {
        $ledger = new InMemoryLedgerRepository();
        $port = new InMemoryWhmcsFinancialPort([1 => $due]);
        return [new ReceivedPaymentApplier($ledger, $port, new InMemoryFollowUpQueue()), $port];
    }

    private function payment(int $invoiceId, string $id, int $cents, string $method = 'pix'): ReceivedPayment
    {
        return new ReceivedPayment(EconomicPaymentKey::fromRemotePayment($invoiceId, 'pagou', $id), 'evt-' . $id, $invoiceId, $cents, 'pagou', $id, new \DateTimeImmutable('2026-08-22T12:00:00Z'), $method);
    }
}
