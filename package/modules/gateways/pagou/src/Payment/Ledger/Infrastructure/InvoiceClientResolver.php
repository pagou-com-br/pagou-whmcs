<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger\Infrastructure;

/** Resolves the WHMCS client required by the immutable module ledger table. */
interface InvoiceClientResolver
{
    public function clientIdForInvoice(int $invoiceId): int;
}
