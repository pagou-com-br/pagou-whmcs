<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

final class SystemAsyncClock implements AsyncClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
