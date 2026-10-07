<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Configuration;

use Pagou\Whmcs\Infrastructure\Http\ApiClientConfig;

/** Immutable runtime configuration, constructed only from trusted sources. */
final class GatewayConfiguration
{
    public const API_BASE_URL = ApiClientConfig::OFFICIAL_BASE_URL;

    public function __construct(
        public readonly PagouCredential $credential,
        public readonly string $apiBaseUrl = self::API_BASE_URL,
        public readonly CapabilityRegistry $capabilities = new CapabilityRegistry(),
    ) {
        ApiClientConfig::assertAllowedBaseUrl($apiBaseUrl);
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed>|null $environment
     */
    public static function fromWhmcsSettings(array $settings, ?array $environment = null): self
    {
        $environment ??= $_ENV + $_SERVER;
        $internalOverride = $environment['PAGOU_INTERNAL_API_BASE_URL'] ?? getenv('PAGOU_INTERNAL_API_BASE_URL');
        $baseUrl = is_string($internalOverride) && trim($internalOverride) !== ''
            ? trim($internalOverride)
            : self::API_BASE_URL;

        return new self(PagouCredential::fromSettings($settings, $environment), $baseUrl);
    }

    /**
     * WHMCS gateway configuration schema. The stored value is a password and
     * an empty value keeps an existing stored credential untouched on updates.
     * The production API URL is intentionally absent from this schema.
     *
     * @return array<string, array<string, string>>
     */
    public static function settings(): array
    {
        return [
            'api_key' => [
                'FriendlyName' => 'Credencial Pagou',
                'Type' => 'password',
                'Size' => '60',
                'Description' => 'Uma única credencial Pagou. A credencial nunca será exibida novamente.',
            ],
        ];
    }

    public function apiClientConfig(): ApiClientConfig
    {
        return new ApiClientConfig($this->credential->value(), $this->apiBaseUrl);
    }
}
