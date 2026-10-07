<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Contracts;

/** Minimal transport boundary. The implementation lives in the module infrastructure. */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     */
    public function request(string $method, string $path, array $headers = [], ?array $json = null): HttpResponse;
}
