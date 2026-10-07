<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\Infrastructure;

use PDO;
use Pagou\Whmcs\Payment\Card\CardStatus;
use Pagou\Whmcs\Payment\Card\Dto\CardCharge;
use Pagou\Whmcs\Payment\Card\IdempotencyKey;
use Pagou\Whmcs\Support\Uuid;

final class PdoCardAttemptStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id:string,key:IdempotencyKey,created:bool,status:string,remote_id:?string} */
    public function begin(int $invoiceId, int $clientId, int $amountCents, string $cardReference, int $installments): array
    {
        if ($invoiceId < 1 || $clientId < 1 || $amountCents < 1 || $cardReference === '') {
            throw new \InvalidArgumentException('A tentativa de cartão informada é inválida.');
        }
        $ownsTransaction = false;
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $ownsTransaction = true;
            }
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $lock = $this->pdo->prepare('SELECT id FROM tblinvoices WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $invoiceId]);
                if ($lock->fetchColumn() === false) {
                    throw new \OutOfBoundsException('A fatura informada não existe.');
                }
            }
            $latest = $this->latestForInvoice($invoiceId);
            if ($latest !== null && CardStatus::blocksNewAttempt((string) $latest['status'])) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }

                return $this->existingResult($latest);
            }
            $revision = $this->count($invoiceId) + 1;
            $id = Uuid::v4();
            $rawKey = 'card:invoice:' . $invoiceId . ':amount:' . $amountCents . ':attempt:' . $revision;
            $key = hash('sha256', $rawKey);
            $now = $this->now();
            $request = json_encode(
                ['revision' => $revision, 'card_reference' => $cardReference, 'installments' => $installments],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
            $statement = $this->pdo->prepare(
                'INSERT INTO pagou_payment_attempts '
                . '(id, invoice_id, client_id, gateway, method, status, amount_cents, currency, idempotency_key, '
                . 'economic_key, request_json, created_at, updated_at, version) '
                . "VALUES (:id, :invoice_id, :client_id, 'pagou_creditcard', 'card', 'dispatching', :amount_cents, "
                . "'BRL', :idempotency_key, :economic_key, :request_json, :created_at, :updated_at, 1)"
            );
            $statement->execute([
                'id' => $id,
                'invoice_id' => $invoiceId,
                'client_id' => $clientId,
                'amount_cents' => $amountCents,
                'idempotency_key' => $key,
                'economic_key' => hash('sha256', 'card:invoice:' . $invoiceId . ':attempt:' . $revision),
                'request_json' => $request,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return ['id' => $id, 'key' => IdempotencyKey::fromString($key), 'created' => true, 'status' => 'dispatching', 'remote_id' => null];
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function complete(string $attemptId, CardCharge $charge, string $cardReference, ?string $brand, ?string $last4, int $installments): bool
    {
        $status = match ($charge->status) {
            CardStatus::Paid, CardStatus::Settled => 'paid',
            CardStatus::Authorized => 'authorized',
            CardStatus::Pending => 'pending',
            CardStatus::ActionRequired => 'action_required',
            CardStatus::Cancelled => 'cancelled',
            CardStatus::Reversed => 'reversed',
            CardStatus::Refunded => 'refunded',
            CardStatus::ChargedBack => 'charged_back',
            CardStatus::Unknown => 'uncertain',
            CardStatus::Failed => 'failed',
        };
        $now = $this->now();
        $response = json_encode(
            ['status' => $charge->status->value, 'provider_status' => $charge->providerStatus],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM pagou_payment_attempts WHERE id = :id'
                . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute(['id' => $attemptId]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if (
                $current === false || $current['method'] !== 'card'
                || (int) $current['amount_cents'] !== $charge->amountCentavos
                || (!empty($current['remote_id']) && !hash_equals((string) $current['remote_id'], $charge->id))
            ) {
                throw new \DomainException('A resposta não corresponde à tentativa de cartão.');
            }
            if ($charge->customerId !== null || $charge->cardId !== null) {
                $reference = \Pagou\Whmcs\Payment\Card\RemoteCardReference::decode($cardReference);
                if (
                    ($charge->customerId !== null && $charge->customerId !== $reference->customerId)
                    || ($charge->cardId !== null && $charge->cardId !== $reference->cardId)
                ) {
                    throw new \DomainException('A cobrança retornada pertence a outro cliente ou cartão.');
                }
            }
            if (!self::acceptsStatus((string) $current['status'], $status)) {
                $this->pdo->commit();
                return false;
            }
            $update = $this->pdo->prepare(
                'UPDATE pagou_payment_attempts SET remote_id = :remote_id, status = :status, response_json = :response, '
                . 'updated_at = :updated_at, version = version + 1 WHERE id = :id'
            );
            $update->execute([
                'remote_id' => $charge->id,
                'status' => $status,
                'response' => $response,
                'updated_at' => $now,
                'id' => $attemptId,
            ]);
            $existing = $this->pdo->prepare('SELECT id FROM pagou_card_transactions WHERE attempt_id = :attempt_id LIMIT 1');
            $existing->execute(['attempt_id' => $attemptId]);
            $transactionId = $existing->fetchColumn();
            if (is_string($transactionId) && $transactionId !== '') {
                $transaction = $this->pdo->prepare(
                    'UPDATE pagou_card_transactions SET provider_transaction_id = :provider, token_reference = :token, '
                    . 'brand = :brand, last4 = :last4, installments = :installments, capture_status = :status, '
                    . 'chargeback_status = :chargeback, updated_at = :updated_at, version = version + 1 WHERE id = :id'
                );
                $transaction->execute(['provider' => $charge->id, 'token' => $cardReference, 'brand' => $brand, 'last4' => $last4, 'installments' => $installments, 'status' => $charge->status->value, 'chargeback' => $charge->status === CardStatus::ChargedBack ? 'open' : null, 'updated_at' => $now, 'id' => $transactionId]);
            } else {
                $transaction = $this->pdo->prepare(
                    'INSERT INTO pagou_card_transactions '
                    . '(id, attempt_id, provider_transaction_id, token_reference, brand, last4, installments, '
                    . 'capture_status, chargeback_status, created_at, updated_at, version) '
                    . 'VALUES (:id, :attempt_id, :provider, :token, :brand, :last4, :installments, :status, :chargeback, :created_at, :updated_at, 1)'
                );
                $transaction->execute(['id' => Uuid::v4(), 'attempt_id' => $attemptId, 'provider' => $charge->id, 'token' => $cardReference, 'brand' => $brand, 'last4' => $last4, 'installments' => $installments, 'status' => $charge->status->value, 'chargeback' => $charge->status === CardStatus::ChargedBack ? 'open' : null, 'created_at' => $now, 'updated_at' => $now]);
            }
            $this->pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function rememberRemoteId(string $attemptId, string $remoteId): void
    {
        if ($remoteId === '') {
            throw new \InvalidArgumentException('A cobrança remota deve ser identificada.');
        }
        $statement = $this->pdo->prepare('UPDATE pagou_payment_attempts SET remote_id = :remote, updated_at = :at WHERE id = :id AND (remote_id IS NULL OR remote_id = :same)');
        $statement->execute(['remote' => $remoteId, 'at' => $this->now(), 'id' => $attemptId, 'same' => $remoteId]);
    }

    public function uncertain(string $attemptId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE pagou_payment_attempts SET status = 'uncertain', updated_at = :updated_at, "
            . "version = version + 1 WHERE id = :id AND status IN ('created', 'queued', 'dispatching', 'pending', 'authorized', 'action_required', 'uncertain')"
        );
        $statement->execute(['updated_at' => $this->now(), 'id' => $attemptId]);
    }

    public function failed(string $attemptId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE pagou_payment_attempts SET status = 'failed', updated_at = :updated_at, "
            . "version = version + 1 WHERE id = :id AND status IN ('created', 'queued', 'dispatching', 'pending', 'authorized', 'action_required', 'uncertain')"
        );
        $statement->execute(['updated_at' => $this->now(), 'id' => $attemptId]);
    }

    /** @return array<string, mixed>|null */
    public function byCharge(string $chargeId): ?array
    {
        if (trim($chargeId) === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT a.*, c.id AS card_transaction_id, c.token_reference, c.brand, c.last4, c.installments, '
            . 'c.capture_status, c.chargeback_status FROM pagou_payment_attempts a '
            . 'INNER JOIN pagou_card_transactions c ON c.attempt_id = a.id '
            . 'WHERE c.provider_transaction_id = :charge_id LIMIT 1'
        );
        $statement->execute(['charge_id' => $chargeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function synchronize(CardCharge $charge): ?array
    {
        $current = $this->byCharge($charge->id);
        if ($current === null) {
            return null;
        }
        $this->complete(
            (string) $current['id'],
            $charge,
            (string) ($current['token_reference'] ?? ''),
            is_string($current['brand'] ?? null) ? $current['brand'] : null,
            is_string($current['last4'] ?? null) ? $current['last4'] : null,
            max(1, (int) ($current['installments'] ?? 1)),
        );

        return $this->byCharge($charge->id);
    }

    /** @return array<string, mixed>|null */
    public function latestForInvoice(int $invoiceId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM pagou_payment_attempts WHERE invoice_id = :invoice_id AND method = 'card' "
            . "ORDER BY CASE WHEN status IN ('created', 'queued', 'dispatching', 'pending', 'authorized', 'action_required', 'uncertain', 'paid') THEN 0 ELSE 1 END, created_at DESC LIMIT 1"
        );
        $statement->execute(['invoice_id' => $invoiceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function count(int $invoiceId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM pagou_payment_attempts WHERE invoice_id = :invoice_id AND method = 'card'"
        );
        $statement->execute(['invoice_id' => $invoiceId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id:string,key:IdempotencyKey,created:bool,status:string,remote_id:?string}
     */
    private function existingResult(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'key' => IdempotencyKey::fromString((string) $row['idempotency_key']),
            'created' => false,
            'status' => (string) $row['status'],
            'remote_id' => is_string($row['remote_id'] ?? null) ? $row['remote_id'] : null,
        ];
    }

    private static function acceptsStatus(string $current, string $incoming): bool
    {
        if (in_array($current, ['reversed', 'refunded', 'charged_back'], true)) {
            return $incoming === $current || ($current === 'reversed' && $incoming === 'refunded');
        }
        if ($current === 'paid') {
            return in_array($incoming, ['paid', 'reversed', 'refunded', 'charged_back'], true);
        }
        if (in_array($current, ['failed', 'cancelled'], true)) {
            return $incoming === $current || in_array($incoming, ['paid', 'reversed', 'refunded', 'charged_back'], true);
        }
        return true;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
