<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\Infrastructure;

use PDO;
use Pagou\Whmcs\Payment\Card\Contracts\CardOperationJournal;

/** Durable, low-data journal for card operations. It stores references only. */
final class PdoCardOperationJournal implements CardOperationJournal
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function started(string $operation, string $idempotencyKey, array $context): void
    {
        $context = $this->safeContext($context);
        $key = $this->key($idempotencyKey);
        $existing = $this->pdo->prepare('SELECT operation_type FROM pagou_payment_operations WHERE idempotency_key = :key');
        $existing->execute(['key' => $key]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            if ($row['operation_type'] !== $operation) {
                throw new \RuntimeException('Idempotency key is associated with a different card operation.');
            }
            return;
        }
        $now = $this->now();
        $statement = $this->pdo->prepare('INSERT INTO pagou_payment_operations (id, attempt_id, operation_type, status, deduplication_key, idempotency_key, priority, payload_json, request_json, available_at, started_at, created_at, updated_at) VALUES (:id, :attempt_id, :operation, :status, :deduplication_key, :key, :priority, :payload, :request, :available_at, :started_at, :created_at, :updated_at)');
        $statement->execute([
            'id' => $this->uuid(),
            'attempt_id' => $context['attempt_id'] ?? null,
            'operation' => $operation,
            'status' => 'started',
            'deduplication_key' => hash('sha256', 'card:journal:' . $key),
            'key' => $key,
            'priority' => 400,
            'payload' => $this->json(['attempt_id' => $context['attempt_id'] ?? null]),
            'request' => $this->json($context),
            'available_at' => $now,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Unable to durably journal card operation.');
        }
    }

    public function succeeded(string $operation, string $idempotencyKey, array $result): void
    {
        $this->transition($operation, $idempotencyKey, 'succeeded', $this->safeResult($result));
    }

    public function uncertain(string $operation, string $idempotencyKey, array $context): void
    {
        $this->transition($operation, $idempotencyKey, 'uncertain', $this->safeContext($context));
    }

    public function failed(string $operation, string $idempotencyKey, array $context): void
    {
        $this->transition($operation, $idempotencyKey, 'failed', $this->safeContext($context));
    }

    /** @param array<string,mixed> $value */
    private function transition(string $operation, string $idempotencyKey, string $status, array $value): void
    {
        $key = $this->key($idempotencyKey);
        $current = $this->pdo->prepare(
            'SELECT status FROM pagou_payment_operations WHERE idempotency_key = :key '
            . 'AND operation_type = :operation LIMIT 1'
        );
        $current->execute(['key' => $key, 'operation' => $operation]);
        $currentStatus = $current->fetchColumn();
        if (is_string($currentStatus) && $currentStatus === $status) {
            return;
        }
        $now = $this->now();
        $statement = $this->pdo->prepare("UPDATE pagou_payment_operations SET status = :status, response_json = :response, finished_at = :finished_at, updated_at = :updated_at, version = version + 1 WHERE idempotency_key = :key AND operation_type = :operation AND status IN ('started', 'uncertain')");
        $statement->execute(['status' => $status, 'response' => $this->json($value), 'finished_at' => $now, 'updated_at' => $now, 'key' => $key, 'operation' => $operation]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Card operation journal transition was rejected.');
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, scalar|null>
     */
    private function safeContext(array $context): array
    {
        $safe = [];
        foreach (['attempt_id', 'invoice_id', 'customer_id', 'card_id', 'card_reference', 'charge_id', 'reference', 'amount_centavos', 'reason'] as $key) {
            if (array_key_exists($key, $context) && is_scalar($context[$key])) {
                $safe[$key] = $context[$key];
            }
        }
        return $safe;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, scalar|null>
     */
    private function safeResult(array $result): array
    {
        $safe = [];
        foreach (['remote_id', 'status'] as $key) {
            if (array_key_exists($key, $result) && is_scalar($result[$key])) {
                $safe[$key] = $result[$key];
            }
        }
        return $safe;
    }

    private function key(string $idempotencyKey): string
    {
        return hash('sha256', $idempotencyKey);
    }
    /** @param array<string,scalar|null> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
