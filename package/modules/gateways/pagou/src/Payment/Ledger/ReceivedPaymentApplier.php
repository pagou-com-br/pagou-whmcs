<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger;

/**
 * Exactly-once coordinator. The durable ledger is claimed before WHMCS is
 * touched, and a crash after the native operation is recovered by asking the
 * WHMCS adapter whether the economic key was already applied.
 */
final class ReceivedPaymentApplier
{
    public function __construct(
        private readonly LedgerRepository $ledger,
        private readonly WhmcsFinancialPort $whmcs,
        private readonly FollowUpQueue $followUps,
    ) {
    }

    public function apply(ReceivedPayment $payment): PaymentApplyResult
    {
        $entry = $this->ledger->claim($payment);
        if ($entry->status === LedgerEntry::APPLIED) {
            return PaymentApplyResult::replay();
        }
        if ($entry->status === LedgerEntry::APPLYING && $this->whmcs->paymentAlreadyApplied($entry->key, $entry->payment->nativeTransactionId)) {
            // A previous run booked the payment in WHMCS and stopped before recording it.
            return $this->settle($entry);
        }
        if ($entry->status === LedgerEntry::QUARANTINED) {
            return PaymentApplyResult::quarantined($entry->finding ?? 'Payment is quarantined.');
        }

        $entry = $this->ledger->begin($payment->economicKey);
        if ($entry === null) {
            return PaymentApplyResult::replay();
        }

        return $this->applyClaimed($entry);
    }

    /**
     * Completes applications interrupted after WHMCS booked the payment (for
     * example a request ended by a time limit while WHMCS ran its hooks). An
     * application still without a native record after the review period is
     * quarantined; it is never applied again automatically.
     */
    public function settleInterrupted(\DateTimeImmutable $now, int $graceSeconds = 120, int $reviewSeconds = 900): int
    {
        $settled = 0;
        foreach ($this->ledger->recoverable() as $entry) {
            if ($entry->status !== LedgerEntry::APPLYING || $entry->updatedAt === null) {
                continue;
            }
            $age = $now->getTimestamp() - $entry->updatedAt->getTimestamp();
            if ($age < $graceSeconds) {
                continue;
            }
            if ($this->whmcs->paymentAlreadyApplied($entry->key, $entry->payment->nativeTransactionId)) {
                $this->settle($entry);
                $settled++;
            } elseif ($age >= $reviewSeconds) {
                $this->ledger->save($entry->with(LedgerEntry::QUARANTINED, 'payment-application-outcome-unknown-after-interruption', $entry->attempts));
            }
        }

        return $settled;
    }

    private function settle(LedgerEntry $entry): PaymentApplyResult
    {
        $receipt = new NativePaymentReceipt($entry->payment->invoiceId, $entry->payment->amountInCents, 0, true, 'already-applied');
        $this->ledger->save($entry->with(LedgerEntry::APPLIED));
        $this->followUps->cancelSiblings($entry->payment->invoiceId, $entry->key);

        return PaymentApplyResult::applied($receipt);
    }

    /** @return list<PaymentApplyResult> */
    public function recover(): array
    {
        $results = [];
        foreach ($this->ledger->recoverable() as $entry) {
            if ($entry->status === LedgerEntry::QUARANTINED) {
                continue;
            }
            if ($this->whmcs->paymentAlreadyApplied($entry->key, $entry->payment->nativeTransactionId)) {
                $receipt = new NativePaymentReceipt($entry->payment->invoiceId, $entry->payment->amountInCents, 0, true, 'recovered');
                $this->ledger->save($entry->with(LedgerEntry::APPLIED));
                $this->followUps->cancelSiblings($entry->payment->invoiceId, $entry->key);
                $results[] = PaymentApplyResult::applied($receipt);
                continue;
            }

            if ($entry->status === LedgerEntry::APPLYING) {
                $finding = 'payment-application-outcome-unknown-after-crash';
                $this->ledger->save($entry->with(LedgerEntry::QUARANTINED, $finding, $entry->attempts));
                $results[] = PaymentApplyResult::quarantined($finding);
                continue;
            }

            $claimed = $this->ledger->begin($entry->key);
            if ($claimed !== null) {
                $results[] = $this->applyClaimed($claimed);
            }
        }

        return $results;
    }

    private function applyClaimed(LedgerEntry $entry): PaymentApplyResult
    {
        try {
            if ($this->whmcs->paymentAlreadyApplied($entry->key, $entry->payment->nativeTransactionId)) {
                $receipt = new NativePaymentReceipt($entry->payment->invoiceId, $entry->payment->amountInCents, 0, true, 'already-applied');
            } else {
                $receipt = $this->whmcs->applyInvoicePayment($entry->payment);
            }

            $this->assertPostconditions($entry->payment, $receipt);
            $this->ledger->save($entry->with(LedgerEntry::APPLIED));
            $this->followUps->cancelSiblings($entry->payment->invoiceId, $entry->key);

            return PaymentApplyResult::applied($receipt);
        } catch (\Throwable $error) {
            $finding = sprintf('payment-application-failed: %s', $error->getMessage());
            $this->ledger->save($entry->with(LedgerEntry::QUARANTINED, $finding, $entry->attempts + 1));

            return PaymentApplyResult::quarantined($finding);
        }
    }

    private function assertPostconditions(ReceivedPayment $payment, NativePaymentReceipt $receipt): void
    {
        if ($receipt->invoiceId !== $payment->invoiceId || $receipt->appliedInCents !== $payment->amountInCents) {
            throw new \RuntimeException('Native payment postconditions do not match the received payment.');
        }
    }
}
