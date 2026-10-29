<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Persistence;

use PDO;
use Pagou\Whmcs\Infrastructure\Persistence\GatewayTypeMigration;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class GatewayTypeMigrationTest extends TestCase
{
    public function testPixAndBoletoActivatedAsBankStopBeingChargedByTheDailyCron(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE tblpaymentgateways (gateway TEXT, setting TEXT, value TEXT, "order" INTEGER)');
        $pdo->exec("INSERT INTO tblpaymentgateways (gateway, setting, value) VALUES
            ('pagou_pix', 'name', 'PIX'), ('pagou_pix', 'type', 'Bank'), ('pagou_pix', 'visible', 'on'),
            ('pagou_boleto', 'name', 'Boleto'), ('pagou_boleto', 'type', 'Bank'), ('pagou_boleto', 'visible', 'on'),
            ('pagou_creditcard', 'type', 'CC'), ('pagou_creditcard', 'visible', ''),
            ('gocardless', 'type', 'Bank'), ('pagoupix', 'type', 'Invoices')");
        $before = $this->settings($pdo);
        $messages = [];
        $migration = $this->migration($messages);

        $runner = new MigrationRunner($pdo);
        $runner->migrate([$migration]);
        $runner->migrate([$migration]);

        $after = $this->settings($pdo);
        self::assertSame('Invoices', $after['pagou_pix|type']);
        self::assertSame('Invoices', $after['pagou_boleto|type']);
        unset($before['pagou_pix|type'], $before['pagou_boleto|type'], $after['pagou_pix|type'], $after['pagou_boleto|type']);
        // Names, visibility, the card gateway and other modules are untouched.
        self::assertSame($before, $after);
        self::assertSame([
            'Pagou Payments: tipo dos gateways no WHMCS conferido para a cobrança automática diária: '
            . 'pagou_boleto Bank para Invoices, pagou_pix Bank para Invoices.',
        ], $messages);
    }

    public function testAnAlreadyCorrectTypeIsReportedOnceAndKept(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE tblpaymentgateways (gateway TEXT, setting TEXT, value TEXT)');
        $pdo->exec("INSERT INTO tblpaymentgateways VALUES ('pagou_pix', 'type', 'Invoices'), ('pagou_pix', 'visible', '')");
        $messages = [];

        (new MigrationRunner($pdo))->migrate([$this->migration($messages)]);

        self::assertSame('Invoices', $this->settings($pdo)['pagou_pix|type']);
        self::assertSame(['Pagou Payments: tipo dos gateways no WHMCS conferido para a cobrança automática diária: pagou_pix Invoices sem alteração.'], $messages);
    }

    public function testAnInstallationWithoutActiveGatewaysIsMigratedSilently(): void
    {
        $messages = [];
        $withoutTable = $this->pdo();
        (new MigrationRunner($withoutTable))->migrate([$this->migration($messages)]);
        $inactive = $this->pdo();
        $inactive->exec('CREATE TABLE tblpaymentgateways (gateway TEXT, setting TEXT, value TEXT)');
        (new MigrationRunner($inactive))->migrate([$this->migration($messages)]);

        self::assertSame([], $messages);
        foreach ([$withoutTable, $inactive] as $pdo) {
            self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM pagou_schema_migrations WHERE version = '018'")->fetchColumn());
        }
    }

    public function testTheShippedMigrationCoversOnlyPixAndBoleto(): void
    {
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        $migration = end($migrations);

        self::assertInstanceOf(GatewayTypeMigration::class, $migration);
        self::assertSame('018', $migration->version());
        self::assertSame(
            ["UPDATE tblpaymentgateways SET value = 'Invoices' WHERE setting = 'type' AND value = 'Bank' AND gateway IN ('pagou_pix', 'pagou_boleto')"],
            $migration->statements('mysql'),
        );
    }

    /** @param list<string> $messages */
    private function migration(array &$messages): GatewayTypeMigration
    {
        return new GatewayTypeMigration('018', 'Stop the daily WHMCS card charge task on Pix and boleto invoices', [
            'pagou_pix',
            'pagou_boleto',
        ], static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
    }

    private function pdo(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @return array<string, string> */
    private function settings(PDO $pdo): array
    {
        $settings = [];
        foreach ($pdo->query('SELECT gateway, setting, value FROM tblpaymentgateways ORDER BY gateway, setting')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['gateway'] . '|' . $row['setting']] = (string) $row['value'];
        }
        return $settings;
    }
}
