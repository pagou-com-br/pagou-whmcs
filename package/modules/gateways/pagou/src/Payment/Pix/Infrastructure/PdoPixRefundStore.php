<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Pix\Infrastructure;

use PDO;
use Pagou\Whmcs\Support\Uuid;

/** Durable financial history. The public API currently permits one refund per Pix. */
final class PdoPixRefundStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(string $attemptId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM pagou_pix_refunds WHERE attempt_id = ?');
        $query->execute([$attemptId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $attempt
     * @return array<string,mixed>
     */
    public function reserve(array $attempt, string $transactionId, int $amount, int $receipt, int $actor): array
    {
        $existing = $this->find((string) $attempt['id']);
        if ($existing !== null) {
            $this->assertSameRequest($existing, $transactionId, $amount);
            return $existing + ['created' => false];
        }
        $now = self::now();
        $query = $this->pdo->prepare('INSERT INTO pagou_pix_refunds '
            . '(id, attempt_id, invoice_id, client_id, remote_id, original_transaction_id, amount_cents, receipt_cents, status, actor_id, requested_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        try {
            $query->execute([Uuid::v4(), $attempt['id'], $attempt['invoice_id'], $attempt['client_id'], $attempt['remote_id'], $transactionId, $amount, $receipt, 'requested', $actor, $now, $now]);
        } catch (\PDOException $error) {
            $existing = $this->find((string) $attempt['id']);
            if ($existing === null) {
                throw $error;
            }
            $this->assertSameRequest($existing, $transactionId, $amount);
            return $existing + ['created' => false];
        }
        return ($this->find((string) $attempt['id']) ?? throw new \RuntimeException('Refund reservation missing.')) + ['created' => true];
    }

    /** @param array<string,mixed> $row */
    private function assertSameRequest(array $row, string $transactionId, int $amount): void
    {
        if ($row['original_transaction_id'] !== $transactionId || (int) $row['amount_cents'] !== $amount) {
            // Completing a pending refund with another amount is the common mistake; say which amount applies.
            $pending = $row['original_transaction_id'] === $transactionId && in_array($row['status'], ['requested', 'uncertain', 'confirmed'], true);
            throw new \DomainException($pending
                ? 'Este Pix já possui uma devolução de R$ ' . number_format((int) $row['amount_cents'] / 100, 2, ',', '.') . '. Informe esse valor para concluir o registro.'
                : 'Este Pix já possui uma solicitação de reembolso. Confira o pedido existente.');
        }
    }

    public function mark(string $attemptId, string $status, ?string $error = null): void
    {
        $query = $this->pdo->prepare("UPDATE pagou_pix_refunds SET status = ?, error_code = ?, updated_at = ? WHERE attempt_id = ? AND status <> 'applied'");
        $query->execute([$status, $error, self::now(), $attemptId]);
    }

    public function confirm(string $attemptId, string $providerId): void
    {
        $query = $this->pdo->prepare("UPDATE pagou_pix_refunds SET provider_refund_id = ?, confirmed_at = COALESCE(confirmed_at, ?), status = CASE WHEN native_dispatch_at IS NULL THEN 'confirmed' ELSE status END, updated_at = ? WHERE attempt_id = ? AND status <> 'applied' AND (provider_refund_id IS NULL OR provider_refund_id = ?)");
        $query->execute([$providerId, self::now(), self::now(), $attemptId, $providerId]);
    }

    /** An uncertain native write is recovered by reading the transaction, never by resending it. */
    public function claimNative(string $attemptId): bool
    {
        $query = $this->pdo->prepare("UPDATE pagou_pix_refunds SET native_dispatch_at = ?, status = 'applying', updated_at = ? WHERE attempt_id = ? AND status = 'confirmed' AND native_dispatch_at IS NULL");
        $query->execute([self::now(), self::now(), $attemptId]);
        return $query->rowCount() === 1;
    }

    /** @param array<string,mixed> $row */
    public function applied(array $row): void
    {
        $key = hash('sha256', 'pix-refund:' . $row['remote_id'] . ':' . $row['provider_refund_id']);
        $check = $this->pdo->prepare('SELECT 1 FROM pagou_ledger_entries WHERE idempotency_key = ?');
        $check->execute([$key]);
        if ($check->fetchColumn() === false) {
            $query = $this->pdo->prepare('INSERT INTO pagou_ledger_entries (id, idempotency_key, invoice_id, client_id, attempt_id, entry_type, amount_cents, currency, payment_at_utc, effective_at_utc, metadata_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $query->execute([Uuid::v4(), $key, $row['invoice_id'], $row['client_id'], $row['attempt_id'], 'refunded_payment', -(int) $row['amount_cents'], 'BRL', $row['confirmed_at'], self::now(), json_encode([
                'method' => 'pix', 'remote_charge_id' => $row['remote_id'], 'refund_id' => $row['provider_refund_id'],
                'original_transaction_id' => $row['original_transaction_id'], 'partial' => (int) $row['amount_cents'] < (int) $row['receipt_cents'],
            ], JSON_THROW_ON_ERROR), self::now()]);
        }
        $this->mark((string) $row['attempt_id'], 'applied');
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
