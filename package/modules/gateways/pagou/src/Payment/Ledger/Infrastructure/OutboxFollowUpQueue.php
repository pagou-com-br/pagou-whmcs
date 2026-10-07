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
        $statement = $this->pdo->prepare(
            "SELECT id, method, remote_id FROM pagou_payment_attempts WHERE invoice_id = :invoice_id "
            // A charge already being cancelled has its own job; a second one would fail.
            . "AND remote_id IS NOT NULL AND status NOT IN ('paid', 'cancelled', 'canceled', 'refunded', 'superseded', 'cancel_requested')"
        );
        $statement->execute(['invoice_id' => $invoiceId]);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $method = (string) $row['method'];
            $type = match ($method) {
                'pix' => OperationType::CancelPix,
                'boleto' => OperationType::CancelBoleto,
                default => null,
            };
            if ($type === null) {
                continue;
            }
            $this->outbox->enqueue(new OperationJob(
                Uuid::v4(),
                $type,
                'sibling-cancel:' . (string) $row['id'] . ':' . $paidKey->value,
                JobPriority::FinancialRecovery,
                [
                    'attempt_id' => (string) $row['id'],
                    'remote_id' => (string) $row['remote_id'],
                    'paid_economic_key' => $paidKey->value,
                ],
                $now,
                $now,
            ));
        }
    }
}
