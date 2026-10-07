<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Split;

use Pagou\Whmcs\Payment\Split\PaymentSplitResponseMapper;
use Pagou\Whmcs\Payment\Split\PaymentSplitStatus;
use PHPUnit\Framework\TestCase;

final class PaymentSplitResponseMapperTest extends TestCase
{
    public function testItPreservesCanonicalProjectionWithoutChangingDecimalPrecision(): void
    {
        $payload = $this->fixture('pix-with-split-response.json');
        $projection = (new PaymentSplitResponseMapper())->fromPaymentResponse($payload);

        self::assertNotNull($projection);
        self::assertSame('2.50', $projection->fee);
        self::assertSame(PaymentSplitStatus::Completed, $projection->status);
        self::assertCount(1, $projection->allocations);
        self::assertSame('12.3456', $projection->allocations[0]->value);
        self::assertSame('18.52', $projection->allocations[0]->resolvedValue);
    }

    public function testUnknownStatesRemainReadableAndNeverEnableAnOperation(): void
    {
        $projection = (new PaymentSplitResponseMapper())->projection([
            'status' => 999,
            'allocations' => [['status' => 'future_state', 'type' => 'future_type', 'value' => '1.00']],
        ]);

        self::assertNotNull($projection);
        self::assertSame(PaymentSplitStatus::Unknown, $projection->status);
        self::assertSame(PaymentSplitStatus::Unknown, $projection->allocations[0]->status);
        self::assertSame('unknown', $projection->allocations[0]->type);
    }

    public function testResponseWithoutAllocationsHasNoSplitProjection(): void
    {
        self::assertNull((new PaymentSplitResponseMapper())->fromPaymentResponse(['data' => ['split' => []]]));
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $json = file_get_contents(__DIR__ . '/../../Fixtures/Split/' . $name);
        self::assertNotFalse($json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
