<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

enum OperationState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled => true,
            default => false,
        };
    }
}
