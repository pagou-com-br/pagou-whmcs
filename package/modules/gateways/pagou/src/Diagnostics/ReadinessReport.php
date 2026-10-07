<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Diagnostics;

final class ReadinessReport
{
    /** @param list<ReadinessCheck> $checks */
    public function __construct(public readonly array $checks)
    {
    }

    public function ready(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->required && !$check->passed) {
                return false;
            }
        }
        return true;
    }

    /** @return list<ReadinessCheck> */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, static fn (ReadinessCheck $check): bool => !$check->passed));
    }
}
