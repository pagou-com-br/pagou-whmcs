<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pagou\Whmcs\Domain\Money;

/** Read-only projections. No API calls, migrations, recovery or financial commands. */
final class MerchantReadModel
{
    private const LIMIT = 50000;

    public function __construct(private readonly PDO $pdo, private readonly ?DateTimeImmutable $now = null)
    {
    }

    /**
     * @param array<string,string> $input
     * @return array<string,mixed>
     */
    public function report(array $input): array
    {
        $filter = new ReportFilter($input, $this->now);
        $rows = $this->rowsFor($filter);
        $summary = $this->summary($rows);
        $previous = $filter->kind === 'receipts' ? $this->summary($this->receipts($filter, true)) : null;
        $first = $this->query('SELECT MIN(created_at) AS first_at FROM pagou_payment_attempts')[0]['first_at'] ?? null;
        $pages = max(1, (int) ceil(count($rows) / 50));
        $page = min($filter->page, $pages);
        return [
            'filter' => array_replace($filter->values(), ['page' => (string) $page]), 'summary' => $summary, 'previous' => $previous,
            'rows' => array_slice($rows, ($page - 1) * 50, 50),
            'daily' => $filter->kind === 'receipts' ? $this->daily($rows, $filter) : [],
            'total' => count($rows), 'pages' => $pages,
            'firstAt' => $first, 'asOf' => ($this->now ?? new DateTimeImmutable())->format('c'),
        ];
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $receipts = $this->report([]);
        $openRows = $this->openInvoices(new ReportFilter(['report' => 'open'], $this->now));
        $pending = $this->summary($this->pending(new ReportFilter(['report' => 'pending'], $this->now)));
        $today = (new ReportFilter([], $this->now))->today;
        $trend = new ReportFilter([
            'from' => $today->modify('-29 days')->format('Y-m-d'),
            'to' => $today->format('Y-m-d'),
        ], $this->now);
        $recent = $this->receipts($trend);
        // Same days of the previous month, capped at its last day (31/03 compares with 01-28/02).
        $previousStart = $today->modify('first day of previous month');
        $previousEnd = $previousStart->modify('+' . ((int) $today->format('j') - 1) . ' days');
        $previousCap = $today->modify('last day of previous month');
        $previousEnd = $previousEnd > $previousCap ? $previousCap : $previousEnd;
        $lastMonth = new ReportFilter(['from' => $previousStart->format('Y-m-d'), 'to' => $previousEnd->format('Y-m-d')], $this->now);
        return [
            'receipts' => $receipts, 'open' => $this->summary($openRows), 'pending' => $pending,
            'refunds' => $this->summary($this->refunds(new ReportFilter(['report' => 'refunds'], $this->now))),
            // Oldest due date first, so overdue invoices lead the collection list.
            'openRows' => array_slice($openRows, 0, 5),
            'trend' => $this->daily($recent, $trend),
            'recent' => array_slice($recent, 0, 6),
            'lastMonth' => $this->summary($this->receipts($lastMonth)) + [
                'from' => $previousStart->format('Y-m-d'), 'to' => $previousEnd->format('Y-m-d'),
            ],
        ];
    }

    /**
     * Applied receipts grouped by São Paulo payment day, including empty days.
     * @param list<array<string,mixed>> $rows
     * @return list<array{date:string,amount:int,count:int}>
     */
    private function daily(array $rows, ReportFilter $filter): array
    {
        $days = [];
        for ($day = $filter->start; $day <= $filter->end; $day = $day->modify('+1 day')) {
            $days[$day->format('Y-m-d')] = ['date' => $day->format('Y-m-d'), 'amount' => 0, 'count' => 0];
        }
        $zone = new DateTimeZone('America/Sao_Paulo');
        foreach ($rows as $row) {
            $key = (new DateTimeImmutable((string) $row['date'], new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
            if (isset($days[$key])) {
                $days[$key]['amount'] += (int) $row['amount'];
                $days[$key]['count']++;
            }
        }
        return array_values($days);
    }

    /** @return list<array<string,mixed>> */
    public function rowsFor(ReportFilter $filter): array
    {
        return match ($filter->kind) {
            'open' => $this->openInvoices($filter),
            'attempts' => $this->attempts($filter),
            'pending' => $this->pending($filter),
            'refunds' => $this->refunds($filter),
            default => $this->receipts($filter),
        };
    }

    /**
     * Pix refunds and card refunds requested through this module, dated by the
     * provider confirmation, or by the request while it is not confirmed.
     * @return list<array<string,mixed>>
     */
    private function refunds(ReportFilter $filter): array
    {
        [$from, $until] = $filter->bounds();
        $pixParameters = ['from_date' => $from, 'until_date' => $until];
        $pixWhere = $this->identityWhere($filter, 'r', $pixParameters);
        $pix = $this->query(
            'SELECT r.invoice_id, r.client_id, r.amount_cents, r.receipt_cents, r.status, r.provider_refund_id, r.remote_id, '
            . 'COALESCE(r.confirmed_at, r.requested_at) AS refund_at, c.firstname, c.lastname, c.companyname '
            . 'FROM pagou_pix_refunds r LEFT JOIN tblclients c ON c.id = r.client_id '
            . 'WHERE COALESCE(r.confirmed_at, r.requested_at) >= :from_date AND COALESCE(r.confirmed_at, r.requested_at) < :until_date' . $pixWhere,
            $pixParameters,
        );
        $cardParameters = ['from_date' => $from, 'until_date' => $until];
        $cardWhere = $this->identityWhere($filter, 'a', $cardParameters);
        $card = $this->query(
            'SELECT a.invoice_id, a.client_id, r.amount_cents, a.amount_cents AS receipt_cents, r.status, r.provider_refund_id, a.remote_id, '
            . 'COALESCE(r.completed_at_utc, r.requested_at_utc) AS refund_at, c.firstname, c.lastname, c.companyname '
            . 'FROM pagou_card_refunds r INNER JOIN pagou_card_transactions t ON t.id = r.card_transaction_id '
            . 'INNER JOIN pagou_payment_attempts a ON a.id = t.attempt_id LEFT JOIN tblclients c ON c.id = a.client_id '
            . 'WHERE COALESCE(r.completed_at_utc, r.requested_at_utc) >= :from_date AND COALESCE(r.completed_at_utc, r.requested_at_utc) < :until_date' . $cardWhere,
            $cardParameters,
        );
        $result = [];
        foreach ([['pix', $pix], ['card', $card]] as [$method, $rows]) {
            foreach ($rows as $row) {
                $partial = (int) $row['amount_cents'] < (int) $row['receipt_cents'];
                $record = $this->record($row) + [
                    'date' => (string) $row['refund_at'], 'method' => $method, 'status' => self::refundStatus($method, (string) $row['status']),
                    'pagouId' => (string) ($row['remote_id'] ?? ''), 'reference' => (string) ($row['provider_refund_id'] ?? ''),
                    'partial' => $partial, 'receipt' => (int) $row['receipt_cents'],
                    'basis' => $partial ? 'Devolução parcial do valor recebido' : 'Devolução total do valor recebido',
                ];
                if ($this->matches($filter, $record)) {
                    $result[] = $record;
                }
            }
        }
        usort($result, static fn (array $a, array $b): int => [$b['date'], $b['invoice']] <=> [$a['date'], $a['invoice']]);

        return $result;
    }

    /** Groups provider and native states into what an operator needs to know. */
    private static function refundStatus(string $method, string $status): string
    {
        return match (true) {
            $method === 'pix' && $status === 'applied', $method === 'card' && in_array($status, ['reversed', 'refunded'], true) => 'refund_done',
            $method === 'pix' && in_array($status, ['confirmed', 'applying'], true) => 'refund_confirmed',
            $status === 'review' => 'refund_review',
            in_array($status, ['rejected', 'failed'], true) => 'refund_rejected',
            default => 'refund_progress',
        };
    }

    /** @return list<array<string,mixed>> */
    private function receipts(ReportFilter $filter, bool $previous = false): array
    {
        [$from, $until] = $filter->bounds($previous);
        $parameters = ['from_date' => $from, 'until_date' => $until];
        $where = $this->identityWhere($filter, 'l', $parameters);
        $initial = $this->query(
            "SELECT l.idempotency_key, l.invoice_id, l.client_id, l.amount_cents, l.payment_at_utc, l.metadata_json, "
            . 'c.firstname, c.lastname, c.companyname FROM pagou_ledger_entries l '
            . 'LEFT JOIN tblclients c ON c.id = l.client_id '
            . "WHERE l.entry_type = 'received_payment' AND l.currency = 'BRL' "
            . 'AND l.payment_at_utc >= :from_date AND l.payment_at_utc < :until_date ' . $where
            . ' ORDER BY l.payment_at_utc DESC, l.id DESC',
            $parameters,
        );
        $result = [];
        $seen = [];
        foreach (array_chunk($initial, 200) as $chunk) {
            $keys = array_map(static fn (array $row): string => hash('sha256', 'pagou-ledger-transition-v1|' . $row['idempotency_key'] . '|applied'), $chunk);
            $applied = [];
            foreach ($this->query('SELECT idempotency_key, metadata_json FROM pagou_ledger_entries WHERE entry_type = ? AND idempotency_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', ['received_payment_transition', ...$keys]) as $transition) {
                $metadata = $this->json($transition['metadata_json']);
                if (($metadata['status'] ?? '') === 'applied' && is_string($metadata['economic_key'] ?? null)) {
                    $applied[(string) $transition['idempotency_key']] = $metadata['economic_key'];
                }
            }
            foreach ($chunk as $i => $row) {
                $key = (string) $row['idempotency_key'];
                if (($applied[$keys[$i]] ?? '') !== $key || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $metadata = $this->json($row['metadata_json']);
                $method = $this->method((string) ($metadata['method'] ?? ''));
                $record = $this->record($row) + [
                    'date' => (string) $row['payment_at_utc'], 'method' => $method, 'status' => 'applied',
                    'pagouId' => (string) ($metadata['remote_charge_id'] ?? ''),
                    'reference' => (string) ($metadata['remote_payment_id'] ?? ''), 'basis' => 'Recebimento bruto conciliado',
                ];
                $result[] = $record;
            }
        }
        $enriched = [];
        $identities = new \Pagou\Whmcs\Application\Runtime\PaymentIdentityStore($this->pdo);
        foreach (array_chunk($result, 200) as $chunk) {
            foreach ($identities->enrich($chunk) as $record) {
                $documentMatches = $filter->document === ''
                    || ($filter->party !== 'payer' && $filter->document === $record['issuedDocument'])
                    || ($filter->party !== 'issued' && $filter->document === $record['payerDocument']);
                if ($documentMatches && $this->matches($filter, $record)) {
                    $enriched[] = $record;
                }
            }
        }
        return $enriched;
    }

    /** @return list<array<string,mixed>> */
    private function openInvoices(ReportFilter $filter): array
    {
        $parameters = [];
        $where = '';
        if ($filter->client > 0) {
            $where .= ' AND i.userid = :client_id';
            $parameters['client_id'] = $filter->client;
        }
        if ($filter->invoice > 0) {
            $where .= ' AND i.id = :invoice_id';
            $parameters['invoice_id'] = $filter->invoice;
        }
        $invoices = $this->query(
            'SELECT i.id AS invoice_id, i.userid AS client_id, i.total, i.credit, i.duedate, i.paymentmethod, '
            . 'c.firstname, c.lastname, c.companyname FROM tblinvoices i LEFT JOIN tblclients c ON c.id = i.userid '
            . "WHERE i.status = 'Unpaid' AND i.paymentmethod IN ('pagou_pix', 'pagou_boleto', 'pagou_creditcard') "
            . "AND EXISTS (SELECT 1 FROM pagou_payment_attempts a WHERE a.invoice_id = i.id AND a.currency = 'BRL')"
            . $where . ' ORDER BY i.duedate ASC, i.id ASC',
            $parameters,
        );
        $result = [];
        foreach (array_chunk($invoices, 200) as $chunk) {
            $payments = [];
            $ids = array_column($chunk, 'invoice_id');
            // Sum in integer cents, not a floating SQL aggregate (SQLite fixtures included).
            foreach ($this->query('SELECT invoiceid, amountin, amountout FROM tblaccounts WHERE invoiceid IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $payment) {
                $id = (int) $payment['invoiceid'];
                $payments[$id] = ($payments[$id] ?? 0) + Money::fromDecimal((string) $payment['amountin'])->centavos() - Money::fromDecimal((string) $payment['amountout'])->centavos();
            }
            foreach ($chunk as $row) {
                $balance = max(0, Money::fromDecimal((string) $row['total'])->centavos() - Money::fromDecimal((string) $row['credit'])->centavos() - ($payments[(int) $row['invoice_id']] ?? 0));
                if ($balance === 0) {
                    continue;
                }
                $due = ReportFilter::date((string) $row['duedate']);
                $days = max(0, (int) $due->diff($filter->today)->format('%r%a'));
                $row['amount_cents'] = $balance;
                $record = $this->record($row) + [
                    'date' => (string) $row['duedate'], 'method' => $this->method((string) $row['paymentmethod']),
                    'status' => $days > 0 ? 'overdue' : 'open', 'days' => $days,
                    'bucket' => $days === 0 ? 'A vencer / hoje' : ($days <= 7 ? '1 a 7 dias' : ($days <= 30 ? '8 a 30 dias' : ($days <= 60 ? '31 a 60 dias' : 'Mais de 60 dias'))),
                    'reference' => '', 'basis' => 'Saldo atual da fatura',
                ];
                if ($this->matches($filter, $record)) {
                    $result[] = $record;
                }
            }
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function attempts(ReportFilter $filter): array
    {
        [$from, $until] = $filter->bounds();
        $parameters = ['from_date' => $from, 'until_date' => $until];
        $where = $this->identityWhere($filter, 'a', $parameters);
        $rows = $this->query(
            'SELECT a.id, a.invoice_id, a.client_id, a.amount_cents, a.method, a.status, a.remote_id, a.created_at, '
            . 'c.firstname, c.lastname, c.companyname FROM pagou_payment_attempts a '
            . 'LEFT JOIN tblclients c ON c.id = a.client_id '
            . "WHERE a.currency = 'BRL' AND a.created_at >= :from_date AND a.created_at < :until_date " . $where
            . ' ORDER BY a.created_at DESC, a.id DESC',
            $parameters,
        );
        $result = [];
        foreach ($rows as $row) {
            $record = $this->record($row) + [
                'date' => (string) $row['created_at'], 'method' => $this->method((string) $row['method']),
                'status' => (string) $row['status'], 'reference' => (string) ($row['remote_id'] ?? $row['id']),
                'basis' => 'Valor nominal da tentativa',
            ];
            if ($this->matches($filter, $record)) {
                $result[] = $record;
            }
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function pending(ReportFilter $filter): array
    {
        $rows = $this->query(
            'SELECT f.id, f.finding_type, f.status, f.severity, f.details_json, f.detected_at_utc, '
            . 'a.invoice_id, a.client_id, a.amount_cents, a.method '
            . 'FROM pagou_reconciliation_findings f LEFT JOIN pagou_payment_attempts a ON a.id = f.attempt_id '
            . "WHERE f.status IN ('open', 'pending') ORDER BY f.detected_at_utc ASC, f.id ASC",
        );
        $result = [];
        // Ledger findings have no attempt; recover their explicit economic identity.
        $economicKeys = [];
        foreach ($rows as $row) {
            $key = $this->json($row['details_json'])['economic_key'] ?? null;
            if (is_string($key) && $key !== '') {
                $economicKeys[] = $key;
            }
        }
        $ledger = [];
        foreach (array_chunk(array_values(array_unique($economicKeys)), 200) as $keys) {
            foreach ($this->query("SELECT idempotency_key, invoice_id, client_id, amount_cents, metadata_json FROM pagou_ledger_entries WHERE entry_type = 'received_payment' AND currency = 'BRL' AND idempotency_key IN (" . implode(',', array_fill(0, count($keys), '?')) . ')', $keys) as $entry) {
                $ledger[(string) $entry['idempotency_key']] = $entry;
            }
        }
        foreach ($rows as $row) {
            $key = $this->json($row['details_json'])['economic_key'] ?? null;
            $entry = is_string($key) ? ($ledger[$key] ?? null) : null;
            if ($entry !== null) {
                $row = array_replace($row, $entry);
                $row['method'] = $this->json($entry['metadata_json'])['method'] ?? '';
            }
            $date = new DateTimeImmutable((string) $row['detected_at_utc'], new DateTimeZone('UTC'));
            $days = max(0, (int) $date->setTimezone(new DateTimeZone('America/Sao_Paulo'))->setTime(0, 0)->diff($filter->today)->format('%r%a'));
            $record = $this->record($row) + [
                'date' => (string) $row['detected_at_utc'], 'method' => $this->method((string) ($row['method'] ?? '')),
                'status' => (string) $row['status'], 'days' => $days, 'severity' => (string) $row['severity'],
                'type' => (string) $row['finding_type'], 'reference' => (string) $row['id'],
                'guidance' => $row['finding_type'] === 'late_charges_require_review'
                    ? (string) ($this->json($row['details_json'])['message'] ?? self::guidance('late_charges_require_review'))
                    : self::guidance((string) $row['finding_type']), 'basis' => 'Valor de referência da pendência',
            ];
            if ($this->matches($filter, $record)) {
                $result[] = $record;
            }
        }
        return $result;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public function summary(array $rows): array
    {
        $amount = 0;
        $unknown = 0;
        $methods = [];
        $buckets = [];
        $invoices = [];
        $statuses = [];
        $statusAmounts = [];
        $types = [];
        foreach (['pix', 'boleto', 'card', 'unknown'] as $method) {
            $methods[$method] = ['amount' => 0, 'count' => 0];
        }
        foreach (['A vencer / hoje', '1 a 7 dias', '8 a 30 dias', '31 a 60 dias', 'Mais de 60 dias'] as $bucket) {
            $buckets[$bucket] = ['amount' => 0, 'count' => 0];
        }
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'unknown');
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            $statusAmounts[$status] = ($statusAmounts[$status] ?? 0) + (int) ($row['amount'] ?? 0);
            if (isset($row['type'])) {
                $type = (string) $row['type'];
                $types[$type] = ($types[$type] ?? 0) + 1;
            }
            $cents = $row['amount'] ?? null;
            $amount += (int) $cents;
            $unknown += $cents === null ? 1 : 0;
            $methods[$row['method']]['amount'] += (int) $cents;
            $methods[$row['method']]['count']++;
            if (isset($row['bucket'])) {
                $buckets[$row['bucket']]['amount'] += (int) $cents;
                $buckets[$row['bucket']]['count']++;
            }
            if ($row['invoice'] > 0 && $cents !== null) {
                // Pending exposure is a reference per invoice, not sum of repeated findings.
                $invoices[$row['invoice']] = max($invoices[$row['invoice']] ?? 0, (int) $cents);
            }
        }
        return [
            'count' => count($rows), 'amount' => $amount, 'unknown' => $unknown, 'invoiceCount' => count($invoices),
            'invoiceAmount' => array_sum($invoices), 'methods' => $methods, 'buckets' => $buckets,
            'statuses' => $statuses, 'statusAmounts' => $statusAmounts, 'types' => $types,
            'average' => count($rows) > 0 ? (int) round($amount / count($rows)) : null,
        ];
    }

    /** @return array<string,mixed> */
    public function detail(int $invoice): array
    {
        if ($invoice < 1) {
            throw new \InvalidArgumentException('Informe a fatura que deseja consultar.');
        }
        $attempts = $this->query(
            'SELECT id, invoice_id, client_id, method, status, amount_cents, currency, remote_id, created_at, updated_at '
            . 'FROM pagou_payment_attempts WHERE invoice_id = ? ORDER BY created_at DESC, id DESC',
            [$invoice],
        );
        $ledger = $this->query(
            'SELECT idempotency_key, entry_type, amount_cents, currency, payment_at_utc, effective_at_utc, metadata_json '
            . 'FROM pagou_ledger_entries WHERE invoice_id = ? ORDER BY effective_at_utc DESC, id DESC',
            [$invoice],
        );
        if ($attempts === [] && $ledger === []) {
            throw new \InvalidArgumentException('Não há registros desta fatura no módulo oficial Pagou.');
        }
        $invoiceRows = $this->query(
            'SELECT i.id, i.userid, i.status, i.duedate, i.total, i.credit, i.paymentmethod, '
            . 'c.firstname, c.lastname, c.companyname FROM tblinvoices i LEFT JOIN tblclients c ON c.id = i.userid WHERE i.id = ?',
            [$invoice],
        );
        $operations = $this->query(
            'SELECT o.id, o.operation_type, o.status, o.attempts, o.created_at, o.updated_at, o.attempt_id, o.started_at, o.finished_at, o.response_json, o.available_at, o.error_message '
            . 'FROM pagou_payment_operations o INNER JOIN pagou_payment_attempts a ON a.id = o.attempt_id '
            . 'WHERE a.invoice_id = ? ORDER BY o.created_at DESC, o.id DESC',
            [$invoice],
        );
        $notifications = $this->query(
            'SELECT w.id, w.event_key, w.event_type, w.signature_valid, w.processing_status, w.received_at_utc, w.processed_at_utc '
            . 'FROM pagou_webhook_deliveries w WHERE EXISTS ('
            . 'SELECT 1 FROM pagou_payment_attempts a WHERE a.invoice_id = ? '
            . "AND (a.id = w.attempt_id OR (a.remote_id IS NOT NULL AND a.remote_id <> '' AND a.remote_id = w.provider_event_id))) "
            . 'ORDER BY w.received_at_utc DESC, w.id DESC',
            [$invoice],
        );
        // Webhook processing means handed to the queue. Show its actual job outcome separately.
        $jobs = [];
        foreach (array_chunk($notifications, 200) as $chunk) {
            $keys = array_map(static fn (array $row): string => hash('sha256', 'webhook:' . hash('sha256', $row['event_key'])), $chunk);
            foreach ($this->query('SELECT deduplication_key, status FROM pagou_payment_operations WHERE deduplication_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys) as $job) {
                $jobs[$job['deduplication_key']] = $job['status'];
            }
        }
        foreach ($notifications as &$notification) {
            $notification['job_status'] = $jobs[hash('sha256', 'webhook:' . hash('sha256', $notification['event_key']))] ?? 'history_unavailable';
            unset($notification['event_key']);
        }
        unset($notification);
        $refunds = $this->query(
            'SELECT r.id, r.amount_cents, r.status, r.requested_at_utc, r.completed_at_utc, r.provider_refund_id '
            . 'FROM pagou_card_refunds r INNER JOIN pagou_card_transactions t ON t.id = r.card_transaction_id '
            . 'INNER JOIN pagou_payment_attempts a ON a.id = t.attempt_id '
            . 'WHERE a.invoice_id = ? ORDER BY r.requested_at_utc DESC, r.id DESC',
            [$invoice],
        );
        $pixRefunds = $this->query(
            'SELECT id, amount_cents, receipt_cents, status, requested_at, confirmed_at, updated_at, provider_refund_id '
            . 'FROM pagou_pix_refunds WHERE invoice_id = ? ORDER BY requested_at DESC, id DESC',
            [$invoice],
        );
        $history = [];
        foreach ($ledger as $entry) {
            $metadata = $this->json($entry['metadata_json']);
            if (!in_array($entry['entry_type'], ['received_payment', 'received_payment_transition'], true)) {
                continue;
            }
            $history[] = [
                'date' => $entry['effective_at_utc'], 'paymentDate' => $entry['payment_at_utc'],
                'amount' => $entry['amount_cents'], 'currency' => $entry['currency'],
                'status' => $metadata['status'] ?? 'unknown',
                'reference' => $metadata['remote_payment_id'] ?? '',
            ];
        }
        return [
            'identities' => (new \Pagou\Whmcs\Application\Runtime\PaymentIdentityStore($this->pdo))->forInvoice($invoice, match ((string) ($invoiceRows[0]['paymentmethod'] ?? '')) {
                'pagou_pix' => 'pix', 'pagou_boleto' => 'boleto', default => null,
            }),
            'invoiceId' => $invoice, 'invoice' => $invoiceRows[0] ?? null,
            'attempts' => array_slice($attempts, 0, 100), 'operations' => array_slice($operations, 0, 100),
            'notifications' => array_slice($notifications, 0, 100), 'ledger' => array_slice($history, 0, 100),
            'refunds' => array_slice($refunds, 0, 100), 'pixRefunds' => array_slice($pixRefunds, 0, 100),
            'counts' => ['Tentativas' => count($attempts), 'Operações' => count($operations),
                'Notificações' => count($notifications), 'Eventos financeiros' => count($history),
                'Devoluções Pix' => count($pixRefunds), 'Estornos' => count($refunds)],
            'pending' => $this->pending(new ReportFilter(['report' => 'pending', 'invoice' => (string) $invoice], $this->now)),
        ];
    }

    public static function guidance(string $type): string
    {
        return match ($type) {
            'invoice_email_delivery_uncertain' => 'Confira o histórico de e-mails da fatura no WHMCS antes de reenviar. O envio automático foi interrompido para evitar repetição.',
            'invoice_email_template_invalid' => 'Selecione um template do tipo fatura nas configurações do boleto e confira o envio manualmente.',
            'payment_application_failed' => 'Confira a transação e os créditos na fatura antes de qualquer baixa manual. Consulte o suporte se o resultado continuar incerto.',
            'amount_mismatch', 'payment_amount_mismatch', 'card_amount_mismatch' => 'Compare o valor recebido na Pagou com o saldo e os pagamentos da fatura.',
            'invoice_not_found', 'missing_invoice' => 'Localize a fatura correspondente no WHMCS antes de vincular o pagamento.',
            'payment_evidence_missing' => 'Confira a notificação do recebimento e sua conciliação. O estado pago da cobrança, sozinho, não comprova os dados necessários para a baixa.',
            'payment_during_cancellation' => 'Confira o pagamento recebido antes de substituir o boleto. A cobrança foi paga durante o cancelamento.',
            'late_charges_require_review' => 'Revise as unidades e a periodicidade dos encargos e confira a multa nativa do WHMCS antes de emitir novamente.',
            'due_date_requires_review' => 'Confira o vencimento da fatura e o prazo permitido para a cobrança antes de emitir novamente.',
            'card_remote_identity_unavailable', 'remote_identity_unavailable' => 'Localize a operação original na Pagou. Não repita uma cobrança enquanto seu resultado e vínculo estiverem incertos.',
            'card_chargeback_requires_review' => 'Confira a contestação na Pagou e os pagamentos da fatura antes de qualquer ajuste manual.',
            'card_unknown_provider_status' => 'Consulte a situação do cartão na Pagou e o diagnóstico. Não presuma aprovação ou recusa diante de estado desconhecido.',
            'card_deletion_requires_review' => 'Confira se o cartão anterior ainda está salvo na Pagou antes de repetir a remoção.',
            'currency_mismatch' => 'Confira a moeda da fatura e da cobrança. Este módulo opera em reais.',
            'pix_refund_requires_review', 'pix_refund_external_requires_review' => 'Confira a devolução na Pagou e a transação de reembolso na fatura do WHMCS. Não solicite outra devolução para este Pix.',
            default => 'Abra o detalhe, confira a cobrança e a fatura. Consulte o diagnóstico ou o suporte antes de repetir uma ação financeira.',
        };
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function record(array $row): array
    {
        $name = trim((string) ($row['companyname'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? ''));
        }
        return [
            'invoice' => (int) ($row['invoice_id'] ?? 0), 'client' => (int) ($row['client_id'] ?? 0),
            'customer' => $name, 'amount' => isset($row['amount_cents']) ? (int) $row['amount_cents'] : null,
        ];
    }

    private function method(string $method): string
    {
        return match ($method) {
            'pix', 'pagou_pix' => 'pix', 'boleto', 'pagou_boleto' => 'boleto',
            'card', 'creditcard', 'credit_card', 'pagou_creditcard' => 'card', default => 'unknown',
        };
    }

    /** @param array<string,mixed> $row */
    private function matches(ReportFilter $filter, array $row): bool
    {
        return ($filter->query === '' || mb_stripos((string) ($row['customer'] ?? ''), $filter->query) !== false)
            && ($filter->method === '' || $filter->method === $row['method'])
            && ($filter->status === '' || $filter->status === $row['status'])
            && ($filter->client === 0 || $filter->client === $row['client'])
            && ($filter->invoice === 0 || $filter->invoice === $row['invoice']);
    }

    /** @param array<string,scalar> $parameters */
    private function identityWhere(ReportFilter $filter, string $alias, array &$parameters): string
    {
        $where = '';
        foreach (['invoice' => $filter->invoice, 'client' => $filter->client] as $key => $value) {
            if ($value > 0) {
                $where .= ' AND ' . $alias . '.' . $key . '_id = :' . $key . '_id';
                $parameters[$key . '_id'] = $value;
            }
        }
        return $where;
    }

    /**
     * @param array<array-key,mixed> $parameters
     * @return list<array<string,mixed>>
     */
    private function query(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql . ' LIMIT ' . (self::LIMIT + 1));
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::LIMIT) {
            throw new \LengthException('O relatório excede 50.000 registros de origem. Reduza o período ou filtre por cliente/fatura.');
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    private function json(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
