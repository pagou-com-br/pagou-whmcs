<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

enum ArtifactState: string
{
    case Requested = 'requested';
    case Pending = 'pending';
    case Available = 'available';
    case Failed = 'failed';
    case Expired = 'expired';
}
