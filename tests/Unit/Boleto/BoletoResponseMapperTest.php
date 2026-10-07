<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Boleto;

use Pagou\Whmcs\Payment\Boleto\Api\BoletoResponseMapper;
use PHPUnit\Framework\TestCase;

final class BoletoResponseMapperTest extends TestCase
{
    public function testItMapsAllArtifactsFromTheMaterializedPayload(): void
    {
        $charge = (new BoletoResponseMapper())->charge([
            'id' => 'charge-1', 'status' => 'registered', 'amount' => 12.34,
            'payload' => ['bank' => '001', 'bar_code' => '00190', 'line' => '00190.12345', 'qrcode_id' => 'qr-1', 'data' => '000201...', 'image' => 'base64-image'],
        ]);

        self::assertSame('charge-1', $charge->remoteId);
        self::assertSame('00190.12345', $charge->artifacts->digitableLine);
        self::assertSame('00190', $charge->artifacts->barcode);
        self::assertSame('000201...', $charge->artifacts->pixCopyPaste);
        self::assertSame('base64-image', $charge->artifacts->pixQrCode);
        self::assertTrue($charge->artifacts->isReady());
    }

    public function testItKeepsAttemptAwaitingRegistrationWhenPayloadIsNotYetMaterialized(): void
    {
        $charge = (new BoletoResponseMapper())->charge(['id' => 'charge-1', 'status' => 'processing', 'amount' => 5]);
        self::assertTrue($charge->awaitsRegistration());
        self::assertNull($charge->artifacts->digitableLine);
    }

    public function testItNormalizesPagouNumericFinancialStatuses(): void
    {
        $mapper = new BoletoResponseMapper();

        self::assertSame('pending', $mapper->charge(['id' => 'charge-1', 'status' => 1, 'amount' => 5])->status);
        self::assertSame('active', $mapper->charge(['id' => 'charge-2', 'status' => 2, 'amount' => 5])->status);
        self::assertSame('cancelled', $mapper->charge(['id' => 'charge-3', 'status' => 3, 'amount' => 5])->status);
        self::assertSame('paid', $mapper->charge(['id' => 'charge-4', 'status' => 4, 'amount' => 5])->status);
        self::assertSame('paid_with_discount', $mapper->charge(['id' => 'charge-8', 'status' => 8, 'amount' => 5])->status);
        self::assertSame('paid_with_fine_or_interest', $mapper->charge(['id' => 'charge-9', 'status' => 9, 'amount' => 5])->status);
    }

    public function testItNormalizesEmptyOptionalArtifactsToNull(): void
    {
        $charge = (new BoletoResponseMapper())->charge([
            'id' => 'charge-1',
            'status' => 'registered',
            'amount' => 10,
            'payload' => ['line' => '00190.12345', 'bar_code' => '00190', 'pdf_url' => '  '],
        ]);

        self::assertNull($charge->artifacts->pdfUrl);
    }

    public function testBoletoAndItsEmbeddedPixRemainOneChargeWithOneSplitProjection(): void
    {
        $fixture = file_get_contents(__DIR__ . '/../../Fixtures/Split/boleto-with-split-response.json');
        self::assertNotFalse($fixture);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($fixture, true, 512, JSON_THROW_ON_ERROR);

        $charge = (new BoletoResponseMapper())->charge($payload);
        self::assertSame('charge_split_sanitized_0001', $charge->remoteId);
        self::assertNotNull($charge->artifacts->digitableLine);
        self::assertNotNull($charge->artifacts->pixCopyPaste);
        self::assertNotNull($charge->split);
        self::assertCount(1, $charge->split->allocations);
        self::assertSame('25.00', $charge->split->allocations[0]->resolvedValue);
    }
}
