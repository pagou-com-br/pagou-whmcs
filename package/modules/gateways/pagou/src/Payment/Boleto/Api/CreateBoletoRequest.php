<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Api;

final class CreateBoletoRequest
{
    /**
     * @param array<string, string> $payer
     * @param list<array{key:string,value:string}> $metadata
     */
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $idempotencyKey,
        public readonly int $amountCentavos,
        public readonly string $dueDate,
        public readonly array $payer,
        public readonly string $description,
        public readonly int $gracePeriod,
        public readonly string $customerCode,
        public readonly array $metadata = [],
        public readonly ?string $notificationUrl = null,
        public readonly float $fine = 0.0,
        public readonly float $interest = 0.0,
    ) {
        if ($invoiceId === '' || $idempotencyKey === '' || $amountCentavos < 500 || $description === '' || $customerCode === '') {
            throw new \InvalidArgumentException('Invoice, idempotency key, description, customer code and an amount of at least BRL 5.00 are required.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) !== 1) {
            throw new \InvalidArgumentException('Boleto due date must be a civil YYYY-MM-DD date.');
        }
        if ($gracePeriod < 1 || $gracePeriod > 30) {
            throw new \InvalidArgumentException('Boleto grace period must be between 1 and 30 days.');
        }
        \Pagou\Whmcs\Payment\LateChargeRules::boleto($fine, $amountCentavos);
        \Pagou\Whmcs\Payment\LateChargeRules::boleto($interest, $amountCentavos);
        foreach (['name', 'document', 'zip', 'street', 'city', 'state', 'number', 'neighborhood'] as $field) {
            if (!isset($payer[$field]) || trim($payer[$field]) === '') {
                throw new \InvalidArgumentException(sprintf('Boleto payer %s is required.', $field));
            }
        }
    }
}
