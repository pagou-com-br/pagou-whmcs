<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Webhook;

/**
 * Framework-neutral application service for a thin WHMCS callback endpoint.
 * The HTTP adapter supplies raw headers/body and translates the returned code.
 */
final class WebhookEndpointService
{
    public function __construct(
        private readonly HmacWebhookVerifier $verifier,
        private readonly WebhookEventParser $parser,
        private readonly WebhookInbox $inbox,
        private readonly WebhookEventHandler $handler,
    ) {
    }

    /** @param array<string, string> $headers */
    public function receive(array $headers, string $rawBody, ?\DateTimeImmutable $now = null): WebhookReceipt
    {
        $timestamp = $this->header($headers, 'x-pagou-timestamp') ?? $this->header($headers, 'x-webhook-timestamp');
        $signature = $this->header($headers, 'x-pagou-signature') ?? $this->header($headers, 'x-webhook-signature');
        if ($timestamp === null || $signature === null) {
            return WebhookReceipt::rejected('missing_signature');
        }

        try {
            $this->verifier->assertValid($rawBody, $timestamp, $signature, $now);
        } catch (WebhookVerificationException) {
            return WebhookReceipt::rejected('invalid_signature');
        }

        $fallbackKey = hash('sha256', $timestamp . '.' . $rawBody);
        try {
            $event = $this->parser->parse($rawBody, $fallbackKey, $now);
        } catch (UnknownWebhookPayloadException) {
            return WebhookReceipt::rejected('invalid_payload');
        }

        $receivedAt = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!$this->inbox->reserve($event->deliveryKey, $receivedAt)) {
            return WebhookReceipt::duplicate($event->deliveryKey);
        }

        try {
            $this->handler->handle($event);
            $this->inbox->markProcessed($event->deliveryKey);
        } catch (\Throwable) {
            $this->inbox->markRejected($event->deliveryKey, 'handler_failure');
            return WebhookReceipt::acceptedForRetry($event->deliveryKey);
        }

        return WebhookReceipt::processed($event->deliveryKey, $event->paymentMethod === 'unknown');
    }

    /** @param array<string, string> $headers */
    private function header(array $headers, string $wanted): ?string
    {
        foreach ($headers as $name => $value) {
            if (strtolower($name) === $wanted) {
                return $value;
            }
        }
        return null;
    }
}
