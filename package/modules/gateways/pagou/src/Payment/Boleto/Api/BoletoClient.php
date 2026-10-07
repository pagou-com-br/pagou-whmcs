<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Api;

use Pagou\Whmcs\Payment\Boleto\Contracts\HttpClient;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoCharge;

final class BoletoClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly BoletoPayloadMapper $payloads,
        private readonly BoletoResponseMapper $responses,
    ) {
    }

    public function create(CreateBoletoRequest $request): BoletoCharge
    {
        return $this->map($this->http->request('POST', '/v1/charges', [
            'Idempotency-Key' => $request->idempotencyKey,
        ], $this->payloads->create($request)));
    }

    public function get(string $remoteId): BoletoCharge
    {
        return $this->map($this->http->request('GET', '/v1/charges/' . rawurlencode($remoteId)));
    }

    /** @return list<BoletoCharge> */
    public function list(BoletoListFilter $filter): array
    {
        $response = $this->http->request('GET', '/v1/charges?' . http_build_query($filter->query()));
        if (!$response->isSuccess()) {
            throw $this->failure('Unable to list Pagou boleto charges.', $response);
        }
        $items = $response->json['data'] ?? $response->json;
        if (!is_array($items)) {
            throw new \UnexpectedValueException('Pagou boleto list response is invalid.');
        }
        $charges = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $charges[] = $this->responses->charge($item);
            }
        }
        return $charges;
    }

    public function cancel(string $remoteId): void
    {
        $response = $this->http->request('DELETE', '/v1/charges/' . rawurlencode($remoteId));
        if ($response->status !== 204) {
            throw $this->failure('Unable to request Pagou boleto cancellation.', $response);
        }
    }

    private function map(\Pagou\Whmcs\Payment\Boleto\Contracts\HttpResponse $response): BoletoCharge
    {
        if (!$response->isSuccess()) {
            throw $this->failure('Pagou boleto request failed.', $response);
        }
        return $this->responses->charge($response->json);
    }

    private function failure(
        string $message,
        \Pagou\Whmcs\Payment\Boleto\Contracts\HttpResponse $response,
    ): BoletoApiException {
        $kind = is_string($response->json['kind'] ?? null) ? $response->json['kind'] : '';

        return new BoletoApiException($message, $response->status, $kind, retryAt: \Pagou\Whmcs\Infrastructure\Http\RetryAfter::parse($response->header('Retry-After')));
    }
}
