<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Split;

/** Maps the public, read-only Split projection without affecting payment parsing. */
final class PaymentSplitResponseMapper
{
    /** @param array<string, mixed> $response */
    public function fromPaymentResponse(array $response): ?PaymentSplitProjection
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $split = $data['split'] ?? null;

        return is_array($split) ? $this->projection($split) : null;
    }

    /** @param array<string, mixed> $split */
    public function projection(array $split): ?PaymentSplitProjection
    {
        $items = is_array($split['allocations'] ?? null) ? $split['allocations'] : [];
        if ($items === []) {
            return null;
        }

        $allocations = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = is_string($item['type'] ?? null) ? strtolower(trim($item['type'])) : 'unknown';
            if (!in_array($type, ['fixed', 'percentage'], true)) {
                $type = 'unknown';
            }
            $allocations[] = new SplitAllocationProjection(
                $this->string($item['id'] ?? null),
                $this->string($item['customer_id'] ?? null),
                $type,
                $this->decimal($item['value'] ?? null),
                $this->decimal($item['resolved_value'] ?? null),
                PaymentSplitStatus::fromRemote($item['status'] ?? null),
            );
        }
        if ($allocations === []) {
            return null;
        }

        return new PaymentSplitProjection(
            $this->decimal($split['fee'] ?? null),
            PaymentSplitStatus::fromRemote($split['status'] ?? null),
            $allocations,
        );
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function decimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }
        if (!is_string($value)) {
            return null;
        }
        $normalized = str_replace(',', '.', trim($value));

        return preg_match('/^\d+(?:\.\d{1,10})?$/', $normalized) === 1 ? $normalized : null;
    }
}
