<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Dto;

use Pagou\Whmcs\Payment\Split\PaymentSplitProjection;

final class PixCharge
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly int $amountCents,
        public readonly PixArtifacts $artifacts,
        public readonly ?string $expiresAt,
        public readonly ?string $paidAt,
        public readonly array $raw = [],
        public readonly ?PaymentSplitProjection $split = null,
        public readonly ?string $dueDate = null,
    ) {
    }
}
