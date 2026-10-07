<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Package;

use PHPUnit\Framework\TestCase;

final class GatewayEntrypointTest extends TestCase
{
    public function testAllGatewayEntrypointsLoadAndExposeExpectedCapabilities(): void
    {
        if (!defined('WHMCS')) {
            define('WHMCS', true);
        }
        $gateways = dirname(__DIR__, 2) . '/package/modules/gateways';
        require_once $gateways . '/pagou_pix.php';
        require_once $gateways . '/pagou_boleto.php';
        require_once $gateways . '/pagou_creditcard.php';
        require_once $gateways . '/../addons/pagou_payments/pagou_payments.php';

        self::assertSame('Pagou - Pix', pagou_pix_MetaData()['DisplayName']);
        self::assertSame('Pagou - Boleto', pagou_boleto_MetaData()['DisplayName']);
        self::assertSame(['FriendlyName', 'PagouSettings'], array_keys(pagou_pix_config()));
        self::assertSame(['FriendlyName', 'PagouSettings'], array_keys(pagou_boleto_config()));
        self::assertSame(['FriendlyName', 'PagouSettings'], array_keys(pagou_creditcard_config()));
        self::assertStringContainsString('section=pix', pagou_pix_config()['PagouSettings']['Description']);
        self::assertStringContainsString('Configurar Pix no addon Pagou', pagou_pix_config()['PagouSettings']['Description']);
        self::assertStringContainsString('section=boleto', pagou_boleto_config()['PagouSettings']['Description']);
        self::assertStringContainsString('Configurar boleto no addon Pagou', pagou_boleto_config()['PagouSettings']['Description']);
        self::assertStringContainsString('section=card', pagou_creditcard_config()['PagouSettings']['Description']);
        self::assertStringContainsString('Configurar cartão no addon Pagou', pagou_creditcard_config()['PagouSettings']['Description']);
        self::assertSame([], pagou_payments_config()['fields']);
        self::assertTrue(function_exists('pagou_pix_refund'));
        self::assertStringContainsString(
            'Não foi possível iniciar o pagamento seguro',
            pagou_creditcard_remoteinput([]),
        );
        self::assertTrue(function_exists('pagou_creditcard_nolocalcc'));
        self::assertTrue(function_exists('pagou_creditcard_capture'));
        self::assertTrue(function_exists('pagou_creditcard_refund'));
        self::assertTrue(function_exists('pagou_creditcard_remoteupdate'));
        self::assertTrue(function_exists('pagou_creditcard_storeremote'));
        self::assertStringContainsString('pagou-card-session-form', pagou_creditcard_auto_submit_script());
        self::assertStringContainsString('.submit()', pagou_creditcard_auto_submit_script());

        $escaped = pagou_gateway_settings_notice('pix', '<script>alert(1)</script>', '"><img src=x>');
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $escaped['Description']);
        self::assertStringContainsString('&quot;&gt;&lt;img src=x&gt;', $escaped['Description']);
        self::assertStringNotContainsString('<script>', $escaped['Description']);
    }

    public function testCardCaptureReportsThePagouChargeIdentifierToWhmcs(): void
    {
        if (!function_exists('pagou_creditcard_result')) {
            $this->testAllGatewayEntrypointsLoadAndExposeExpectedCapabilities();
        }
        $paid = pagou_creditcard_result(\Pagou\Whmcs\Payment\Card\CardStatus::Paid, 'Charge-Mixed-19');
        self::assertSame(['status' => 'success', 'transid' => 'Charge-Mixed-19'], array_intersect_key($paid, ['status' => 1, 'transid' => 1]));
        // The native transaction keeps the remote identifier; the economic hash stays in the ledger.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/package/modules/gateways/pagou_creditcard.php');
        self::assertStringNotContainsString('EconomicPaymentKey', $source);
    }

    public function testCardGatewayAcceptsOnlyTwoOpaqueUuidReferences(): void
    {
        if (!function_exists('pagou_creditcard_token')) {
            $this->testAllGatewayEntrypointsLoadAndExposeExpectedCapabilities();
        }
        $token = '11111111-1111-4111-8111-111111111111|22222222-2222-4222-8222-222222222222';

        self::assertSame(explode('|', $token), pagou_creditcard_token($token));
    }
}
