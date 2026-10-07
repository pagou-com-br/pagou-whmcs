<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\PaymentAttemptStore;
use Pagou\Whmcs\Application\Runtime\RetentionService;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class RetentionServiceTest extends TestCase
{
    public function testRetentionRedactsOldOperationalPayloadWithoutDeletingFinancialAttempt(): void
    {
        $pdo = $this->pdo();
        $attempt = (new PaymentAttemptStore($pdo))->ensureCurrent(1, 2, 'pix', 1000, '2026-01-01');
        $old = '2020-01-01 00:00:00.000000';
        $insert = $pdo->prepare(
            'INSERT INTO pagou_webhook_deliveries '
            . '(id, event_key, event_type, signature_valid, payload_json, received_at_utc, processing_status) '
            . 'VALUES (:id, :event_key, :event_type, 1, :payload, :received_at, :status)'
        );
        $insert->execute([
            'id' => '11111111-1111-4111-8111-111111111111',
            'event_key' => str_repeat('a', 64),
            'event_type' => 'qrcode.completed',
            'payload' => '{"payer":{"document":"00000000000"}}',
            'received_at' => $old,
            'status' => 'queued',
        ]);

        $dryRun = (new RetentionService($pdo))->run(90, true);
        self::assertSame(1, $dryRun['webhooks']);
        self::assertNotSame('{}', $pdo->query('SELECT payload_json FROM pagou_webhook_deliveries')->fetchColumn());

        (new RetentionService($pdo))->run(90);
        self::assertSame('{}', $pdo->query('SELECT payload_json FROM pagou_webhook_deliveries')->fetchColumn());
        self::assertNotNull((new PaymentAttemptStore($pdo))->find($attempt['id']));
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);

        return $pdo;
    }
}
