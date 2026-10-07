<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger;

interface LedgerRepository
{
    /** Atomically creates a received entry or returns the existing one. */
    public function claim(ReceivedPayment $payment): LedgerEntry;

    /** Atomically moves an entry from received/applying into applying. */
    public function begin(EconomicPaymentKey $key): ?LedgerEntry;

    public function save(LedgerEntry $entry): void;

    /** @return list<LedgerEntry> */
    public function recoverable(): array;
}
