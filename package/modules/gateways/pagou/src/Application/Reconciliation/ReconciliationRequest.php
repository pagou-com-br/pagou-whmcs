<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

use InvalidArgumentException;

final class ReconciliationRequest
{
    /** @param array<array-key, mixed> $cursors Last persisted cursor per scanner. */
    public function __construct(
        public readonly ReconciliationMode $mode,
        public readonly ReconciliationWindow $window,
        public readonly array $cursors = [],
        public readonly int $maxItemsPerScanner = 100,
    ) {
        if ($maxItemsPerScanner < 1 || $maxItemsPerScanner > 1000) {
            throw new InvalidArgumentException('The scanner item limit must be between 1 and 1000.');
        }

        foreach ($cursors as $scannerId => $cursor) {
            if (!is_string($scannerId) || !is_string($cursor) || $scannerId === '' || strlen($cursor) > 512) {
                throw new InvalidArgumentException('Reconciliation cursors must be short string values keyed by scanner id.');
            }
        }
    }
}
