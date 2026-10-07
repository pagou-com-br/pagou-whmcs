<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Http;

final class HttpResponse
{
    /** @param array<array-key, mixed> $json */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $json,
        public readonly string $requestId = '',
    ) {
    }
}
