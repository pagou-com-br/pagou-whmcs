<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Persistence\Webhook;

use PDO;
use PDOException;
use Pagou\Whmcs\Application\Webhook\WebhookInbox;
use Pagou\Whmcs\Support\Uuid;

final class PdoWebhookInbox implements WebhookInbox
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function reserve(string $deliveryKey, \DateTimeImmutable $receivedAt): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO pagou_webhook_deliveries '
                . '(id, event_key, event_type, signature_valid, payload_json, received_at_utc, processing_status) '
                . 'VALUES (:id, :event_key, :event_type, 1, :payload_json, :received_at, :status)'
            );
            $statement->execute([
                'id' => Uuid::v4(),
                'event_key' => $deliveryKey,
                'event_type' => 'pending',
                'payload_json' => '{}',
                'received_at' => $receivedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                'status' => 'reserved',
            ]);

            return true;
        } catch (PDOException $exception) {
            if (in_array((string) $exception->getCode(), ['19', '23000'], true)) {
                return false;
            }
            throw $exception;
        }
    }

    public function markProcessed(string $deliveryKey): void
    {
        $this->transition($deliveryKey, 'queued', null);
    }

    public function markRejected(string $deliveryKey, string $reason): void
    {
        $this->transition($deliveryKey, 'rejected', mb_substr($reason, 0, 255));
    }

    private function transition(string $deliveryKey, string $status, ?string $reason): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE pagou_webhook_deliveries SET processing_status = :status, error_message = :reason, '
            . 'processed_at_utc = :processed_at WHERE event_key = :event_key'
        );
        $statement->execute([
            'status' => $status,
            'reason' => $reason,
            'processed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            'event_key' => $deliveryKey,
        ]);
    }
}
