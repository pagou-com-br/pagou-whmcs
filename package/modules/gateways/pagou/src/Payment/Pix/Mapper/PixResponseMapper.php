<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Mapper;

use Pagou\Whmcs\Payment\Pix\Dto\PixArtifacts;
use Pagou\Whmcs\Payment\Pix\Dto\PixCharge;
use Pagou\Whmcs\Payment\Split\PaymentSplitResponseMapper;

final class PixResponseMapper
{
    /** @param array<string, mixed> $response */
    public function charge(array $response): PixCharge
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $id = $this->string($data, ['id', 'pix_id', 'transaction_id']);
        if ($id === null || $id === '') {
            throw new \UnexpectedValueException('Pagou Pix response has no remote identifier.');
        }
        $amount = $this->cents($data['amount'] ?? $data['value'] ?? 0);
        $pix = is_array($data['payload'] ?? null)
            ? $data['payload']
            : (is_array($data['pix'] ?? null) ? $data['pix'] : $data);

        return new PixCharge(
            $id,
            $this->status($data['status'] ?? 'unknown'),
            $amount,
            new PixArtifacts(
                $this->string($pix, ['data', 'copy_paste', 'pix_copy_paste', 'emv', 'brcode']),
                $this->string($pix, ['image', 'qr_code_image', 'qrcode_image', 'qr_code_base64']),
                $this->string($pix, ['qr_code_url', 'qrcode_url']),
            ),
            $this->string($data, ['expired_at', 'expires_at', 'expiration_date']),
            $this->string($data, ['paid_at', 'payment_date']),
            $data,
            (new PaymentSplitResponseMapper())->fromPaymentResponse($data),
            is_array($data['due_info'] ?? null) ? $this->string($data['due_info'], ['due_at']) : null,
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
                return (string) $data[$key];
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
            if (preg_match('/^\\d+(?:\\.\\d{1,2})?$/', $normalized) !== 1) {
                throw new \UnexpectedValueException('Pagou Pix response has an invalid amount.');
            }
            [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
            return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        }
        throw new \UnexpectedValueException('Pagou Pix response has no valid amount.');
    }

    private function status(mixed $status): string
    {
        if (is_int($status) || (is_string($status) && ctype_digit($status))) {
            return match ((int) $status) {
                1 => 'empty',
                2 => 'active',
                3 => 'cancelled',
                4 => 'paid',
                5 => 'refunded',
                6 => 'removed',
                default => 'unknown',
            };
        }

        return is_string($status) && trim($status) !== '' ? strtolower(trim($status)) : 'unknown';
    }
}
