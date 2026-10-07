<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Handler;

use Pagou\Whmcs\Payment\Pix\PixPaymentService;

final class CancelPixHandler
{
    public function __construct(private readonly PixPaymentService $service)
    {
    }

    public function handle(string $attemptId, string $pixId, string $idempotencyKey): void
    {
        $this->service->cancel($attemptId, $pixId, $idempotencyKey);
    }
}
