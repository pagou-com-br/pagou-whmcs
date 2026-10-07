<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

interface ReconciliationScanner
{
    public function id(): string;

    public function priority(): ReconciliationPriority;

    public function scan(ReconciliationScanContext $context): ReconciliationPage;
}
