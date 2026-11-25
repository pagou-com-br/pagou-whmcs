<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;

/**
 * Closes the review of an invoice email with an unknown outcome once nobody needs that
 * email any more: the invoice was paid, cancelled or refunded, or the client turned invoice
 * emails off, so WHMCS does not send it. Nothing is sent again here, and the delivery
 * records that prevent a second send are kept as they are.
 */
final class InvoiceEmailReview
{
    public const DISABLED = 'invoice_emails_disabled';
    public const INVOICE_CLOSED = 'settled_by_invoice_state';
    private const CLOSED_INVOICE = ['Paid', 'Cancelled', 'Refunded'];

    /** @var array<int, ?string> */
    private array $reasons = [];

    /** @param \Closure(int): bool $invoiceEmailsDisabled */
    public function __construct(private readonly PDO $pdo, private readonly \Closure $invoiceEmailsDisabled)
    {
    }

    public function close(\DateTimeImmutable $now, int $limit = 50): int
    {
        $limit = max(1, min(200, $limit));
        $at = $now->format('Y-m-d H:i:s.u');
        $operations = $this->pdo->prepare(
            "SELECT id, payload_json FROM pagou_payment_operations WHERE operation_type = 'deliver_invoice_email' "
            . "AND status = 'uncertain' AND updated_at < :cutoff ORDER BY updated_at ASC LIMIT " . $limit
        );
        $operations->execute(['cutoff' => $now->modify('-10 minutes')->format('Y-m-d H:i:s.u')]);
        $settle = $this->pdo->prepare(
            "UPDATE pagou_payment_operations SET status = 'succeeded', error_message = :reason, lease_token = NULL, "
            . 'lease_expires_at = NULL, finished_at = :finished_at, updated_at = :updated_at, version = version + 1 '
            . "WHERE id = :id AND status = 'uncertain'"
        );
        $closed = 0;
        foreach ($operations->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = json_decode((string) $row['payload_json'], true);
            $reason = $this->reason(is_array($payload) ? (int) ($payload['invoice_id'] ?? 0) : 0);
            if ($reason !== null) {
                $settle->execute(['reason' => $reason, 'finished_at' => $at, 'updated_at' => $at, 'id' => (string) $row['id']]);
                $closed += $settle->rowCount();
            }
        }

        $findings = $this->pdo->query(
            "SELECT id, attempt_id FROM pagou_reconciliation_findings WHERE finding_type = 'invoice_email_delivery_uncertain' "
            . "AND status = 'open' ORDER BY detected_at_utc ASC LIMIT " . $limit
        );
        $resolve = $this->pdo->prepare(
            "UPDATE pagou_reconciliation_findings SET status = 'resolved', resolved_at_utc = :resolved_at, "
            . 'resolution_json = :resolution, updated_at = :updated_at, version = version + 1 '
            . "WHERE id = :id AND status = 'open'"
        );
        foreach ($findings === false ? [] : $findings->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $reason = $this->reason($this->invoiceOf((string) $row['attempt_id']));
            if ($reason !== null) {
                $resolve->execute([
                    'resolved_at' => $at,
                    'resolution' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
                    'updated_at' => $at,
                    'id' => (string) $row['id'],
                ]);
                $closed += $resolve->rowCount();
            }
        }

        return $closed;
    }

    /** Why the email is no longer needed, or null while it may still matter to the client. */
    private function reason(int $invoiceId): ?string
    {
        if ($invoiceId < 1) {
            return null;
        }
        if (array_key_exists($invoiceId, $this->reasons)) {
            return $this->reasons[$invoiceId];
        }
        $statement = $this->pdo->prepare('SELECT userid, status FROM tblinvoices WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $invoiceId]);
        $invoice = $statement->fetch(PDO::FETCH_ASSOC);
        $reason = match (true) {
            $invoice === false => null,
            in_array((string) $invoice['status'], self::CLOSED_INVOICE, true) => self::INVOICE_CLOSED,
            ($this->invoiceEmailsDisabled)((int) $invoice['userid']) => self::DISABLED,
            default => null,
        };

        return $this->reasons[$invoiceId] = $reason;
    }

    /** The invoice of a review: from its charge, or from the only invoice of its delivery records. */
    private function invoiceOf(string $attemptId): int
    {
        $statement = $this->pdo->prepare('SELECT invoice_id FROM pagou_payment_attempts WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $attemptId]);
        $invoice = $statement->fetchColumn();
        if ($invoice !== false) {
            return (int) $invoice;
        }
        $statement = $this->pdo->prepare('SELECT DISTINCT invoice_id FROM pagou_invoice_deliveries WHERE attempt_id = :id');
        $statement->execute(['id' => $attemptId]);
        $invoices = $statement->fetchAll(PDO::FETCH_COLUMN);

        return count($invoices) === 1 ? (int) $invoices[0] : 0;
    }
}
