<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger;

final class PaymentApplyResult
{
    public const APPLIED = 'applied';
    public const REPLAY = 'replay';
    public const QUARANTINED = 'quarantined';

    private function __construct(public readonly string $outcome, public readonly ?NativePaymentReceipt $receipt = null, public readonly ?string $finding = null)
    {
    }

    public static function applied(NativePaymentReceipt $receipt): self
    {
        return new self(self::APPLIED, $receipt);
    }
    public static function replay(): self
    {
        return new self(self::REPLAY);
    }
    public static function quarantined(string $finding): self
    {
        return new self(self::QUARANTINED, null, $finding);
    }
}
