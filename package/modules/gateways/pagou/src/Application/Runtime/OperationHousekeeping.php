<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;
use Pagou\Whmcs\Application\Async\JobPriority;
use Pagou\Whmcs\Application\Async\OperationScheduler;
use Pagou\Whmcs\Application\Async\OperationType;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\ReceiptBooking;
use Pagou\Whmcs\Support\Uuid;

/**
 * Closes what the queue can no longer resolve by itself: an uncertain operation whose
 * charge has meanwhile been settled, a boleto cancellation left without an outcome, a
 * refused boleto still shown as being prepared, an issuance review whose charge was replaced
 * and the listing of a charge not issued once its invoice moved on.
 * Nothing financial is decided here; open payments and refunds are never touched.
 */
final class OperationHousekeeping
{
    private const SETTLED = ['cancelled', 'canceled', 'superseded', 'refunded'];

    public function __construct(private readonly PDO $pdo, private readonly OperationScheduler $scheduler)
    {
    }

    public function settle(\DateTimeImmutable $now, int $limit = 50): int
    {
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.operation_type, o.error_message, a.id AS attempt_id, a.invoice_id, a.status AS attempt_status, a.remote_id '
            . 'FROM pagou_payment_operations o INNER JOIN pagou_payment_attempts a ON a.id = o.attempt_id '
            . "WHERE o.status = 'uncertain' AND o.updated_at < :cutoff "
            . "AND o.operation_type IN ('cancel_boleto', 'cancel_pix', 'issue_boleto', 'reconcile_payment', 'reconcile_uncertain_operation') "
            . 'ORDER BY o.updated_at ASC LIMIT ' . max(1, min(200, $limit))
        );
        $statement->execute(['cutoff' => $now->modify('-10 minutes')->format('Y-m-d H:i:s.u')]);
        $close = $this->pdo->prepare(
            "UPDATE pagou_payment_operations SET status = 'succeeded', error_message = 'settled_by_charge_state', "
            // Distinct placeholders: MySQL native prepares reject a repeated name.
            . 'lease_token = NULL, lease_expires_at = NULL, finished_at = :finished_at, updated_at = :updated_at, version = version + 1 '
            . "WHERE id = :id AND status = 'uncertain'"
        );
        $closed = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string) $row['attempt_status'];
            $booked = $status === 'paid' && $this->booked((int) $row['invoice_id'], (string) $row['remote_id']);
            if (in_array((string) $row['operation_type'], ['cancel_boleto', 'cancel_pix'], true)) {
                $settled = in_array($status, self::SETTLED, true) || $booked;
            } elseif ((string) $row['operation_type'] === 'issue_boleto' || str_starts_with((string) $row['error_message'], 'boleto_issue_recovery')) {
                // An issuance nobody waits for any more: the charge was replaced, paid or cancelled.
                $settled = in_array($status, [...self::SETTLED, 'paid', 'failed'], true);
            } else {
                // Only a payment check that waited for evidence which has since been booked.
                $settled = str_starts_with((string) $row['error_message'], 'payment_evidence_missing')
                    && $booked && !$this->openFinding((string) $row['attempt_id'], 'payment_evidence_missing');
            }
            if ($settled) {
                $at = $now->format('Y-m-d H:i:s.u');
                $close->execute(['finished_at' => $at, 'updated_at' => $at, 'id' => (string) $row['id']]);
                $closed += $close->rowCount();
            }
        }

        return $closed;
    }

    /** Asks Pagou again about boletos still marked as being cancelled with nothing left to do so. */
    public function repair(\DateTimeImmutable $now, int $limit = 20): int
    {
        $statement = $this->pdo->prepare(
            "SELECT a.id, a.invoice_id, a.remote_id FROM pagou_payment_attempts a WHERE a.method = 'boleto' "
            . "AND a.status = 'cancel_requested' AND a.remote_id IS NOT NULL AND a.remote_id <> '' AND a.updated_at < :cutoff "
            . 'AND NOT EXISTS (SELECT 1 FROM pagou_payment_operations o WHERE o.attempt_id = a.id '
            . "AND o.operation_type IN ('cancel_boleto', 'replace_boleto') AND o.status IN ('queued', 'pending', 'leased', 'retrying')) "
            . 'ORDER BY a.updated_at ASC LIMIT ' . max(1, min(100, $limit))
        );
        $statement->execute(['cutoff' => $now->modify('-15 minutes')->format('Y-m-d H:i:s.u')]);
        $scheduled = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // At most once a day per charge: a refusal settles it from the boleto state at Pagou.
            $scheduled += $this->scheduler->schedule(
                Uuid::v4(),
                OperationType::CancelBoleto,
                'boleto:cancel-repair:' . (string) $row['id'] . ':' . $now->format('Y-m-d'),
                JobPriority::FinancialRecovery,
                ['attempt_id' => (string) $row['id'], 'invoice_id' => (int) $row['invoice_id'], 'remote_id' => (string) $row['remote_id']],
            ) ? 1 : 0;
        }

        return $scheduled;
    }

    /** Schedules a lookup for each boleto issuance left without an answer for five minutes. */
    public function recoverIssuance(\DateTimeImmutable $now, int $limit = 20): int
    {
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.updated_at, o.started_at, o.created_at, a.id AS attempt_id, a.invoice_id '
            . 'FROM pagou_payment_operations o INNER JOIN pagou_payment_attempts a ON a.id = o.attempt_id '
            . "WHERE o.operation_type = 'issue_boleto' AND o.status = 'uncertain' AND o.updated_at < :cutoff "
            . "AND a.status = 'queued' AND (a.remote_id IS NULL OR a.remote_id = '') "
            . 'ORDER BY o.updated_at ASC LIMIT ' . max(1, min(100, $limit))
        );
        $statement->execute(['cutoff' => $now->modify('-5 minutes')->format('Y-m-d H:i:s.u')]);
        $scheduled = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $scheduled += $this->scheduler->schedule(
                Uuid::v4(),
                OperationType::ReconcileUncertainOperation,
                // Once per uncertain answer: a later retry that is uncertain again gets its own lookup.
                'boleto:issue-recovery:' . (string) $row['id'] . ':' . substr(hash('sha256', (string) $row['updated_at']), 0, 16),
                JobPriority::FinancialRecovery,
                [
                    'operation' => 'boleto.issue',
                    'attempt_id' => (string) $row['attempt_id'],
                    'invoice_id' => (int) $row['invoice_id'],
                    'issue_operation_id' => (string) $row['id'],
                    'requested_at' => (string) ($row['started_at'] ?? '') !== '' ? (string) $row['started_at'] : (string) $row['created_at'],
                ],
            ) ? 1 : 0;
        }

        return $scheduled;
    }

    /**
     * Shows as failed a boleto whose issuance Pagou refused, or the customer data could not
     * make, while it is still waiting to be prepared: refusals from before the issuance did so.
     */
    public function refuse(\DateTimeImmutable $now, int $limit = 50): int
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, o.error_message FROM pagou_payment_attempts a '
            . 'INNER JOIN pagou_payment_operations o ON o.attempt_id = a.id '
            . "WHERE a.method = 'boleto' AND a.status = 'queued' AND (a.remote_id IS NULL OR a.remote_id = '') "
            . "AND o.operation_type = 'issue_boleto' AND o.status = 'failed' AND o.updated_at < :cutoff "
            . "AND (o.error_message LIKE 'boleto_request_rejected:%' OR o.error_message LIKE 'boleto_local_validation:%') "
            // An unknown local error may come after Pagou created the boleto: that one is never retired.
            . "AND o.error_message <> 'boleto_local_validation:unknown' "
            . 'AND NOT EXISTS (SELECT 1 FROM pagou_payment_operations p WHERE p.attempt_id = a.id '
            . "AND p.status IN ('queued', 'pending', 'leased', 'retrying', 'uncertain')) "
            . 'ORDER BY o.updated_at ASC LIMIT ' . max(1, min(200, $limit))
        );
        $statement->execute(['cutoff' => $now->modify('-5 minutes')->format('Y-m-d H:i:s.u')]);
        $attempts = new PaymentAttemptStore($this->pdo);
        $refused = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($refused[(string) $row['id']])) {
                continue;
            }
            $refused[(string) $row['id']] = true;
            $reason = (string) $row['error_message'];
            $attempts->complete((string) $row['id'], '', 'failed', ['state' => 'failed', 'message' => IssuanceFailure::customerMessage($reason)]);
            // No payer fingerprint: the next view of the invoice tries once more with the current data.
            $attempts->recordFailure((string) $row['id'], $reason);
        }

        return count($refused);
    }

    /**
     * Closes an issuance review once its charge was replaced, paid or cancelled. A review
     * opened because Pagou listed several boletos stays open: those still need a look.
     */
    public function resolve(\DateTimeImmutable $now, int $limit = 50): int
    {
        $statement = $this->pdo->prepare(
            'SELECT f.id, f.details_json, a.status AS attempt_status FROM pagou_reconciliation_findings f '
            . 'INNER JOIN pagou_payment_attempts a ON a.id = f.attempt_id '
            . "WHERE f.finding_type = 'boleto_issuance_requires_review' AND f.status = 'open' "
            . "AND a.status IN ('paid', 'cancelled', 'canceled', 'superseded', 'refunded', 'failed') "
            . 'ORDER BY f.detected_at_utc ASC LIMIT ' . max(1, min(200, $limit))
        );
        $statement->execute();
        $close = $this->pdo->prepare(
            "UPDATE pagou_reconciliation_findings SET status = 'resolved', resolved_at_utc = :resolved_at, "
            . 'resolution_json = :resolution, updated_at = :updated_at, version = version + 1 '
            . "WHERE id = :id AND status = 'open'"
        );
        $resolved = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $details = json_decode((string) $row['details_json'], true);
            if (is_array($details) && ($details['reason'] ?? null) === 'ambiguous') {
                continue;
            }
            $at = $now->format('Y-m-d H:i:s.u');
            $close->execute([
                'resolved_at' => $at,
                'resolution' => json_encode(['reason' => 'charge_closed', 'attempt_status' => (string) $row['attempt_status']], JSON_THROW_ON_ERROR),
                'updated_at' => $at,
                'id' => (string) $row['id'],
            ]);
            $resolved += $close->rowCount();
        }

        return $resolved;
    }

    /**
     * Closes the listing of a charge that could not be issued once the invoice moved on:
     * a new charge was attempted, the customer chose another method, or it was paid or cancelled.
     */
    public function closeRefusals(\DateTimeImmutable $now, int $limit = 100): int
    {
        $statement = $this->pdo->prepare(
            'SELECT f.id FROM pagou_reconciliation_findings f INNER JOIN pagou_payment_attempts a ON a.id = f.attempt_id '
            . 'LEFT JOIN tblinvoices i ON i.id = a.invoice_id '
            . "WHERE f.finding_type = 'charge_issuance_refused' AND f.status = 'open' AND (a.status <> 'failed' OR i.id IS NULL "
            // A draft is still open: a refusal recorded on it stands until it is published.
            . "OR i.status NOT IN ('Unpaid', 'Draft') OR i.paymentmethod <> (CASE a.method WHEN 'pix' THEN 'pagou_pix' ELSE 'pagou_boleto' END) "
            . 'OR EXISTS (SELECT 1 FROM pagou_payment_attempts n WHERE n.invoice_id = a.invoice_id AND n.method = a.method AND n.created_at > a.created_at)) '
            . 'ORDER BY f.detected_at_utc ASC LIMIT ' . max(1, min(500, $limit))
        );
        $statement->execute();
        $close = $this->pdo->prepare(
            "UPDATE pagou_reconciliation_findings SET status = 'resolved', resolved_at_utc = :resolved_at, "
            . 'resolution_json = :resolution, updated_at = :updated_at, version = version + 1 '
            . "WHERE id = :id AND status = 'open'"
        );
        $closed = 0;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $at = $now->format('Y-m-d H:i:s.u');
            $close->execute(['resolved_at' => $at, 'resolution' => '{"reason":"invoice_moved_on"}', 'updated_at' => $at, 'id' => (string) $id]);
            $closed += $close->rowCount();
        }

        return $closed;
    }

    private function booked(int $invoiceId, string $remoteId): bool
    {
        if ($remoteId === '') {
            return false;
        }
        $statement = $this->pdo->prepare("SELECT * FROM pagou_ledger_entries WHERE invoice_id = :invoice AND entry_type = 'received_payment'");
        $statement->execute(['invoice' => $invoiceId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            $data = json_decode((string) ($entry['metadata_json'] ?? ''), true);
            if (is_array($data) && ($data['remote_charge_id'] ?? null) === $remoteId && ReceiptBooking::booked($this->pdo, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function openFinding(string $attemptId, string $type): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM pagou_reconciliation_findings WHERE attempt_id = :attempt AND finding_type = :type AND status = 'open' LIMIT 1");
        $statement->execute(['attempt' => $attemptId, 'type' => $type]);

        return $statement->fetchColumn() !== false;
    }
}
