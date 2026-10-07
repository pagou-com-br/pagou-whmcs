<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

final class ReconciliationScanContext
{
    public function __construct(
        public readonly ReconciliationRequest $request,
        public readonly string $scannerId,
        public readonly ?string $cursor,
    ) {
    }
}
