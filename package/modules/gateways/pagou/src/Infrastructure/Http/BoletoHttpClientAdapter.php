<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Http;

use Pagou\Whmcs\Payment\Boleto\Contracts\HttpClient;
use Pagou\Whmcs\Payment\Boleto\Contracts\HttpResponse;

final class BoletoHttpClientAdapter implements HttpClient
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
            ], headers: is_string($exception->context['retry_after'] ?? null)
                ? ['Retry-After' => $exception->context['retry_after']] : []);
        }
    }
}
