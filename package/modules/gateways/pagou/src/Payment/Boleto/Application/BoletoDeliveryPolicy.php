<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Application;

use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;

/** Determines whether a boleto can be delivered without making a remote request. */
final class BoletoDeliveryPolicy
{
    public function __construct(private readonly bool $attachPdf = true)
    {
    }

    public function mayDeliver(BoletoAttempt $attempt): bool
    {
        if ($attempt->status !== BoletoAttempt::READY || $attempt->artifacts === null) {
            return false;
        }

        return !$this->attachPdf || $attempt->artifacts->localPdfKey !== null;
    }

    public function requiresPdf(): bool
    {
        return $this->attachPdf;
    }
}
