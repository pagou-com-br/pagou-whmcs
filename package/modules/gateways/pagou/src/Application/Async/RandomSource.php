<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

interface RandomSource
{
    /** Returns an integer in the inclusive range. */
    public function int(int $minimum, int $maximum): int;
}
