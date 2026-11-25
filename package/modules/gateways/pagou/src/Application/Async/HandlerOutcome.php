<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

final class HandlerOutcome
{
    private function __construct(public readonly OperationResult $result, public readonly ?string $reason = null, public readonly ?\DateTimeImmutable $retryAt = null)
    {
    }

    /** A note says why nothing else was done, such as an email the client turned off. */
    public static function succeeded(?string $note = null): self
    {
        return new self(OperationResult::Succeeded, $note);
    }

    public static function retryable(string $reason, ?\DateTimeImmutable $retryAt = null): self
    {
        return new self(OperationResult::RetryableFailure, $reason, $retryAt);
    }

    /** A later $retryAt spaces out the checks of something that is taking long at the provider. */
    public static function pending(string $reason, ?\DateTimeImmutable $retryAt = null): self
    {
        return new self(OperationResult::Pending, $reason, $retryAt);
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
