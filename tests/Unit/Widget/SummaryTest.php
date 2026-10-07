<?php

declare(strict_types=1);

namespace Pagou\Payments\Tests\Unit\Widget;

use DateTimeImmutable;
use Pagou\Payments\Admin\Widget\Summary;
use Pagou\Payments\Admin\Widget\View;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class SummaryTest extends TestCase
{
    private function payload(): array
    {
        return [
            'version' => 1, 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo',
            'basis' => 'gross_payment_credits', 'sources' => ['pix', 'charge', 'creditcard_settlement'],
            'as_of' => '2026-09-18T15:00:00.000Z',
            'today' => ['from' => '2026-09-18T03:00:00.000Z', 'until' => '2026-09-18T15:00:00.000Z', 'amount_cents' => 3014, 'receipt_count' => 6],
            'month' => ['from' => '2026-09-01T03:00:00.000Z', 'until' => '2026-09-18T15:00:00.000Z', 'amount_cents' => 3714, 'receipt_count' => 8],
        ];
    }

    public function testApiContractMapsExactCentsAndServerTimestamp(): void
    {
        $now = new DateTimeImmutable('2026-09-18T15:00:05Z');
        $data = Summary::fromPayload($this->payload(), $now);
        self::assertSame(3014, $data['today_amount']);
        self::assertSame(3714, $data['month_amount']);
        self::assertSame(6, $data['today_count']);
        self::assertSame(8, $data['month_count']);
        self::assertSame($now->getTimestamp() - 5, $data['as_of']);
        $empty = $this->payload();
        foreach (['today', 'month'] as $period) {
            $empty[$period]['amount_cents'] = 0;
            $empty[$period]['receipt_count'] = 0;
        }
        self::assertSame(0, Summary::fromPayload($empty, $now)['today_amount']);
    }

    public function testInvalidOrIncompatiblePayloadCannotBecomeZero(): void
    {
        $bad = [[], ['version' => 2], ['currency' => 'USD'], ['timezone' => 'UTC'], ['basis' => 'invoice_totals'], ['sources' => ['pix']],
            ['as_of' => '2026-09-18T25:00:00Z'], ['as_of' => '2026-09-17T15:00:00Z'], ['as_of' => '2026-09-18T15:05:00Z'],
            ['today' => ['amount_cents' => '3014']], ['today' => ['amount_cents' => -1]], ['today' => ['amount_cents' => 3.14]],
            ['today' => ['amount_cents' => null]], ['today' => ['amount_cents' => 9007199254740992]],
            ['today' => ['receipt_count' => 0]], ['today' => ['receipt_count' => 9]],
            ['today' => ['from' => '2026-09-18T00:00:00Z']], ['month' => ['until' => '2026-09-18T15:01:00Z']]];
        foreach ($bad as $patch) {
            $payload = $patch === [] ? [] : array_replace_recursive($this->payload(), $patch);
            if (isset($patch['sources'])) {
                $payload['sources'] = $patch['sources'];
            }
            try {
                Summary::fromPayload($payload, new DateTimeImmutable('2026-09-18T15:00:05Z'));
                self::fail('Invalid receipt contract accepted: ' . json_encode($patch));
            } catch (UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testLargeSafeAmountsKeepTheirLastCentInTheWidget(): void
    {
        $payload = $this->payload();
        $payload['today']['amount_cents'] = 9007199254740991;
        $payload['month']['amount_cents'] = 9007199254740991;
        $value = Summary::fromPayload($payload, new DateTimeImmutable('2026-09-18T15:00:00Z'));
        $html = (new View())->render(['configured' => true, 'summary' => ['value' => $value]]);
        self::assertStringContainsString('R$ 90.071.992.547.409,91', $html);
    }

    public function testUnavailableApiDoesNotHideLocalFindingsOrImplyZeroReceipts(): void
    {
        $html = (new View())->render(['configured' => true, 'summary' => ['error' => true], 'findings' => ['value' => ['pending' => 2]]]);
        self::assertStringContainsString('Recebimentos da conta indisponíveis', $html);
        self::assertStringContainsString('2 pendência(s) no módulo WHMCS', $html);
        self::assertStringNotContainsString('R$ 0,00', $html);
    }
}
