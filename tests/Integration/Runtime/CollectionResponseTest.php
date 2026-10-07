<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use Pagou\Whmcs\Infrastructure\Http\ApiClientConfig;
use Pagou\Whmcs\Infrastructure\Http\ApiException;
use Pagou\Whmcs\Infrastructure\Http\SafeCurlApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CollectionResponseTest extends TestCase
{
    #[DataProvider('collections')]
    public function testPublicCollectionsAcceptListsAndNull(string $path): void
    {
        self::assertSame([], $this->decode('null', 200, 'GET', $path));
        self::assertSame([], $this->decode('[]', 200, 'GET', $path));
        self::assertSame([['id' => 'charge-1']], $this->decode('[{"id":"charge-1"}]', 200, 'GET', $path));
        self::assertSame(['data' => []], $this->decode('{"data":[]}', 200, 'GET', $path));
    }

    public static function collections(): iterable
    {
        yield ['/v1/pix'];
        yield ['/v1/pix?limit=10&page=2'];
        yield ['/v1/charges'];
        yield ['/v1/charges?external_id=invoice-7'];
    }

    #[DataProvider('invalidResponses')]
    public function testOtherResponsesRemainStrict(string $body, int $status, string $method, string $path): void
    {
        try {
            $this->decode($body, $status, $method, $path);
            self::fail('Invalid response accepted');
        } catch (ApiException $error) {
            self::assertSame('invalid_response', $error->kind);
            self::assertSame($status, $error->statusCode);
        }
    }

    public static function invalidResponses(): iterable
    {
        foreach (['null', '[]', '[{"id":"charge-1"}]'] as $body) {
            yield [$body, 201, 'POST', '/v1/charges'];
            yield [$body, 200, 'GET', '/v1/pix/charge-1'];
            yield [$body, 200, 'GET', '/v1/charges/charge-1'];
            yield [$body, 200, 'GET', '/v1/creditcard/charges'];
            yield [$body, 200, 'DELETE', '/v1/pix'];
            yield [$body, 429, 'GET', '/v1/charges'];
        }
        foreach (['[null]', '[1]', '[["id"]]', '[{}]', '{}', 'true', '12', '"text"', '{broken'] as $body) {
            yield [$body, 200, 'GET', '/v1/pix'];
        }
    }

    public function testIndividualObjectsErrorsAndEmptyCancellationArePreserved(): void
    {
        self::assertSame(['id' => 'charge-1'], $this->decode('{"id":"charge-1"}', 201, 'POST', '/v1/charges'));
        self::assertSame(['message' => 'Rate limit'], $this->decode('{"message":"Rate limit"}', 429, 'GET', '/v1/pix'));
        self::assertSame([], $this->decode('', 204, 'DELETE', '/v1/charges/charge-1'));
    }

    private function decode(string $body, int $status, string $method, string $path): array
    {
        $client = new SafeCurlApiClient(new ApiClientConfig('synthetic-test-key'));
        return (new \ReflectionMethod($client, 'decodeJson'))->invoke($client, $body, $status, $method, $path);
    }
}
