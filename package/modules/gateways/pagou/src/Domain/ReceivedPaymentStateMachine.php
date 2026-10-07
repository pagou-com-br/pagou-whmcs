<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

final class ReceivedPaymentStateMachine
{
    public static function canTransition(ReceivedPaymentState $from, ReceivedPaymentState $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, match ($from) {
            ReceivedPaymentState::Received => [ReceivedPaymentState::Applying, ReceivedPaymentState::Quarantined, ReceivedPaymentState::Rejected],
            ReceivedPaymentState::Applying => [ReceivedPaymentState::Applied, ReceivedPaymentState::Credited, ReceivedPaymentState::Quarantined],
            ReceivedPaymentState::Quarantined => [ReceivedPaymentState::Applying, ReceivedPaymentState::Applied, ReceivedPaymentState::Credited, ReceivedPaymentState::Rejected],
            ReceivedPaymentState::Applied, ReceivedPaymentState::Credited, ReceivedPaymentState::Rejected => [],
        }, true);
    }

    public static function transition(ReceivedPaymentState $from, ReceivedPaymentState $to): ReceivedPaymentState
    {
        if (!self::canTransition($from, $to)) {
            throw TransitionNotAllowed::between($from->value, $to->value);
        }

        return $to;
    }
}
