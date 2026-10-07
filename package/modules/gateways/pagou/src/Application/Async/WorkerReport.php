<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

final class WorkerReport
{
    public int $claimed = 0;
    public int $succeeded = 0;
    public int $retried = 0;
    public int $uncertain = 0;
    public int $failed = 0;
    public int $reclaimed = 0;
    public bool $budgetExhausted = false;
}
