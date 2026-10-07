<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Security\Card;

use InvalidArgumentException;
use Pagou\Whmcs\Payment\Card\CardApiClient;
use Pagou\Whmcs\Payment\Card\CardTransport;
use Pagou\Whmcs\Payment\Card\RemoteInput\RemoteInputPolicy;
use Pagou\Whmcs\Payment\Card\RemoteInput\RemoteInputEntrypoint;
use Pagou\Whmcs\Payment\Card\RemoteInput\RemoteInputSession;
use Pagou\Whmcs\Payment\Card\ThreeDs\ThreeDsReturnValidator;
use PHPUnit\Framework\TestCase;

final class RemoteInputSecurityTest extends TestCase
{
    public function testRawCardDataIsRejectedByBackend(): void
    {
        $transport = new class implements CardTransport {
            public function request(
                string $method,
                string $path,
                array $headers = [],
                ?array $body = null,
            ): array {
                return [];
            }
        };
        $api = new CardApiClient($transport);
        $this->expectException(InvalidArgumentException::class);
        $api->createCustomer(['card_number' => '4111111111111111'], \Pagou\Whmcs\Payment\Card\IdempotencyKey::create('customer', '1'));
    }

    public function testRemoteInputHostMustBeAllowlistedAnd3dsReturnMustBeSigned(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RemoteInputPolicy('https://evil.example/card', ['input.pagou.com.br']);
    }

    public function testThreeDsSignatureCannotBeReusedForAnotherAttempt(): void
    {
        $validator = new ThreeDsReturnValidator(str_repeat('x', 32));
        self::assertTrue($validator->isValid('charge-1', 'attempt-1', $validator->sign('charge-1', 'attempt-1')));
        self::assertFalse($validator->isValid('charge-1', 'attempt-2', $validator->sign('charge-1', 'attempt-1')));
    }

    public function testMissingInternalRemoteInputConfigurationFailsClosed(): void
    {
        $entrypoint = RemoteInputEntrypoint::fromEnvironment([]);
        $session = RemoteInputSession::issue('session-1', 'customer-1', 'https://whmcs.example/return');
        self::assertFalse($entrypoint->begin($session)['enabled']);
        self::assertFalse($entrypoint->acceptsThreeDsReturn($session, ['session_id' => 'session-1', 'signature' => 'anything']));
    }
}
