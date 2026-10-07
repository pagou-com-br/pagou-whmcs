<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\OperationalSettings;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class OperationalSettingsTest extends TestCase
{
    public function testWritesAndUpdatesNonSecretRows(): void
    {
        $pdo = $this->pdo();
        $settings = new OperationalSettings($pdo);

        self::assertNull($settings->get('admin_alert_worker'));
        $settings->set('admin_alert_worker', '{"ids":[]}');
        $settings->set('admin_alert_worker', '{"ids":["worker"]}');

        self::assertSame('{"ids":["worker"]}', $settings->get('admin_alert_worker'));
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM pagou_settings WHERE setting_key = 'admin_alert_worker'")->fetchColumn());
        $settings->set('admin_alert_worker', '');
        self::assertNull($settings->get('admin_alert_worker'));
    }

    public function testNeverReadsOrChangesSecretRows(): void
    {
        $pdo = $this->pdo();
        $pdo->prepare('INSERT INTO pagou_settings (setting_key, setting_value, is_secret, updated_at) VALUES (?, ?, 1, ?)')
            ->execute(['api_key_ciphertext', 'encrypted-value', '2026-01-01 00:00:00.000000']);
        $settings = new OperationalSettings($pdo);

        self::assertNull($settings->get('api_key_ciphertext'));
        $settings->set('api_key_ciphertext', 'replacement');

        $row = $pdo->query("SELECT setting_value, is_secret, updated_at FROM pagou_settings WHERE setting_key = 'api_key_ciphertext'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['setting_value' => 'encrypted-value', 'is_secret' => 1, 'updated_at' => '2026-01-01 00:00:00.000000'], $row);
    }

    public function testRejectsInvalidKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new OperationalSettings($this->pdo()))->set('Invalid-Key', 'value');
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);

        return $pdo;
    }
}
