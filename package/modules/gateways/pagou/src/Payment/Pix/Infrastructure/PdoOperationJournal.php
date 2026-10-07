<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Infrastructure;

use PDO;
use Pagou\Whmcs\Payment\Pix\Contracts\OperationJournal;

/**
 * Durable Pix mutation journal. It deliberately rejects incomplete local
 * correlation rather than performing an untraceable financial operation.
 */
final class PdoOperationJournal implements OperationJournal
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function started(string $operation, string $idempotencyKey, array $context): array
    {
        $context = $this->safeContext($context);
        $attemptId = $this->attemptId($context);
        $now = $this->now();

        $statement = $this->pdo->prepare(
            'INSERT INTO pagou_payment_operations (id, attempt_id, operation_type, status, deduplication_key, idempotency_key, priority, payload_json, request_json, available_at, started_at, created_at, updated_at) '
            . 'VALUES (:id, :attempt_id, :operation, :status, :deduplication_key, :key, :priority, :payload, :context, :available_at, :started_at, :created_at, :updated_at)'
        );
        try {
            $statement->execute([
                'id' => $this->uuid(),
                'attempt_id' => $attemptId,
                'operation' => $operation,
                'status' => 'started',
                'deduplication_key' => hash('sha256', 'pix:journal:' . $idempotencyKey),
                'key' => $idempotencyKey,
                'priority' => 400,
                'payload' => $this->json(['attempt_id' => $attemptId]),
                'context' => $this->json($context),
                'available_at' => $now,
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }
            $existing = $this->pdo->prepare('SELECT attempt_id, operation_type, status, request_json, response_json FROM pagou_payment_operations WHERE idempotency_key = :key');
            $existing->execute(['key' => $idempotencyKey]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw $exception;
            }
            $request = json_decode((string) ($row['request_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            if (
                $row['attempt_id'] !== $attemptId || $row['operation_type'] !== $operation
                || (isset($request['fingerprint']) && ($request['fingerprint'] !== ($context['fingerprint'] ?? null)))
            ) {
                throw new \DomainException('Idempotency key is already associated with a different Pix operation.');
            }
            foreach (['pix_id', 'invoice_id', 'due_date'] as $field) {
                if (isset($request[$field]) && $request[$field] !== ($context[$field] ?? null)) {
                    throw new \DomainException('Pix operation correlation does not match the original request.');
                }
            }
            $result = json_decode((string) ($row['response_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            return ['status' => (string) $row['status'], 'result' => is_array($result) ? $result : []];
        }
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Unable to durably journal the Pix operation.');
        }
        return ['status' => 'claimed', 'result' => []];
    }

    public function rejected(string $operation, string $idempotencyKey, int $httpStatus): void
    {
        $this->transition($operation, $idempotencyKey, 'failed', ['http_status' => $httpStatus]);
    }

    public function succeeded(string $operation, string $idempotencyKey, array $result): void
    {
        $this->transition($operation, $idempotencyKey, 'succeeded', $this->safeResult($result));
    }

    public function uncertain(string $operation, string $idempotencyKey, array $context): void
    {
        $this->transition($operation, $idempotencyKey, 'uncertain', $this->safeContext($context));
    }

    /** @param array<string, mixed> $result */
    private function transition(string $operation, string $idempotencyKey, string $status, array $result): void
    {
        $now = $this->now();
        $statement = $this->pdo->prepare(
            'UPDATE pagou_payment_operations SET status = :status, response_json = :result, finished_at = :finished_at, updated_at = :updated_at, version = version + 1 '
            . 'WHERE idempotency_key = :key AND operation_type = :operation AND status IN (\'started\', \'uncertain\')'
        );
        $statement->execute([
            'status' => $status,
            'result' => $this->json($result),
            'finished_at' => $now,
            'updated_at' => $now,
            'key' => $idempotencyKey,
            'operation' => $operation,
        ]);
        if ($statement->rowCount() !== 1) {
            $existing = $this->pdo->prepare('SELECT status FROM pagou_payment_operations WHERE idempotency_key = :key AND operation_type = :operation');
            $existing->execute(['key' => $idempotencyKey, 'operation' => $operation]);
            $current = $existing->fetchColumn();
            if ($current === $status || ($current === 'succeeded' && $status === 'uncertain')) {
                return;
            }
            throw new \RuntimeException('Pix operation journal transition was rejected.');
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, scalar>
     */
    private function safeContext(array $context): array
    {
        $allowed = ['attempt_id', 'invoice_id', 'pix_id', 'due_date', 'reason', 'fingerprint'];
        $safe = [];
        foreach ($allowed as $key) {
            if (isset($context[$key]) && is_scalar($context[$key])) {
                $safe[$key] = (string) $context[$key];
            }
        }
        return $safe;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, scalar>
     */
    private function safeResult(array $result): array
    {
        $safe = [];
        foreach (['remote_id', 'status'] as $key) {
            if (isset($result[$key]) && is_scalar($result[$key])) {
                $safe[$key] = (string) $result[$key];
            }
        }
        return $safe;
    }

    /** @param array<string, scalar> $context */
    private function attemptId(array $context): string
    {
        $attemptId = $context['attempt_id'] ?? '';
        if (!is_string($attemptId) || $attemptId === '') {
            throw new \InvalidArgumentException('Pix operation journal requires an attempt_id.');
        }
        return $attemptId;
    }

    /** @param array<string, scalar> $value */
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
