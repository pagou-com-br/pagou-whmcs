<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Ledger;

use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\LedgerEntry;
use Pagou\Whmcs\Payment\Ledger\LedgerRepository;
use Pagou\Whmcs\Payment\Ledger\ReceivedPayment;

final class InMemoryLedgerRepository implements LedgerRepository
{
    /** @var array<string, LedgerEntry> */
    private array $entries = [];

    public function claim(ReceivedPayment $payment): LedgerEntry
    {
        return $this->entries[$payment->economicKey->value] ??= new LedgerEntry($payment->economicKey, $payment, LedgerEntry::RECEIVED);
    }

    public function begin(EconomicPaymentKey $key): ?LedgerEntry
    {
        $entry = $this->entries[$key->value] ?? null;
        if ($entry === null || $entry->status === LedgerEntry::APPLIED || $entry->status === LedgerEntry::QUARANTINED || $entry->status === LedgerEntry::APPLYING) {
            return null;
        }

        return $this->entries[$key->value] = $entry->with(LedgerEntry::APPLYING, null, $entry->attempts + 1);
    }

    public function save(LedgerEntry $entry): void
    {
        $this->entries[$entry->key->value] = $entry;
    }

    public function recoverable(): array
    {
        return array_values(array_filter($this->entries, static fn (LedgerEntry $entry): bool => $entry->status === LedgerEntry::APPLYING || $entry->status === LedgerEntry::RECEIVED));
    }

    public function get(EconomicPaymentKey $key): ?LedgerEntry
    {
        return $this->entries[$key->value] ?? null;
    }
}
