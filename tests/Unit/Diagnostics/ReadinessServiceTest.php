<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Diagnostics;

use Pagou\Whmcs\Configuration\CapabilityRegistry;
use Pagou\Whmcs\Configuration\GatewayConfiguration;
use Pagou\Whmcs\Configuration\PagouCredential;
use Pagou\Whmcs\Diagnostics\ApiReadinessPort;
use Pagou\Whmcs\Diagnostics\CronReadinessPort;
use Pagou\Whmcs\Diagnostics\DatabaseReadinessPort;
use Pagou\Whmcs\Diagnostics\ReadinessCheck;
use Pagou\Whmcs\Diagnostics\ReadinessService;
use Pagou\Whmcs\Diagnostics\RuntimeEnvironmentPort;
use Pagou\Whmcs\Diagnostics\SchemaReadinessPort;
use PHPUnit\Framework\TestCase;

final class ReadinessServiceTest extends TestCase
{
    public function testGlobalReadinessIncludesAllInfrastructurePorts(): void
    {
        $service = $this->service('America/Sao_Paulo', 'America/Sao_Paulo');
        $report = $service->global($this->configuration());

        self::assertTrue($report->ready());
        self::assertSame(
            ['timezone', 'database', 'schema', 'cron', 'api'],
            array_map(static fn (ReadinessCheck $check): string => $check->name, $report->checks),
        );
    }

    public function testTimezoneDivergenceBlocksReadiness(): void
    {
        $report = $this->service('America/Sao_Paulo', 'UTC')->pix($this->configuration());

        self::assertFalse($report->ready());
        self::assertSame('timezone', $report->failures()[0]->name);
    }

    public function testCardReadinessRemainsBlockedUntilCardCapabilityIsEnabled(): void
    {
        $report = $this->service('UTC', 'UTC')->card($this->configuration());

        self::assertFalse($report->ready());
        self::assertSame('capability.card', $report->failures()[0]->name);
    }

    private function configuration(): GatewayConfiguration
    {
        return new GatewayConfiguration(
            PagouCredential::fromSettings(['api_key' => 'secret'], []),
            capabilities: new CapabilityRegistry(),
        );
    }

    private function service(string $webTimezone, string $cliTimezone): ReadinessService
    {
        $runtime = new class ($webTimezone, $cliTimezone) implements RuntimeEnvironmentPort {
            public function __construct(private string $web, private string $cli)
            {
            }

            public function webTimezone(): string
            {
                return $this->web;
            }

            public function cliTimezone(): string
            {
                return $this->cli;
            }
        };
        $database = new class implements DatabaseReadinessPort {
            public function verifyDatabase(): ReadinessCheck
            {
                return new ReadinessCheck('database', true, 'ok');
            }
        };
        $schema = new class implements SchemaReadinessPort {
            public function verifySchema(): ReadinessCheck
            {
                return new ReadinessCheck('schema', true, 'ok');
            }
        };
        $cron = new class implements CronReadinessPort {
            public function verifyCron(): ReadinessCheck
            {
                return new ReadinessCheck('cron', true, 'ok');
            }
        };
        $api = new class implements ApiReadinessPort {
            public function verify(PagouCredential $credential): ReadinessCheck
            {
                return new ReadinessCheck(
                    'api',
                    $credential->fingerprint() !== 'sha256:ausente',
                    'ok',
                );
            }
        };

        return new ReadinessService($runtime, $database, $schema, $cron, $api);
    }
}
