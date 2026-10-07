<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;
use Pagou\Whmcs\Application\Async\OperationOutbox;
use Pagou\Whmcs\Application\Webhook\HmacWebhookVerifier;
use Pagou\Whmcs\Application\Webhook\WebhookEndpointService;
use Pagou\Whmcs\Application\Webhook\WebhookEventParser;
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Infrastructure\Persistence\Webhook\OutboxWebhookEventHandler;
use Pagou\Whmcs\Infrastructure\Persistence\Webhook\PdoWebhookInbox;
use Pagou\Whmcs\Infrastructure\Http\ApiClientConfig;
use Pagou\Whmcs\Infrastructure\Http\SafeCurlApiClient;
use Pagou\Whmcs\Payment\Card\CardApiClient;
use Pagou\Whmcs\Payment\Card\CardPaymentService;
use Pagou\Whmcs\Payment\Card\CardReconciler;
use Pagou\Whmcs\Payment\Card\Infrastructure\OutboxCardReconciliationScheduler;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardOperationJournal;
use Pagou\Whmcs\Payment\Card\SafeCurlCardTransport;

final class RuntimeFactory
{
    public static function pdo(): PDO
    {
        $override = $GLOBALS['pagou_whmcs_test_pdo'] ?? null;
        if ($override instanceof PDO) {
            return $override;
        }
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            throw new \RuntimeException('WHMCS database runtime is unavailable.');
        }
        $pdo = \WHMCS\Database\Capsule::connection()->getPdo();
        if (!$pdo instanceof PDO) {
            throw new \RuntimeException('WHMCS did not expose a PDO connection.');
        }

        return $pdo;
    }

    public static function migrate(?PDO $pdo = null): void
    {
        $pdo ??= self::pdo();
        $migrations = require dirname(__DIR__, 5) . '/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
    }

    public static function runtime(?PDO $pdo = null): WhmcsRuntime
    {
        $pdo ??= self::pdo();

        return new WhmcsRuntime($pdo, AddonSettings::fromPdo($pdo), self::outbox($pdo), self::localApi());
    }

    public static function webhook(?PDO $pdo = null): WebhookEndpointService
    {
        $pdo ??= self::pdo();
        $settings = AddonSettings::fromPdo($pdo);
        $outbox = self::outbox($pdo);

        return new WebhookEndpointService(
            new HmacWebhookVerifier($settings->apiKey()),
            new WebhookEventParser(),
            new PdoWebhookInbox($pdo),
            new OutboxWebhookEventHandler($pdo, $outbox),
        );
    }

    public static function outbox(?PDO $pdo = null): OperationOutbox
    {
        return new PdoOperationOutbox($pdo ?? self::pdo());
    }

    public static function card(?PDO $pdo = null): CardPaymentService
    {
        $pdo ??= self::pdo();
        $settings = AddonSettings::fromPdo($pdo);
        $configured = getenv('PAGOU_INTERNAL_API_BASE_URL');
        $baseUrl = is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
        $http = new SafeCurlApiClient(ApiClientConfig::fromInternalOverride($settings->apiKey(), $baseUrl));
        $api = new CardApiClient(new SafeCurlCardTransport($http));

        return new CardPaymentService(
            $api,
            new CardReconciler($api),
            new PdoCardOperationJournal($pdo),
            new OutboxCardReconciliationScheduler(self::outbox($pdo)),
        );
    }

    public static function cardReconciliation(?PDO $pdo = null): CardReconciliationRuntime
    {
        $pdo ??= self::pdo();

        return new CardReconciliationRuntime(
            $pdo,
            self::card($pdo),
            self::localApi(),
            self::outbox($pdo),
        );
    }

    /** @return callable(string, array<string, mixed>): array<string, mixed> */
    public static function localApi(): callable
    {
        $override = $GLOBALS['pagou_whmcs_test_local_api'] ?? null;
        if (is_callable($override)) {
            return $override;
        }

        return static function (string $command, array $parameters): array {
            if (!function_exists('localAPI')) {
                throw new \RuntimeException('WHMCS localAPI is unavailable.');
            }
            $result = localAPI($command, $parameters);

            return is_array($result) ? $result : ['result' => 'error'];
        };
    }
}
