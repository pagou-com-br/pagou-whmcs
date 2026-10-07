<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Boleto;

use Pagou\Whmcs\Payment\Boleto\Application\PdfFetchPolicy;
use Pagou\Whmcs\Payment\Boleto\Application\SecurePdfFetcher;
use Pagou\Whmcs\Payment\Boleto\Contracts\HttpClient;
use Pagou\Whmcs\Payment\Boleto\Contracts\HttpResponse;
use Pagou\Whmcs\Payment\Boleto\Contracts\PdfStorage;
use PHPUnit\Framework\TestCase;

final class SecurePdfFetcherTest extends TestCase
{
    public function testItFetchesOnlyAllowedHttpsPdfAndStoresLocally(): void
    {
        $storage = new BoletoMemoryPdfStorage();
        $fetcher = new SecurePdfFetcher(new BoletoPdfHttpClient(new HttpResponse(200, [], '%PDF-1.7 test', ['Content-Type' => 'application/pdf'])), $storage, new PdfFetchPolicy(['fatura.pagou.com.br']));
        $key = $fetcher->fetchAndCache('charge-1', 'https://fatura.pagou.com.br/boleto/charge-1.pdf');
        self::assertSame('boleto/charge-1.pdf', $key);
        self::assertSame('%PDF-1.7 test', $storage->content);
    }

    public function testItRejectsUntrustedHostBeforeAnyRequest(): void
    {
        $http = new BoletoPdfHttpClient(new HttpResponse(200, [], '%PDF-1.7', ['Content-Type' => 'application/pdf']));
        $fetcher = new SecurePdfFetcher($http, new BoletoMemoryPdfStorage(), new PdfFetchPolicy(['fatura.pagou.com.br']));
        $this->expectException(\DomainException::class);
        $fetcher->fetchAndCache('charge-1', 'https://evil.example/file.pdf');
    }

    public function testItRejectsHtmlPretendingToBeAPdf(): void
    {
        $fetcher = new SecurePdfFetcher(new BoletoPdfHttpClient(new HttpResponse(200, [], '<html>', ['Content-Type' => 'text/html'])), new BoletoMemoryPdfStorage(), new PdfFetchPolicy(['fatura.pagou.com.br']));
        $this->expectException(\UnexpectedValueException::class);
        $fetcher->fetchAndCache('charge-1', 'https://fatura.pagou.com.br/file.pdf');
    }
}

final class BoletoPdfHttpClient implements HttpClient
{
    public int $calls = 0;
    public function __construct(private readonly HttpResponse $response)
    {
    }
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|null $json
     */
    public function request(string $method, string $path, array $headers = [], ?array $json = null): HttpResponse
    {
        $this->calls++;
        return $this->response;
    }
}

final class BoletoMemoryPdfStorage implements PdfStorage
{
    public string $content = '';
    /** @var array<string, bool> */ private array $keys = [];
    public function put(string $key, string $contents, string $mimeType): string
    {
        $this->keys[$key] = true;
        $this->content = $contents;
        return $key;
    }
    public function has(string $key): bool
    {
        return isset($this->keys[$key]);
    }
}
