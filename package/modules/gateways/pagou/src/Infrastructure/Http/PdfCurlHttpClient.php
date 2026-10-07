<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Http;

use Pagou\Whmcs\Payment\Boleto\Contracts\HttpClient;
use Pagou\Whmcs\Payment\Boleto\Contracts\HttpResponse;

final class PdfCurlHttpClient implements HttpClient
{
    /** @param list<string> $allowedHosts */
    public function __construct(private readonly array $allowedHosts, private readonly int $maximumBytes = 8_388_608)
    {
    }

    public function request(string $method, string $path, array $headers = [], ?array $json = null): HttpResponse
    {
        if ($method !== 'GET' || $json !== null || !$this->isAllowed($path)) {
            throw new \InvalidArgumentException('Invalid boleto PDF request.');
        }
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('A extensão cURL é obrigatória para obter o PDF do boleto.');
        }

        $body = '';
        $bytes = 0;
        $responseHeaders = [];
        $handle = curl_init($path);
        if ($handle === false) {
            throw new \RuntimeException('Unable to initialize boleto PDF transport.');
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => ['Accept: application/pdf'],
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $length = strlen($line);
                    $separator = strpos($line, ':');
                    if ($separator !== false) {
                        $responseHeaders[trim(substr($line, 0, $separator))] = trim(substr($line, $separator + 1));
                    }

                    return $length;
                },
                CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, &$bytes): int {
                    $bytes += strlen($chunk);
                    if ($bytes > $this->maximumBytes) {
                        return 0;
                    }
                    $body .= $chunk;

                    return strlen($chunk);
                },
            ]);
            $success = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($success === false) {
                throw new \RuntimeException('Unable to download the boleto PDF safely.');
            }
        } finally {
            curl_close($handle);
        }

        return new HttpResponse($status, [], $body, $responseHeaders);
    }

    private function isAllowed(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return $scheme === 'https' && is_string($host) && in_array(strtolower($host), $this->allowedHosts, true);
    }
}
