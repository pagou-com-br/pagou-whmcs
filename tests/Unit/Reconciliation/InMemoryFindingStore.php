<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Reconciliation;

use Pagou\Whmcs\Application\Reconciliation\FindingUpsertResult;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationFinding;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationFindingStore;

final class InMemoryFindingStore implements ReconciliationFindingStore
{
    /** @var list<ReconciliationFinding> */
    public array $findings = [];

    public function upsert(ReconciliationFinding $finding): FindingUpsertResult
    {
        foreach ($this->findings as $index => $existing) {
            if ($existing->fingerprint === $finding->fingerprint) {
                $this->findings[$index] = $finding;
                return new FindingUpsertResult($finding, false);
            }
        }

        $this->findings[] = $finding;
        return new FindingUpsertResult($finding, true);
    }

    public function replace(ReconciliationFinding $finding): void
    {
        foreach ($this->findings as $index => $existing) {
            if ($existing->fingerprint === $finding->fingerprint) {
                $this->findings[$index] = $finding;
                return;
            }
        }

        $this->findings[] = $finding;
    }
}
