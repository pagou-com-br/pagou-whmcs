<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Ledger;

use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\NativePaymentReceipt;
use Pagou\Whmcs\Payment\Ledger\ReceivedPayment;
use Pagou\Whmcs\Payment\Ledger\WhmcsFinancialPort;

/** Simulates WHMCS' native payment operation, including native account credit. */
final class InMemoryWhmcsFinancialPort implements WhmcsFinancialPort
{
    /** @var array<string, true> */
    private array $keys = [];
    /** @var array<int, int> */
    private array $paid = [];
    /** @var array<int, int> */
    private array $credits = [];
    /** @var array<int, int> */
    private array $due;
    public bool $crashAfterNativeApply = false;

    /** @param array<int, int> $due */
    public function __construct(array $due)
    {
        $this->due = $due;
    }

    public function applyInvoicePayment(ReceivedPayment $payment): NativePaymentReceipt
    {
        if ($this->paymentAlreadyApplied($payment->economicKey)) {
            throw new \LogicException('Duplicate native operation.');
        }
        $this->keys[$payment->economicKey->value] = true;
        $this->paid[$payment->invoiceId] = ($this->paid[$payment->invoiceId] ?? 0) + $payment->amountInCents;
        $balanceBefore = max(0, ($this->due[$payment->invoiceId] ?? 0) - (($this->paid[$payment->invoiceId] ?? 0) - $payment->amountInCents));
        $credit = max(0, $payment->amountInCents - $balanceBefore);
        $this->credits[$payment->invoiceId] = ($this->credits[$payment->invoiceId] ?? 0) + $credit;

        if ($this->crashAfterNativeApply) {
            throw new \RuntimeException('simulated crash after native apply');
        }

        return new NativePaymentReceipt($payment->invoiceId, $payment->amountInCents, $credit, $this->invoicePaid($payment->invoiceId), 'txn-' . $payment->remotePaymentId);
    }

    public function paymentAlreadyApplied(EconomicPaymentKey $key, ?string $nativeTransactionId = null): bool
    {
        return isset($this->keys[$key->value]);
    }

    public function paid(int $invoiceId): int
    {
        return $this->paid[$invoiceId] ?? 0;
    }
    public function credit(int $invoiceId): int
    {
        return $this->credits[$invoiceId] ?? 0;
    }
    public function invoicePaid(int $invoiceId): bool
    {
        return $this->paid($invoiceId) >= ($this->due[$invoiceId] ?? 0);
    }
}
