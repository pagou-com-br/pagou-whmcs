<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Http;

use Pagou\Whmcs\Payment\Pix\Contracts\HttpClient;
use Pagou\Whmcs\Payment\Pix\Contracts\HttpResponse;

final class PixHttpClientAdapter implements HttpClient
{
    public function __construct(private readonly SafeCurlApiClient $client)
    {
    }

    public function request(string $method, string $path, array $headers = [], ?array $json = null): HttpResponse
    {
        try {
            $response = $this->client->request($method, $path, $json, $headers['Idempotency-Key'] ?? null);

            return new HttpResponse($response->statusCode, $response->json);
        } catch (ApiException $exception) {
            return new HttpResponse($exception->statusCode, [
                'message' => $exception->getMessage(),
                'kind' => $exception->kind,
            ]);
        }
    }
}
