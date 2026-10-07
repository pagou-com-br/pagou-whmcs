<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

interface OperationHandler
{
    public function type(): OperationType;

    /**
     * This runs only in a worker. Hooks and invoice rendering must only enqueue.
     */
    public function handle(OperationJob $job): HandlerOutcome;
}
