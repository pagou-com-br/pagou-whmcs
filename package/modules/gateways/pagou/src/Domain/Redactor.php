<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

/** Safe representations for logs and diagnostics. Never use to persist secrets. */
final class Redactor
{
    public static function secret(string $value): string
    {
        $length = strlen($value);
        if ($length === 0) {
            return '[empty]';
        }
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 2) . str_repeat('*', max(4, $length - 4)) . substr($value, -2);
    }

    public static function remoteId(RemoteId|string $value): string
    {
        $value = (string) $value;
        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4) . '…' . substr($value, -4);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function context(array $context): array
    {
        $redacted = [];
        foreach ($context as $key => $value) {
            $normalized = strtolower(str_replace(['-', '_'], '', $key));
            $redacted[$key] = self::isSensitiveKey($normalized)
                ? '[redacted]'
                : (is_array($value) ? self::context($value) : $value);
        }

        return $redacted;
    }

    private static function isSensitiveKey(string $key): bool
    {
        foreach (['token', 'secret', 'authorization', 'credential', 'password', 'cvv', 'cvc', 'cardnumber', 'pan', 'privatekey'] as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
