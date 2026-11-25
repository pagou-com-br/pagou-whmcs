<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;
use Pagou\Whmcs\Application\Async\JobPriority;
use Pagou\Whmcs\Application\Async\OperationScheduler;
use Pagou\Whmcs\Application\Async\OperationType;
use Pagou\Whmcs\Infrastructure\Persistence\LeaseRepository;
use Pagou\Whmcs\Support\Uuid;

/**
 * Read-only remote checks. Payments are booked from signed evidence, except a boleto
 * without late charges or discount whose notification is missing (see WhmcsRuntime).
 */
final class PeriodicReconciliation
{
    private const OLD_SLOTS = 3;
    private const LEASE = 'periodic-pix-boleto';
    /**
     * One run a minute, counted from its start. The cron starts every minute, sometimes a few
     * seconds early or late, so the limit stops short of 60 seconds instead of skipping a minute.
     */
    private const RUN_EVERY_SECONDS = 50;
    /** Held while a run goes on, so a slow run is never overlapped; a crashed run frees it by itself. */
    private const RUN_LEASE_SECONDS = 300;
    /** Ids per statement when reading due dates or the full check history. */
    private const CHUNK = 200;
    private const ACTIVE = "('queued','pending','leased','retrying')";

    public function __construct(private readonly PDO $pdo, private readonly OperationScheduler $scheduler)
    {
    }

    public static function eligibleSql(): string
    {
        return "a.method IN ('pix', 'boleto') AND a.remote_id IS NOT NULL AND a.remote_id <> '' "
            . "AND (a.status IN ('queued', 'pending', 'ready', 'awaiting_registration', 'uncertain') "
            . "OR (a.status = 'paid' AND EXISTS (SELECT 1 FROM pagou_reconciliation_findings f "
            . "WHERE f.attempt_id = a.id AND f.status = 'open' AND f.finding_type = 'payment_evidence_missing')) "
            . "OR EXISTS (SELECT 1 FROM pagou_pix_refunds r WHERE r.attempt_id = a.id AND r.status IN ('requested','uncertain','accepted','confirmed','applying')))";
    }

    /**
     * The follow-up group of a charge: 0 needs attention and is checked every 15 minutes,
     * 1 is recent and checked every hour, 2 is old and checked about once a day. Recent means
     * an invoice due in the last 35 days or later, or a charge created in the last 3 days,
     * such as a new Pix for an old invoice. Needs `a` and the invoice joined as `i`.
     */
    public static function tierSql(): string
    {
        return self::groupSql('WHEN a.created_at >= :fresh_since OR i.id IS NULL OR i.duedate IS NULL OR i.duedate >= :recent_due THEN 1 ELSE 2 END');
    }

    /** @return array{fresh_since:string,recent_due:string} */
    public static function tierParameters(\DateTimeImmutable $now): array
    {
        return [
            'fresh_since' => $now->modify('-3 days')->format('Y-m-d H:i:s.u'),
            // WHMCS keeps the civil due date of the invoice.
            'recent_due' => $now->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->modify('-35 days')->format('Y-m-d'),
        ];
    }

    public function schedule(int $limit = 10, ?\DateTimeImmutable $now = null): int
    {
        $leases = new LeaseRepository($this->pdo);
        $owner = Uuid::v4();
        $startedAt = time();
        if (!$leases->acquire(self::LEASE, $owner, self::RUN_LEASE_SECONDS)) {
            return 0;
        }
        try {
            return $this->scheduleDue($limit, $now);
        } finally {
            // The lease stays a global one-minute rate limit, even on errors.
            try {
                $leases->holdUntil(self::LEASE, $owner, $startedAt + self::RUN_EVERY_SECONDS);
            } catch (\Throwable) {
                // The lease then expires by itself.
            }
        }
    }

    /**
     * Charges of each group whose last check is older than their group asks for, the longest
     * waiting first: `near` holds groups 0 and 1 and `old` group 2, at most $limit each (up to 25).
     *
     * Read in short steps instead of one statement, so that nothing holds tblinvoices, a MyISAM
     * table that a read locks as a whole: the charges come from the module tables, the checks of
     * the last day from the updated_at index, and the due dates by id, a few hundred at a time.
     * Only a charge not checked within that day reads its whole history.
     *
     * @return array{near: list<array{id:string,method:string,remote_id:string}>, old: list<array{id:string,method:string,remote_id:string}>}
     */
    public function dueCharges(\DateTimeImmutable $now, int $limit): array
    {
        $limit = max(1, min(25, $limit));
        $tiers = self::tierParameters($now);
        // The last check a group accepts; a check started in the last 15 minutes also keeps the charge busy.
        $before = [
            0 => $now->modify('-15 minutes')->format('Y-m-d H:i:s.u'),
            1 => $now->modify('-1 hour')->format('Y-m-d H:i:s.u'),
            2 => $now->modify('-20 hours')->format('Y-m-d H:i:s.u'),
        ];
        $busySince = $before[0];

        $charges = [];
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.method, a.remote_id, a.created_at, a.invoice_id, '
            . self::groupSql('WHEN a.created_at >= :fresh_since THEN 1 ELSE NULL END') . ' AS tier '
            . 'FROM pagou_payment_attempts a WHERE ' . self::eligibleSql()
        );
        $statement->execute(['fresh_since' => $tiers['fresh_since']]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $charges[(string) $row['id']] = $row;
        }
        if ($charges === []) {
            return ['near' => [], 'old' => []];
        }

        $busy = [];
        $active = $this->pdo->query(
            'SELECT DISTINCT attempt_id FROM pagou_payment_operations WHERE status IN ' . self::ACTIVE
            . " AND operation_type LIKE 'reconcile%' AND attempt_id IS NOT NULL"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($active as $attemptId) {
            $busy[(string) $attemptId] = true;
        }

        // Rows are created with updated_at = created_at and only move it forward; the margin covers clock steps.
        $lastCheck = [];
        $statement = $this->pdo->prepare(
            'SELECT attempt_id, MAX(created_at) AS last_check, MAX(CASE WHEN created_at >= :busy_since THEN 1 ELSE 0 END) AS busy '
            . 'FROM pagou_payment_operations WHERE updated_at >= :scan_from AND created_at >= :window_from '
            . "AND operation_type LIKE 'reconcile%' AND attempt_id IS NOT NULL GROUP BY attempt_id"
        );
        $statement->execute([
            'busy_since' => $busySince,
            'scan_from' => $now->modify('-21 hours')->format('Y-m-d H:i:s.u'),
            'window_from' => $before[2],
        ]);
        $this->collectChecks($statement->fetchAll(PDO::FETCH_ASSOC), $charges, $lastCheck, $busy);

        $unchecked = array_values(array_filter(
            array_keys($charges),
            static fn (string $id): bool => !isset($busy[$id]) && !array_key_exists($id, $lastCheck),
        ));
        foreach (array_chunk($unchecked, self::CHUNK) as $chunk) {
            $statement = $this->pdo->prepare(
                'SELECT attempt_id, MAX(created_at) AS last_check, MAX(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS busy '
                . 'FROM pagou_payment_operations WHERE attempt_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ") "
                . "AND operation_type LIKE 'reconcile%' GROUP BY attempt_id"
            );
            $statement->execute([$busySince, ...$chunk]);
            $this->collectChecks($statement->fetchAll(PDO::FETCH_ASSOC), $charges, $lastCheck, $busy);
        }

        // The due date decides between groups 1 and 2; a charge checked in the last hour is due in neither.
        $invoices = [];
        foreach ($charges as $id => $charge) {
            $last = $lastCheck[$id] ?? null;
            if ($charge['tier'] === null && !isset($busy[$id]) && ($last === null || strcmp($last, $before[1]) < 0)) {
                $invoices[(int) $charge['invoice_id']] = true;
            }
        }
        $dueDates = [];
        foreach (array_chunk(array_keys($invoices), self::CHUNK) as $chunk) {
            $statement = $this->pdo->prepare('SELECT id, duedate FROM tblinvoices WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')');
            $statement->execute($chunk);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
                $dueDates[(int) $invoice['id']] = $invoice['duedate'] === null ? null : (string) $invoice['duedate'];
            }
        }

        $due = [];
        foreach ($charges as $id => $charge) {
            if (isset($busy[$id])) {
                continue;
            }
            $tier = $charge['tier'];
            if ($tier === null) {
                $invoiceId = (int) $charge['invoice_id'];
                if (!array_key_exists($invoiceId, $invoices)) {
                    continue;
                }
                $dueDate = $dueDates[$invoiceId] ?? null;
                $tier = $dueDate === null || strcmp($dueDate, $tiers['recent_due']) >= 0 ? 1 : 2;
            }
            $tier = (int) $tier;
            $last = $lastCheck[$id] ?? null;
            if ($last !== null && strcmp($last, $before[$tier]) >= 0) {
                continue;
            }
            $due[] = ['tier' => $tier, 'last' => $last ?? '1970-01-01', 'created_at' => (string) $charge['created_at'], 'charge' => [
                'id' => $id,
                'method' => (string) $charge['method'],
                'remote_id' => (string) $charge['remote_id'],
            ]];
        }
        // The order of the former single statement: group, longest waiting, oldest charge, id.
        usort($due, static fn (array $a, array $b): int => ($a['tier'] <=> $b['tier'])
            ?: strcmp($a['last'], $b['last']) ?: strcmp($a['created_at'], $b['created_at']) ?: strcmp($a['charge']['id'], $b['charge']['id']));
        $pick = static fn (bool $old): array => array_slice(array_values(array_map(
            static fn (array $entry): array => $entry['charge'],
            array_filter($due, static fn (array $entry): bool => ($entry['tier'] === 2) === $old),
        )), 0, $limit);

        return ['near' => $pick(false), 'old' => $pick(true)];
    }

    /**
     * Charges in follow-up and how many of them had a check succeed in the last 24 hours.
     * The checks of that day come from the updated_at index, not from the history of each charge.
     * @return array{eligible:int,checked:int,stale:int}
     */
    public function coverage(): array
    {
        $eligible = $this->pdo->query('SELECT a.id FROM pagou_payment_attempts a WHERE ' . self::eligibleSql())->fetchAll(PDO::FETCH_COLUMN);
        $cutoff = time() - 86400;
        // A check ends with finished_at and updated_at set together; legacy rows have only updated_at.
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT attempt_id FROM pagou_payment_operations WHERE updated_at >= :scan_from '
            . "AND COALESCE(finished_at, updated_at) >= :cutoff AND operation_type LIKE 'reconcile%' AND status = 'succeeded' AND attempt_id IS NOT NULL"
        );
        $statement->execute(['scan_from' => gmdate('Y-m-d H:i:s', $cutoff - 3600), 'cutoff' => gmdate('Y-m-d H:i:s', $cutoff)]);
        $succeeded = array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);
        $checked = count(array_filter($eligible, static fn (mixed $id): bool => isset($succeeded[(string) $id])));

        return ['eligible' => count($eligible), 'checked' => $checked, 'stale' => count($eligible) - $checked];
    }

    private function scheduleDue(int $limit, ?\DateTimeImmutable $now): int
    {
        $backlog = (int) $this->pdo->query("SELECT COUNT(*) FROM pagou_payment_operations WHERE operation_type = 'reconcile_payment' AND priority = 10 AND status IN ('queued','pending','leased','retrying')")->fetchColumn();
        $limit = min($limit, 25 - $backlog);
        if ($limit < 1) {
            return 0;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        ['near' => $near, 'old' => $old] = $this->dueCharges($now, $limit);
        // A few places each minute keep the old charges going even when the recent ones fill the queue.
        $oldTaken = min(count($old), max(self::OLD_SLOTS, $limit - count($near)));
        $scheduled = 0;
        foreach ([...array_slice($near, 0, $limit - $oldTaken), ...array_slice($old, 0, $oldTaken)] as $attempt) {
            $scheduled += $this->scheduler->schedule(
                Uuid::v4(),
                OperationType::ReconcilePayment,
                'periodic:reconcile:' . $attempt['id'] . ':' . intdiv($now->getTimestamp(), 900),
                JobPriority::Maintenance,
                ['attempt_id' => $attempt['id'], 'method' => $attempt['method'], 'remote_id' => $attempt['remote_id']],
            ) ? 1 : 0;
        }
        return $scheduled;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, array<string, mixed>> $charges
     * @param array<string, string> $lastCheck
     * @param array<string, true> $busy
     */
    private function collectChecks(array $rows, array $charges, array &$lastCheck, array &$busy): void
    {
        foreach ($rows as $row) {
            $id = (string) $row['attempt_id'];
            if (!isset($charges[$id])) {
                continue;
            }
            $lastCheck[$id] = (string) $row['last_check'];
            if ((int) $row['busy'] === 1) {
                $busy[$id] = true;
            }
        }
    }

    /** The group as a CASE expression; $byAge ends it, for the charges that need no attention. */
    private static function groupSql(string $byAge): string
    {
        // A check that keeps failing, or a refund waiting for the Refund tab, is listed and asked once a day.
        return "CASE WHEN EXISTS (SELECT 1 FROM pagou_reconciliation_findings sf WHERE sf.attempt_id = a.id AND sf.status = 'open' "
            . "AND sf.finding_type IN ('reconciliation_keeps_failing', 'pix_refund_not_recorded')) THEN 2 "
            . "WHEN a.status IN ('queued', 'awaiting_registration', 'uncertain', 'paid') "
            . "OR EXISTS (SELECT 1 FROM pagou_pix_refunds tr WHERE tr.attempt_id = a.id AND tr.status IN ('requested','uncertain','accepted','confirmed','applying')) THEN 0 "
            . $byAge;
    }
}
