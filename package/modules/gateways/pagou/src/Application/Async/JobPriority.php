<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

enum JobPriority: int
{
    case FinancialRecovery = 400;
    case PaymentConfirmation = 300;
    case Issuance = 200;
    case Delivery = 100;
    case Maintenance = 10;
}
