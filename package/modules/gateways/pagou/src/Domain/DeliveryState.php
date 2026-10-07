<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

enum DeliveryState: string
{
    case Pending = 'pending';
    case AwaitingArtifacts = 'awaiting_artifacts';
    case Sending = 'sending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
}
