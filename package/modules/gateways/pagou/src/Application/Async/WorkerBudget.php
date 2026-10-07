<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

final class WorkerBudget
{
    public function __construct(
        public readonly int $maximumJobs = 20,
        public readonly int $maximumSeconds = 20,
        public readonly int $leaseSeconds = 45,
    ) {
        if ($maximumJobs < 1 || $maximumSeconds < 1 || $leaseSeconds < 1) {
            throw new \InvalidArgumentException('Worker budget values must be positive.');
        }
    }

    public function leaseDuration(): \DateInterval
    {
        return new \DateInterval('PT' . $this->leaseSeconds . 'S');
    }
}
