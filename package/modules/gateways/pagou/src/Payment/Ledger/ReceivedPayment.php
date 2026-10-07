<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger;

final class ReceivedPayment
{
    public function __construct(
        public readonly EconomicPaymentKey $economicKey,
        public readonly string $eventId,
        public readonly int $invoiceId,
        public readonly int $amountInCents,
        public readonly string $provider,
        public readonly string $remotePaymentId,
        public readonly \DateTimeImmutable $paidAt,
        public readonly string $method,
        public readonly ?string $remoteChargeId = null,
        // Identifier shown as the WHMCS Transaction ID (the Pix txid); never part of the economic identity.
        public readonly ?string $nativeTransactionId = null,
        public readonly ?int $feeInCents = null,
        // Nominal amount of the charge; a larger payment carries the late charges of a paid-late charge.
        public readonly ?int $chargeAmountCents = null,
    ) {
        if (trim($eventId) === '') {
            throw new \InvalidArgumentException('Event id cannot be empty.');
        }
        if ($invoiceId <= 0 || $amountInCents <= 0) {
            throw new \InvalidArgumentException('Invoice id and amount must be positive.');
        }
    }
}
