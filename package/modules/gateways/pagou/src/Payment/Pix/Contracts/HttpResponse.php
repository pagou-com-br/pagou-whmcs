<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Contracts;

final class HttpResponse
{
    /** @param array<array-key, mixed> $json */
    public function __construct(public readonly int $status, public readonly array $json = [])
    {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
