<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Api;

final class BoletoPayloadMapper
{
    /** @return array<string, mixed> */
    public function create(CreateBoletoRequest $request): array
    {
        $payload = [
            'amount' => $request->amountCentavos / 100,
            'due_date' => $request->dueDate,
            'grace_period' => $request->gracePeriod,
            'payer' => $request->payer,
            'description' => $request->description,
            'customer_code' => $request->customerCode,
            'metadata' => array_merge($request->metadata, [[
                'key' => 'whmcs_invoice_id',
                'value' => $request->invoiceId,
            ]]),
        ];
        if ($request->notificationUrl !== null) {
            $payload['notification_url'] = $request->notificationUrl;
        }
        if ($request->fine > 0) {
            $payload['fine'] = $request->fine;
        }
        if ($request->interest > 0) {
            $payload['interest'] = $request->interest;
        }
        return $payload;
    }
}
