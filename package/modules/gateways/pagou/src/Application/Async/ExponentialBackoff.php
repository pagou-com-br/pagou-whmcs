<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

final class ExponentialBackoff implements RetryPolicy
{
    public function __construct(
        private readonly RandomSource $random,
        private readonly int $baseSeconds = 5,
        private readonly int $maximumSeconds = 900,
        private readonly int $maximumAttempts = 8,
        private readonly int $jitterPercent = 20,
    ) {
        if ($baseSeconds < 1 || $maximumSeconds < $baseSeconds || $maximumAttempts < 1 || $jitterPercent < 0 || $jitterPercent > 100) {
            throw new \InvalidArgumentException('Invalid retry policy.');
        }
    }

    public function canRetry(OperationJob $job): bool
    {
        return $job->attempts < $this->maximumAttempts;
    }

    public function nextAttemptAt(OperationJob $job, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $power = max(0, $job->attempts - 1);
        $seconds = min($this->maximumSeconds, $this->baseSeconds * (2 ** min(20, $power)));
        $variation = (int) floor($seconds * $this->jitterPercent / 100);
        if ($variation > 0) {
            $seconds += $this->random->int(-$variation, $variation);
        }

        return $now->modify(sprintf('+%d seconds', max(1, $seconds)));
    }
}
