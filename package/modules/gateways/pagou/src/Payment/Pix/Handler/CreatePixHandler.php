<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Handler;

use Pagou\Whmcs\Payment\Pix\Dto\CreatePixRequest;
use Pagou\Whmcs\Payment\Pix\Dto\PixCharge;
use Pagou\Whmcs\Payment\Pix\PixPaymentService;

final class CreatePixHandler
{
    public function __construct(private readonly PixPaymentService $service)
    {
    }

    public function handle(CreatePixRequest $request): PixCharge
    {
        return $this->service->create($request);
    }
}
