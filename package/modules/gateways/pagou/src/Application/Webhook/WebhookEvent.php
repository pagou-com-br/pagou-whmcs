<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Webhook;

final class WebhookEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $deliveryKey,
        public readonly string $type,
        public readonly string $paymentMethod,
        public readonly string $status,
        public readonly ?string $remoteId,
        public readonly array $payload,
        public readonly string $rawBody,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }

    public function isKnownPaymentMethod(): bool
    {
        return in_array($this->paymentMethod, ['pix', 'boleto', 'card'], true);
    }
}
