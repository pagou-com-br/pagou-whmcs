<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

enum ReconciliationFindingStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case NeedsReview = 'needs_review';
}
