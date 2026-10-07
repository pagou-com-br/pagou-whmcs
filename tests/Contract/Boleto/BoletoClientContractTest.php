<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Contract\Boleto;

use Pagou\Whmcs\Payment\Boleto\Api\BoletoClient;
use Pagou\Whmcs\Payment\Boleto\Api\BoletoListFilter;
use Pagou\Whmcs\Payment\Boleto\Api\BoletoPayloadMapper;
use Pagou\Whmcs\Payment\Boleto\Api\BoletoResponseMapper;
use Pagou\Whmcs\Payment\Boleto\Api\CreateBoletoRequest;
use Pagou\Whmcs\Payment\Boleto\Contracts\HttpClient;
use Pagou\Whmcs\Payment\Boleto\Contracts\HttpResponse;
use PHPUnit\Framework\TestCase;

final class BoletoClientContractTest extends TestCase
{
    public function testItUsesTheCanonicalChargePathsAndAsyncDelete(): void
    {
        $transport = new BoletoRecordingTransport();
        $client = new BoletoClient($transport, new BoletoPayloadMapper(), new BoletoResponseMapper());
        $request = new CreateBoletoRequest('42', 'idem-42', 500, '2026-09-10', ['name' => 'N','document' => '123','zip' => '01001000','street' => 'R','city' => 'S','state' => 'SP','number' => '1','neighborhood' => 'C'], 'Fatura', 1, 'c');
        $created = $client->create($request);
        self::assertSame('POST', $transport->method);
        self::assertSame('/v1/charges', $transport->path);
        self::assertSame('idem-42', $transport->headers['Idempotency-Key']);
        self::assertSame(500, $created->amountCentavos);
        $client->list(new BoletoListFilter(limit: 10));
        self::assertSame('/v1/charges?limit=10&offset=0', $transport->path);
        $client->cancel('charge/a');
        self::assertSame('DELETE', $transport->method);
        self::assertSame('/v1/charges/charge%2Fa', $transport->path);
    }
}

final class BoletoRecordingTransport implements HttpClient
{
    public string $method = '';
    public string $path = '';
/** @var array<string,string> */ public array $headers = [];
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|null $json
     */
    public function request(string $method, string $path, array $headers = [], ?array $json = null): HttpResponse
    {
        $this->method = $method;
        $this->path = $path;
        $this->headers = $headers;
        if ($method === 'DELETE') {
            return new HttpResponse(204);
        }
        return new HttpResponse(201, ['id' => 'charge-1', 'amount' => 5, 'status' => 'processing']);
    }
}
