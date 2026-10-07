<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card;

use Pagou\Whmcs\Payment\Card\Dto\CardCharge;

/** Polls only states that cannot safely settle an invoice yet. */
final class CardReconciler
{
    public function __construct(private readonly CardApiClient $api)
    {
    }

    public function reconcile(string $chargeId): CardCharge
    {
        return $this->api->getCharge($chargeId);
    }

    /**
     * @param iterable<CardCharge> $charges
     * @return list<CardCharge>
     */
    public function reconcileOutstanding(iterable $charges): array
    {
        $resolved = [];
        foreach ($charges as $charge) {
            if ($charge->status->requiresReconciliation()) {
                $resolved[] = $this->reconcile($charge->id);
            }
        }
        return $resolved;
    }
}
