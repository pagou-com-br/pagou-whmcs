<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Application;

use Pagou\Whmcs\Payment\Boleto\Contracts\HttpClient;
use Pagou\Whmcs\Payment\Boleto\Contracts\PdfStorage;

/** Fetches existing provider PDFs server-side. It never exposes a provider URL to a browser. */
final class SecurePdfFetcher
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly PdfStorage $storage,
        private readonly PdfFetchPolicy $policy,
    ) {
    }

    public function fetchAndCache(string $remoteId, string $url): string
    {
        if (!$this->policy->accepts($url)) {
            throw new \DomainException('Boleto PDF URL is not an allowed HTTPS provider endpoint.');
        }

        $response = $this->http->request('GET', $url, ['Accept' => 'application/pdf']);
        if (!$response->isSuccess()) {
            throw new \RuntimeException('Unable to fetch boleto PDF.');
        }
        $mime = strtolower(trim((string) $response->header('Content-Type')));
        $mime = trim(explode(';', $mime, 2)[0]);
        if ($mime !== 'application/pdf' || !str_starts_with($response->body, '%PDF-')) {
            throw new \UnexpectedValueException('Provider response is not a PDF.');
        }
        if (strlen($response->body) > $this->policy->maximumBytes()) {
            throw new \LengthException('Boleto PDF exceeds the configured size limit.');
        }

        $key = 'boleto/' . rawurlencode($remoteId) . '.pdf';
        if ($this->storage->has($key)) {
            return $key;
        }

        return $this->storage->put($key, $response->body, 'application/pdf');
    }
}
