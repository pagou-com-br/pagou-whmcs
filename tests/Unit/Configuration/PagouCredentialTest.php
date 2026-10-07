<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Configuration;

use Pagou\Whmcs\Configuration\CredentialRedactor;
use Pagou\Whmcs\Configuration\GatewayConfiguration;
use Pagou\Whmcs\Configuration\PagouCredential;
use PHPUnit\Framework\TestCase;

final class PagouCredentialTest extends TestCase
{
    public function testEnvironmentCredentialTakesPrecedenceOverStoredPassword(): void
    {
        $credential = PagouCredential::fromSettings(
            ['api_key' => 'stored-secret'],
            ['PAGOU_API_KEY' => 'environment-secret'],
        );

        self::assertSame('environment', $credential->source());
        self::assertSame('environment-secret', $credential->value());
        self::assertStringNotContainsString('environment-secret', $credential->masked());
    }

    public function testAStoredCredentialIsAcceptedWhenNoEnvironmentOverrideExists(): void
    {
        $credential = PagouCredential::fromSettings(['api_key' => 'stored-secret'], []);

        self::assertSame('whmcs', $credential->source());
        self::assertSame('sha256:' . substr(hash('sha256', 'stored-secret'), 0, 12), $credential->fingerprint());
    }

    public function testGatewaySettingsContainOnlyOnePasswordCredentialAndNoEnvironmentSelector(): void
    {
        $settings = GatewayConfiguration::settings();

        self::assertSame(['api_key'], array_keys($settings));
        self::assertSame('password', $settings['api_key']['Type']);
        self::assertArrayNotHasKey('api_base_url', $settings);
        self::assertArrayNotHasKey('sandbox', $settings);
    }

    public function testUnknownInternalOverrideIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GatewayConfiguration::fromWhmcsSettings(
            ['api_key' => 'stored-secret'],
            ['PAGOU_INTERNAL_API_BASE_URL' => 'https://untrusted.example'],
        );
    }

    public function testRedactionNeverLeaksSecretValues(): void
    {
        $redacted = CredentialRedactor::redactContext([
            'api_key' => 'secret',
            'nested' => ['authorization' => 'Bearer secret'],
            'invoice_id' => 42,
        ]);

        self::assertSame('[redacted]', $redacted['api_key']);
        self::assertSame('[redacted]', $redacted['nested']['authorization']);
        self::assertSame(42, $redacted['invoice_id']);
    }
}
