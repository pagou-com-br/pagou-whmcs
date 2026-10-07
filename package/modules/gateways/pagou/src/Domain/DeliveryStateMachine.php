<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

final class DeliveryStateMachine
{
    public static function canTransition(DeliveryState $from, DeliveryState $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, match ($from) {
            DeliveryState::Pending => [DeliveryState::AwaitingArtifacts, DeliveryState::Sending, DeliveryState::Suppressed],
            DeliveryState::AwaitingArtifacts => [DeliveryState::Sending, DeliveryState::Failed, DeliveryState::Suppressed],
            DeliveryState::Sending => [DeliveryState::Delivered, DeliveryState::Failed],
            DeliveryState::Failed => [DeliveryState::Pending, DeliveryState::Sending, DeliveryState::Suppressed],
            DeliveryState::Delivered, DeliveryState::Suppressed => [],
        }, true);
    }

    public static function transition(DeliveryState $from, DeliveryState $to): DeliveryState
    {
        if (!self::canTransition($from, $to)) {
            throw TransitionNotAllowed::between($from->value, $to->value);
        }

        return $to;
    }
}
