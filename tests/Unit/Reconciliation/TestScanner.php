<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Reconciliation;

use Pagou\Whmcs\Application\Reconciliation\ReconciliationPage;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationPriority;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationScanContext;
use Pagou\Whmcs\Application\Reconciliation\ReconciliationScanner;

final class TestScanner implements ReconciliationScanner
{
    public ?ReconciliationScanContext $context = null;

    /** @var list<string> */
    private array $calls;

    /** @param list<string> $calls */
    public function __construct(
        private readonly string $scannerId,
        private readonly ReconciliationPriority $scannerPriority,
        private readonly ReconciliationPage $page,
        array &$calls = [],
    ) {
        $this->calls = &$calls;
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
        $this->context = $context;
        $this->calls[] = $this->scannerId;
        return $this->page;
    }
}
