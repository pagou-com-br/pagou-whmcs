<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Package;

use PHPUnit\Framework\TestCase;

final class HookRegistrationTest extends TestCase
{
    public function testClientInvoiceViewPreparesMissingChargeUsingItsMethodPolicy(): void
    {
        $hooks = file_get_contents(dirname(__DIR__, 2) . '/package/modules/addons/pagou_payments/hooks.php');

        self::assertIsString($hooks);
        self::assertStringContainsString("add_hook('ViewInvoiceDetailsPage'", $hooks);
        self::assertStringContainsString('falha local ao preparar a cobrança exibida', $hooks);
        self::assertStringNotContainsString("'ClientAreaHeadOutput'", $hooks);
    }

    public function testAWrongDocumentIsRefusedOnTheProfileWithoutEverBlockingItOnAFailure(): void
    {
        $hooks = file_get_contents(dirname(__DIR__, 2) . '/package/modules/addons/pagou_payments/hooks.php');

        self::assertIsString($hooks);
        self::assertStringContainsString("add_hook('ClientDetailsValidation'", $hooks);
        self::assertStringContainsString('ClientDocumentValidation::errors($vars, $settings)', $hooks);
        self::assertStringContainsString('conferência local do CPF ou CNPJ indisponível', $hooks);
    }

    public function testACorrectedProfileIssuesRefusedChargesOnceTheRequestEnds(): void
    {
        $hooks = file_get_contents(dirname(__DIR__, 2) . '/package/modules/addons/pagou_payments/hooks.php');

        self::assertIsString($hooks);
        self::assertStringContainsString("add_hook('ClientEdit'", $hooks);
        self::assertStringContainsString('register_shutdown_function', $hooks);
        self::assertStringContainsString('->retryRefusedCharges($clientId)', $hooks);
        self::assertStringContainsString('preparar de novo as cobranças recusadas do cliente', $hooks);
    }

    public function testClientAssetsPreventDuplicateInitialization(): void
    {
        $root = dirname(__DIR__, 2);
        $script = file_get_contents($root . '/package/modules/addons/pagou_payments/assets/client.js');

        self::assertIsString($script);
        self::assertStringContainsString('window.pagouClientInitialized', $script);
        self::assertStringContainsString('event.preventDefault()', $script);
    }

    public function testInvoiceCancellationSchedulesRemoteChargeCancellation(): void
    {
        $hooks = file_get_contents(dirname(__DIR__, 2) . '/package/modules/addons/pagou_payments/hooks.php');

        self::assertIsString($hooks);
        self::assertStringContainsString("add_hook('InvoiceCancelled'", $hooks);
        self::assertStringContainsString('falha local ao cancelar a cobrança da fatura', $hooks);
    }

    public function testAdminAlertsRunAfterCronWithoutBreakingIt(): void
    {
        $hooks = file_get_contents(dirname(__DIR__, 2) . '/package/modules/addons/pagou_payments/hooks.php');

        self::assertIsString($hooks);
        self::assertStringContainsString("add_hook('AfterCronJob'", $hooks);
        self::assertStringContainsString('AdminAlerts::fromRuntime()->run()', $hooks);
        self::assertStringContainsString('alertas administrativos indisponíveis nesta execução do cron', $hooks);
        self::assertStringNotContainsString("'DailyCronJob'", $hooks);
    }

    public function testAdminInvoiceSummaryUsesDedicatedAssetsAndNoFreeTextReason(): void
    {
        $root = dirname(__DIR__, 2);
        $hooks = file_get_contents($root . '/package/modules/addons/pagou_payments/hooks.php');

        self::assertIsString($hooks);
        self::assertStringContainsString("add_hook(\n    'AdminAreaHeadOutput'", $hooks);
        self::assertStringContainsString('invoice-admin.css', $hooks);
        self::assertStringContainsString('invoice-admin.js', $hooks);
        $controls = file_get_contents($root . '/package/modules/addons/pagou_payments/admin/InvoiceControls.php');
        self::assertIsString($controls);
        self::assertStringContainsString('Indisponível pelas regras configuradas', $controls);
        self::assertStringContainsString('type="hidden" name="reason"', $controls);
        self::assertStringNotContainsString('<label>Motivo', $hooks);
        self::assertFileExists($root . '/package/modules/addons/pagou_payments/assets/invoice-admin.css');
        self::assertFileExists($root . '/package/modules/addons/pagou_payments/assets/invoice-admin.js');
    }
}
