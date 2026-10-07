<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

interface OperationOutbox
{
    public function enqueue(OperationJob $job): bool;

    public function claim(string $workerId, \DateTimeImmutable $now, \DateInterval $leaseDuration): ?Lease;

    public function complete(string $jobId, string $leaseToken): bool;

    public function retry(string $jobId, string $leaseToken, \DateTimeImmutable $availableAt, string $reason, bool $countAttempt = true): bool;

    public function markUncertain(string $jobId, string $leaseToken, string $reason): bool;

    public function fail(string $jobId, string $leaseToken, string $reason): bool;

    public function releaseExpiredLeases(\DateTimeImmutable $now): int;

    public function find(string $jobId): ?OperationJob;
}
