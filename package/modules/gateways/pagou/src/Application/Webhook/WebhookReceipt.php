<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Webhook;

final class WebhookReceipt
{
    private function __construct(
        public readonly int $httpStatus,
        public readonly string $outcome,
        public readonly ?string $deliveryKey = null,
        public readonly bool $requiresReconciliation = false,
    ) {
    }

    public static function rejected(string $reason): self
    {
        return new self(400, $reason);
    }
    public static function duplicate(string $key): self
    {
        return new self(200, 'duplicate', $key);
    }
    public static function acceptedForRetry(string $key): self
    {
        return new self(503, 'retry', $key);
    }
    public static function processed(string $key, bool $unknown): self
    {
        return new self(200, $unknown ? 'unknown' : 'processed', $key, $unknown);
    }
}
