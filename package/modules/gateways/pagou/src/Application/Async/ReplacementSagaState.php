<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

enum ReplacementSagaState: string
{
    case Requested = 'requested';
    case CancelPending = 'cancel_pending';
    case Cancelled = 'cancelled';
    case IssuePending = 'issue_pending';
    case ReplacementIssued = 'replacement_issued';
    case ManualReview = 'manual_review';
    case Completed = 'completed';
}
