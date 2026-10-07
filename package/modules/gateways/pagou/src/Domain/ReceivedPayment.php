<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

use InvalidArgumentException;

/** A real provider settlement. Duplicate delivery is represented by the same remote payment ID. */
final class ReceivedPayment
{
    public function __construct(
        public readonly RemoteId $remotePaymentId,
        public readonly EconomicPaymentKey $economicPaymentKey,
        public readonly int $invoiceId,
        public readonly Money $amount,
        public readonly SettlementRail $rail,
        public readonly UtcInstant $paidAt,
        public readonly ReceivedPaymentState $state = ReceivedPaymentState::Received,
    ) {
        if ($invoiceId < 1 || !$amount->isPositive()) {
            throw new InvalidArgumentException('Received payment must have a positive invoice ID and amount.');
        }
    }

    public function transitionTo(ReceivedPaymentState $state): self
    {
        ReceivedPaymentStateMachine::transition($this->state, $state);

        return new self($this->remotePaymentId, $this->economicPaymentKey, $this->invoiceId, $this->amount, $this->rail, $this->paidAt, $state);
    }
}
