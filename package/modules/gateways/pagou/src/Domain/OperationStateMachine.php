<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

final class OperationStateMachine
{
    public static function canTransition(OperationState $from, OperationState $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, match ($from) {
            OperationState::Pending => [OperationState::Running, OperationState::Cancelled],
            OperationState::Running => [OperationState::Succeeded, OperationState::Failed, OperationState::Uncertain],
            OperationState::Uncertain => [OperationState::Running, OperationState::Succeeded, OperationState::Failed, OperationState::Cancelled],
            OperationState::Succeeded, OperationState::Failed, OperationState::Cancelled => [],
        }, true);
    }

    public static function transition(OperationState $from, OperationState $to): OperationState
    {
        if (!self::canTransition($from, $to)) {
            throw TransitionNotAllowed::between($from->value, $to->value);
        }

        return $to;
    }
}
