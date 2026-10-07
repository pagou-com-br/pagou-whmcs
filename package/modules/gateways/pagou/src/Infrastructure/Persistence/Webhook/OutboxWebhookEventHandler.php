<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Persistence\Webhook;

use PDO;
use Pagou\Whmcs\Application\Async\JobPriority;
use Pagou\Whmcs\Application\Async\OperationJob;
use Pagou\Whmcs\Application\Async\OperationOutbox;
use Pagou\Whmcs\Application\Async\OperationType;
use Pagou\Whmcs\Application\Webhook\WebhookEvent;
use Pagou\Whmcs\Application\Webhook\WebhookEventHandler;
use Pagou\Whmcs\Support\Uuid;

final class OutboxWebhookEventHandler implements WebhookEventHandler
{
    public function __construct(private readonly PDO $pdo, private readonly OperationOutbox $outbox)
    {
    }

    public function handle(WebhookEvent $event): void
    {
        $attemptId = $this->attemptId($event->remoteId);
        $payload = $this->reconciliationPayload($event, $attemptId);
        $update = $this->pdo->prepare(
            'UPDATE pagou_webhook_deliveries SET event_type = :event_type, provider_event_id = :provider_event_id, '
            . 'payload_json = :payload_json, attempt_id = :attempt_id WHERE event_key = :event_key'
        );
        $update->execute([
            'event_type' => $event->type,
            'provider_event_id' => $event->remoteId,
            'payload_json' => json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'attempt_id' => $attemptId,
            'event_key' => $event->deliveryKey,
        ]);

        (new \Pagou\Whmcs\Application\Runtime\PaymentIdentityStore($this->pdo))->observeWebhook($event);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $queued = $this->outbox->enqueue(new OperationJob(
            Uuid::v4(),
            OperationType::ReconcilePayment,
            'webhook:' . hash('sha256', $event->deliveryKey),
            $event->status === 'paid' ? JobPriority::PaymentConfirmation : JobPriority::FinancialRecovery,
            $payload,
            $now,
            $now,
        ));
        if (!$queued && $this->alreadyQueued($event->deliveryKey)) {
            return;
        }
        if (!$queued) {
            throw new \RuntimeException('Unable to queue webhook reconciliation.');
        }
    }

    private function attemptId(?string $remoteId): ?string
    {
        if ($remoteId === null || $remoteId === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id FROM pagou_payment_attempts WHERE remote_id = :remote_id ORDER BY created_at DESC LIMIT 1'
        );
        $statement->execute(['remote_id' => $remoteId]);
        $id = $statement->fetchColumn();
        if (!is_string($id) || $id === '') {
            // A boleto paid through its embedded Pix is notified with the Pix identifier.
            $linked = $this->pdo->prepare(
                'SELECT a.id FROM pagou_issued_parties p INNER JOIN pagou_payment_attempts a ON a.id = p.attempt_id '
                . "WHERE p.pix_id = :pix_id AND p.link_conflict = 0 AND a.method = 'boleto' ORDER BY a.created_at DESC LIMIT 1"
            );
            $linked->execute(['pix_id' => $remoteId]);
            $id = $linked->fetchColumn();
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @return array<string, scalar|null> */
    private function reconciliationPayload(WebhookEvent $event, ?string $attemptId): array
    {
        $data = is_array($event->payload['data'] ?? null) ? $event->payload['data'] : $event->payload;
        $amount = $data['amount'] ?? null;
        if (is_array($amount)) {
            $amount = $amount['paid'] ?? null;
        }

        return [
            'attempt_id' => $attemptId,
            'event_key' => $event->deliveryKey,
            'event_type' => $event->type,
            'method' => $event->paymentMethod,
            'status' => $event->status,
            'remote_id' => $event->remoteId,
            'transaction_id' => $this->scalar($data['transaction_id'] ?? null),
            'amount' => is_int($amount) || is_float($amount) || is_string($amount) ? (string) $amount : null,
            'paid_at' => $this->scalar($data['paid_in'] ?? $data['paid_at'] ?? null),
            'payment_type' => $this->scalar($data['payment_type'] ?? null),
        ];
    }

    private function scalar(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function alreadyQueued(string $deliveryKey): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM pagou_payment_operations WHERE deduplication_key = :key LIMIT 1'
        );
        $statement->execute(['key' => hash('sha256', 'webhook:' . hash('sha256', $deliveryKey))]);

        return $statement->fetchColumn() !== false;
    }
}
