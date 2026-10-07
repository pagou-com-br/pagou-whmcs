<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

enum CardPaymentState: string
{
    case Draft = 'draft';
    case AwaitingToken = 'awaiting_token';
    case AwaitingAuthentication = 'awaiting_authentication';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Chargeback = 'chargeback';
    case ActionRequired = 'action_required';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Captured, self::Declined, self::Cancelled, self::Refunded, self::Chargeback => true,
            default => false,
        };
    }
}
