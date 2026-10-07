<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Handler;

use Pagou\Whmcs\Payment\Pix\Dto\CreateDuePixRequest;
use Pagou\Whmcs\Payment\Pix\Dto\PixCharge;
use Pagou\Whmcs\Payment\Pix\PixPaymentService;

final class CreateDuePixHandler
{
    public function __construct(private readonly PixPaymentService $service)
    {
    }

    public function handle(CreateDuePixRequest $request): PixCharge
    {
        return $this->service->createDue($request);
    }
}
