<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Persistence\Webhook;

use PDO;
use Pagou\Whmcs\Application\Webhook\WebhookEventParser;

/** Recovers financial facts only from signature-verified payment deliveries. */
final class StoredPaymentEvidence
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forCharge(string $remoteId, string $method, ?string $paidAt): array
    {
        $query = $this->pdo->prepare('SELECT event_key, payload_json FROM pagou_webhook_deliveries WHERE provider_event_id = :id AND signature_valid = 1 ORDER BY received_at_utc ASC');
        $query->execute(['id' => $remoteId]);
        $payments = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $event = (new WebhookEventParser())->parse((string) $row['payload_json'], (string) $row['event_key']);
            if ($event->remoteId !== $remoteId || $event->paymentMethod !== $method || $event->status !== 'paid') {
                continue;
            }
            $data = is_array($event->payload['data'] ?? null) ? $event->payload['data'] : $event->payload;
            $amount = $data['amount'] ?? null;
            $amount = is_array($amount) ? ($amount['paid'] ?? null) : $amount;
            $id = $data['transaction_id'] ?? null;
            $date = $data['paid_in'] ?? $data['paid_at'] ?? $paidAt;
            if (!is_string($id) || trim($id) === '' || !is_scalar($amount) || !is_string($date) || trim($date) === '') {
                continue;
            }
            $normalizedAmount = \Pagou\Whmcs\Domain\Money::fromDecimal((string) $amount)->jsonSerialize();
            $payment = ['transaction_id' => $id, 'amount' => $normalizedAmount, 'paid_at' => $date, 'event_key' => $event->deliveryKey];
            // The Pix txid (external_id) is what WHMCS shows as the Transaction ID.
            $txid = $method === 'pix' ? ($data['external_id'] ?? null) : null;
            if (is_string($txid) && preg_match('/^[A-Za-z0-9]{1,64}$/D', trim($txid)) === 1) {
                $payment['native_transaction_id'] = trim($txid);
            }
            if (isset($payments[$id]) && $payments[$id]['amount'] !== $payment['amount']) {
                throw new \UnexpectedValueException('Verified payment deliveries disagree about the amount.');
            }
            $payments[$id] = $payment;
        }
        return array_values($payments);
    }
}
