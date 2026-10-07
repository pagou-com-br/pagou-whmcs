<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger;

/**
 * Adapter boundary for WHMCS. Applying an overpayment must use WHMCS' native
 * invoice-payment path, which is responsible for the customer's account
 * credit. Implementations must never call AddCredit manually.
 */
interface WhmcsFinancialPort
{
    /**
     * Applies the payment using the native WHMCS invoice operation.
     * The returned receipt records the balance after application.
     */
    public function applyInvoicePayment(ReceivedPayment $payment): NativePaymentReceipt;

    public function paymentAlreadyApplied(EconomicPaymentKey $key, ?string $nativeTransactionId = null): bool;
}
