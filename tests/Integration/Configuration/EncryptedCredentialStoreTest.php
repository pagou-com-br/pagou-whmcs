<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Configuration;

use PDO;
use Pagou\Whmcs\Configuration\CredentialRedactor;
use Pagou\Whmcs\Configuration\EncryptedCredentialStore;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class EncryptedCredentialStoreTest extends TestCase
{
    public function testCredentialIsNeverStoredInPlaintextAndCanBeRotated(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $store = new EncryptedCredentialStore($pdo);
        $credential = 'credential-for-integration-test';

        self::assertSame(CredentialRedactor::fingerprint($credential), $store->save($credential));
        $stored = $pdo->query(
            "SELECT setting_value FROM pagou_settings WHERE setting_key = 'api_key_ciphertext'"
        );
        self::assertNotFalse($stored);
        self::assertNotSame($credential, $stored->fetchColumn());
        self::assertSame($credential, $store->load());

        $rotated = 'rotated-credential-for-test';
        $store->save($rotated);
        self::assertSame($rotated, $store->load());
    }
}
