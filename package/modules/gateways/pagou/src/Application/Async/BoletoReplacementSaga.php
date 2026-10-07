<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

/**
 * Local state machine for a replacement. It never reissues automatically after
 * an uncertain cancellation: that transition is deliberately manual review.
 */
final class BoletoReplacementSaga
{
    public function __construct(public ReplacementSagaState $state = ReplacementSagaState::Requested)
    {
    }

    public function beginCancellation(): void
    {
        $this->transition(ReplacementSagaState::Requested, ReplacementSagaState::CancelPending);
    }

    public function cancellationConfirmed(): void
    {
        $this->transition(ReplacementSagaState::CancelPending, ReplacementSagaState::Cancelled);
    }

    public function requestReplacementIssue(): void
    {
        $this->transition(ReplacementSagaState::Cancelled, ReplacementSagaState::IssuePending);
    }

    public function replacementIssued(): void
    {
        $this->transition(ReplacementSagaState::IssuePending, ReplacementSagaState::ReplacementIssued);
    }

    public function complete(): void
    {
        $this->transition(ReplacementSagaState::ReplacementIssued, ReplacementSagaState::Completed);
    }

    public function markUncertain(): void
    {
        if (in_array($this->state, [ReplacementSagaState::CancelPending, ReplacementSagaState::IssuePending], true)) {
            $this->state = ReplacementSagaState::ManualReview;
            return;
        }
        throw new \LogicException('Replacement saga cannot become uncertain from ' . $this->state->value);
    }

    public function resumeAfterReview(ReplacementSagaState $confirmedState): void
    {
        if ($this->state !== ReplacementSagaState::ManualReview || !in_array($confirmedState, [ReplacementSagaState::Cancelled, ReplacementSagaState::ReplacementIssued], true)) {
            throw new \LogicException('Invalid replacement saga review resolution.');
        }
        $this->state = $confirmedState;
    }

    private function transition(ReplacementSagaState $from, ReplacementSagaState $to): void
    {
        if ($this->state !== $from) {
            throw new \LogicException(sprintf('Invalid replacement saga transition from %s to %s.', $this->state->value, $to->value));
        }
        $this->state = $to;
    }
}
