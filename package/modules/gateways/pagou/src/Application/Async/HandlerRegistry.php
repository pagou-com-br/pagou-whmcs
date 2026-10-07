<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

final class HandlerRegistry
{
    /** @var array<string, OperationHandler> */
    private array $handlers = [];

    /** @param iterable<OperationHandler> $handlers */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            $key = $handler->type()->value;
            if (isset($this->handlers[$key])) {
                throw new \LogicException('Duplicate async operation handler: ' . $key);
            }
            $this->handlers[$key] = $handler;
        }
    }

    public function for(OperationType $type): ?OperationHandler
    {
        return $this->handlers[$type->value] ?? null;
    }
}
