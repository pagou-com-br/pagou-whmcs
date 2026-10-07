<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

interface AsyncClock
{
    public function now(): \DateTimeImmutable;
}
