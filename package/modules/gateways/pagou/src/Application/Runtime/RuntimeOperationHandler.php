<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use Closure;
use Pagou\Whmcs\Application\Async\HandlerOutcome;
use Pagou\Whmcs\Application\Async\OperationHandler;
use Pagou\Whmcs\Application\Async\OperationJob;
use Pagou\Whmcs\Application\Async\OperationType;

final class RuntimeOperationHandler implements OperationHandler
{
    /** @var Closure(OperationJob): HandlerOutcome */
    private readonly Closure $handler;

    /** @param callable(OperationJob): HandlerOutcome $handler */
    public function __construct(private readonly OperationType $operationType, callable $handler)
    {
        $this->handler = Closure::fromCallable($handler);
    }

    public function type(): OperationType
    {
        return $this->operationType;
    }

    public function handle(OperationJob $job): HandlerOutcome
    {
        return ($this->handler)($job);
    }
}
