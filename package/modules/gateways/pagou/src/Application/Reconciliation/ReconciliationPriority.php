<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

/** Lower weights run first, keeping financial uncertainty ahead of housekeeping. */
enum ReconciliationPriority: int
{
    case Critical = 10;
    case High = 20;
    case Normal = 30;
    case Low = 40;
}
