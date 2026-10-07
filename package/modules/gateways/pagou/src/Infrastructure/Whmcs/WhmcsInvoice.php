<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Whmcs;

use Pagou\Whmcs\Domain\CivilDate;
use Pagou\Whmcs\Domain\Money;
use RuntimeException;

/** Read model intentionally limited to data used by Pagou payment flows. */
final class WhmcsInvoice
{
    public function __construct(
        public readonly int $id,
        public readonly int $clientId,
        public readonly string $status,
        public readonly Money $balance,
        public readonly CivilDate $dueDate,
        public readonly string $paymentMethod,
    ) {
        if ($id < 1 || $clientId < 1) {
            throw new RuntimeException('WHMCS invoice identifiers must be positive.');
        }
    }
}
