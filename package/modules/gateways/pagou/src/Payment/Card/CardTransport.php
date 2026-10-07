<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card;

/** Small boundary that keeps HTTP and WHMCS dependencies out of card logic. */
interface CardTransport
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $headers = [], ?array $body = null): array;
}
