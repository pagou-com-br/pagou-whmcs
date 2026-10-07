<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

interface ReconciliationRecoveryPort
{
    /** The implementation must be idempotent and re-read current state before effects. */
    public function recover(ReconciliationFinding $finding): RecoveryResult;
}
