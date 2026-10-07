<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Split;

/** Immutable, read-only projection. It never authorizes or executes a transfer. */
final class SplitAllocationProjection
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $customerId,
        public readonly string $type,
        public readonly ?string $value,
        public readonly ?string $resolvedValue,
        public readonly PaymentSplitStatus $status,
    ) {
    }

    /** @return array{id:?string,customer_id:?string,type:string,value:?string,resolved_value:?string,status:string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customerId,
            'type' => $this->type,
            'value' => $this->value,
            'resolved_value' => $this->resolvedValue,
            'status' => $this->status->value,
        ];
    }
}
