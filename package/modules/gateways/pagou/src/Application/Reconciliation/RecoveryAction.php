<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reconciliation;

/** Recovery actions are intentionally descriptive, never arbitrary callbacks. */
enum RecoveryAction: string
{
    case None = 'none';
    case RefreshOperation = 'refresh_operation';
    case ReplayInbox = 'replay_inbox';
    case RefreshAttempt = 'refresh_attempt';
    case ApplyPayment = 'apply_payment';
}
