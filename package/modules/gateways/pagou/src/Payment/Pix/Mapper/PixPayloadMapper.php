<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Mapper;

use Pagou\Whmcs\Payment\Pix\Dto\CreateDuePixRequest;
use Pagou\Whmcs\Payment\Pix\Dto\CreatePixRequest;

final class PixPayloadMapper
{
    /** @return array<string, mixed> */
    public function immediate(CreatePixRequest $request): array
    {
        $payload = [
            'amount' => (float) $request->amount->decimal(),
            'payer' => $request->payer,
            'description' => $request->description,
            'expiration' => $request->expiration,
        ];
        if ($request->customerCode !== null) {
            $payload['customer_code'] = $request->customerCode;
        }
        $payload['metadata'] = $this->metadata($request->metadata, $request->invoiceId);
        if ($request->notificationUrl !== null) {
            $payload['notification_url'] = $request->notificationUrl;
        }
        return $payload;
    }

    /** @return array<string, mixed> */
    public function due(CreateDuePixRequest $request): array
    {
        $payload = [
            'amount' => (float) $request->amount->decimal(),
            'payer' => $request->payer,
            'description' => $request->description,
            'expiration' => $request->expiration,
            'due_date' => $request->dueDate->value,
        ];
        $payload['metadata'] = $this->metadata($request->metadata, $request->invoiceId);
        if ($request->notificationUrl !== null) {
            $payload['notification_url'] = $request->notificationUrl;
        }
        if ($request->fine !== null) {
            $payload['fine'] = $request->fine;
        }
        if ($request->interest !== null) {
            $payload['interest'] = $request->interest;
        }
        return $payload;
    }

    /** @return array<string, mixed> */
    public function refund(\Pagou\Whmcs\Payment\Pix\Dto\RefundPixRequest $request): array
    {
        return ['reason' => $request->reason, 'amount' => (float) $request->amount->decimal(), 'description' => $request->description];
    }

    /**
     * @param array<string, scalar> $metadata
     * @return list<array{key:string,value:string}>
     */
    private function metadata(array $metadata, string $invoiceId): array
    {
        $metadata['whmcs_invoice_id'] = $invoiceId;
        $result = [];
        foreach ($metadata as $key => $value) {
            $result[] = ['key' => (string) $key, 'value' => (string) $value];
        }

        return $result;
    }
}
