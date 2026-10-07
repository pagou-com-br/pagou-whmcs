<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\RemoteInput;

use InvalidArgumentException;

/**
 * Internal-only bridge configuration. There is intentionally no WHMCS setting
 * for this URL, so an administrator cannot redirect card capture elsewhere.
 */
final class RemoteInputPolicy
{
    /** @param list<string> $allowedHosts */
    public function __construct(public readonly string $baseUrl, public readonly array $allowedHosts)
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        if (!is_string($host) || $host === '' || $scheme !== 'https' || !in_array(strtolower($host), $allowedHosts, true)) {
            throw new InvalidArgumentException('Remote input URL is not an approved HTTPS host.');
        }
    }

    /** @param array<string,string|false>|null $environment */
    public static function fromEnvironment(?array $environment = null): self
    {
        $environment ??= $_ENV + $_SERVER;
        $baseUrl = self::required($environment, 'PAGOU_CARD_REMOTE_INPUT_BASE_URL');
        $hosts = array_values(array_filter(array_map('trim', explode(',', self::required($environment, 'PAGOU_CARD_REMOTE_INPUT_ALLOWED_HOSTS')))));
        if ($hosts === []) {
            throw new InvalidArgumentException('Remote input host allowlist is empty.');
        }
        return new self($baseUrl, array_map('strtolower', $hosts));
    }

    /** @param array<string,string|false> $environment */
    private static function required(array $environment, string $key): string
    {
        $value = $environment[$key] ?? false;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($key . ' must be configured internally.');
        }
        return $value;
    }
}
