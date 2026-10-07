<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Api;

use Pagou\Whmcs\Payment\Boleto\Domain\BoletoArtifacts;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoCharge;
use Pagou\Whmcs\Payment\Split\PaymentSplitResponseMapper;

final class BoletoResponseMapper
{
    /** @param array<string, mixed> $response */
    public function charge(array $response): BoletoCharge
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $remoteId = $this->string($data, ['id', 'charge_id', 'transaction_id']);
        if ($remoteId === null || $remoteId === '') {
            throw new \UnexpectedValueException('Pagou boleto response has no remote identifier.');
        }

        return new BoletoCharge(
            $remoteId,
            $this->status($data['status'] ?? null),
            $this->cents($data['amount'] ?? $data['value'] ?? 0),
            new BoletoArtifacts(
                $this->string($payload, ['line']),
                $this->string($payload, ['bar_code']),
                $this->string($payload, ['data']),
                $this->string($payload, ['image']),
                $this->string($payload, ['pdf_url', 'pdf']),
            ),
            $this->string($data, ['due_at', 'due_date', 'expires_at', 'expiration_date']),
            $this->string($data, ['paid_at', 'payment_date']),
            $data,
            (new PaymentSplitResponseMapper())->fromPaymentResponse($data),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private function string(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $value = trim((string) $data[$key]);

                return $value === '' ? null : $value;
            }
        }
        return null;
    }

    private function cents(mixed $amount): int
    {
        if (is_int($amount)) {
            return $amount * 100;
        }
        if (is_float($amount) || is_string($amount)) {
            $normalized = str_replace(',', '.', (string) $amount);
            if (preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized) !== 1) {
                throw new \UnexpectedValueException('Pagou boleto response has an invalid amount.');
            }
            [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
            return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        }
        throw new \UnexpectedValueException('Pagou boleto response has no valid amount.');
    }

    private function status(mixed $status): string
    {
        if (!is_scalar($status)) {
            return 'unknown';
        }

        $status = strtolower(trim((string) $status));

        return match ($status) {
            '1' => 'pending',
            '2' => 'active',
            '3' => 'cancelled',
            '4' => 'paid',
            '5' => 'refunded',
            '6' => 'rejected',
            '7' => 'removed',
            '8' => 'paid_with_discount',
            '9' => 'paid_with_fine_or_interest',
            '' => 'unknown',
            default => $status,
        };
    }
}
