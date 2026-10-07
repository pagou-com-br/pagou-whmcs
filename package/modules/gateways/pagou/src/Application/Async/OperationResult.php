<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

enum OperationResult: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case RetryableFailure = 'retryable_failure';
    case Uncertain = 'uncertain';
    case PermanentFailure = 'permanent_failure';
}
