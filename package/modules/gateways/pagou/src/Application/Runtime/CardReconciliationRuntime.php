<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use Closure;
use PDO;
use Pagou\Whmcs\Application\Async\OperationOutbox;
use Pagou\Whmcs\Infrastructure\Whmcs\NativeWhmcsFinancialPort;
use Pagou\Whmcs\Payment\Card\CardPaymentService;
use Pagou\Whmcs\Payment\Card\CardStatus;
use Pagou\Whmcs\Payment\Card\Dto\CardCharge;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardAttemptStore;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardRefundStore;
use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\OutboxFollowUpQueue;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\PdoInvoiceClientResolver;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\PdoLedgerRepository;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\PdoReconciliationFindingRepository;
use Pagou\Whmcs\Payment\Ledger\ReceivedPayment;
use Pagou\Whmcs\Payment\Ledger\ReceivedPaymentApplier;
use Pagou\Whmcs\Support\Uuid;

/**
 * Consulta somente cobranças que já possuem identidade remota. Uma operação
 * incerta sem ID nunca é repetida, pois isso poderia duplicar uma cobrança.
 */
final class CardReconciliationRuntime
{
    /** @var Closure(string, array<string, mixed>): array<string, mixed> */
    private readonly Closure $localApi;

    /** @param callable(string, array<string, mixed>): array<string, mixed> $localApi */
    public function __construct(
        private readonly PDO $pdo,
        private readonly CardPaymentService $cards,
        callable $localApi,
        private readonly OperationOutbox $outbox,
    ) {
        $this->localApi = Closure::fromCallable($localApi);
    }

    /** @return array{inspected:int,updated:int,applied:int,findings:int,failed:int} */
    public function run(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $report = ['inspected' => 0, 'updated' => 0, 'applied' => 0, 'findings' => 0, 'failed' => 0];

        foreach ($this->unidentifiedUncertainAttempts($limit) as $attempt) {
            try {
                if ($this->restoreKnownAttempt($attempt)) {
                    $report['updated']++;
                    continue;
                }
            } catch (\Throwable) {
                $report['failed']++;
                continue;
            }
            $this->recordFinding(
                (string) $attempt['id'],
                'card_remote_identity_unavailable',
                'high',
                ['invoice_id' => (int) $attempt['invoice_id']],
            );
            $report['findings']++;
        }

        foreach ($this->outstandingCharges($limit) as $chargeId) {
            $report['inspected']++;
            try {
                $outcome = $this->refresh($chargeId);
                $report[$outcome]++;
            } catch (\Throwable) {
                $report['failed']++;
            } finally {
                // Failed reads also rotate so one unavailable charge cannot starve the batch.
                $checked = $this->pdo->prepare('UPDATE pagou_card_transactions SET updated_at = :at WHERE provider_transaction_id = :id');
                $checked->execute(['at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'), 'id' => $chargeId]);
            }
        }

        return $report;
    }

    /** @return 'updated'|'applied'|'findings' */
    public function refresh(string $chargeId): string
    {
        $chargeId = trim($chargeId);
        if ($chargeId === '') {
            throw new \InvalidArgumentException('A cobrança do cartão não foi informada.');
        }

        $charge = $this->cards->reconcile($chargeId);
        if (!hash_equals($chargeId, $charge->id)) {
            throw new \DomainException('A consulta retornou outra cobrança de cartão.');
        }
        return $this->process($charge);
    }

    /** @return 'updated'|'applied'|'findings' */
    public function process(CardCharge $charge): string
    {
        $attempts = new PdoCardAttemptStore($this->pdo);
        $local = $attempts->byCharge($charge->id);
        if ($local === null) {
            throw new \OutOfBoundsException('A cobrança não pertence a uma tentativa local de cartão.');
        }

        if ($charge->amountCentavos < 1 || $charge->amountCentavos !== (int) $local['amount_cents']) {
            $attempts->uncertain((string) $local['id']);
            $this->recordFinding(
                (string) $local['id'],
                'card_amount_mismatch',
                'critical',
                [
                    'invoice_id' => (int) $local['invoice_id'],
                    'local_amount_cents' => (int) $local['amount_cents'],
                    'remote_amount_cents' => $charge->amountCentavos,
                    'charge_id' => $charge->id,
                ],
            );

            return 'findings';
        }

        $attempts->synchronize($charge);
        $updated = $attempts->byCharge($charge->id);
        if ($updated === null || $updated['capture_status'] !== $charge->status->value) {
            return 'updated';
        }
        if ($charge->status->permitsAutomaticInvoiceSettlement()) {
            return $this->applyPayment($local, $charge) ? 'applied' : 'findings';
        }

        (new PdoCardRefundStore($this->pdo))->synchronize($charge);

        if (in_array($charge->status, [CardStatus::Reversed, CardStatus::Refunded], true)) {
            $refund = $this->pdo->prepare('SELECT 1 FROM pagou_card_refunds WHERE card_transaction_id = :id LIMIT 1');
            $refund->execute(['id' => $local['card_transaction_id']]);
            if (!in_array($local['capture_status'], ['paid', 'settled'], true) && $refund->fetchColumn() === false) {
                return 'updated';
            }
            $this->recordFinding(
                (string) $local['id'],
                'card_refund_confirmed_requires_review',
                'high',
                ['invoice_id' => (int) $local['invoice_id'], 'charge_id' => $charge->id],
            );
            return 'findings';
        }

        if ($charge->status === CardStatus::ChargedBack) {
            $this->recordFinding(
                (string) $local['id'],
                'card_chargeback_requires_review',
                'critical',
                ['invoice_id' => (int) $local['invoice_id'], 'charge_id' => $charge->id],
            );

            return 'findings';
        }

        if ($charge->status === CardStatus::Unknown) {
            $this->recordFinding(
                (string) $local['id'],
                'card_unknown_provider_status',
                'high',
                [
                    'invoice_id' => (int) $local['invoice_id'],
                    'charge_id' => $charge->id,
                    'provider_status' => mb_substr((string) $charge->providerStatus, 0, 64),
                ],
            );

            return 'findings';
        }

        return 'updated';
    }

    /** @param array<string, mixed> $local */
    private function applyPayment(array $local, CardCharge $charge): bool
    {
        $key = EconomicPaymentKey::fromRemotePayment((int) $local['invoice_id'], 'pagou', $charge->id);
        $payment = new ReceivedPayment(
            $key,
            'card-status:' . $charge->id . ':' . $charge->status->value,
            (int) $local['invoice_id'],
            (int) $local['amount_cents'],
            'pagou',
            $charge->id,
            $this->settledAt($charge),
            'card',
        );
        $repository = new PdoLedgerRepository(
            $this->pdo,
            new PdoInvoiceClientResolver($this->pdo),
            new PdoReconciliationFindingRepository($this->pdo),
        );
        $result = (new ReceivedPaymentApplier(
            $repository,
            new NativeWhmcsFinancialPort($this->pdo, $this->localApi),
            new OutboxFollowUpQueue($this->pdo, $this->outbox),
        ))->apply($payment);

        return in_array($result->outcome, ['applied', 'replay'], true);
    }

    private function settledAt(CardCharge $charge): \DateTimeImmutable
    {
        foreach (['paid_at', 'captured_at', 'settled_at', 'updated_at', 'created_at'] as $key) {
            $value = $charge->raw[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            try {
                return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                continue;
            }
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @return list<string> */
    private function outstandingCharges(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT c.provider_transaction_id, c.capture_status, a.invoice_id FROM pagou_card_transactions c "
            . 'INNER JOIN pagou_payment_attempts a ON a.id = c.attempt_id '
            . "WHERE c.provider_transaction_id IS NOT NULL AND (a.status IN ('pending', 'authorized', "
            . "'action_required', 'uncertain') OR c.capture_status IN ('pending', 'authorized', "
            . "'action_required', 'unknown') OR (c.capture_status IN ('paid', 'settled') AND c.updated_at <= :monitor_before) "
            . 'OR EXISTS (SELECT 1 FROM pagou_card_refunds r WHERE r.card_transaction_id = c.id '
            . "AND r.status NOT IN ('reversed', 'refunded', 'failed'))) "
            . 'ORDER BY c.updated_at ASC, c.id ASC LIMIT :fetch_limit'
        );
        $statement->bindValue(
            'monitor_before',
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-6 hours')->format('Y-m-d H:i:s.u'),
        );
        $statement->bindValue('fetch_limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $ids = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $value = $row['provider_transaction_id'] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $ids[] = $value;
            if (count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    private function unidentifiedUncertainAttempts(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT a.* FROM pagou_payment_attempts a LEFT JOIN pagou_card_transactions c ON c.attempt_id = a.id WHERE a.method = 'card' "
            . "AND (a.status = 'uncertain' OR (a.status = 'dispatching' AND a.updated_at < :stale)) AND c.id IS NULL ORDER BY a.updated_at ASC LIMIT :limit"
        );
        $statement->bindValue('stale', (new \DateTimeImmutable('-10 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'));
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $attempt */
    private function restoreKnownAttempt(array $attempt): bool
    {
        $remoteId = (string) ($attempt['remote_id'] ?? '');
        if ($remoteId === '') {
            $operation = $this->pdo->prepare("SELECT response_json FROM pagou_payment_operations WHERE idempotency_key = :key AND operation_type IN ('card.charge.create', 'card.charge.recurring') AND status = 'succeeded'");
            $operation->execute(['key' => hash('sha256', (string) $attempt['idempotency_key'])]);
            $response = json_decode((string) ($operation->fetchColumn() ?: '{}'), true, 32, JSON_THROW_ON_ERROR);
            $remoteId = is_string($response['remote_id'] ?? null) ? $response['remote_id'] : '';
        }
        if ($remoteId === '') {
            return false;
        }
        $charge = $this->cards->getCharge($remoteId);
        if (!hash_equals($remoteId, $charge->id) || $charge->amountCentavos !== (int) $attempt['amount_cents']) {
            $this->recordFinding(
                (string) $attempt['id'],
                'card_recovery_response_mismatch',
                'critical',
                ['invoice_id' => (int) $attempt['invoice_id']]
            );
            return false;
        }
        $request = json_decode((string) $attempt['request_json'], true, 32, JSON_THROW_ON_ERROR);
        (new PdoCardAttemptStore($this->pdo))->complete(
            (string) $attempt['id'],
            $charge,
            (string) ($request['card_reference'] ?? ''),
            null,
            null,
            max(1, (int) ($request['installments'] ?? 1)),
        );
        $this->process($charge);
        return true;
    }

    /** @param array<string, int|string> $details */
    private function recordFinding(string $attemptId, string $type, string $severity, array $details): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO pagou_reconciliation_findings '
                . '(id, finding_key, attempt_id, severity, finding_type, status, details_json, detected_at_utc, '
                . 'resolved_at_utc, resolved_by, resolution_json, created_at, updated_at, version) '
                . 'VALUES (:id, :finding_key, :attempt_id, :severity, :finding_type, :status, :details_json, '
                . ':detected_at, NULL, NULL, NULL, :created_at, :updated_at, 1)'
            );
            $statement->execute([
                'id' => Uuid::v4(),
                'finding_key' => hash('sha256', 'card|' . $attemptId . '|' . $type),
                'attempt_id' => $attemptId,
                'severity' => $severity,
                'finding_type' => $type,
                'status' => 'open',
                'details_json' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['19', '23000', '23505'], true)) {
                throw $exception;
            }
        }
    }
}
