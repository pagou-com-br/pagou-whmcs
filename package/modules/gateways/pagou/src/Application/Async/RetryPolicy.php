<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

interface RetryPolicy
{
    public function nextAttemptAt(OperationJob $job, \DateTimeImmutable $now): \DateTimeImmutable;

    public function canRetry(OperationJob $job): bool;
}
