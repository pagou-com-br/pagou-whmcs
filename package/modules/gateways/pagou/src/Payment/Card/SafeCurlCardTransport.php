<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card;

use Pagou\Whmcs\Infrastructure\Http\SafeCurlApiClient;

final class SafeCurlCardTransport implements CardTransport
{
    public function __construct(private readonly SafeCurlApiClient $client)
    {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $headers = [], ?array $body = null): array
    {
        $idempotencyKey = $headers['Idempotency-Key'] ?? null;
        return $this->client->request($method, $path, $body, $idempotencyKey)->json;
    }
}
