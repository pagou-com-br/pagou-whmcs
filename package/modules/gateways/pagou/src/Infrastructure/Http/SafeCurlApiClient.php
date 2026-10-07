<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Http;

/**
 * Small cURL-only client. It never follows redirects and only targets the
 * allowlisted base URL held by ApiClientConfig.
 */
final class SafeCurlApiClient
{
    /** @param \Closure(float):void|null $timingObserver */
    public function __construct(private readonly ApiClientConfig $config, private readonly ?\Closure $timingObserver = null)
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('A extensão cURL é obrigatória para o módulo Pagou.');
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return HttpResponse
     */
    public function request(string $method, string $path, ?array $payload = null, ?string $idempotencyKey = null): HttpResponse
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new \InvalidArgumentException('Método HTTP não permitido.');
        }

        $url = $this->buildUrl($path);
        $body = $payload === null ? null : $this->encodePayload($payload);
        $headers = [
            'Accept: application/json',
            'X-API-KEY: ' . $this->config->apiKey,
            'User-Agent: pagou-whmcs/1.0',
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($body);
        }
        if ($idempotencyKey !== null && preg_match('/^[A-Za-z0-9._:-]{8,200}$/', $idempotencyKey) === 1) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $responseHeaders = [];
        $bytes = 0;
        $responseBody = '';
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ApiException('Não foi possível inicializar a conexão com a Pagou.', 'transport');
        }

        try {
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => $this->config->connectTimeoutSeconds,
                CURLOPT_TIMEOUT => $this->config->timeoutSeconds,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $length = strlen($line);
                    $separator = strpos($line, ':');
                    if ($separator !== false) {
                        $responseHeaders[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
                    }
                    return $length;
                },
                CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$bytes, &$responseBody): int {
                    $bytes += strlen($chunk);
                    if ($bytes > $this->config->maxResponseBytes) {
                        return 0;
                    }
                    $responseBody .= $chunk;
                    return strlen($chunk);
                },
            ]);

            $executed = curl_exec($handle);
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($executed === false) {
                $kind = $bytes > $this->config->maxResponseBytes ? 'response_too_large' : 'transport';
                throw new ApiException('Falha segura na comunicação com a Pagou.', $kind, $status, ['curl_errno' => $errno]);
            }
        } finally {
            $milliseconds = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
            curl_close($handle);
            if ($this->timingObserver !== null) {
                try {
                    ($this->timingObserver)($milliseconds);
                } catch (\Throwable) {
                    // Telemetry cannot turn a successful remote mutation into a failure.
                }
            }
        }

        try {
            $json = $this->decodeJson($responseBody, $status, $method, $path);
        } catch (ApiException $exception) {
            throw new ApiException(
                $exception->getMessage(),
                $exception->kind,
                $status,
                $exception->context + ['retry_after' => $responseHeaders['retry-after'] ?? null],
                $exception
            );
        }
        $requestId = $responseHeaders['x-request-id'] ?? $responseHeaders['request-id'] ?? '';
        if ($status < 200 || $status >= 300) {
            throw $this->mapApiError($status, $json, $requestId, $responseHeaders['retry-after'] ?? null);
        }

        return new HttpResponse($status, $json, $requestId);
    }

    private function buildUrl(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, '\\') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('O path da API é inválido.');
        }
        if (preg_match('#^//|://#', $path) === 1) {
            throw new \InvalidArgumentException('O path não pode substituir o host da API.');
        }
        return $this->config->normalizedBaseUrl() . $path;
    }

    /** @param array<string, mixed> $payload */
    private function encodePayload(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new ApiException('O payload da Pagou não pode ser serializado.', 'invalid_request', 0, [], $exception);
        }
    }

    /** @return array<array-key, mixed> */
    private function decodeJson(string $body, int $status, string $method, string $path): array
    {
        if ($body === '') {
            return [];
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ApiException('A Pagou retornou uma resposta JSON inválida.', 'invalid_response', $status, [], $exception);
        }
        // Only these public collection GETs return a bare list or JSON null.
        // Errors, mutations, individual charges and card envelopes remain strict.
        $collection = $status === 200 && $method === 'GET'
            && in_array(parse_url($path, PHP_URL_PATH), ['/v1/pix', '/v1/charges'], true);
        if ($collection && $decoded === null) {
            return [];
        }
        if ($collection && is_array($decoded) && array_is_list($decoded) && str_starts_with(ltrim($body), '[')) {
            foreach ($decoded as $item) {
                if (!is_array($item) || array_is_list($item)) {
                    throw new ApiException('A Pagou retornou um item de listagem inválido.', 'invalid_response', $status);
                }
            }
            return $decoded;
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException('A Pagou retornou uma estrutura JSON inesperada.', 'invalid_response', $status);
        }
        return $decoded;
    }

    /** @param array<string, mixed> $json */
    private function mapApiError(int $status, array $json, string $requestId, ?string $retryAfter = null): ApiException
    {
        $message = $this->safeErrorMessage($json) ?? 'A Pagou recusou a solicitação.';
        $kind = match (true) {
            $status === 401 || $status === 403 => 'authentication',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 422 => 'validation',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'remote_failure',
            default => 'remote_rejection',
        };
        $code = is_string($json['code'] ?? null) && preg_match('/^[a-z0-9_]{1,100}$/', $json['code']) === 1
            ? $json['code'] : '';
        return new ApiException($message, $kind, $status, ['request_id' => $requestId, 'code' => $code, 'retry_after' => $retryAfter]);
    }

    /** @param array<string, mixed> $json */
    private function safeErrorMessage(array $json): ?string
    {
        foreach (['message', 'error', 'detail'] as $field) {
            if (isset($json[$field]) && is_string($json[$field])) {
                return mb_substr(trim($json[$field]), 0, 300);
            }
        }
        return null;
    }
}
