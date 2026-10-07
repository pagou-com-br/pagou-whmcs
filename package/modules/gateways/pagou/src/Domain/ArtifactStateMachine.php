<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

final class ArtifactStateMachine
{
    public static function canTransition(ArtifactState $from, ArtifactState $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, match ($from) {
            ArtifactState::Requested => [ArtifactState::Pending, ArtifactState::Available, ArtifactState::Failed],
            ArtifactState::Pending => [ArtifactState::Available, ArtifactState::Failed, ArtifactState::Expired],
            ArtifactState::Available => [ArtifactState::Expired],
            ArtifactState::Failed => [ArtifactState::Requested, ArtifactState::Pending],
            ArtifactState::Expired => [ArtifactState::Requested],
        }, true);
    }

    public static function transition(ArtifactState $from, ArtifactState $to): ArtifactState
    {
        if (!self::canTransition($from, $to)) {
            throw TransitionNotAllowed::between($from->value, $to->value);
        }

        return $to;
    }
}
