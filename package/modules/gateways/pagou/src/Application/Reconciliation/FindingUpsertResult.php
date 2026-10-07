<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

final class FindingUpsertResult
{
    public function __construct(public readonly ReconciliationFinding $finding, public readonly bool $created)
    {
    }
}
