<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card;

/**
 * States understood by the module. A new provider state is deliberately
 * represented by unknown, which prevents automatic financial side effects.
 */
enum CardStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Settled = 'settled';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';
    case Refunded = 'refunded';
    case ActionRequired = 'action_required';
    case ChargedBack = 'charged_back';
    case Unknown = 'unknown';

    public static function fromProvider(?string $value): self
    {
        return match (strtolower((string) $value)) {
            'pending', 'processing', 'waiting_payment' => self::Pending,
            'authorized', 'approved', 'pre_authorized' => self::Authorized,
            'paid', 'succeeded', 'captured', 'completed' => self::Paid,
            'settled' => self::Settled,
            'failed', 'declined', 'rejected' => self::Failed,
            'cancelled', 'canceled' => self::Cancelled,
            'reversed', 'voided' => self::Reversed,
            'refunded' => self::Refunded,
            'action_required', 'requires_action', '3ds_required' => self::ActionRequired,
            'charged_back', 'chargeback', 'disputed' => self::ChargedBack,
            default => self::Unknown,
        };
    }

    public static function blocksNewAttempt(string $state): bool
    {
        return in_array($state, ['created', 'queued', 'dispatching', 'pending', 'authorized', 'action_required', 'uncertain', 'paid'], true);
    }

    public function permitsAutomaticInvoiceSettlement(): bool
    {
        return $this === self::Paid || $this === self::Settled;
    }

    public function requiresReconciliation(): bool
    {
        return $this === self::Pending || $this === self::Authorized || $this === self::ActionRequired || $this === self::Unknown;
    }
}
