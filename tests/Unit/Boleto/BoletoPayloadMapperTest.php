<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Boleto;

use Pagou\Whmcs\Payment\Boleto\Api\BoletoPayloadMapper;
use Pagou\Whmcs\Payment\Boleto\Api\CreateBoletoRequest;
use PHPUnit\Framework\TestCase;

final class BoletoPayloadMapperTest extends TestCase
{
    public function testItUsesTheDocumentedChargeFieldsAndLocalCorrelation(): void
    {
        $request = new CreateBoletoRequest('42', 'idem-42', 1099, '2026-09-10', [
            'name' => 'Cliente', 'document' => '12345678901', 'zip' => '01001000', 'street' => 'Rua A',
            'city' => 'Sao Paulo', 'state' => 'SP', 'number' => '1', 'neighborhood' => 'Centro',
        ], 'Fatura #42', 5, 'customer-7', [['key' => 'source', 'value' => 'whmcs']], null, 2.5, 1.0);

        $payload = (new BoletoPayloadMapper())->create($request);

        self::assertSame(10.99, $payload['amount']);
        self::assertSame(5, $payload['grace_period']);
        self::assertSame('customer-7', $payload['customer_code']);
        self::assertSame(2.5, $payload['fine']);
        self::assertSame(1.0, $payload['interest']);
        self::assertSame(['key' => 'whmcs_invoice_id', 'value' => '42'], $payload['metadata'][1]);
        self::assertArrayNotHasKey('split', $payload);
        self::assertArrayNotHasKey('reference_id', $payload);
    }

    public function testItRejectsAnIncompletePayerAndSmallAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateBoletoRequest('42', 'idem', 499, '2026-09-10', [], 'Fatura', 1, 'customer');
    }
}
