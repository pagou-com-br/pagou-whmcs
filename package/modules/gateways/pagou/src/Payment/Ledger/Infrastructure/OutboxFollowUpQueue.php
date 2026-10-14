<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Ledger\Infrastructure;

use PDO;
use Pagou\Whmcs\Application\Async\JobPriority;
use Pagou\Whmcs\Application\Async\OperationJob;
use Pagou\Whmcs\Application\Async\OperationOutbox;
use Pagou\Whmcs\Application\Async\OperationType;
use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\FollowUpQueue;
use Pagou\Whmcs\Support\Uuid;

final class OutboxFollowUpQueue implements FollowUpQueue
{
    public function __construct(private readonly PDO $pdo, private readonly OperationOutbox $outbox)
    {
    }

    public function cancelSiblings(int $invoiceId, EconomicPaymentKey $paidKey): void
    {
        // A charge that received a payment is never cancelled, including the one just paid.
        $paid = [];
        $ledger = $this->pdo->prepare("SELECT metadata_json FROM pagou_ledger_entries WHERE invoice_id = :invoice_id AND entry_type = 'received_payment'");
        $ledger->execute(['invoice_id' => $invoiceId]);
        foreach ($ledger->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $data = json_decode((string) $json, true);
            if (is_array($data) && is_string($data['remote_charge_id'] ?? null) && $data['remote_charge_id'] !== '') {
                $paid[$data['remote_charge_id']] = true;
            }
        }
        $statement = $this->pdo->prepare(
            "SELECT id, method, remote_id FROM pagou_payment_attempts WHERE invoice_id = :invoice_id "
            // A charge already being cancelled has its own job; a second one would fail.
            . "AND remote_id IS NOT NULL AND remote_id <> '' AND status NOT IN ('paid', 'cancelled', 'canceled', 'refunded', 'superseded', 'cancel_requested')"
        );
        $statement->execute(['invoice_id' => $invoiceId]);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $mark = $this->pdo->prepare(
            "UPDATE pagou_payment_attempts SET status = 'cancel_requested', updated_at = :updated_at, version = version + 1 "
            . "WHERE id = :id AND status NOT IN ('paid', 'cancelled', 'canceled', 'refunded', 'superseded', 'cancel_requested')"
        );
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $method = (string) $row['method'];
            $remoteId = (string) $row['remote_id'];
            $type = match ($method) {
                'pix' => OperationType::CancelPix,
                'boleto' => OperationType::CancelBoleto,
                default => null,
            };
            if ($type === null || isset($paid[$remoteId])) {
                continue;
            }
            $mark->execute(['updated_at' => $now->format('Y-m-d H:i:s.u'), 'id' => (string) $row['id']]);
            // The same key as the InvoicePaid hook uses, so only one request exists
            // whichever of the two runs first.
            $this->outbox->enqueue(new OperationJob(
                Uuid::v4(),
                $type,
                sprintf('%s:cancel:%s:%s', $method, (string) $row['id'], hash('sha256', $remoteId)),
                JobPriority::FinancialRecovery,
                [
                    'attempt_id' => (string) $row['id'],
                    'remote_id' => $remoteId,
                    'invoice_id' => $invoiceId,
                    'paid_economic_key' => $paidKey->value,
                ],
                $now,
                $now,
            ));
        }
    }
}
