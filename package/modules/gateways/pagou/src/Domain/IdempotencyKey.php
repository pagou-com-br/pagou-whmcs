<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

use InvalidArgumentException;

/** Stable key for one logical remote command, never reused after a revision changes. */
final class IdempotencyKey extends Identifier
{
    public static function fromString(string $value): self
    {
        $value = trim($value);
        if (strlen($value) < 16 || strlen($value) > 255 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $value)) {
            throw new InvalidArgumentException('Idempotency key must be 16-255 URL-safe characters.');
        }

        return new self($value);
    }

    public static function forInvoice(int $invoiceId, InvoiceRevision $revision, PaymentMethod $method): self
    {
        if ($invoiceId < 1) {
            throw new InvalidArgumentException('Invoice ID must be positive.');
        }

        return new self(sprintf(
            'whmcs:%d:r%d:%s:%s',
            $invoiceId,
            $revision->value(),
            $method->value,
            bin2hex(random_bytes(12)),
        ));
    }
}
