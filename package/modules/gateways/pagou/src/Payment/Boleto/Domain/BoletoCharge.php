<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Domain;

use Pagou\Whmcs\Payment\Split\PaymentSplitProjection;

final class BoletoCharge
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $remoteId,
        public readonly string $status,
        public readonly int $amountCentavos,
        public readonly BoletoArtifacts $artifacts,
        public readonly ?string $dueDate,
        public readonly ?string $paidAt,
        public readonly array $raw = [],
        public readonly ?PaymentSplitProjection $split = null,
    ) {
        if ($remoteId === '') {
            throw new \InvalidArgumentException('Boleto remote id is required.');
        }
    }

    public function awaitsRegistration(): bool
    {
        return !$this->artifacts->isReady() || in_array(strtolower($this->status), ['processing', 'pending', 'awaiting_registration', 'registering'], true);
    }
}
