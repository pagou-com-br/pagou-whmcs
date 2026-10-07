<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

/**
 * Adapter called from the WHMCS daily or periodic hook. It only works through
 * the bounded worker and therefore cannot make the invoice-generation cron wait
 * for every remote boleto or PDF.
 */
final class WhmcsCronRunner
{
    public function __construct(private readonly OperationWorker $worker, private readonly WorkerBudget $budget)
    {
    }

    public function run(string $workerId): WorkerReport
    {
        return $this->worker->run($workerId, $this->budget);
    }
}
