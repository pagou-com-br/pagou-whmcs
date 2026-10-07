<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\Widget;

use Pagou\Whmcs\Application\Runtime\RuntimeFactory;
use Pagou\Whmcs\Configuration\EncryptedCredentialStore;
use Pagou\Whmcs\Infrastructure\Http\ApiClientConfig;
use Pagou\Whmcs\Infrastructure\Http\SafeCurlApiClient;

/**
 * Account-wide balance and receipts read from the Pagou API, shared by the
 * WHMCS home widget and the addon overview through one session snapshot, so
 * both screens respect the same permission, cache window and rate limit.
 */
final class AccountSummary
{
    public static function allowed(): bool
    {
        // Outside the WHMCS admin runtime (cron, CLI) nobody holds the finance permission.
        if (!class_exists(\WHMCS\User\Admin::class)) {
            return false;
        }
        try {
            $admin = \WHMCS\User\Admin::getAuthenticatedUser();
            return $admin !== null && !$admin->isDisabled && $admin->hasPermission('View Income Totals')
                && array_key_exists('pagou_payments', $admin->getModulePermissions());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Without $fetchRemote an expired part is only marked pending, letting the
     * page render immediately and refresh the account values asynchronously.
     *
     * @return array<string, mixed>
     */
    public static function read(bool $force, ?bool $fetchRemote = null): array
    {
        try {
            $pdo = RuntimeFactory::pdo();
            $override = getenv('PAGOU_INTERNAL_API_BASE_URL');
            $base = is_string($override) && trim($override) !== '' ? trim($override) : ApiClientConfig::OFFICIAL_BASE_URL;
            ApiClientConfig::assertAllowedBaseUrl($base);
            $environment = rtrim($base, '/') === ApiClientConfig::OFFICIAL_BASE_URL ? 'Produção' : 'Ambiente interno';
            $key = getenv('PAGOU_API_KEY');
            try {
                $key = is_string($key) && trim($key) !== '' ? trim($key) : (new EncryptedCredentialStore($pdo))->load();
            } catch (\Throwable) {
                $key = null;
            }
            $configured = is_string($key) && $key !== '';
            // The addon directory keeps the identity shared with the original widget cache.
            $identity = hash('sha256', dirname(__DIR__, 2) . '|' . (int) ($_SESSION['adminid'] ?? 0) . '|' . $base . '|' . ($key ?? ''));
            if (!is_array($_SESSION['pagou_dashboard_snapshot'] ?? null)) {
                $_SESSION['pagou_dashboard_snapshot'] = [];
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $data = (new Snapshot())->read(
                $_SESSION['pagou_dashboard_snapshot'],
                $identity,
                static function () use ($key, $base): array {
                    if (!is_string($key) || $key === '') {
                        throw new \RuntimeException('Credencial indisponível.');
                    }
                    return (new SafeCurlApiClient(new ApiClientConfig($key, $base, 1, 5, 16384)))
                        ->request('GET', '/v1/customers/balance')->json;
                },
                static function () use ($key, $base): array {
                    if (!is_string($key) || $key === '') {
                        throw new \RuntimeException('Credencial indisponível.');
                    }
                    $payload = (new SafeCurlApiClient(new ApiClientConfig($key, $base, 1, 5, 16384)))
                        ->request('GET', '/v1/customers/receipts/summary')->json;
                    return Summary::fromPayload($payload, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                },
                $now,
                $force,
                ($fetchRemote ?? $force) || !$configured,
                static fn (): array => (new Findings($pdo))->read(),
            );
            return $data + ['environment' => $environment, 'configured' => $configured];
        } catch (\Throwable) {
            return ['unavailable' => true];
        }
    }
}
