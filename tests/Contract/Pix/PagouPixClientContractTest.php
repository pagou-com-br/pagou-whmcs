<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Contract\Pix;

use Pagou\Whmcs\Payment\Pix\Contracts\HttpClient;
use Pagou\Whmcs\Payment\Pix\Contracts\HttpResponse;
use Pagou\Whmcs\Payment\Pix\PagouPixClient;
use PHPUnit\Framework\TestCase;

final class PagouPixClientContractTest extends TestCase
{
    public function testCreationUsesApiKeyAndIdempotencyHeader(): void
    {
        $client = new RecordingHttpClient();
        (new PagouPixClient($client, 'private-key'))->create(['amount' => '10.00'], 'idem-1');

        self::assertSame('POST', $client->method);
        self::assertSame('/v1/pix', $client->path);
        self::assertSame('private-key', $client->headers['X-API-KEY']);
        self::assertSame('idem-1', $client->headers['Idempotency-Key']);
    }

    public function testDueCancelAndRefundUseCanonicalPaths(): void
    {
        $client = new RecordingHttpClient();
        $api = new PagouPixClient($client, 'key');
        $api->createDue(['amount' => '1.00'], 'idem-due');
        self::assertSame('/v1/pix/due', $client->path);
        $api->cancel('pix/a', 'idem-cancel');
        self::assertSame('/v1/pix/pix%2Fa', $client->path);
        $api->refund('pix/a', ['reason' => 1, 'amount' => 1.0, 'description' => 'Teste'], 'idem-refund');
        self::assertSame('/v1/pix/pix%2Fa/refund', $client->path);
    }
}

final class RecordingHttpClient implements HttpClient
{
    public string $method = '';
    public string $path = '';
    /** @var array<string, string> */
    public array $headers = [];

    public function request(string $method, string $path, array $headers = [], ?array $json = null): HttpResponse
    {
        $this->method = $method;
        $this->path = $path;
        $this->headers = $headers;
        return new HttpResponse(200, ['id' => 'pix-1', 'amount' => '1.00']);
    }
}
