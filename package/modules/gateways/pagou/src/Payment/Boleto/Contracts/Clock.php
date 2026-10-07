<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Contracts;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
