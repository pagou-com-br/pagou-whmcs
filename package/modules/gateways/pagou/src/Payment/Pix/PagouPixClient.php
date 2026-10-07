<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix;

use Pagou\Whmcs\Payment\Pix\Contracts\HttpClient;
use Pagou\Whmcs\Payment\Pix\Contracts\HttpResponse;
use Pagou\Whmcs\Payment\Pix\Exception\PixApiException;

final class PagouPixClient
{
    public function __construct(private readonly HttpClient $http, private readonly string $apiKey)
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('A Pagou API key is required.');
        }
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload, string $idempotencyKey): HttpResponse
    {
        return $this->send('POST', '/v1/pix', $payload, $idempotencyKey);
    }

    /** @param array<string, mixed> $payload */
    public function createDue(array $payload, string $idempotencyKey): HttpResponse
    {
        return $this->send('POST', '/v1/pix/due', $payload, $idempotencyKey);
    }

    public function get(string $pixId): HttpResponse
    {
        return $this->send('GET', '/v1/pix/' . rawurlencode($pixId));
    }

    /**
     * @param array<string, scalar> $query
     * @return array<array-key, mixed>
     */
    public function list(array $query = []): array
    {
        $path = '/v1/pix';
        if ($query !== []) {
            $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $this->send('GET', $path)->json;
    }

    public function cancel(string $pixId, string $idempotencyKey): HttpResponse
    {
        return $this->send('DELETE', '/v1/pix/' . rawurlencode($pixId), null, $idempotencyKey);
    }

    /** @param array<string, mixed> $payload */
    public function refund(string $pixId, array $payload, string $idempotencyKey): HttpResponse
    {
        return $this->send('DELETE', '/v1/pix/' . rawurlencode($pixId) . '/refund', $payload, $idempotencyKey);
    }

    /** @param array<string, mixed>|null $payload */
    private function send(string $method, string $path, ?array $payload = null, ?string $idempotencyKey = null): HttpResponse
    {
        $headers = ['X-API-KEY' => $this->apiKey, 'Accept' => 'application/json'];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->http->request($method, $path, $headers, $payload);
        if (!$response->isSuccess()) {
            throw new PixApiException(
                (string) ($response->json['message'] ?? $response->json['error'] ?? 'Pagou Pix request failed.'),
                $response->status,
                $response->json,
            );
        }
        return $response;
    }
}
