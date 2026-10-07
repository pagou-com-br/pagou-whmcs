<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

use InvalidArgumentException;

/** Card lifecycle stores only an opaque payment token, never PAN or CVV. */
final class CardPayment
{
    public function __construct(
        public readonly PaymentAttempt $attempt,
        public readonly CardPaymentState $state = CardPaymentState::Draft,
        public readonly ?string $tokenReference = null,
        public readonly ?RemoteId $authorizationId = null,
    ) {
        if ($attempt->method !== PaymentMethod::Card) {
            throw new InvalidArgumentException('Card payment requires a card payment attempt.');
        }
        if ($tokenReference !== null && (trim($tokenReference) === '' || strlen($tokenReference) > 512)) {
            throw new InvalidArgumentException('Card token reference is invalid.');
        }
        if (in_array($state, [CardPaymentState::Authorized, CardPaymentState::Captured, CardPaymentState::Refunded, CardPaymentState::Chargeback], true) && $authorizationId === null) {
            throw new InvalidArgumentException('A card authorization identity is required for this state.');
        }
    }

    public function transitionTo(CardPaymentState $state): self
    {
        CardPaymentStateMachine::transition($this->state, $state);

        return new self($this->attempt, $state, $this->tokenReference, $this->authorizationId);
    }

    public function withToken(string $tokenReference): self
    {
        if ($this->state !== CardPaymentState::AwaitingToken) {
            throw TransitionNotAllowed::between($this->state->value, CardPaymentState::AwaitingToken->value);
        }

        return new self($this->attempt, $this->state, $tokenReference, $this->authorizationId);
    }

    public function authorized(RemoteId $authorizationId): self
    {
        return new self(
            $this->attempt,
            CardPaymentStateMachine::transition($this->state, CardPaymentState::Authorized),
            $this->tokenReference,
            $authorizationId,
        );
    }
}
