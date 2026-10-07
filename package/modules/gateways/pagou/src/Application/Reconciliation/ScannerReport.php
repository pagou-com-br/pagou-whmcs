<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

final class ScannerReport
{
    public function __construct(
        public readonly string $scannerId,
        public readonly ReconciliationPriority $priority,
        public readonly int $observed,
        public readonly int $newFindings,
        public readonly int $deduplicatedFindings,
        public readonly int $recovered,
        public readonly int $needsReview,
        public readonly ?string $nextCursor,
        public readonly bool $exhausted,
        public readonly ?string $error = null,
    ) {
    }
}
