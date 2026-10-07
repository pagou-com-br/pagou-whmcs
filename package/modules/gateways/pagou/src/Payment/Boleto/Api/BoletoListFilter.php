<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Api;

final class BoletoListFilter
{
    public function __construct(
        public readonly ?string $createdAtFrom = null,
        public readonly ?string $createdAtTo = null,
        public readonly ?string $dueDate = null,
        public readonly ?string $paidAtFrom = null,
        public readonly ?string $paidAtTo = null,
        public readonly ?int $status = null,
        public readonly int $limit = 10,
        public readonly int $offset = 0,
    ) {
        if ($limit < 10 || $limit > 500 || $offset < 0) {
            throw new \InvalidArgumentException('Boleto list pagination is invalid.');
        }
    }

    /** @return array<string, scalar> */
    public function query(): array
    {
        return array_filter([
            'created_at_from' => $this->createdAtFrom,
            'created_at_to' => $this->createdAtTo,
            'due_date' => $this->dueDate,
            'paid_at_from' => $this->paidAtFrom,
            'paid_at_to' => $this->paidAtTo,
            'status' => $this->status,
            'limit' => $this->limit,
            'offset' => $this->offset,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
