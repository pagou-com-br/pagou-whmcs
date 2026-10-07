<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Infrastructure;

use Pagou\Whmcs\Application\Async\JobPriority;
use Pagou\Whmcs\Application\Async\OperationJob;
use Pagou\Whmcs\Application\Async\OperationOutbox;
use Pagou\Whmcs\Application\Async\OperationType;
use Pagou\Whmcs\Payment\Pix\Contracts\ReconciliationScheduler;

/** Queues local reconciliation only. It never repeats the remote Pix mutation. */
final class OutboxReconciliationScheduler implements ReconciliationScheduler
{
    public function __construct(private readonly OperationOutbox $outbox)
    {
    }

    public function schedule(string $operation, string $idempotencyKey, array $context): void
    {
        $attemptId = $context['attempt_id'] ?? null;
        if (!is_string($attemptId) || $attemptId === '') {
            throw new \InvalidArgumentException('Pix reconciliation requires an attempt_id.');
        }
        $payload = ['attempt_id' => $attemptId, 'operation' => $operation, 'idempotency_key' => $idempotencyKey];
        if (isset($context['pix_id']) && is_scalar($context['pix_id'])) {
            $payload['pix_id'] = (string) $context['pix_id'];
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->outbox->enqueue(new OperationJob(
            $this->uuid(),
            OperationType::ReconcileUncertainOperation,
            'pix:reconcile:' . hash('sha256', $idempotencyKey),
            JobPriority::FinancialRecovery,
            $payload,
            $now,
            $now,
        ));
        // false means the durable deduplication key already exists; exceptions still propagate.
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
