<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

enum JobStatus: string
{
    case Queued = 'queued';
    case Leased = 'leased';
    case Retrying = 'retrying';
    case Uncertain = 'uncertain';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled => true,
            default => false,
        };
    }
}
