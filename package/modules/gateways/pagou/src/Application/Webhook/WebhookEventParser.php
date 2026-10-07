<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Webhook;

/**
 * Schema-tolerant parser. New event types are persisted as unknown and must
 * never be interpreted as a successful payment by a default branch.
 */
final class WebhookEventParser
{
    public function parse(string $rawBody, string $fallbackDeliveryKey, ?\DateTimeImmutable $now = null): WebhookEvent
    {
        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new UnknownWebhookPayloadException('O corpo da notificação não contém JSON válido.', 0, $exception);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new UnknownWebhookPayloadException('O corpo da notificação possui formato inesperado.');
        }

        $type = $this->stringAt($payload, ['name'])
            ?? $this->stringAt($payload, ['event', 'type'])
            ?? $this->stringAt($payload, ['type'])
            ?? $this->inferPagouEventType($payload);
        $resource = $this->resource($payload);
        $method = $this->normalizeMethod(
            $this->stringAt($resource, ['payment_method'])
                ?? $this->stringAt($resource, ['method'])
                ?? $this->stringAt($payload, ['payment_method']),
            $type,
        );
        $status = strtolower(
            $this->stringAt($resource, ['status'])
                ?? $this->stringAt($payload, ['status'])
                ?? $this->statusForType($type),
        );
        $remoteId = $this->stringAt($resource, ['id']) ?? $this->stringAt($payload, ['data', 'id']) ?? $this->stringAt($payload, ['id']);
        $deliveryKey = $this->stringAt($payload, ['event_id']) ?? $this->stringAt($payload, ['idempotency_key']) ?? $fallbackDeliveryKey;
        if (trim($deliveryKey) === '') {
            throw new UnknownWebhookPayloadException('A notificação não contém uma identidade de entrega.');
        }

        return new WebhookEvent(
            $deliveryKey,
            strtolower($type),
            $method,
            $status,
            $remoteId,
            $payload,
            $rawBody,
            $this->parseDate($this->stringAt($payload, ['created_at']) ?? $this->stringAt($payload, ['occurred_at']), $now),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resource(array $payload): array
    {
        foreach (['data', 'resource', 'payment', 'charge'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && !array_is_list($payload[$key])) {
                return $payload[$key];
            }
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $path
     */
    private function stringAt(array $source, array $path): ?string
    {
        $value = $source;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Compatibility for previously stored deliveries without the current name/data
     * envelope. Infer only known shapes and keep other payloads unknown-safe.
     *
     * @param array<string, mixed> $payload
     */
    private function inferPagouEventType(array $payload): string
    {
        if (isset($payload['payload']) && is_array($payload['payload']) && isset($payload['client_code'])) {
            return 'charge.created';
        }
        if (isset($payload['amount']) && is_array($payload['amount']) && isset($payload['paid_in'], $payload['client_code'])) {
            return 'charge.paid';
        }
        if (isset($payload['external_id'], $payload['e2e_id'], $payload['payer'], $payload['amount'])) {
            return 'qrcode.completed';
        }
        if (isset($payload['client_code'], $payload['code'], $payload['description'])) {
            return isset($payload['amount']) ? 'qrcode.refunded' : 'charge.rejected';
        }

        return 'unknown';
    }

    private function normalizeMethod(?string $value, string $type): string
    {
        $explicit = match (strtolower((string) $value)) {
            'pix' => 'pix',
            'boleto', 'bank_slip' => 'boleto',
            'card', 'credit_card', 'credit-card' => 'card',
            default => 'unknown',
        };
        if ($explicit !== 'unknown') {
            return $explicit;
        }

        return match (true) {
            str_starts_with($type, 'qrcode.') => 'pix',
            str_starts_with($type, 'charge.') => 'boleto',
            str_starts_with($type, 'creditcard.') => 'card',
            default => 'unknown',
        };
    }

    private function statusForType(string $type): string
    {
        return match ($type) {
            'qrcode.completed', 'charge.paid' => 'paid',
            'qrcode.refunded' => 'refunded',
            'charge.created' => 'ready',
            'charge.canceled' => 'cancelled',
            'charge.rejected' => 'failed',
            default => 'unknown',
        };
    }

    private function parseDate(?string $value, ?\DateTimeImmutable $fallback): \DateTimeImmutable
    {
        if ($value !== null) {
            try {
                return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                // Unknown date is deliberately replaced with receipt time.
            }
        }
        return $fallback ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
