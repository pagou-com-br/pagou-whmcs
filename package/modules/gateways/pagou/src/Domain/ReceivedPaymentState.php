<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

enum ReceivedPaymentState: string
{
    case Received = 'received';
    case Applying = 'applying';
    case Applied = 'applied';
    case Credited = 'credited';
    case Quarantined = 'quarantined';
    case Rejected = 'rejected';
}
