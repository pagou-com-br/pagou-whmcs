<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;
use Pagou\Whmcs\Application\Async\JobPriority;
use Pagou\Whmcs\Application\Async\OperationScheduler;
use Pagou\Whmcs\Application\Async\OperationType;
use Pagou\Whmcs\Infrastructure\Persistence\LeaseRepository;
use Pagou\Whmcs\Support\Uuid;

/** Read-only remote checks. Financial application still requires signed payment evidence. */
final class PeriodicReconciliation
{
    public function __construct(private readonly PDO $pdo, private readonly OperationScheduler $scheduler)
    {
    }

    public static function eligibleSql(): string
    {
        return "a.method IN ('pix', 'boleto') AND a.remote_id IS NOT NULL AND a.remote_id <> '' "
            . "AND (a.status IN ('queued', 'pending', 'ready', 'awaiting_registration', 'uncertain') "
            . "OR (a.status = 'paid' AND EXISTS (SELECT 1 FROM pagou_reconciliation_findings f "
            . "WHERE f.attempt_id = a.id AND f.status = 'open' AND f.finding_type = 'payment_evidence_missing')) "
            . "OR EXISTS (SELECT 1 FROM pagou_pix_refunds r WHERE r.attempt_id = a.id AND r.status IN ('requested','uncertain','confirmed','applying')))";
    }

    public function schedule(int $limit = 10, ?\DateTimeImmutable $now = null): int
    {
        // The lease is deliberately retained as a global one-minute rate limit, even on errors.
        if (!(new LeaseRepository($this->pdo))->acquire('periodic-pix-boleto', Uuid::v4(), 60)) {
            return 0;
        }
        $backlog = (int) $this->pdo->query("SELECT COUNT(*) FROM pagou_payment_operations WHERE operation_type = 'reconcile_payment' AND priority = 10 AND status IN ('queued','pending','leased','retrying')")->fetchColumn();
        $limit = min($limit, 25 - $backlog);
        if ($limit < 1) {
            return 0;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.method, a.remote_id, '
            . "(SELECT MAX(o.created_at) FROM pagou_payment_operations o WHERE o.attempt_id = a.id AND o.operation_type LIKE 'reconcile%') AS last_check "
            . 'FROM pagou_payment_attempts a WHERE ' . self::eligibleSql()
            . " AND NOT EXISTS (SELECT 1 FROM pagou_payment_operations busy WHERE busy.attempt_id = a.id "
            . "AND busy.operation_type LIKE 'reconcile%' AND (busy.status IN ('queued','pending','leased','retrying') OR busy.created_at >= :cutoff)) "
            . "ORDER BY COALESCE(last_check, '1970-01-01'), a.created_at, a.id LIMIT " . max(1, min(25, $limit))
        );
        $statement->execute(['cutoff' => $now->modify('-15 minutes')->format('Y-m-d H:i:s.u')]);
        $scheduled = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $attempt) {
            $scheduled += $this->scheduler->schedule(
                Uuid::v4(),
                OperationType::ReconcilePayment,
                'periodic:reconcile:' . $attempt['id'] . ':' . intdiv($now->getTimestamp(), 900),
                JobPriority::Maintenance,
                ['attempt_id' => (string) $attempt['id'], 'method' => (string) $attempt['method'], 'remote_id' => (string) $attempt['remote_id']],
            ) ? 1 : 0;
        }
        return $scheduled;
    }

    /** @return array{eligible:int,checked:int,stale:int} */
    public function coverage(): array
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) AS eligible, COALESCE(SUM(CASE WHEN EXISTS ('
            . 'SELECT 1 FROM pagou_payment_operations o WHERE o.attempt_id = a.id '
            . "AND o.operation_type LIKE 'reconcile%' AND o.status = 'succeeded' AND COALESCE(o.finished_at, o.updated_at) >= :cutoff) "
            . 'THEN 1 ELSE 0 END), 0) AS checked FROM pagou_payment_attempts a WHERE ' . self::eligibleSql());
        $statement->execute(['cutoff' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $eligible = (int) ($row['eligible'] ?? 0);
        $checked = (int) ($row['checked'] ?? 0);
        return ['eligible' => $eligible, 'checked' => $checked, 'stale' => $eligible - $checked];
    }
}
