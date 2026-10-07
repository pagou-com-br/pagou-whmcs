<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

/**
 * Deterministic adapter for tests and local execution. A SQL adapter must enforce
 * the same deduplication and fencing rules in one transaction.
 */
final class InMemoryOperationOutbox implements OperationOutbox
{
    /** @var array<string, OperationJob> */
    private array $jobs = [];

    /** @var array<string, string> */
    private array $activeDeduplicationKeys = [];

    private int $leaseSequence = 0;

    public function enqueue(OperationJob $job): bool
    {
        if (isset($this->jobs[$job->id]) || isset($this->activeDeduplicationKeys[$job->deduplicationKey])) {
            return false;
        }

        $this->jobs[$job->id] = $job;
        $this->activeDeduplicationKeys[$job->deduplicationKey] = $job->id;

        return true;
    }

    public function claim(string $workerId, \DateTimeImmutable $now, \DateInterval $leaseDuration): ?Lease
    {
        $this->releaseExpiredLeases($now);
        $candidates = array_filter($this->jobs, static fn (OperationJob $job): bool => $job->isAvailableAt($now));
        uasort($candidates, static function (OperationJob $left, OperationJob $right): int {
            $priority = $right->priority->value <=> $left->priority->value;
            if ($priority !== 0) {
                return $priority;
            }

            return $left->createdAt <=> $right->createdAt;
        });
        $job = reset($candidates);
        if (!$job instanceof OperationJob) {
            return null;
        }

        $token = hash('sha256', $workerId . ':' . $job->id . ':' . (++$this->leaseSequence));
        $expiresAt = $now->add($leaseDuration);
        $job->status = JobStatus::Leased;
        $job->attempts++;
        $job->leaseToken = $token;
        $job->leaseExpiresAt = $expiresAt;

        return new Lease($job, $token, $expiresAt);
    }

    public function complete(string $jobId, string $leaseToken): bool
    {
        return $this->transition($jobId, $leaseToken, function (OperationJob $job): void {
            $job->status = JobStatus::Succeeded;
            $this->releaseDeduplicationKey($job);
        });
    }

    public function retry(string $jobId, string $leaseToken, \DateTimeImmutable $availableAt, string $reason, bool $countAttempt = true): bool
    {
        return $this->transition($jobId, $leaseToken, static function (OperationJob $job) use ($availableAt, $reason, $countAttempt): void {
            $job->status = JobStatus::Retrying;
            $job->availableAt = $availableAt;
            if (!$countAttempt) {
                $job->attempts = max(0, $job->attempts - 1);
            }
            $job->lastError = $reason;
        });
    }

    public function markUncertain(string $jobId, string $leaseToken, string $reason): bool
    {
        return $this->transition($jobId, $leaseToken, static function (OperationJob $job) use ($reason): void {
            $job->status = JobStatus::Uncertain;
            $job->uncertainReason = $reason;
            $job->lastError = $reason;
        });
    }

    public function fail(string $jobId, string $leaseToken, string $reason): bool
    {
        return $this->transition($jobId, $leaseToken, function (OperationJob $job) use ($reason): void {
            $job->status = JobStatus::Failed;
            $job->lastError = $reason;
            $this->releaseDeduplicationKey($job);
        });
    }

    public function releaseExpiredLeases(\DateTimeImmutable $now): int
    {
        $released = 0;
        foreach ($this->jobs as $job) {
            if ($job->status !== JobStatus::Leased || $job->leaseExpiresAt === null || $job->leaseExpiresAt > $now) {
                continue;
            }
            $job->status = JobStatus::Retrying;
            $job->availableAt = $now;
            $job->lastError = 'lease_expired';
            $job->leaseToken = null;
            $job->leaseExpiresAt = null;
            $released++;
        }

        return $released;
    }

    public function find(string $jobId): ?OperationJob
    {
        return $this->jobs[$jobId] ?? null;
    }

    /** @param callable(OperationJob): void $mutation */
    private function transition(string $jobId, string $leaseToken, callable $mutation): bool
    {
        $job = $this->jobs[$jobId] ?? null;
        if ($job === null || $job->status !== JobStatus::Leased || !hash_equals((string) $job->leaseToken, $leaseToken)) {
            return false;
        }
        $mutation($job);
        $job->leaseToken = null;
        $job->leaseExpiresAt = null;

        return true;
    }

    private function releaseDeduplicationKey(OperationJob $job): void
    {
        unset($this->activeDeduplicationKeys[$job->deduplicationKey]);
    }
}
