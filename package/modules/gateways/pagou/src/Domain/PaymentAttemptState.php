<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

enum PaymentAttemptState: string
{
    case Draft = 'draft';
    case PendingIssuance = 'pending_issuance';
    case Issuing = 'issuing';
    case AwaitingPayment = 'awaiting_payment';
    case Processing = 'processing';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Failed = 'failed';
    case Uncertain = 'uncertain';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Paid, self::Cancelled, self::Expired, self::Failed => true,
            default => false,
        };
    }
}
