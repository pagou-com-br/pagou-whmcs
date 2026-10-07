<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

use InvalidArgumentException;

/** A non-sensitive discrepancy returned by a scanner. */
final class ReconciliationCandidate
{
    /** @param array<array-key, mixed> $attributes */
    public function __construct(
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly string $category,
        public readonly ReconciliationPriority $priority,
        public readonly string $summary,
        public readonly RecoveryAction $recoveryAction = RecoveryAction::None,
        public readonly array $attributes = [],
    ) {
        foreach ([$subjectType, $subjectId, $category, $summary] as $value) {
            if (trim($value) === '' || strlen($value) > 512) {
                throw new InvalidArgumentException('A reconciliation candidate contains an invalid identifier or summary.');
            }
        }

        foreach ($attributes as $key => $value) {
            if (!is_string($key) || $key === '' || !is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Finding attributes must contain only scalar, non-sensitive values.');
            }
        }
    }

    public function fingerprint(string $scannerId): string
    {
        return hash('sha256', implode('|', [$scannerId, $this->subjectType, $this->subjectId, $this->category]));
    }
}
