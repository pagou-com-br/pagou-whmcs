<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Support\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pagou\Whmcs\Application\Runtime\PeriodicReconciliation;
use Pagou\Whmcs\Support\Uuid;

/**
 * Random charges, checks and invoices around every limit of the periodic selection, and the
 * former single statement that defined it, kept as the reference the stepped reading must match.
 */
final class PeriodicSelectionFixture
{
    private const OFFSETS = [
        '-10 seconds', '-14 minutes', '-15 minutes', '-16 minutes', '-59 minutes', '-1 hour', '-61 minutes',
        '-5 hours', '-1199 minutes', '-20 hours', '-1201 minutes', '-21 hours', '-22 hours', '-1439 minutes',
        '-1441 minutes', '-25 hours', '-3 days',
        '-4 days', '-30 days', '-200 days',
    ];
    private const DUE = ['+10 days', '-10 days', '-34 days', '-35 days', '-36 days', '-400 days'];

    /** Fills the tables with $charges random charges; the same seed gives the same data. */
    public static function seed(PDO $pdo, DateTimeImmutable $now, int $seed, int $charges): void
    {
        mt_srand($seed);
        $pick = static fn (array $values): mixed => $values[mt_rand(0, count($values) - 1)];
        $at = static fn (string $offset): string => $now->modify($offset)->format('Y-m-d H:i:s.u');
        $shared = [$at('-2 days'), $at('-9 days'), $at('-1199 minutes')];
        $invoice = $pdo->prepare('INSERT INTO tblinvoices (id, userid, duedate, status) VALUES (?, 20, ?, ?)');
        $invoices = max(1, intdiv($charges * 4, 5));
        for ($id = 1; $id <= $invoices; $id++) {
            if (mt_rand(1, 10) === 1) {
                continue; // The invoice was removed from WHMCS.
            }
            $dueDate = mt_rand(1, 15) === 1 ? null : $now->setTimezone(new DateTimeZone('America/Sao_Paulo'))->modify($pick(self::DUE))->format('Y-m-d');
            $invoice->execute([$id, $dueDate, 'Unpaid']);
        }
        $attempt = $pdo->prepare(
            'INSERT INTO pagou_payment_attempts (id, invoice_id, client_id, gateway, method, status, amount_cents, currency, remote_id, '
            . "idempotency_key, economic_key, created_at, updated_at, version) VALUES (?, ?, 20, 'pagou', ?, ?, 1000, 'BRL', ?, ?, ?, ?, ?, 1)"
        );
        $operation = $pdo->prepare(
            'INSERT INTO pagou_payment_operations (id, attempt_id, operation_type, status, deduplication_key, priority, payload_json, '
            . "available_at, created_at, updated_at, finished_at) VALUES (?, ?, ?, ?, ?, 10, '{}', ?, ?, ?, ?)"
        );
        $finding = $pdo->prepare(
            'INSERT INTO pagou_reconciliation_findings (id, finding_key, attempt_id, severity, finding_type, status, details_json, '
            . "detected_at_utc, created_at, updated_at) VALUES (?, ?, ?, 'medium', ?, ?, '{}', ?, ?, ?)"
        );
        $refund = $pdo->prepare(
            'INSERT INTO pagou_pix_refunds (id, attempt_id, invoice_id, client_id, remote_id, original_transaction_id, amount_cents, '
            . 'receipt_cents, status, actor_id, requested_at, updated_at) VALUES (?, ?, ?, 20, ?, ?, 100, 1000, ?, 1, ?, ?)'
        );
        $emptyRemote = false;
        for ($n = 1; $n <= $charges; $n++) {
            $id = Uuid::v4();
            $remote = 'remote-' . $seed . '-' . $n;
            if (mt_rand(1, 25) === 1) {
                $remote = null;
            } elseif (!$emptyRemote && mt_rand(1, 40) === 1) {
                $remote = '';
                $emptyRemote = true;
            }
            $created = mt_rand(1, 6) === 1 ? $pick($shared) : $at($pick(self::OFFSETS));
            $attempt->execute([
                $id, mt_rand(1, $invoices + 5), $pick(['pix', 'pix', 'boleto', 'card']),
                $pick(['pending', 'pending', 'ready', 'queued', 'awaiting_registration', 'uncertain', 'paid', 'cancelled', 'failed', 'refunded']),
                $remote, hash('sha256', 'i' . $id), hash('sha256', 'e' . $id), $created, $created,
            ]);
            $checks = $pick([0, 1, 2, 4, 8, 12]);
            for ($c = 0; $c < $checks; $c++) {
                $createdAt = mt_rand(1, 5) === 1 ? $pick($shared) : $at($pick(self::OFFSETS));
                // Retries and retention move updated_at forward, sometimes long after the check.
                $updatedAt = mt_rand(1, 4) === 1 ? $at($pick(['-1 second', '-30 minutes', '-2 hours'])) : $createdAt;
                if (strcmp($updatedAt, $createdAt) < 0) {
                    $updatedAt = $createdAt;
                }
                $operation->execute([
                    Uuid::v4(), $id,
                    $pick(['reconcile_payment', 'reconcile_payment', 'reconcile_uncertain_operation', 'issue_pix', 'fetch_boleto_pdf']),
                    $pick(['succeeded', 'succeeded', 'succeeded', 'failed', 'uncertain', 'queued', 'retrying', 'leased', 'pending']),
                    hash('sha256', 'op' . $id . $c), $createdAt, $createdAt, $updatedAt,
                    // Ends with updated_at, at the start for some writers, or unset in legacy rows.
                    $pick([$updatedAt, $updatedAt, $createdAt, null]),
                ]);
            }
            if (mt_rand(1, 8) === 1) {
                $type = $pick(['reconciliation_keeps_failing', 'pix_refund_not_recorded', 'payment_evidence_missing', 'charge_issuance_refused']);
                $finding->execute([Uuid::v4(), hash('sha256', 'f' . $id), $id, $type, $pick(['open', 'open', 'resolved']), $created, $created, $created]);
            }
            if (mt_rand(1, 12) === 1) {
                $refund->execute([
                    Uuid::v4(), $id, $n, 'refund-' . $seed . '-' . $n, 'tx-' . $n,
                    $pick(['requested', 'uncertain', 'accepted', 'confirmed', 'applying', 'applied', 'failed']), $created, $created,
                ]);
            }
        }
    }

    /**
     * The former statement of each group set ('< 2' or '= 2'), as the module ran it until 0.3.0-rc.3.
     * @return list<array{id:string,method:string,remote_id:string}>
     */
    public static function legacyDue(PDO $pdo, DateTimeImmutable $now, string $tier, int $limit): array
    {
        $statement = $pdo->prepare(
            'SELECT t.id, t.method, t.remote_id FROM (SELECT a.id, a.method, a.remote_id, a.created_at, ' . PeriodicReconciliation::tierSql() . ' AS tier, '
            . "(SELECT MAX(o.created_at) FROM pagou_payment_operations o WHERE o.attempt_id = a.id AND o.operation_type LIKE 'reconcile%') AS last_check "
            . 'FROM pagou_payment_attempts a LEFT JOIN tblinvoices i ON i.id = a.invoice_id WHERE ' . PeriodicReconciliation::eligibleSql()
            . ' AND NOT EXISTS (SELECT 1 FROM pagou_payment_operations busy WHERE busy.attempt_id = a.id '
            . "AND busy.operation_type LIKE 'reconcile%' AND (busy.status IN ('queued','pending','leased','retrying') OR busy.created_at >= :cutoff))) t "
            . 'WHERE t.tier ' . $tier . ' AND (t.last_check IS NULL OR (t.tier = 0 AND t.last_check < :hot_before) '
            . 'OR (t.tier = 1 AND t.last_check < :recent_before) OR (t.tier = 2 AND t.last_check < :old_before)) '
            . "ORDER BY t.tier, COALESCE(t.last_check, '1970-01-01'), t.created_at, t.id LIMIT " . max(1, min(25, $limit))
        );
        $statement->execute(PeriodicReconciliation::tierParameters($now) + [
            'cutoff' => $now->modify('-15 minutes')->format('Y-m-d H:i:s.u'),
            'hot_before' => $now->modify('-15 minutes')->format('Y-m-d H:i:s.u'),
            'recent_before' => $now->modify('-1 hour')->format('Y-m-d H:i:s.u'),
            'old_before' => $now->modify('-20 hours')->format('Y-m-d H:i:s.u'),
        ]);

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'method' => (string) $row['method'],
            'remote_id' => (string) $row['remote_id'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The former coverage statement, which looked up the history of each charge.
     * @return array{eligible:int,checked:int,stale:int}
     */
    public static function legacyCoverage(PDO $pdo): array
    {
        $statement = $pdo->prepare('SELECT COUNT(*) AS eligible, COALESCE(SUM(CASE WHEN EXISTS ('
            . 'SELECT 1 FROM pagou_payment_operations o WHERE o.attempt_id = a.id '
            . "AND o.operation_type LIKE 'reconcile%' AND o.status = 'succeeded' AND COALESCE(o.finished_at, o.updated_at) >= :cutoff) "
            . 'THEN 1 ELSE 0 END), 0) AS checked FROM pagou_payment_attempts a WHERE ' . PeriodicReconciliation::eligibleSql());
        $statement->execute(['cutoff' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $eligible = (int) ($row['eligible'] ?? 0);
        $checked = (int) ($row['checked'] ?? 0);

        return ['eligible' => $eligible, 'checked' => $checked, 'stale' => $eligible - $checked];
    }

    /**
     * Differences between the stepped selection and the former statement, empty when they agree.
     * @return list<string>
     */
    public static function differences(PDO $pdo, PeriodicReconciliation $periodic, DateTimeImmutable $now, int $limit): array
    {
        $selected = $periodic->dueCharges($now, $limit);
        $differences = [];
        foreach (['near' => '< 2', 'old' => '= 2'] as $group => $tier) {
            $expected = self::legacyDue($pdo, $now, $tier, $limit);
            if ($selected[$group] !== $expected) {
                $differences[] = $group . ' with limit ' . $limit . ': expected ' . json_encode(array_column($expected, 'id'))
                    . ', got ' . json_encode(array_column($selected[$group], 'id'));
            }
        }

        return $differences;
    }
}
