<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Webhook;

/** Verifies a signed timestamp plus the unmodified HTTP body. */
final class HmacWebhookVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $replayWindowSeconds = 300,
    ) {
        if ($secret === '' || $replayWindowSeconds < 30) {
            throw new \InvalidArgumentException('A configuração de assinatura webhook é inválida.');
        }
    }

    public function assertValid(string $rawBody, string $timestamp, string $signature, ?\DateTimeImmutable $now = null): void
    {
        if (!ctype_digit($timestamp) || $signature === '' || strlen($signature) > 512) {
            throw new WebhookVerificationException('Cabeçalhos de assinatura inválidos.');
        }

        $reference = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (abs($reference->getTimestamp() - (int) $timestamp) > $this->replayWindowSeconds) {
            throw new WebhookVerificationException('A notificação está fora da janela de aceitação.');
        }

        $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        if (preg_match('/^[a-f0-9]{64}$/i', $provided) !== 1) {
            throw new WebhookVerificationException('Formato de assinatura inválido.');
        }

        $expected = hash_hmac('sha256', $timestamp . $rawBody, $this->secret);
        if (!hash_equals($expected, strtolower($provided))) {
            throw new WebhookVerificationException('Assinatura da notificação inválida.');
        }
    }
}
