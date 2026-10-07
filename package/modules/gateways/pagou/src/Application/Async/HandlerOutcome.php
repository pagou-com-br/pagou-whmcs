<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

final class HandlerOutcome
{
    private function __construct(public readonly OperationResult $result, public readonly ?string $reason = null, public readonly ?\DateTimeImmutable $retryAt = null)
    {
    }

    public static function succeeded(): self
    {
        return new self(OperationResult::Succeeded);
    }

    public static function retryable(string $reason, ?\DateTimeImmutable $retryAt = null): self
    {
        return new self(OperationResult::RetryableFailure, $reason, $retryAt);
    }

    public static function pending(string $reason): self
    {
        return new self(OperationResult::Pending, $reason);
    }

    /**
     * An unknown remote side effect is never retried blindly. It must be reconciled.
     */
    public static function uncertain(string $reason): self
    {
        return new self(OperationResult::Uncertain, $reason);
    }

    public static function permanentFailure(string $reason): self
    {
        return new self(OperationResult::PermanentFailure, $reason);
    }
}
