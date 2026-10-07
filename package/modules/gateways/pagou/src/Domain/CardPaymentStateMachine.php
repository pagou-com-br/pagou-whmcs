<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

final class CardPaymentStateMachine
{
    public static function canTransition(CardPaymentState $from, CardPaymentState $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, match ($from) {
            CardPaymentState::Draft => [CardPaymentState::AwaitingToken, CardPaymentState::Cancelled],
            CardPaymentState::AwaitingToken => [CardPaymentState::AwaitingAuthentication, CardPaymentState::Authorized, CardPaymentState::Declined, CardPaymentState::ActionRequired, CardPaymentState::Cancelled],
            CardPaymentState::AwaitingAuthentication => [CardPaymentState::Authorized, CardPaymentState::Declined, CardPaymentState::ActionRequired, CardPaymentState::Cancelled],
            CardPaymentState::ActionRequired => [CardPaymentState::AwaitingAuthentication, CardPaymentState::Authorized, CardPaymentState::Declined, CardPaymentState::Cancelled],
            CardPaymentState::Authorized => [CardPaymentState::Captured, CardPaymentState::Cancelled, CardPaymentState::Declined],
            CardPaymentState::Captured => [CardPaymentState::Refunded, CardPaymentState::Chargeback],
            CardPaymentState::Declined, CardPaymentState::Cancelled, CardPaymentState::Refunded, CardPaymentState::Chargeback => [],
        }, true);
    }

    public static function transition(CardPaymentState $from, CardPaymentState $to): CardPaymentState
    {
        if (!self::canTransition($from, $to)) {
            throw TransitionNotAllowed::between($from->value, $to->value);
        }

        return $to;
    }
}
