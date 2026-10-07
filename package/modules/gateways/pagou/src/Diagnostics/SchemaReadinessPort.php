<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Diagnostics;

interface SchemaReadinessPort
{
    public function verifySchema(): ReadinessCheck;
}
