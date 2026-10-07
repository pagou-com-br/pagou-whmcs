<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

use InvalidArgumentException;

/** Pure-domain representation of exactly one remote payment attempt. */
final class PaymentAttempt
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly InvoiceRevision $invoiceRevision,
        public readonly PaymentMethod $method,
        public readonly Money $amount,
        public readonly EconomicPaymentKey $economicPaymentKey,
        public readonly IdempotencyKey $idempotencyKey,
        public readonly PaymentAttemptState $state = PaymentAttemptState::Draft,
        public readonly ?RemoteId $remoteId = null,
        public readonly ?SettlementRail $settlementRail = null,
    ) {
        if ($invoiceId < 1) {
            throw new InvalidArgumentException('Invoice ID must be positive.');
        }
        if (!$amount->isPositive()) {
            throw new InvalidArgumentException('Payment attempt amount must be positive.');
        }
        if ($settlementRail !== null && !$this->canUseRail($settlementRail)) {
            throw new InvalidArgumentException('Settlement rail is incompatible with payment method.');
        }
        if ($settlementRail !== null && $state !== PaymentAttemptState::Paid) {
            throw new InvalidArgumentException('Settlement rail may only be set on a paid attempt.');
        }
        if ($state === PaymentAttemptState::Paid && $settlementRail === null) {
            throw new InvalidArgumentException('A paid attempt must identify its settlement rail.');
        }
    }

    public function transitionTo(PaymentAttemptState $state): self
    {
        PaymentAttemptStateMachine::transition($this->state, $state);
        return new self(
            $this->invoiceId,
            $this->invoiceRevision,
            $this->method,
            $this->amount,
            $this->economicPaymentKey,
            $this->idempotencyKey,
            $state,
            $this->remoteId,
            $state === PaymentAttemptState::Paid ? $this->settlementRail : null,
        );
    }

    public function issued(RemoteId $remoteId): self
    {
        if ($this->remoteId !== null && !$this->remoteId->equals($remoteId)) {
            throw new InvalidArgumentException('A payment attempt cannot be rebound to a different remote ID.');
        }

        return new self(
            $this->invoiceId,
            $this->invoiceRevision,
            $this->method,
            $this->amount,
            $this->economicPaymentKey,
            $this->idempotencyKey,
            PaymentAttemptStateMachine::transition($this->state, PaymentAttemptState::AwaitingPayment),
            $remoteId,
        );
    }

    public function settle(SettlementRail $rail): self
    {
        if (!$this->canUseRail($rail)) {
            throw new InvalidArgumentException('Settlement rail is incompatible with payment method.');
        }

        return new self(
            $this->invoiceId,
            $this->invoiceRevision,
            $this->method,
            $this->amount,
            $this->economicPaymentKey,
            $this->idempotencyKey,
            PaymentAttemptStateMachine::transition($this->state, PaymentAttemptState::Paid),
            $this->remoteId,
            $rail,
        );
    }

    private function canUseRail(SettlementRail $rail): bool
    {
        return match ($this->method) {
            PaymentMethod::Pix => $rail === SettlementRail::Pix,
            PaymentMethod::Boleto => in_array($rail, [SettlementRail::Boleto, SettlementRail::PixEmbeddedInBoleto], true),
            PaymentMethod::Card => $rail === SettlementRail::Card,
        };
    }
}
