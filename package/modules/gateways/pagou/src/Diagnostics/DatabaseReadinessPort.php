<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Diagnostics;

interface DatabaseReadinessPort
{
    public function verifyDatabase(): ReadinessCheck;
}
