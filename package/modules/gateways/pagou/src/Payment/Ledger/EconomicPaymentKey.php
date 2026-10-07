<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger;

/**
 * Stable identity for one economic payment.  Transport event identifiers are
 * deliberately not used here: a provider can retry, re-sign or re-deliver an
 * event without creating a second payment.
 */
final class EconomicPaymentKey
{
    private function __construct(
        public readonly string $value,
        public readonly int $invoiceId,
        public readonly string $remotePaymentId,
    ) {
    }

    public static function fromRemotePayment(
        int $invoiceId,
        string $provider,
        string $remotePaymentId,
    ): self {
        if ($invoiceId <= 0) {
            throw new \InvalidArgumentException('Invoice id must be positive.');
        }

        $provider = self::part($provider, 'provider');
        $remotePaymentId = self::part($remotePaymentId, 'remote payment id');

        return new self(hash('sha256', implode('|', [
            'pagou-economic-payment-v1',
            (string) $invoiceId,
            $provider,
            $remotePaymentId,
        ])), $invoiceId, $remotePaymentId);
    }

    private static function part(string $value, string $name): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException(sprintf('The %s cannot be empty.', $name));
        }

        return strtolower($value);
    }
}
