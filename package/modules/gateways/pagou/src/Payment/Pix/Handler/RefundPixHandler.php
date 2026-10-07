<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Handler;

use Pagou\Whmcs\Payment\Pix\Dto\RefundPixRequest;
use Pagou\Whmcs\Payment\Pix\PixPaymentService;

final class RefundPixHandler
{
    public function __construct(private readonly PixPaymentService $service)
    {
    }

    public function handle(RefundPixRequest $request): void
    {
        $this->service->refund($request);
    }
}
