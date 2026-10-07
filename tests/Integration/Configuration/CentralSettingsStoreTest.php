<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Configuration;

use PDO;
use Pagou\Whmcs\Application\Runtime\AddonSettings;
use Pagou\Whmcs\Configuration\CentralSettingsStore;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class CentralSettingsStoreTest extends TestCase
{
    public function testStoresCentralSettingsAndPreservesDefaults(): void
    {
        $pdo = $this->pdo();
        $store = new CentralSettingsStore($pdo);

        self::assertSame('', $store->values()['cpf_field_id']);
        self::assertSame('25', $store->values()['worker_max_jobs']);
        // Thirty days, so an invoice email opened days later still carries a payable code.
        self::assertSame('2592000', $store->values()['pix_expiration_seconds']);
        self::assertSame('30', $store->values()['boleto_grace_period']);
        self::assertSame('', $store->values()['boleto_min_amount']);
        self::assertSame('0', $store->values()['pix_due_respect_late_fees']);
        self::assertSame('0', $store->values()['boleto_respect_late_fees']);
        self::assertSame('1', $store->values()['card_max_installments']);

        $saved = $store->save([
            'cpf_field_id' => '12',
            'cnpj_field_id' => ' 34 ',
            'worker_max_jobs' => '40',
            'worker_max_seconds' => '60',
            'retention_operational_days' => '180',
            'pix_due_enabled' => 'on',
        ]);

        self::assertSame('12', $saved['cpf_field_id']);
        self::assertSame('34', $saved['cnpj_field_id']);
        self::assertSame('40', $saved['worker_max_jobs']);
        self::assertSame('1', $saved['pix_due_enabled']);
        self::assertSame(CentralSettingsStore::keys(), array_keys($saved));
        self::assertSame(6, (int) $pdo->query('SELECT COUNT(*) FROM pagou_settings')->fetchColumn());
    }

    public function testAdminAlertsAreEnabledByDefaultAndNormalizedAsBoolean(): void
    {
        $pdo = $this->pdo();
        $store = new CentralSettingsStore($pdo);

        self::assertSame('1', $store->values()['admin_alerts_enabled']);
        self::assertContains('admin_alerts_enabled', CentralSettingsStore::keysForSection('general'));
        self::assertSame('1', CentralSettingsStore::defaultsForSection('general')['admin_alerts_enabled']);

        self::assertSame('0', $store->save(['admin_alerts_enabled' => ''])['admin_alerts_enabled']);
        self::assertSame('0', $store->values()['admin_alerts_enabled']);
        self::assertSame('1', $store->save(['admin_alerts_enabled' => 'on'])['admin_alerts_enabled']);
        self::assertSame('0', $store->save(['admin_alerts_enabled' => 'unexpected'])['admin_alerts_enabled']);

        $store->resetSection('general');
        self::assertSame('1', $store->values()['admin_alerts_enabled']);
    }

    public function testRejectsInvalidInputWithoutPartialPersistence(): void
    {
        $pdo = $this->pdo();
        $store = new CentralSettingsStore($pdo);

        try {
            $store->save([
                'cpf_field_id' => '8',
                'worker_max_jobs' => '201',
            ]);
            self::fail('A configuração fora do limite deveria ser recusada.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('entre 1 e 200', $exception->getMessage());
        }

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_settings')->fetchColumn());
    }

    public function testRuntimeCombinesGeneralAndMethodSpecificSettings(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE tblpaymentgateways (gateway TEXT, setting TEXT, value TEXT)');
        (new CentralSettingsStore($pdo))->save([
            'cpf_field_id' => '11',
            'cnpj_field_id' => '11',
            'worker_max_jobs' => '70',
        ]);
        $insert = $pdo->prepare(
            'INSERT INTO tblpaymentgateways (gateway, setting, value) VALUES (:gateway, :setting, :value)'
        );
        $methodSettings = [
            ['pagou_pix', 'pix_due_enabled', 'on'],
            ['pagou_pix', 'pix_expiration_seconds', '3600'],
            ['pagou_boleto', 'boleto_grace_period', '15'],
            ['unrelated_gateway', 'worker_max_jobs', '999'],
        ];
        foreach ($methodSettings as [$gateway, $setting, $value]) {
            $insert->execute(compact('gateway', 'setting', 'value'));
        }

        $settings = AddonSettings::fromPdo($pdo);

        self::assertSame([11], $settings->documentFieldIds());
        self::assertSame(70, $settings->integer('worker_max_jobs', 25));
        self::assertTrue($settings->boolean('pix_due_enabled'));
        self::assertSame(3600, $settings->integer('pix_expiration_seconds', 86400));
        self::assertSame(15, $settings->integer('boleto_grace_period', 30));
    }

    public function testAddonCommandSavesGeneralSettingsAndAuditTogether(): void
    {
        if (!defined('WHMCS')) {
            define('WHMCS', true);
        }
        require_once dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/pagou_payments.php';
        $pdo = $this->pdo();

        $result = \pagou_payments_save_settings($pdo, [
            'cpf_field_id' => '21',
            'cnpj_field_id' => '',
            'worker_max_jobs' => '30',
            'worker_max_seconds' => '45',
            'retention_operational_days' => '120',
        ]);

        self::assertSame('Configurações gerais salvas.', $result['notice']);
        self::assertSame(
            'settings.updated',
            $pdo->query('SELECT action FROM pagou_audit_log ORDER BY id DESC LIMIT 1')->fetchColumn(),
        );
    }

    public function testSectionResetRestoresOnlyItsDefaultsAndPreservesSecrets(): void
    {
        if (!defined('WHMCS')) {
            define('WHMCS', true);
        }
        require_once dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/pagou_payments.php';
        $pdo = $this->pdo();
        $store = new CentralSettingsStore($pdo);
        $store->save([
            'cpf_field_id' => '21',
            'pix_fee_fixed' => '4.50',
            'pix_show_qr' => '0',
            'boleto_min_amount' => '25.00',
        ]);
        $secret = $pdo->prepare(
            'INSERT INTO pagou_settings (setting_key, setting_value, is_secret, updated_at) '
            . 'VALUES (:key, :value, 1, :updated_at)'
        );
        $secret->execute([
            'key' => 'api_key_ciphertext',
            'value' => 'encrypted-value',
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ]);

        $result = \pagou_payments_reset_settings($pdo, ['settings_section' => 'pix']);
        $values = $store->values();

        self::assertStringContainsString('Padrões do Pix restaurados', $result['notice']);
        self::assertSame('0.00', $values['pix_fee_fixed']);
        self::assertSame('1', $values['pix_show_qr']);
        self::assertSame('21', $values['cpf_field_id']);
        self::assertSame('25.00', $values['boleto_min_amount']);
        self::assertSame(
            'encrypted-value',
            $pdo->query("SELECT setting_value FROM pagou_settings WHERE setting_key = 'api_key_ciphertext'")->fetchColumn(),
        );
        self::assertSame(
            'settings.reset',
            $pdo->query('SELECT action FROM pagou_audit_log ORDER BY id DESC LIMIT 1')->fetchColumn(),
        );
        self::assertStringContainsString(
            '"section":"pix"',
            (string) $pdo->query('SELECT metadata_json FROM pagou_audit_log ORDER BY id DESC LIMIT 1')->fetchColumn(),
        );
    }

    public function testInvalidSectionCannotBeReset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('seção de configurações');

        (new CentralSettingsStore($this->pdo()))->resetSection('unknown');
    }

    public function testAdvancedCardSettingsAreRejectedAndLegacyValuesAreNotRewritten(): void
    {
        $pdo = $this->pdo();
        $store = new CentralSettingsStore($pdo);
        foreach ([['card_max_installments' => '2'], ['card_auto_capture' => '0']] as $input) {
            try {
                $store->save($input);
                self::fail('Advanced card options must not be saved.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_settings')->fetchColumn());
            }
        }
        $pdo->exec("INSERT INTO pagou_settings (setting_key, setting_value, is_secret, updated_at) VALUES ('card_auto_capture', '0', 0, '2026-01-01')");
        $store->save(['cpf_field_id' => '11']);
        self::assertSame('0', $store->values()['card_auto_capture']);
        $store->save(['card_auto_capture' => '1', 'card_max_installments' => '1']);
        self::assertSame('1', $store->values()['card_auto_capture']);
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);

        return $pdo;
    }
}
