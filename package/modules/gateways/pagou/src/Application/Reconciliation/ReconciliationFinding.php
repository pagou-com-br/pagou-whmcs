<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

use Pagou\Whmcs\Domain\UtcInstant;

/** Persistable, sanitized representation of a discrepancy. */
final class ReconciliationFinding
{
    /** @param array<string, scalar|null> $attributes */
    public function __construct(
        public readonly string $fingerprint,
        public readonly string $scannerId,
        public readonly ReconciliationCandidate $candidate,
        public readonly ReconciliationFindingStatus $status,
        public readonly UtcInstant $observedAt,
        public readonly array $attributes,
    ) {
    }

    public static function open(string $scannerId, ReconciliationCandidate $candidate, UtcInstant $observedAt): self
    {
        return new self(
            $candidate->fingerprint($scannerId),
            $scannerId,
            $candidate,
            ReconciliationFindingStatus::Open,
            $observedAt,
            $candidate->attributes,
        );
    }

    public function resolved(): self
    {
        return new self(
            $this->fingerprint,
            $this->scannerId,
            $this->candidate,
            ReconciliationFindingStatus::Resolved,
            $this->observedAt,
            $this->attributes,
        );
    }

    public function needsReview(): self
    {
        return new self(
            $this->fingerprint,
            $this->scannerId,
            $this->candidate,
            ReconciliationFindingStatus::NeedsReview,
            $this->observedAt,
            $this->attributes,
        );
    }
}
