<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Contracts;

/** Transport boundary. Implementations must not follow redirects for PDF downloads. */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     */
    public function request(string $method, string $path, array $headers = [], ?array $json = null): HttpResponse;
}
