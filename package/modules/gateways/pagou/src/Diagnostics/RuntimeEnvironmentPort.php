<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Diagnostics;

interface RuntimeEnvironmentPort
{
    public function webTimezone(): string;

    public function cliTimezone(): string;
}
