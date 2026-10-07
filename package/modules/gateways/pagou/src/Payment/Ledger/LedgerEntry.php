<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger;

final class LedgerEntry
{
    public const RECEIVED = 'received';
    public const APPLYING = 'applying';
    public const APPLIED = 'applied';
    public const QUARANTINED = 'quarantined';

    public function __construct(
        public readonly EconomicPaymentKey $key,
        public readonly ReceivedPayment $payment,
        public readonly string $status,
        public readonly int $attempts = 0,
        public readonly ?string $finding = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function with(string $status, ?string $finding = null, ?int $attempts = null): self
    {
        return new self(
            $this->key,
            $this->payment,
            $status,
            $attempts ?? $this->attempts,
            $finding,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }
}
