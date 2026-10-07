<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

use InvalidArgumentException;

final class ReconciliationPage
{
    /** @param list<ReconciliationCandidate> $candidates */
    public function __construct(public readonly array $candidates, public readonly ?string $nextCursor, public readonly bool $exhausted)
    {
        if ($nextCursor !== null && ($nextCursor === '' || strlen($nextCursor) > 512)) {
            throw new InvalidArgumentException('A reconciliation cursor must be a short non-empty string.');
        }
    }
}
