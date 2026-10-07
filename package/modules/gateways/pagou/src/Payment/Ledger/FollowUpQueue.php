<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger;

interface FollowUpQueue
{
    /** Enqueue asynchronous cancellation of sibling unpaid remote charges. */
    public function cancelSiblings(int $invoiceId, EconomicPaymentKey $paidKey): void;
}
