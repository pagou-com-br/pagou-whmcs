<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Split;

/** Public Split states returned by the Pagou payment APIs. */
enum PaymentSplitStatus: string
{
    case Empty = 'empty';
    case Pending = 'pending';
    case Completed = 'completed';
    case Refunded = 'refunded';
    case ActionRequired = 'action_required';
    case Unknown = 'unknown';

    public static function fromRemote(mixed $status): self
    {
        $normalized = is_string($status) ? strtolower(trim($status)) : $status;

        return match ($normalized) {
            1, '1', 'empty' => self::Empty,
            2, '2', 'pending', 'processing' => self::Pending,
            4, '4', 'completed', 'complete' => self::Completed,
            5, '5', 'refunded' => self::Refunded,
            6, '6', 'action_required' => self::ActionRequired,
            default => self::Unknown,
        };
    }
}
