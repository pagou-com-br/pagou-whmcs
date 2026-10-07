<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

final class SystemRandomSource implements RandomSource
{
    public function int(int $minimum, int $maximum): int
    {
        return random_int($minimum, $maximum);
    }
}
