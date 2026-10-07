<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Split;

final class PaymentSplitProjection
{
    /** @param list<SplitAllocationProjection> $allocations */
    public function __construct(
        public readonly ?string $fee,
        public readonly PaymentSplitStatus $status,
        public readonly array $allocations,
    ) {
    }

    /**
     * @return array{
     *   schema:string,
     *   fee:?string,
     *   status:string,
     *   allocations:list<array{id:?string,customer_id:?string,type:string,value:?string,resolved_value:?string,status:string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema' => 'pagou-payment-split-v1',
            'fee' => $this->fee,
            'status' => $this->status->value,
            'allocations' => array_map(
                static fn (SplitAllocationProjection $allocation): array => $allocation->toArray(),
                $this->allocations,
            ),
        ];
    }
}
