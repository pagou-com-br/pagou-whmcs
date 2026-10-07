<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger;

final class NativePaymentReceipt
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly int $appliedInCents,
        public readonly int $creditCreatedInCents,
        public readonly bool $invoicePaid,
        public readonly string $transactionId,
    ) {
        if ($invoiceId <= 0 || $appliedInCents <= 0 || $creditCreatedInCents < 0 || trim($transactionId) === '') {
            throw new \InvalidArgumentException('Invalid native payment receipt.');
        }
    }
}
