<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Contract\Card;

use Pagou\Whmcs\Payment\Card\CardApiClient;
use Pagou\Whmcs\Payment\Card\CardTransport;
use Pagou\Whmcs\Payment\Card\Dto\CardChargeRequest;
use Pagou\Whmcs\Payment\Card\IdempotencyKey;
use PHPUnit\Framework\TestCase;

final class CardApiClientTest extends TestCase
{
    public function testChargeUsesCurrentContractPathAndIdempotencyKey(): void
    {
        $transport = new class implements CardTransport {
            /** @var array{string,string,array<string,string>,array<string,mixed>|null}|null */ public ?array $last = null;
            public function request(string $method, string $path, array $headers = [], ?array $body = null): array
            {
                $this->last = [$method, $path, $headers, $body];
                return ['id' => 'charge-1', 'status' => 'captured', 'value' => 1999];
            }
        };
        $client = new CardApiClient($transport);
        $charge = $client->createCharge(new CardChargeRequest(
            1999,
            'customer-1',
            'opaque-card-ref',
            'invoice:1',
            3,
            true,
            [
                'three_ds' => [
                    'internal_emv' => [
                        'ip' => '203.0.113.10',
                        'user_agent' => 'Browser test',
                        'redirect_url_3ds' => 'https://merchant.example/return',
                        'device' => [
                            'color_depth' => '24',
                            'device_type_3ds' => 'browser',
                            'java_enabled' => false,
                            'language' => 'pt-BR',
                            'screen_height' => '1080',
                            'screen_width' => '1920',
                            'time_zone_offset' => '-180',
                            'unexpected' => 'discarded',
                        ],
                        'unexpected' => 'discarded',
                    ],
                ],
            ],
            '2026-08-23',
            'PAGOU LOJA',
        ), IdempotencyKey::create('charge', 'invoice:1'));
        self::assertSame('charge-1', $charge->id);
        self::assertSame(1999, $charge->amountCentavos);
        self::assertSame('POST', $transport->last[0]);
        self::assertSame('/v1/creditcard/charges', $transport->last[1]);
        self::assertArrayHasKey('Idempotency-Key', $transport->last[2]);
        self::assertSame('opaque-card-ref', $transport->last[3]['card_id']);
        self::assertSame(1999, $transport->last[3]['value']);
        self::assertSame(3, $transport->last[3]['installments']);
        self::assertTrue($transport->last[3]['installments_capture']);
        self::assertSame('2026-08-23', $transport->last[3]['payday']);
        self::assertSame('PAGOU LOJA', $transport->last[3]['soft_descriptor']);
        self::assertSame(
            [
                'ip' => '203.0.113.10',
                'user_agent' => 'Browser test',
                'redirect_url_3ds' => 'https://merchant.example/return',
                'device' => [
                    'color_depth' => '24',
                    'device_type_3ds' => 'browser',
                    'java_enabled' => false,
                    'language' => 'pt-BR',
                    'screen_height' => '1080',
                    'screen_width' => '1920',
                    'time_zone_offset' => '-180',
                ],
            ],
            $transport->last[3]['three_ds']['internal_emv'],
        );
        self::assertArrayNotHasKey('unexpected', $transport->last[3]['three_ds']['internal_emv']);
        self::assertStringNotContainsString('411111', json_encode($transport->last[3], JSON_THROW_ON_ERROR));
    }

    public function testCardCreationUsesProviderTokenAndYearMonthExpiration(): void
    {
        $transport = new class implements CardTransport {
            /** @var array{string,string,array<string,string>,array<string,mixed>|null}|null */ public ?array $last = null;
            public function request(string $method, string $path, array $headers = [], ?array $body = null): array
            {
                $this->last = [$method, $path, $headers, $body];
                return [
                    'id' => 'card-1',
                    'customer_id' => 'customer-1',
                    'brand' => ['name' => 'Visa'],
                    'last4' => '4242',
                    'expires_at' => '2030-12',
                ];
            }
        };

        $card = (new CardApiClient($transport))->createCard(
            'customer-1',
            ['token' => 'provider-token', 'holder' => 'Cliente Teste', 'expires_at' => '2030-12'],
            IdempotencyKey::create('card', 'customer-1'),
        );

        self::assertSame('/v1/creditcard/cards', $transport->last[1]);
        self::assertSame('provider-token', $transport->last[3]['card_token']);
        self::assertSame('2030-12', $transport->last[3]['expires_at']);
        self::assertSame(12, $card->expiryMonth);
        self::assertSame(2030, $card->expiryYear);
        self::assertSame('4242', $card->lastFour);
        self::assertArrayNotHasKey('token', $transport->last[3]);
    }

    public function testCaptureReverseRetryUsePutAndCancelUsesDelete(): void
    {
        $transport = new class implements CardTransport {
            /** @var list<array{string,string}> */ public array $calls = [];
            public function request(string $method, string $path, array $headers = [], ?array $body = null): array
            {
                $this->calls[] = [$method, $path];
                return ['id' => 'charge-1', 'status' => 'pending', 'value' => 1];
            }
        };
        $client = new CardApiClient($transport);
        $key = IdempotencyKey::create('charge', '1');
        $client->capture('charge-1', $key);
        $client->reverse('charge-1', $key);
        $client->retry('charge-1', $key);
        $client->cancelPendingCharge('charge-1', $key);
        self::assertSame([['PUT', '/v1/creditcard/charges/charge-1/capture'], ['PUT', '/v1/creditcard/charges/charge-1/reverse'], ['PUT', '/v1/creditcard/charges/charge-1/retry'], ['DELETE', '/v1/creditcard/charges/charge-1']], $transport->calls);
    }
}
