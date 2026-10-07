<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Contract;

use Pagou\Whmcs\Application\Webhook\WebhookEventParser;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;
use Pagou\Whmcs\Payment\Boleto\Api\BoletoResponseMapper;
use Pagou\Whmcs\Payment\Pix\Mapper\PixResponseMapper;
use Pagou\Whmcs\Payment\Card\CardApiClient;
use Pagou\Whmcs\Payment\Card\CardTransport;
use Pagou\Whmcs\Payment\Card\IdempotencyKey;
use PHPUnit\Framework\TestCase;

/** Synthetic public contracts from api-monorepo 91393d1, not provider/legacy envelopes. */
final class CurrentPlatformTest extends TestCase
{
    public function testSignedDeliveryEnvelopeUsesNameAndData(): void
    {
        $event = (new WebhookEventParser())->parse(json_encode([
            'name' => 'charge.paid',
            'data' => ['id' => 'charge-1', 'transaction_id' => 'payment-1', 'amount' => ['paid' => 12.50]],
        ], JSON_THROW_ON_ERROR), 'delivery-1');
        self::assertSame('charge.paid', $event->type);
        self::assertSame('boleto', $event->paymentMethod);
        self::assertSame('paid', $event->status);
        self::assertSame('charge-1', $event->remoteId);
    }

    public function testCanonicalDatesAndPaidBoletoProjection(): void
    {
        $pix = (new PixResponseMapper())->charge(['id' => 'pix-1', 'amount' => 10, 'status' => 2, 'expired_at' => '2026-09-18T12:00:00Z']);
        self::assertSame('2026-09-18T12:00:00Z', $pix->expiresAt);
        $charge = (new BoletoResponseMapper())->charge([
            'id' => 'boleto-1', 'amount' => 10, 'status' => 4, 'due_at' => '2026-09-18T00:00:00Z',
            'payload' => ['bar_code' => 'barcode', 'line' => 'digitable'],
        ]);
        self::assertSame('2026-09-18T00:00:00Z', $charge->dueDate);
        $attempt = new BoletoAttempt('attempt', '19', 1, 1000, '2026-09-18', 'key');
        self::assertSame('paid', $attempt->issued($charge)->status);
    }

    public function testHttpConflictCodesRetainTheirFinancialMeaning(): void
    {
        $client = new \Pagou\Whmcs\Infrastructure\Http\SafeCurlApiClient(new \Pagou\Whmcs\Infrastructure\Http\ApiClientConfig('synthetic-key'));
        $map = new \ReflectionMethod($client, 'mapApiError');
        foreach (['idempotency_in_progress' => false, 'idempotency_outcome_unknown' => false, 'charge_not_authorized' => true, '' => false] as $code => $conclusive) {
            $error = $map->invoke($client, 409, ['code' => $code, 'message' => 'Synthetic conflict'], 'request-1');
            self::assertSame($code, $error->context['code']);
            self::assertSame($conclusive, $error->isConclusiveRejection());
        }
        foreach ([408, 425, 429, 500, 503] as $status) {
            self::assertFalse($map->invoke($client, $status, [], 'request-1')->isConclusiveRejection());
        }
    }

    public function testApiCalendarUsesUtcWithoutRewritingCivilDueDates(): void
    {
        foreach (['20:59:00' => '2026-09-17', '21:00:00' => '2026-09-18', '23:59:00' => '2026-09-18', '00:00:00' => '2026-09-17'] as $time => $expected) {
            $now = new \DateTimeImmutable('2026-09-17 ' . $time, new \DateTimeZone('America/Sao_Paulo'));
            self::assertSame($expected, \Pagou\Whmcs\Support\ApiCalendar::today($now));
            \Pagou\Whmcs\Support\ApiCalendar::assertDueDate($expected, $now);
        }
        $this->expectException(\InvalidArgumentException::class);
        \Pagou\Whmcs\Support\ApiCalendar::assertDueDate('2026-09-17', new \DateTimeImmutable('2026-09-18T00:00:00Z'));
    }

    public function testReverseSerializesAnObject(): void
    {
        $transport = new class implements CardTransport {
            public string $json = '';
            public function request(string $method, string $path, array $headers = [], ?array $body = null): array
            {
                $this->json = json_encode($body, JSON_THROW_ON_ERROR);
                return ['id' => 'charge-1', 'value' => 1000, 'status' => 'reversed'];
            }
        };
        (new CardApiClient($transport))->reverse('charge-1', IdempotencyKey::create('reverse', 'charge-1'));
        self::assertIsObject(json_decode($transport->json));
    }
}
