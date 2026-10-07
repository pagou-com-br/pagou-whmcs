<?php

declare(strict_types=1);

namespace Pagou\Whmcs\InvoicePdf;

final class PaymentDocument
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $method,
        public readonly int $amountCents,
        public readonly string $content,
        public readonly string $validUntil = '',
    ) {
    }
}
