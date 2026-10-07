<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Domain;

use InvalidArgumentException;
use Pagou\Whmcs\Domain\EconomicPaymentKey;
use Pagou\Whmcs\Domain\IdempotencyKey;
use Pagou\Whmcs\Domain\InvoiceRevision;
use Pagou\Whmcs\Domain\Money;
use Pagou\Whmcs\Domain\PaymentAttempt;
use Pagou\Whmcs\Domain\PaymentAttemptState;
use Pagou\Whmcs\Domain\PaymentMethod;
use Pagou\Whmcs\Domain\RemoteId;
use Pagou\Whmcs\Domain\SettlementRail;
use PHPUnit\Framework\TestCase;

final class PaymentAttemptTest extends TestCase
{
    public function testBoletoCanBePaidByItsEmbeddedPixWithoutNewEconomicKey(): void
    {
        $attempt = $this->attempt(PaymentMethod::Boleto)
            ->transitionTo(PaymentAttemptState::PendingIssuance)
            ->transitionTo(PaymentAttemptState::Issuing)
            ->issued(RemoteId::fromString('boleto_123'))
            ->settle(SettlementRail::PixEmbeddedInBoleto);

        self::assertSame(PaymentAttemptState::Paid, $attempt->state);
        self::assertSame(SettlementRail::PixEmbeddedInBoleto, $attempt->settlementRail);
        self::assertSame('economic-payment-key-001', $attempt->economicPaymentKey->value());
    }

    public function testItRejectsIncompatibleSettlementRail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PaymentAttempt(
            42,
            InvoiceRevision::initial(),
            PaymentMethod::Pix,
            Money::fromDecimal('1.00'),
            EconomicPaymentKey::fromString('economic-payment-key-001'),
            IdempotencyKey::fromString('idempotency-key-0001'),
            PaymentAttemptState::Paid,
            null,
            SettlementRail::Boleto,
        );
    }

    private function attempt(PaymentMethod $method): PaymentAttempt
    {
        return new PaymentAttempt(
            42,
            InvoiceRevision::initial(),
            $method,
            Money::fromDecimal('1.00'),
            EconomicPaymentKey::fromString('economic-payment-key-001'),
            IdempotencyKey::fromString('idempotency-key-0001'),
        );
    }
}
