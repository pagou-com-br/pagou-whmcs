<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Http;

/**
 * Immutable, deliberately restrictive configuration for Pagou API traffic.
 */
final class ApiClientConfig
{
    public const OFFICIAL_BASE_URL = 'https://api.pagou.com.br';

    /** @var list<string> */
    private const INTERNAL_OVERRIDE_ALLOWLIST = [
        'https://api.pagou.com.br',
        'https://api-dev.pagou.com.br',
    ];

    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = self::OFFICIAL_BASE_URL,
        public readonly int $connectTimeoutSeconds = 5,
        public readonly int $timeoutSeconds = 20,
        public readonly int $maxResponseBytes = 1048576,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('A credencial Pagou é obrigatória.');
        }

        if ($connectTimeoutSeconds < 1 || $timeoutSeconds < $connectTimeoutSeconds || $maxResponseBytes < 1024) {
            throw new \InvalidArgumentException('Os limites HTTP configurados são inválidos.');
        }

        self::assertAllowedBaseUrl($baseUrl);
    }

    /**
     * An override may be supplied only by internal deployment configuration.
     * It is never a value accepted from an administrator form or request.
     */
    public static function fromInternalOverride(string $apiKey, ?string $internalBaseUrl = null): self
    {
        return new self($apiKey, $internalBaseUrl ?? self::OFFICIAL_BASE_URL);
    }

    public static function assertAllowedBaseUrl(string $baseUrl): void
    {
        $normalized = rtrim(strtolower(trim($baseUrl)), '/');
        if (!in_array($normalized, self::INTERNAL_OVERRIDE_ALLOWLIST, true)) {
            throw new \InvalidArgumentException('A URL da API não faz parte da lista interna permitida.');
        }
    }

    public function normalizedBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }
}
