<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Contract\Http;

use Pagou\Whmcs\Infrastructure\Http\ApiClientConfig;
use PHPUnit\Framework\TestCase;

final class ApiClientConfigTest extends TestCase
{
    public function testItUsesTheOfficialUrlByDefault(): void
    {
        $config = new ApiClientConfig('test-key');

        self::assertSame('https://api.pagou.com.br', $config->normalizedBaseUrl());
    }

    public function testItRejectsAnArbitraryUrlOverride(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApiClientConfig('test-key', 'https://example.invalid');
    }

    public function testItPermitsOnlyTheInternalDevelopmentOverride(): void
    {
        $config = ApiClientConfig::fromInternalOverride('test-key', 'https://api-dev.pagou.com.br/');

        self::assertSame('https://api-dev.pagou.com.br', $config->normalizedBaseUrl());
    }
}
