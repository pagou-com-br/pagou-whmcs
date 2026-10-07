<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Whmcs;

use Pagou\Whmcs\Domain\CivilDate;
use Pagou\Whmcs\Domain\Document;
use Pagou\Whmcs\Domain\Money;
use RuntimeException;

final class WhmcsLookup
{
    public function __construct(private readonly WhmcsPorts $ports)
    {
    }

    public function invoice(int $invoiceId): WhmcsInvoice
    {
        $payload = $this->successful('GetInvoice', ['invoiceid' => $invoiceId]);

        return new WhmcsInvoice(
            $this->positiveInt($payload, 'invoiceid', $invoiceId),
            $this->positiveInt($payload, 'userid'),
            $this->string($payload, 'status'),
            Money::fromDecimal($this->string($payload, 'balance')),
            CivilDate::fromString($this->string($payload, 'duedate')),
            $this->string($payload, 'paymentmethod'),
        );
    }

    public function client(int $clientId): WhmcsClient
    {
        $payload = $this->successful('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);

        return new WhmcsClient(
            $this->positiveInt($payload, 'userid', $clientId),
            $this->string($payload, 'firstname'),
            $this->string($payload, 'lastname'),
            $this->string($payload, 'email'),
            $this->optionalString($payload, 'companyname'),
        );
    }

    /**
     * Searches only configured field identifiers. Never guess a document from
     * arbitrary profile data, because that could send an unintended document.
     *
     * @param list<int> $customFieldIds
     */
    public function clientDocument(int $clientId, array $customFieldIds): ?Document
    {
        if ($customFieldIds === []) {
            return null;
        }

        $payload = $this->successful('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
        $fields = $payload['customfields']['customfield'] ?? [];
        if (!is_array($fields)) {
            return null;
        }

        foreach ($this->normaliseList($fields) as $field) {
            if (!is_array($field) || !isset($field['id']) || !in_array((int) $field['id'], $customFieldIds, true)) {
                continue;
            }

            $value = $field['value'] ?? null;
            if (!is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            try {
                return Document::fromString((string) $value);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function successful(string $command, array $parameters): array
    {
        $payload = $this->ports->localApi($command, $parameters);
        if (($payload['result'] ?? null) !== 'success') {
            throw new RuntimeException(sprintf('WHMCS %s failed: %s', $command, $this->safeError($payload)));
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function positiveInt(array $payload, string $key, ?int $fallback = null): int
    {
        $value = $payload[$key] ?? $fallback;
        if (!is_scalar($value) || (int) $value < 1) {
            throw new RuntimeException(sprintf('WHMCS response has no valid %s.', $key));
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new RuntimeException(sprintf('WHMCS response has no valid %s.', $key));
        }

        return trim((string) $value);
    }

    /** @param array<string,mixed> $payload */
    private function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** @param array<string,mixed> $payload */
    private function safeError(array $payload): string
    {
        $message = $payload['message'] ?? $payload['description'] ?? 'unknown error';
        return is_scalar($message) ? substr(trim((string) $message), 0, 180) : 'unknown error';
    }

    /**
     * @param array<mixed> $fields
     * @return list<mixed>
     */
    private function normaliseList(array $fields): array
    {
        return array_is_list($fields) ? $fields : [$fields];
    }
}
