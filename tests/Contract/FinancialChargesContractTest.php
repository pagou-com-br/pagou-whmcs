<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Contract;

use Pagou\Whmcs\Payment\Boleto\Api\{CreateBoletoRequest, BoletoPayloadMapper};
use Pagou\Whmcs\Payment\Pix\Dto\CreateDuePixRequest;
use Pagou\Whmcs\Payment\Pix\Mapper\PixPayloadMapper;
use Pagou\Whmcs\Payment\Pix\Value\{CivilDate, Money};
use PHPUnit\Framework\TestCase;

/** Contract source: monorepo 91393d1, charge-create and qrcode-due-create schemas/mappers. */
final class FinancialChargesContractTest extends TestCase
{
    public function testPixUsesExplicitProviderModalitiesAndPreservesValues(): void
    {
        foreach (['fixed', 'percentage_calendar_days', 'percentage_month_calendar_days'] as $type) {
            $payload = (new PixPayloadMapper())->due($this->pix(15000, ['type' => $type, 'amount' => 1.5]));
            self::assertSame(['type' => $type, 'amount' => 1.5], $payload['interest']);
            self::assertSame(150.0, $payload['amount']);
            self::assertSame(['type' => 'percentage', 'amount' => 2.0], $payload['fine']);
        }
    }

    public function testRejectsAmbiguousPixInterestAndApiRejectedNumericLimits(): void
    {
        foreach ([['percentage', 1.0, 15000], ['fixed', 1.001, 15000], ['fixed', -1.0, 15000], ['percentage_calendar_days', 2.5, 100], ['fixed', INF, 15000]] as [$type, $amount, $principal]) {
            try {
                $this->pix($principal, ['type' => $type, 'amount' => $amount]);
                self::fail('Invalid instruction accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testPixDoesNotInventAPercentageCapAbsentFromTheApi(): void
    {
        $payload = (new PixPayloadMapper())->due($this->pix(100000, ['type' => 'percentage_month_calendar_days', 'amount' => 101.0]));
        self::assertSame(101.0, $payload['interest']['amount']);
    }

    public function testBoletoUsesPercentagesWithoutAddingThemToPrincipal(): void
    {
        $payload = (new BoletoPayloadMapper())->create($this->boleto(2.0, 1.0));
        self::assertSame(150, $payload['amount']);
        self::assertSame(2.0, $payload['fine']);
        self::assertSame(1.0, $payload['interest']);
        self::assertSame(0.1, (new BoletoPayloadMapper())->create($this->boleto(0.1, 0.1))['interest']);
        $zero = (new BoletoPayloadMapper())->create($this->boleto(0.0, 0.0));
        self::assertArrayNotHasKey('fine', $zero);
        self::assertArrayNotHasKey('interest', $zero);
    }

    public function testBoletoRejectsUnsupportedPercentagesBeforeHttp(): void
    {
        foreach ([0.01, 0.09, -1.0, 100.01, INF, NAN] as $amount) {
            try {
                $this->boleto($amount, $amount);
                self::fail('Invalid percentage accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    /** @param array{type:string,amount:float} $interest */
    private function pix(int $principal, array $interest): CreateDuePixRequest
    {
        return new CreateDuePixRequest(
            '42',
            'attempt',
            'key',
            new Money($principal),
            CivilDate::fromIso('2099-09-10'),
            ['name' => 'Synthetic', 'document' => '11144477735'],
            'Synthetic',
            30,
            [],
            null,
            ['type' => 'percentage', 'amount' => 2.0],
            $interest
        );
    }

    private function boleto(float $fine, float $interest): CreateBoletoRequest
    {
        return new CreateBoletoRequest('42', 'key', 15000, '2099-09-10', [
            'name' => 'Synthetic', 'document' => '11144477735', 'zip' => '01001000', 'street' => 'Synthetic',
            'city' => 'Synthetic', 'state' => 'SP', 'number' => '1', 'neighborhood' => 'Synthetic',
        ], 'Synthetic', 30, 'synthetic', [], null, $fine, $interest);
    }
}
