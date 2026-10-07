<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Whmcs;

use RuntimeException;

final class OwnershipGuard
{
    public function assertInvoiceOwner(WhmcsInvoice $invoice, int $authenticatedClientId): void
    {
        if ($authenticatedClientId < 1 || $invoice->clientId !== $authenticatedClientId) {
            throw new RuntimeException('The authenticated client does not own this invoice.');
        }
    }
}
