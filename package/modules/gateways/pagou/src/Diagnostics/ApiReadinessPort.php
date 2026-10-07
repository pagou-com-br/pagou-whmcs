<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Diagnostics;

use Pagou\Whmcs\Configuration\PagouCredential;

interface ApiReadinessPort
{
    public function verify(PagouCredential $credential): ReadinessCheck;
}
