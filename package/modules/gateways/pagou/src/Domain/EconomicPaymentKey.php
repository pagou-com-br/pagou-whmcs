<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

use InvalidArgumentException;

/** Groups every settlement rail that belongs to one economic payment attempt. */
final class EconomicPaymentKey extends Identifier
{
    public static function fromString(string $value): self
    {
        $value = trim($value);
        if (strlen($value) < 16 || strlen($value) > 255 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $value)) {
            throw new InvalidArgumentException('Economic payment key has an invalid format.');
        }

        return new self($value);
    }

    public static function forInvoice(int $invoiceId, InvoiceRevision $revision): self
    {
        if ($invoiceId < 1) {
            throw new InvalidArgumentException('Invoice ID must be positive.');
        }

        return new self(sprintf('whmcs:%d:r%d:%s', $invoiceId, $revision->value(), bin2hex(random_bytes(12))));
    }
}
