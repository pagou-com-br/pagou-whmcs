<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Diagnostics;

use Pagou\Whmcs\Configuration\GatewayConfiguration;

/** Runs only module-local checks and consumes the existing Pagou API as-is. */
final class ReadinessService
{
    public function __construct(
        private readonly RuntimeEnvironmentPort $runtime,
        private readonly DatabaseReadinessPort $database,
        private readonly SchemaReadinessPort $schema,
        private readonly CronReadinessPort $cron,
        private readonly ApiReadinessPort $api,
    ) {
    }

    public function global(GatewayConfiguration $configuration): ReadinessReport
    {
        return new ReadinessReport([
            $this->timezoneCheck(),
            $this->database->verifyDatabase(),
            $this->schema->verifySchema(),
            $this->cron->verifyCron(),
            $this->api->verify($configuration->credential),
        ]);
    }

    public function pix(GatewayConfiguration $configuration): ReadinessReport
    {
        return $this->withCapability($this->global($configuration), 'pix', $configuration);
    }

    public function boleto(GatewayConfiguration $configuration): ReadinessReport
    {
        return $this->withCapability($this->global($configuration), 'boleto', $configuration);
    }

    public function card(GatewayConfiguration $configuration): ReadinessReport
    {
        return $this->withCapability($this->global($configuration), 'card', $configuration);
    }

    private function timezoneCheck(): ReadinessCheck
    {
        $web = $this->runtime->webTimezone();
        $cli = $this->runtime->cliTimezone();
        return new ReadinessCheck(
            'timezone',
            $web === $cli,
            $web === $cli
                ? 'Os fusos do PHP web e CLI estão alinhados.'
                : 'Os fusos do PHP web e CLI divergem.',
            ['web' => $web, 'cli' => $cli],
        );
    }

    private function withCapability(
        ReadinessReport $base,
        string $capability,
        GatewayConfiguration $configuration,
    ): ReadinessReport {
        $checks = $base->checks;
        $enabled = $configuration->capabilities->isEnabled($capability);
        $message = $enabled
            ? sprintf('%s está habilitado.', strtoupper($capability))
            : sprintf('%s ainda não está habilitado.', strtoupper($capability));
        $checks[] = new ReadinessCheck(
            'capability.' . $capability,
            $enabled,
            $message,
        );
        return new ReadinessReport($checks);
    }
}
