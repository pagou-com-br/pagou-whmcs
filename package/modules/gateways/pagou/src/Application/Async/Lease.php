<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Async;

final class Lease
{
    public function __construct(
        public readonly OperationJob $job,
        public readonly string $token,
        public readonly \DateTimeImmutable $expiresAt,
    ) {
    }
}
