<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Package;

use PHPUnit\Framework\TestCase;

final class PackageMetadataTest extends TestCase
{
    public function testPackageMetadataMatchesTheVersionFile(): void
    {
        $root = dirname(__DIR__, 2);
        $metadata = json_decode(
            (string) file_get_contents($root . '/package/release-manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $version = trim((string) file_get_contents($root . '/package/VERSION'));
        $installedRoot = $root . '/package/modules/addons/pagou_payments';

        self::assertSame('modules', $metadata['install_root']);
        self::assertSame($version, $metadata['version']);
        self::assertSame($version, trim((string) file_get_contents($installedRoot . '/VERSION')));
        self::assertSame('MIT', $metadata['license']);
        self::assertSame('>=8.1', $metadata['php']);
        self::assertContains('gateways/pagou', $metadata['components']);
        self::assertContains('gateways/pagou_pix', $metadata['components']);
        self::assertContains('gateways/pagou_boleto', $metadata['components']);
        self::assertContains('gateways/pagou_creditcard', $metadata['components']);
    }

    /** @dataProvider whmcsComponentProvider */
    public function testWhmcsComponentsHaveCompletePortugueseMetadata(
        string $relativePath,
        string $type,
        string $technicalName,
        string $displayName,
    ): void {
        $root = dirname(__DIR__, 2) . '/package/modules/';
        $componentRoot = $root . $relativePath;
        $metadata = json_decode(
            (string) file_get_contents($componentRoot . '/whmcs.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('1.0', $metadata['schema']);
        self::assertSame($type, $metadata['type']);
        self::assertSame($technicalName, $metadata['name']);
        self::assertSame('MIT', $metadata['license']);
        self::assertSame('payments', $metadata['category']);
        self::assertSame($displayName, $metadata['description']['name']);
        self::assertNotSame('', trim($metadata['description']['tagline']));
        self::assertNotSame('', trim($metadata['description']['long']));
        self::assertGreaterThanOrEqual(5, count($metadata['description']['features']));
        self::assertSame('logo.png', $metadata['logo']['filename']);
        self::assertSame('Pagou', $metadata['authors'][0]['name']);
        self::assertSame('https://pagou.com.br', $metadata['authors'][0]['homepage']);
        self::assertSame('https://docs.pagou.com.br', $metadata['support']['docs_url']);
        self::assertSame('https://suporte.pagou.com.br', $metadata['support']['support_url']);
        self::assertSame('suporte@pagou.com.br', $metadata['support']['email']);

        $logoPath = $componentRoot . '/' . $metadata['logo']['filename'];
        self::assertFileExists($logoPath);
        $dimensions = getimagesize($logoPath);
        self::assertIsArray($dimensions);
        self::assertSame(500, $dimensions[0]);
        self::assertSame(204, $dimensions[1]);
        self::assertSame('image/png', $dimensions['mime']);

        $logo = (string) file_get_contents($logoPath);
        self::assertSame("\x89PNG\r\n\x1a\n", substr($logo, 0, 8));
        self::assertSame(6, ord($logo[25]), 'O logotipo precisa preservar transparência RGBA.');
    }

    public function testAllComponentsUseTheSameLogoRenderedFromTheOfficialVector(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root . '/package/brand/pagou-logo.svg';
        self::assertFileExists($source);
        self::assertStringContainsString('viewBox="0 0 1121.64 457.73"', (string) file_get_contents($source));

        $logos = [
            $root . '/package/modules/addons/pagou_payments/logo.png',
            $root . '/package/modules/gateways/pagou_pix/logo.png',
            $root . '/package/modules/gateways/pagou_boleto/logo.png',
            $root . '/package/modules/gateways/pagou_creditcard/logo.png',
        ];
        $digests = array_map(static fn (string $path): string => hash_file('sha256', $path), $logos);

        self::assertCount(1, array_unique($digests));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function whmcsComponentProvider(): iterable
    {
        yield 'addon central' => [
            'addons/pagou_payments',
            'whmcs-addons',
            'pagou_payments',
            'Pagou para WHMCS',
        ];
        yield 'Pix' => [
            'gateways/pagou_pix',
            'whmcs-gateways',
            'pagou_pix',
            'Pagou Pix',
        ];
        yield 'boleto' => [
            'gateways/pagou_boleto',
            'whmcs-gateways',
            'pagou_boleto',
            'Pagou Boleto',
        ];
        yield 'cartão de crédito' => [
            'gateways/pagou_creditcard',
            'whmcs-gateways',
            'pagou_creditcard',
            'Pagou Cartão de Crédito',
        ];
    }
}
