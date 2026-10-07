<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\Infrastructure;

use Pagou\Whmcs\Application\Async\JobPriority;
use Pagou\Whmcs\Application\Async\OperationJob;
use Pagou\Whmcs\Application\Async\OperationOutbox;
use Pagou\Whmcs\Application\Async\OperationType;
use Pagou\Whmcs\Payment\Card\Contracts\CardReconciliationScheduler;

/** Queues local inspection only. It never replays a card mutation. */
final class OutboxCardReconciliationScheduler implements CardReconciliationScheduler
{
    public function __construct(private readonly OperationOutbox $outbox)
    {
    }

    public function schedule(string $operation, string $idempotencyKey, array $context): void
    {
        $payload = ['operation' => $operation, 'idempotency_key_hash' => hash('sha256', $idempotencyKey)];
        foreach (['attempt_id', 'invoice_id', 'charge_id', 'card_id', 'customer_id', 'reference'] as $key) {
            if (isset($context[$key]) && is_scalar($context[$key])) {
                $payload[$key] = (string) $context[$key];
            }
        }
        if (isset($payload['charge_id'])) {
            $payload['remote_id'] = $payload['charge_id'];
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $queued = $this->outbox->enqueue(new OperationJob($this->uuid(), OperationType::ReconcileUncertainOperation, hash('sha256', 'card:reconcile:' . $idempotencyKey), JobPriority::FinancialRecovery, $payload, $now, $now));
        if (!$queued) {
            throw new \RuntimeException('Unable to queue card reconciliation.');
        }
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
