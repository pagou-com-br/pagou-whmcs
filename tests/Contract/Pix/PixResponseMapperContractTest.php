<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Contract\Pix;

use Pagou\Whmcs\Payment\Pix\Mapper\PixResponseMapper;
use PHPUnit\Framework\TestCase;

final class PixResponseMapperContractTest extends TestCase
{
    public function testSanitizedPixFixturePreservesArtifacts(): void
    {
        $fixture = file_get_contents(__DIR__ . '/../../Fixtures/Pix/create-pix-response.json');
        self::assertNotFalse($fixture);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($fixture, true, 512, JSON_THROW_ON_ERROR);

        $charge = (new PixResponseMapper())->charge($payload);
        self::assertSame('pix_sanitized_0001', $charge->id);
        self::assertSame(1234, $charge->amountCents);
        self::assertNotNull($charge->artifacts->copyPaste);
        self::assertSame('https://example.invalid/qr/pix_sanitized_0001', $charge->artifacts->qrCodeUrl);
    }

    public function testCanonicalSplitProjectionIsReadWithoutChangingThePixCharge(): void
    {
        $fixture = file_get_contents(__DIR__ . '/../../Fixtures/Split/pix-with-split-response.json');
        self::assertNotFalse($fixture);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($fixture, true, 512, JSON_THROW_ON_ERROR);

        $charge = (new PixResponseMapper())->charge($payload);
        self::assertSame('pix_split_sanitized_0001', $charge->id);
        self::assertSame(15000, $charge->amountCents);
        self::assertNotNull($charge->split);
        self::assertCount(1, $charge->split->allocations);
        self::assertSame('recipient_sanitized_0001', $charge->split->allocations[0]->customerId);
    }
}
