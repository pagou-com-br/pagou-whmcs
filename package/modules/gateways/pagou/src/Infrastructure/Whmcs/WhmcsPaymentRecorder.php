<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Whmcs;

use InvalidArgumentException;
use Pagou\Whmcs\Domain\Money;
use RuntimeException;

final class WhmcsPaymentRecorder
{
    public function __construct(private readonly WhmcsPorts $ports)
    {
    }

    public function transactionExists(string $transactionId): bool
    {
        $this->assertTransactionId($transactionId);
        return $this->ports->transactionExists($transactionId);
    }

    /**
     * Adds a payment exactly once according to WHMCS's own transaction check.
     * This check is deliberately made immediately before the write. The caller
     * still needs its local ledger lock for concurrent worker processes.
     */
    public function record(int $invoiceId, string $transactionId, Money $amount, string $gateway, \DateTimeInterface $paidAt): bool
    {
        if ($invoiceId < 1 || $amount->isNegative() || $amount->isZero()) {
            throw new InvalidArgumentException('A positive amount and invoice identifier are required.');
        }
        $this->assertTransactionId($transactionId);
        if ($gateway === '') {
            throw new InvalidArgumentException('A gateway name is required.');
        }

        if ($this->ports->transactionExists($transactionId)) {
            return false;
        }

        $this->ports->addInvoicePayment(
            $invoiceId,
            $transactionId,
            $amount->jsonSerialize(),
            $gateway,
            \DateTimeImmutable::createFromInterface($paidAt)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s'),
        );

        return true;
    }

    private function assertTransactionId(string $transactionId): void
    {
        if ($transactionId === '' || strlen($transactionId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $transactionId)) {
            throw new RuntimeException('Invalid remote transaction identifier.');
        }
    }
}
