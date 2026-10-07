<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

final class OperationWorker
{
    public function __construct(
        private readonly OperationOutbox $outbox,
        private readonly HandlerRegistry $handlers,
        private readonly RetryPolicy $retryPolicy,
        private readonly AsyncClock $clock,
        private readonly int $pendingRetrySeconds = 30,
    ) {
    }

    public function run(string $workerId, WorkerBudget $budget): WorkerReport
    {
        $report = new WorkerReport();
        $startedAt = $this->clock->now();
        $report->reclaimed = $this->outbox->releaseExpiredLeases($startedAt);

        while ($report->claimed < $budget->maximumJobs) {
            $now = $this->clock->now();
            if ($now->getTimestamp() - $startedAt->getTimestamp() >= $budget->maximumSeconds) {
                $report->budgetExhausted = true;
                break;
            }

            $lease = $this->outbox->claim($workerId, $now, $budget->leaseDuration());
            if ($lease === null) {
                break;
            }
            $report->claimed++;
            $handler = $this->handlers->for($lease->job->type);
            if ($handler === null) {
                $this->outbox->fail($lease->job->id, $lease->token, 'missing_handler');
                $report->failed++;
                continue;
            }

            try {
                $outcome = $handler->handle($lease->job);
            } catch (\Throwable $exception) {
                $outcome = HandlerOutcome::retryable('worker_exception:' . $exception::class);
            }
            $this->applyOutcome($lease, $outcome, $this->clock->now(), $report);
        }

        if ($report->claimed >= $budget->maximumJobs) {
            $report->budgetExhausted = true;
        }

        return $report;
    }

    private function applyOutcome(Lease $lease, HandlerOutcome $outcome, \DateTimeImmutable $now, WorkerReport $report): void
    {
        match ($outcome->result) {
            OperationResult::Pending => $this->pending($lease, $outcome, $now, $report),
            OperationResult::Succeeded => $this->complete($lease, $report),
            OperationResult::Uncertain => $this->uncertain($lease, $outcome, $report),
            OperationResult::PermanentFailure => $this->fail($lease, $outcome, $report),
            OperationResult::RetryableFailure => $this->retryOrFail($lease, $outcome, $now, $report),
        };
    }

    private function pending(Lease $lease, HandlerOutcome $outcome, \DateTimeImmutable $now, WorkerReport $report): void
    {
        // Normal provider processing is not a failed request and must not exhaust retries.
        if ($this->outbox->retry($lease->job->id, $lease->token, $now->modify('+' . $this->pendingRetrySeconds . ' seconds'), $outcome->reason ?? 'pending', false)) {
            $report->retried++;
        }
    }

    private function complete(Lease $lease, WorkerReport $report): void
    {
        if ($this->outbox->complete($lease->job->id, $lease->token)) {
            $report->succeeded++;
        }
    }

    private function uncertain(Lease $lease, HandlerOutcome $outcome, WorkerReport $report): void
    {
        if ($this->outbox->markUncertain($lease->job->id, $lease->token, $outcome->reason ?? 'unknown_remote_result')) {
            $report->uncertain++;
        }
    }

    private function fail(Lease $lease, HandlerOutcome $outcome, WorkerReport $report): void
    {
        if ($this->outbox->fail($lease->job->id, $lease->token, $outcome->reason ?? 'permanent_failure')) {
            $report->failed++;
        }
    }

    private function retryOrFail(Lease $lease, HandlerOutcome $outcome, \DateTimeImmutable $now, WorkerReport $report): void
    {
        if (!$this->retryPolicy->canRetry($lease->job)) {
            $this->fail($lease, HandlerOutcome::permanentFailure('retry_exhausted:' . ($outcome->reason ?? 'unknown')), $report);
            return;
        }
        $retryAt = $this->retryPolicy->nextAttemptAt($lease->job, $now);
        if ($outcome->retryAt !== null && $outcome->retryAt > $retryAt) {
            $retryAt = $outcome->retryAt;
        }
        if ($this->outbox->retry($lease->job->id, $lease->token, $retryAt, $outcome->reason ?? 'retryable_failure')) {
            $report->retried++;
        }
    }
}
