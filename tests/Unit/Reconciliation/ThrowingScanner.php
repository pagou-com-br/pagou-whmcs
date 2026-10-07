<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Reconciliation;

use Pagou\Whmcs\Application\Reconciliation\ReconciliationPage;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationPriority;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationScanContext;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationScanner;

final class ThrowingScanner implements ReconciliationScanner
{
    public function __construct(
        private readonly string $scannerId,
        private readonly ReconciliationPriority $scannerPriority,
    ) {
    }

    public function id(): string
    {
        return $this->scannerId;
    }

    public function priority(): ReconciliationPriority
    {
        return $this->scannerPriority;
    }

    public function scan(ReconciliationScanContext $context): ReconciliationPage
    {
        throw new \RuntimeException('provider token=top-secret');
    }
}
