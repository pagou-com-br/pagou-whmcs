<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

use InvalidArgumentException;
use Pagou\Whmcs\Domain\UtcInstant;

final class ReconciliationWindow
{
    public function __construct(public readonly UtcInstant $from, public readonly UtcInstant $until)
    {
        if ($from->compareTo($until) > 0) {
            throw new InvalidArgumentException('The reconciliation window cannot end before it starts.');
        }
    }
}
