<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

interface ReconciliationFindingStore
{
    /** Atomically creates or refreshes a finding using its deterministic fingerprint. */
    public function upsert(ReconciliationFinding $finding): FindingUpsertResult;

    public function replace(ReconciliationFinding $finding): void;
}
