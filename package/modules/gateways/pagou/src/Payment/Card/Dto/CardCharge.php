<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\Dto;

use Pagou\Whmcs\Payment\Card\CardStatus;

final class CardCharge
{
    /** @param array<string,mixed> $raw */
    public function __construct(
        public readonly string $id,
        public readonly CardStatus $status,
        public readonly int $amountCentavos,
        public readonly ?string $customerId = null,
        public readonly ?string $cardId = null,
        public readonly ?string $providerStatus = null,
        public readonly ?string $threeDsUrl = null,
        public readonly array $raw = [],
    ) {
    }

    public function maySettleInvoice(): bool
    {
        return $this->status->permitsAutomaticInvoiceSettlement();
    }
}
