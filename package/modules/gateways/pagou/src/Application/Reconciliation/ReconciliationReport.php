<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

use Pagou\Whmcs\Domain\UtcInstant;

/** Report deliberately omits payloads, credentials, and customer-supplied identifiers. */
final class ReconciliationReport
{
    /** @param list<ScannerReport> $scanners */
    public function __construct(
        public readonly ReconciliationMode $mode,
        public readonly UtcInstant $startedAt,
        public readonly UtcInstant $finishedAt,
        public readonly array $scanners,
    ) {
    }

    /** @return array<string, int|string|array<int, array<string, int|string|bool|null>> > */
    public function sanitized(): array
    {
        return [
            'mode' => $this->mode->value,
            'scanners' => array_map(static fn (ScannerReport $report): array => [
                'id' => $report->scannerId,
                'priority' => $report->priority->name,
                'observed' => $report->observed,
                'new_findings' => $report->newFindings,
                'deduplicated_findings' => $report->deduplicatedFindings,
                'recovered' => $report->recovered,
                'needs_review' => $report->needsReview,
                'has_error' => $report->error !== null,
                'exhausted' => $report->exhausted,
            ], $this->scanners),
        ];
    }
}
