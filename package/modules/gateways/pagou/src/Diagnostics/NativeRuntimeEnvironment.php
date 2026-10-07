<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Diagnostics;

final class NativeRuntimeEnvironment implements RuntimeEnvironmentPort
{
    public function webTimezone(): string
    {
        return date_default_timezone_get();
    }

    public function cliTimezone(): string
    {
        $binary = escapeshellcmd(PHP_BINARY);
        $result = @shell_exec($binary . ' -r ' . escapeshellarg('echo date_default_timezone_get();'));
        return is_string($result) && trim($result) !== '' ? trim($result) : $this->webTimezone();
    }
}
