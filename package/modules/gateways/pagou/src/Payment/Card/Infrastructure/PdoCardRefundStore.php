<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\Infrastructure;

use PDO;
use Pagou\Whmcs\Payment\Card\CardStatus;
use Pagou\Whmcs\Payment\Card\Dto\CardCharge;
use Pagou\Whmcs\Payment\Card\IdempotencyKey;
use Pagou\Whmcs\Support\Uuid;

final class PdoCardRefundStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id:string,created:bool,status:string,provider_refund_id:?string} */
    public function begin(string $chargeId, int $amountCents, IdempotencyKey $key): array
    {
        if ($chargeId === '' || $amountCents < 1) {
            throw new \InvalidArgumentException('O reembolso de cartão informado é inválido.');
        }
        $existing = $this->findByKey($key->value());
        if ($existing !== null) {
            return $this->result($existing, false);
        }
        $transaction = $this->pdo->prepare(
            'SELECT id FROM pagou_card_transactions WHERE provider_transaction_id = :charge_id LIMIT 1'
        );
        $transaction->execute(['charge_id' => $chargeId]);
        $transactionId = $transaction->fetchColumn();
        if (!is_string($transactionId) || $transactionId === '') {
            throw new \OutOfBoundsException('A transação de cartão não foi encontrada.');
        }
        $id = Uuid::v4();
        $now = $this->now();
        $insert = $this->pdo->prepare(
            'INSERT INTO pagou_card_refunds '
            . '(id, card_transaction_id, provider_refund_id, idempotency_key, amount_cents, status, reason, '
            . 'requested_at_utc, completed_at_utc, response_json, created_at, updated_at, version) '
            . 'VALUES (:id, :transaction_id, NULL, :idempotency_key, :amount_cents, :status, NULL, '
            . ':requested_at, NULL, NULL, :created_at, :updated_at, 1)'
        );
        try {
            $insert->execute([
                'id' => $id,
                'transaction_id' => $transactionId,
                'idempotency_key' => $key->value(),
                'amount_cents' => $amountCents,
                'status' => 'dispatching',
                'requested_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\PDOException $exception) {
            // A concurrent caller may have reserved the same operation.
            $existing = $this->findByKey($key->value());
            if ($existing === null) {
                throw $exception;
            }
            return $this->result($existing, false);
        }

        return ['id' => $id, 'created' => true, 'status' => 'dispatching', 'provider_refund_id' => null];
    }

    /** Only a confirmed full reversal/refund closes a local refund. */
    public function complete(string $id, CardCharge $charge): void
    {
        $statement = $this->pdo->prepare(
            'SELECT r.amount_cents, c.provider_transaction_id FROM pagou_card_refunds r '
            . 'INNER JOIN pagou_card_transactions c ON c.id = r.card_transaction_id WHERE r.id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (
            $row === false || $row['provider_transaction_id'] !== $charge->id
            || (int) $row['amount_cents'] !== $charge->amountCentavos
        ) {
            throw new \DomainException('A resposta não corresponde ao estorno integral solicitado.');
        }
        $confirmed = in_array($charge->status, [CardStatus::Reversed, CardStatus::Refunded], true);
        $now = $this->now();
        $statement = $this->pdo->prepare(
            'UPDATE pagou_card_refunds SET provider_refund_id = :provider_id, status = :status, '
            . 'completed_at_utc = :completed_at, response_json = :response, updated_at = :updated_at, '
            . "version = version + 1 WHERE id = :id AND status NOT IN ('reversed', 'refunded', 'failed')"
        );
        $statement->execute([
            'provider_id' => $confirmed ? $charge->id : null,
            'status' => $confirmed ? $charge->status->value : 'uncertain',
            'completed_at' => $confirmed ? $now : null,
            'response' => json_encode(
                ['status' => $charge->status->value, 'provider_status' => $charge->providerStatus],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
            'updated_at' => $now,
            'id' => $id,
        ]);
    }

    public function synchronize(CardCharge $charge): void
    {
        if (!in_array($charge->status, [CardStatus::Reversed, CardStatus::Refunded], true)) {
            return;
        }
        $statement = $this->pdo->prepare(
            'SELECT r.id FROM pagou_card_refunds r '
            . 'INNER JOIN pagou_card_transactions c ON c.id = r.card_transaction_id '
            . 'WHERE c.provider_transaction_id = :charge_id '
            . "AND r.status NOT IN ('reversed', 'refunded', 'failed')"
        );
        $statement->execute(['charge_id' => $charge->id]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->complete((string) $id, $charge);
        }
    }

    public function mark(string $id, string $status): void
    {
        if (!in_array($status, ['failed', 'uncertain'], true)) {
            throw new \InvalidArgumentException('O estado do reembolso é inválido.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE pagou_card_refunds SET status = :status, updated_at = :updated_at, '
            . "version = version + 1 WHERE id = :id AND status NOT IN ('reversed', 'refunded')"
        );
        $statement->execute(['status' => $status, 'updated_at' => $this->now(), 'id' => $id]);
    }

    /** @return array<string, mixed>|null */
    private function findByKey(string $key): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM pagou_card_refunds WHERE idempotency_key = :idempotency_key LIMIT 1'
        );
        $statement->execute(['idempotency_key' => $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id:string,created:bool,status:string,provider_refund_id:?string}
     */
    private function result(array $row, bool $created): array
    {
        return [
            'id' => (string) $row['id'],
            'created' => $created,
            'status' => (string) $row['status'],
            'provider_refund_id' => is_string($row['provider_refund_id'] ?? null) ? $row['provider_refund_id'] : null,
        ];
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
