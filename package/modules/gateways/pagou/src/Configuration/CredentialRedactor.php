<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Configuration;

final class CredentialRedactor
{
    private const SENSITIVE_FIELD_PATTERN = '/(?:authorization|api[_-]?key|token|secret|password|cvv|card[_-]?number)/';

    private function __construct()
    {
    }

    public static function mask(string $secret): string
    {
        $length = strlen($secret);
        if ($length === 0) {
            return '[não configurada]';
        }
        if ($length <= 4) {
            return '****';
        }

        return str_repeat('•', min(12, $length - 4)) . substr($secret, -4);
    }

    public static function fingerprint(string $secret): string
    {
        if ($secret === '') {
            return 'sha256:ausente';
        }

        return 'sha256:' . substr(hash('sha256', $secret), 0, 12);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function redactContext(array $context): array
    {
        $result = [];
        foreach ($context as $key => $value) {
            $normalized = strtolower((string) $key);
            $sensitiveField = preg_match(self::SENSITIVE_FIELD_PATTERN, $normalized) === 1;
            if ($sensitiveField) {
                $result[$key] = '[redacted]';
                continue;
            }
            $result[$key] = is_array($value) ? self::redactContext($value) : $value;
        }

        return $result;
    }
}
