<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

final class PaymentAttemptStateMachine
{
    public static function canTransition(PaymentAttemptState $from, PaymentAttemptState $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, match ($from) {
            PaymentAttemptState::Draft => [PaymentAttemptState::PendingIssuance, PaymentAttemptState::Cancelled],
            PaymentAttemptState::PendingIssuance => [PaymentAttemptState::Issuing, PaymentAttemptState::Cancelled, PaymentAttemptState::Failed],
            PaymentAttemptState::Issuing => [PaymentAttemptState::AwaitingPayment, PaymentAttemptState::Uncertain, PaymentAttemptState::Failed],
            PaymentAttemptState::AwaitingPayment => [PaymentAttemptState::Processing, PaymentAttemptState::Paid, PaymentAttemptState::Cancelled, PaymentAttemptState::Expired, PaymentAttemptState::Uncertain],
            PaymentAttemptState::Processing => [PaymentAttemptState::AwaitingPayment, PaymentAttemptState::Paid, PaymentAttemptState::Failed, PaymentAttemptState::Uncertain],
            PaymentAttemptState::Uncertain => [PaymentAttemptState::Issuing, PaymentAttemptState::AwaitingPayment, PaymentAttemptState::Processing, PaymentAttemptState::Paid, PaymentAttemptState::Cancelled, PaymentAttemptState::Expired, PaymentAttemptState::Failed],
            PaymentAttemptState::Paid, PaymentAttemptState::Cancelled, PaymentAttemptState::Expired, PaymentAttemptState::Failed => [],
        }, true);
    }

    public static function transition(PaymentAttemptState $from, PaymentAttemptState $to): PaymentAttemptState
    {
        if (!self::canTransition($from, $to)) {
            throw TransitionNotAllowed::between($from->value, $to->value);
        }

        return $to;
    }
}
