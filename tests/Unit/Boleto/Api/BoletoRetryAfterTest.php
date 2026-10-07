<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Boleto\Api;

use Pagou\Whmcs\Infrastructure\Http\RetryAfter;
use Pagou\Whmcs\Payment\Boleto\Api\{BoletoApiException, BoletoClient, BoletoPayloadMapper, BoletoResponseMapper};
use Pagou\Whmcs\Payment\Boleto\Contracts\{HttpClient, HttpResponse};
use PHPUnit\Framework\TestCase;

final class BoletoRetryAfterTest extends TestCase
{
    public function testItParsesSecondsAndHttpDatesButRejectsInvalidDeadlines(): void
    {
        $now = new \DateTimeImmutable('2026-09-18T00:00:00Z');
        self::assertEquals($now->modify('+60 seconds'), RetryAfter::parse('060', $now));
        self::assertEquals($now->modify('+60 seconds'), RetryAfter::parse('Fri, 18 Sep 2026 00:01:00 GMT', $now));
        foreach ([null, '', '-1', 'invalid', 'Thu, 17 Sep 2026 00:00:00 GMT'] as $value) {
            self::assertNull(RetryAfter::parse($value, $now));
        }
    }

    public function testBoletoLookupCarriesTheProviderRateLimitDeadline(): void
    {
        $transport = new class implements HttpClient {
            public function request(string $method, string $path, array $headers = [], ?array $json = null): HttpResponse
            {
                return new HttpResponse(429, ['kind' => 'rate_limited'], headers: ['Retry-After' => '60']);
            }
        };
        $client = new BoletoClient($transport, new BoletoPayloadMapper(), new BoletoResponseMapper());
        $before = new \DateTimeImmutable('now');
        try {
            $client->get('remote');
            self::fail('Expected the provider rate limit.');
        } catch (BoletoApiException $exception) {
            self::assertSame(429, $exception->status);
            self::assertNotNull($exception->retryAt);
            self::assertGreaterThanOrEqual($before->modify('+60 seconds'), $exception->retryAt);
        }
    }
}
