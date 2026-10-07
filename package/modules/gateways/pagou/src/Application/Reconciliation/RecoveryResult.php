<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

final class RecoveryResult
{
    private function __construct(public readonly bool $recovered, public readonly bool $needsReview, public readonly string $message)
    {
    }

    public static function recovered(string $message = 'Recovered.'): self
    {
        return new self(true, false, $message);
    }

    public static function deferred(string $message = 'Recovery deferred.'): self
    {
        return new self(false, true, $message);
    }

    public static function notRecovered(string $message = 'Not recovered.'): self
    {
        return new self(false, false, $message);
    }
}
