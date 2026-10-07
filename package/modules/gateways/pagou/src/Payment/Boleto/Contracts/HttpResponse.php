<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Contracts;

final class HttpResponse
{
    /**
     * @param array<array-key, mixed> $json
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $json = [],
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
