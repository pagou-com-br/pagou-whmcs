<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Concurrency\Async;

use Pagou\Whmcs\Application\Async\AsyncClock;

final class MutableAsyncClock implements AsyncClock
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
