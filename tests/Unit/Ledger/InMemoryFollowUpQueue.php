<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Ledger;

use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\FollowUpQueue;

final class InMemoryFollowUpQueue implements FollowUpQueue
{
    /** @var list<array{invoiceId:int,key:string}> */
    public array $cancellations = [];

    public function cancelSiblings(int $invoiceId, EconomicPaymentKey $paidKey): void
    {
        $this->cancellations[] = ['invoiceId' => $invoiceId, 'key' => $paidKey->value];
    }
}
