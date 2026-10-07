<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Boleto\Api;

use Pagou\Whmcs\Payment\Boleto\Api\BoletoApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoletoApiExceptionTest extends TestCase
{
    #[DataProvider('unknownOutcomes')]
    public function testItProtectsUnknownRemoteOutcomes(int $status, string $kind): void
    {
        self::assertTrue((new BoletoApiException('failure', $status, $kind))->outcomeUnknown());
    }

    /** @return iterable<string, array{int, string}> */
    public static function unknownOutcomes(): iterable
    {
        yield 'transport before status' => [0, 'transport'];
        yield 'request timeout' => [408, ''];
        yield 'rate limit' => [429, 'remote_failure'];
        yield 'server failure' => [503, 'remote_failure'];
        yield 'invalid response after request' => [200, 'invalid_response'];
    }

    public function testAValidationRejectionIsDefinitive(): void
    {
        self::assertFalse((new BoletoApiException('failure', 422, 'remote_failure'))->outcomeUnknown());
    }
}
