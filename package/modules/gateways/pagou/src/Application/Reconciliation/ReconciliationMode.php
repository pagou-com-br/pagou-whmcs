<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

/** A dry run observes only. Execute may invoke a narrowly scoped recovery port. */
enum ReconciliationMode: string
{
    case DryRun = 'dry_run';
    case Execute = 'execute';
}
