<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

/**
 * Signed public address of a Pix QR Code image. Email clients block images embedded
 * in the message, so the QR is fetched from WHMCS without a session; the signature
 * binds the address to one charge and a deadline, and cannot be guessed.
 */
final class PixQrLink
{
    /** Each email gets a fresh address; the charge itself must also still be payable. */
    public const LIFETIME = 5184000;

    public function __construct(private readonly string $key)
    {
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException('Pix QR link key is too short.');
        }
    }

    /** Derived from the WHMCS installation secret, so no new configuration is needed. */
    public static function fromWhmcs(): ?self
    {
        $secret = $GLOBALS['cc_encryption_hash'] ?? null;

        return is_string($secret) && strlen($secret) >= 16
            ? new self(hash_hmac('sha256', 'pagou-pix-qr-link-v1', $secret))
            : null;
    }

    public function query(string $attemptId, int $expiresAt): string
    {
        return 'a=' . rawurlencode($attemptId) . '&e=' . $expiresAt . '&s=' . $this->signature($attemptId, $expiresAt);
    }

    public function valid(string $attemptId, string $expiresAt, string $signature, int $now): bool
    {
        if (
            preg_match('/^[a-f0-9-]{36}$/i', $attemptId) !== 1
            || preg_match('/^\d{1,12}$/', $expiresAt) !== 1
            || (int) $expiresAt < $now
        ) {
            return false;
        }

        return hash_equals($this->signature($attemptId, (int) $expiresAt), $signature);
    }

    private function signature(string $attemptId, int $expiresAt): string
    {
        return substr(hash_hmac('sha256', strtolower($attemptId) . '|' . $expiresAt, $this->key), 0, 32);
    }
}
