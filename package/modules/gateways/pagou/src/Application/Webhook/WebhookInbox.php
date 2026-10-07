<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Webhook;

/**
 * Persistent implementations must atomically reserve a delivery key before
 * applying its financial effect. Returning false means it was already seen.
 */
interface WebhookInbox
{
    public function reserve(string $deliveryKey, \DateTimeImmutable $receivedAt): bool;

    public function markProcessed(string $deliveryKey): void;

    public function markRejected(string $deliveryKey, string $reason): void;
}
