<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Diagnostics;

interface CronReadinessPort
{
    public function verifyCron(): ReadinessCheck;
}
