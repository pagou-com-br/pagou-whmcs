<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Pix;

use Pagou\Whmcs\Payment\Pix\Dto\CreateDuePixRequest;
use Pagou\Whmcs\Payment\Pix\Dto\CreatePixRequest;
use Pagou\Whmcs\Payment\Pix\Mapper\PixPayloadMapper;
use Pagou\Whmcs\Payment\Pix\Value\CivilDate;
use Pagou\Whmcs\Payment\Pix\Value\Money;
use PHPUnit\Framework\TestCase;

final class PixPayloadMapperTest extends TestCase
{
    public function testImmediatePixUsesDocumentedApiFieldsAndMetadataCorrelation(): void
    {
        $payload = (new PixPayloadMapper())->immediate(new CreatePixRequest(
            'invoice-42',
            'attempt-42',
            'key-42',
            new Money(1099),
            ['name' => 'Cliente de Teste', 'document' => '***'],
            'Invoice 42',
            3600,
            'customer-42',
            ['source' => 'whmcs'],
        ));

        self::assertSame(10.99, $payload['amount']);
        self::assertSame('customer-42', $payload['customer_code']);
        self::assertContains(
            ['key' => 'whmcs_invoice_id', 'value' => 'invoice-42'],
            $payload['metadata'],
        );
        self::assertArrayNotHasKey('external_id', $payload);
        self::assertArrayNotHasKey('split', $payload);
    }

    public function testDuePixUsesCivilDateExactly(): void
    {
        $payload = (new PixPayloadMapper())->due(new CreateDuePixRequest(
            'invoice-42',
            'attempt-42',
            'key-42',
            new Money(10000),
            CivilDate::fromIso('2026-08-22'),
            ['name' => 'Cliente de Teste', 'document' => '***'],
            'Invoice 42',
            1,
            [],
            null,
            ['type' => 'percentage', 'amount' => 2.5],
            ['type' => 'fixed', 'amount' => 0.25],
        ));

        self::assertSame('2026-08-22', $payload['due_date']);
        self::assertSame(100.0, $payload['amount']);
        self::assertArrayNotHasKey('customer_code', $payload);
        self::assertSame(['type' => 'percentage', 'amount' => 2.5], $payload['fine']);
        self::assertSame(['type' => 'fixed', 'amount' => 0.25], $payload['interest']);
    }
}
