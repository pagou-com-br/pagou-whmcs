<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Diagnostics;

final class ReadinessCheck
{
    /** @param array<string, scalar|null> $context */
    public function __construct(
        public readonly string $name,
        public readonly bool $passed,
        public readonly string $message,
        public readonly array $context = [],
        public readonly bool $required = true,
    ) {
    }
}
