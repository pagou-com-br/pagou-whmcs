<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;
use Pagou\Whmcs\Configuration\CentralSettingsStore;
use Pagou\Whmcs\Configuration\EncryptedCredentialStore;
use Pagou\Whmcs\Presentation\ClientPortalView;

final class OperationalReadModel
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    public function admin(string $page, array $filters = []): array
    {
        return match ($page) {
            'payments' => $this->paginated('payments', $filters),
            'operations' => $this->paginated('operations', $filters),
            'webhooks' => $this->paginated('webhooks', $filters),
            'reconciliation' => $this->reconciliation(),
            'findings' => ['findings' => $this->findings()] + (new \Pagou\Whmcs\Application\Reporting\AdminReportProvider($this->pdo))->admin($page, $filters),
            'reports', 'charge' => (new \Pagou\Whmcs\Application\Reporting\AdminReportProvider($this->pdo))->admin($page, $filters),
            'search' => ['search' => (new \Pagou\Whmcs\Application\Reporting\AdminSearch($this->pdo))->search($filters['q'] ?? '')],
            'card' => $this->card(),
            'settings' => $this->settings(),
            'diagnostics' => $this->diagnostics() + ['pdfIntegration' => (new \Pagou\Whmcs\InvoicePdf\IntegrationService($this->pdo, defined('ROOTDIR') ? (string) constant('ROOTDIR') : ''))->inspect()],
            'about' => $this->about(),
            default => $this->dashboard() + (new \Pagou\Whmcs\Application\Reporting\AdminReportProvider($this->pdo))->admin('dashboard', []),
        };
    }

    /**
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private function paginated(string $page, array $filters): array
    {
        $errors = OperationalFilters::errors($page, $filters);
        if ($errors !== []) {
            return [$page => [], 'filters' => $filters, 'filterErrors' => $errors, 'hasNext' => false];
        }
        if ($page === 'payments' && ($filters['group'] ?? '') !== 'all') {
            $groups = $this->paymentGroups($filters);
            return ['payments' => array_slice($groups, 0, 50), 'grouped' => true, 'filters' => $filters, 'hasNext' => count($groups) > 50,
                'statusCounts' => $this->paymentStatusCounts($filters, true)];
        }
        $rows = match ($page) {
            'payments' => $this->payments(51, $filters),
            'webhooks' => $this->webhooks(51, $filters),
            default => $this->operations(51, $filters),
        };

        return [$page => array_slice($rows, 0, 50), 'filters' => $filters, 'hasNext' => count($rows) > 50]
            + ($page === 'webhooks' ? ['eventTypes' => $this->webhookEventTypes(), 'hiddenUnknown' => ($filters['type'] ?? '') === ''
                ? $this->count("SELECT COUNT(*) FROM pagou_webhook_deliveries WHERE event_type = 'unknown'")
                : 0, 'summary' => $this->webhookSummary()] : [])
            + ($page === 'payments' ? ['statusCounts' => $this->paymentStatusCounts($filters, false)] : [])
            + ($page === 'operations' ? ['queue' => $this->queueSummary()] : []);
    }

    /**
     * Counts for the status shortcuts, under the other active filters. Grouped
     * lists count invoices with a matching attempt, as their filter does.
     * @param array<string, string> $filters
     * @return array<string, int>
     */
    private function paymentStatusCounts(array $filters, bool $grouped): array
    {
        [$where, $parameters] = $this->paymentFilters(array_diff_key($filters, ['status' => true]));
        $count = $grouped ? 'COUNT(DISTINCT invoice_id)' : 'COUNT(*)';
        $sql = ' FROM pagou_payment_attempts' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
        try {
            $statement = $this->pdo->prepare('SELECT status, ' . $count . ' AS total' . $sql . ' GROUP BY status');
            $statement->execute($parameters);
            $counts = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[(string) $row['status']] = (int) $row['total'];
            }
            $all = $this->pdo->prepare('SELECT ' . $count . $sql);
            $all->execute($parameters);

            return ['' => (int) $all->fetchColumn()] + $counts;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{pending:int,oldest:string} */
    private function queueSummary(): array
    {
        $status = "status IN ('pending', 'leased', 'retrying', 'uncertain')";
        $oldest = $this->scalar('SELECT MIN(available_at) FROM pagou_payment_operations WHERE ' . $status);

        return ['pending' => $this->count('SELECT COUNT(*) FROM pagou_payment_operations WHERE ' . $status), 'oldest' => $oldest === null ? '' : $this->displayTime($oldest)];
    }

    /**
     * Notifications received since midnight in São Paulo.
     * @return array{today:int,valid:int,last:string}
     */
    private function webhookSummary(): array
    {
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('America/Sao_Paulo')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        try {
            $statement = $this->pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN signature_valid = 1 THEN 1 ELSE 0 END) AS valid FROM pagou_webhook_deliveries WHERE event_type <> 'unknown' AND received_at_utc >= ?");
            $statement->execute([$today]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            $row = [];
        }
        $last = $this->scalar("SELECT MAX(received_at_utc) FROM pagou_webhook_deliveries WHERE event_type <> 'unknown'");

        return ['today' => (int) ($row['total'] ?? 0), 'valid' => (int) ($row['valid'] ?? 0), 'last' => $last === null ? '' : $this->displayTime($last)];
    }

    /** @return array<string, string> */
    private function webhookEventTypes(): array
    {
        $options = ['' => 'Todos os eventos'];
        $statement = $this->pdo->query('SELECT DISTINCT event_type FROM pagou_webhook_deliveries ORDER BY event_type');
        while (($type = $statement->fetchColumn()) !== false) {
            $type = (string) $type;
            if (preg_match('/^[a-z0-9._-]{2,64}$/', $type) !== 1) {
                continue;
            }
            $options[$type] = $type === 'unknown' ? 'Tipo não reconhecido' : $this->label($type);
        }

        return $options;
    }

    /** @return list<array{invoiceNumber:string,method:string,status:string,amount:string,dueDate:string,actionUrl:string}> */
    public function clientPayments(int $clientId, int $page = 1): array
    {
        if ($clientId < 1) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT r.invoice_id, r.method, r.status, r.amount_cents, r.display_json, i.duedate '
            . 'FROM pagou_payment_read_models r INNER JOIN tblinvoices i ON i.id = r.invoice_id '
            . 'WHERE r.client_id = :client_id AND i.userid = :invoice_client_id '
            . 'ORDER BY r.refreshed_at_utc DESC, r.invoice_id DESC, r.method ASC LIMIT 51 OFFSET ' . ((max(1, min(9999, $page)) - 1) * 50)
        );
        $statement->execute(['client_id' => $clientId, 'invoice_client_id' => $clientId]);
        $payments = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $display = $this->json((string) ($row['display_json'] ?? ''));
            $invoiceId = (int) $row['invoice_id'];
            $payments[] = [
                'invoiceNumber' => '#' . $invoiceId,
                'method' => (string) $row['method'],
                'status' => (string) $row['status'],
                'amount' => 'R$ ' . number_format((int) $row['amount_cents'] / 100, 2, ',', '.'),
                'dueDate' => (string) ($display['dueDate'] ?? $row['duedate'] ?? ''),
                'actionUrl' => 'viewinvoice.php?id=' . $invoiceId,
            ];
        }

        return ClientPortalView::payments($payments);
    }

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        $start = new \DateTimeImmutable('today', new \DateTimeZone('America/Sao_Paulo'));
        $today = $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $tomorrow = $start->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $onboarding = $this->onboarding();
        $receipts = $this->confirmedReceipts($today, $tomorrow);
        $processing = $this->count(
            "SELECT COUNT(*) FROM pagou_payment_operations WHERE status IN ('pending', 'leased', 'retrying', 'uncertain')",
        );
        $findings = $this->count(
            "SELECT COUNT(*) FROM pagou_reconciliation_findings WHERE status IN ('open', 'pending')",
        );
        $lastReconciliation = $this->scalar(
            "SELECT MAX(updated_at) FROM pagou_payment_operations WHERE operation_type LIKE 'reconcile%' AND status = 'succeeded'",
        );
        $worker = $this->workerStatus();

        return [
            'paymentsToday' => count($receipts),
            'paymentsTodayValue' => $this->money(array_sum($receipts)),
            'processing' => $processing,
            'findings' => $findings,
            'awaitingConfirmation' => $this->count(
                "SELECT COUNT(*) FROM pagou_payment_attempts WHERE status IN ('queued', 'pending', 'ready', 'awaiting_registration', 'uncertain')",
            ),
            'boletoPending' => $this->count(
                "SELECT COUNT(*) FROM pagou_payment_operations WHERE operation_type IN "
                . "('issue_boleto', 'fetch_boleto_pdf', 'deliver_invoice_email') "
                . "AND status IN ('pending', 'leased', 'retrying', 'uncertain')",
            ),
            'cardAttention' => $this->count(
                "SELECT COUNT(*) FROM pagou_card_transactions WHERE capture_status IN "
                . "('action_required', 'pending', 'authorized', 'unknown', 'charged_back')",
            ),
            'lastReconciliation' => $lastReconciliation ?? 'Nunca',
            'lastReconciliationDisplay' => $this->displayTime($lastReconciliation),
            'worker' => $worker,
            // Same threshold as the diagnostics queue check: work waiting for over an hour.
            'queueStale' => (int) ($worker['pending'] ?? 0) > 0
                && !$this->isRecent(is_string($worker['oldestUtc'] ?? null) ? $worker['oldestUtc'] : null, 60),
            'callback' => $this->callbackStatus(),
            'generatedAt' => $this->displayTime(gmdate('Y-m-d H:i:s')),
            'gatewayStatus' => $this->gatewayStatus(),
            'onboarding' => $onboarding,
            'readinessCards' => $this->readinessCards($onboarding),
        ];
    }

    /**
     * @param array<string, string> $filters
     * @return list<list<string>>
     */
    private function payments(int $limit = 100, array $filters = []): array
    {
        [$where, $parameters] = $this->paymentFilters($filters);
        $page = $this->page($filters);
        $sql = 'SELECT id, invoice_id, client_id, method, amount_cents, updated_at, status FROM pagou_payment_attempts '
            . ($where === [] ? '' : 'WHERE ' . implode(' AND ', $where) . ' ')
            . 'ORDER BY updated_at DESC, id DESC LIMIT ' . max(1, min(100, $limit))
            . ' OFFSET ' . (($page - 1) * 50);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $attempts = $statement->fetchAll(PDO::FETCH_ASSOC);
        $customers = $this->customerNames(array_map(static fn (array $row): int => (int) $row['client_id'], $attempts));

        return array_map(
            fn (array $row): array => [
                '#' . (int) $row['invoice_id'],
                $customers[(int) $row['client_id']] ?? ((int) $row['client_id'] > 0 ? 'Cliente #' . (int) $row['client_id'] : 'Cliente não identificado'),
                $this->label((string) $row['method']),
                'R$ ' . number_format((int) $row['amount_cents'] / 100, 2, ',', '.'),
                $this->displayTime((string) $row['updated_at']),
                $this->label((string) $row['status']),
                (string) $row['id'],
            ],
            $attempts,
        );
    }

    /**
     * One row per invoice: the attempt created last is the current one and the
     * others stay as its history. Filters select invoices with a matching attempt.
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    private function paymentGroups(array $filters): array
    {
        [$where, $parameters] = $this->paymentFilters($filters);
        $invoices = $this->pdo->prepare('SELECT invoice_id, MAX(updated_at) AS last_at FROM pagou_payment_attempts '
            . ($where === [] ? '' : 'WHERE ' . implode(' AND ', $where) . ' ')
            . 'GROUP BY invoice_id ORDER BY last_at DESC, invoice_id DESC LIMIT 51 OFFSET ' . (($this->page($filters) - 1) * 50));
        $invoices->execute($parameters);
        $ids = array_map('intval', $invoices->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            return [];
        }
        $statement = $this->pdo->prepare('SELECT id, invoice_id, client_id, method, amount_cents, created_at, updated_at, status, remote_id FROM pagou_payment_attempts '
            . 'WHERE invoice_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY created_at DESC, id DESC');
        $statement->execute($ids);
        $byInvoice = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byInvoice[(int) $row['invoice_id']][] = $row;
        }
        $customers = $this->customerNames(array_map(static fn (array $rows): int => (int) $rows[0]['client_id'], array_values($byInvoice)));
        $describe = fn (array $row): array => [
            'method' => $this->label((string) $row['method']),
            'amount' => 'R$ ' . number_format((int) $row['amount_cents'] / 100, 2, ',', '.'),
            'createdAt' => $this->displayTime((string) $row['created_at']),
            'updatedAt' => $this->displayTime((string) $row['updated_at']),
            'status' => $this->label((string) $row['status']),
            'attemptId' => (string) $row['id'],
            'methodKey' => (string) $row['method'],
            'statusKey' => (string) $row['status'],
            'remoteId' => (string) ($row['remote_id'] ?? ''),
        ];
        $groups = [];
        foreach ($ids as $id) {
            $rows = $byInvoice[$id] ?? [];
            if ($rows === []) {
                continue;
            }
            $client = (int) $rows[0]['client_id'];
            $groups[] = ['invoice' => $id, 'customer' => $customers[$client] ?? ($client > 0 ? 'Cliente #' . $client : 'Cliente não identificado')]
                + $describe($rows[0]) + ['history' => array_map($describe, array_slice($rows, 1))];
        }

        return $groups;
    }

    /**
     * @param array<string, string> $filters
     * @return array{list<string>, array<string, int|string>}
     */
    private function paymentFilters(array $filters): array
    {
        $where = [];
        $parameters = [];
        if (preg_match('/^[1-9]\d*$/', $filters['invoice'] ?? '') === 1) {
            $where[] = 'invoice_id = :invoice_id';
            $parameters['invoice_id'] = (int) $filters['invoice'];
        }
        if (in_array($filters['method'] ?? '', ['pix', 'boleto', 'card'], true)) {
            $where[] = 'method = :method';
            $parameters['method'] = $filters['method'];
        }
        if (preg_match('/^[a-z_]{2,32}$/', $filters['status'] ?? '') === 1) {
            $where[] = 'status = :status';
            $parameters['status'] = $filters['status'];
        }

        return [$where, $parameters];
    }

    /**
     * Display names for operator lists. A missing WHMCS client table only
     * removes the name; it never hides the financial row itself.
     * @param list<int> $clientIds
     * @return array<int, string>
     */
    private function customerNames(array $clientIds): array
    {
        $ids = array_values(array_unique(array_filter($clientIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, firstname, lastname, companyname FROM tblclients WHERE id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')'
            );
            $statement->execute($ids);
            $names = [];
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $name = trim((string) ($row['companyname'] ?? ''));
                if ($name === '') {
                    $name = trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? ''));
                }
                if ($name !== '') {
                    $names[(int) $row['id']] = $name;
                }
            }

            return $names;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, int> Counts shown beside the addon tabs. */
    public function navigationBadges(): array
    {
        try {
            return ['findings' => $this->count(
                "SELECT COUNT(*) FROM pagou_reconciliation_findings WHERE status IN ('open', 'pending')",
            )];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, string> $filters
     * @return list<list<string>>
     */
    private function operations(int $limit = 100, array $filters = []): array
    {
        $where = [];
        $parameters = [];
        if (preg_match('/^[a-z_][a-z_.]{1,63}$/', $filters['type'] ?? '') === 1) {
            $where[] = 'o.operation_type = :operation_type';
            $parameters['operation_type'] = $filters['type'];
        }
        if (preg_match('/^[1-9]\d*$/', $filters['invoice'] ?? '') === 1) {
            $where[] = 'a.invoice_id = :invoice_id';
            $parameters['invoice_id'] = (int) $filters['invoice'];
        }
        if (in_array($filters['method'] ?? '', ['pix', 'boleto', 'card'], true)) {
            $where[] = 'a.method = :method';
            $parameters['method'] = $filters['method'];
        }
        if (($filters['scope'] ?? '') === 'reconciliation') {
            $where[] = "o.operation_type LIKE 'reconcile%'";
        }
        $status = $filters['status'] ?? '';
        $superseded = "(o.status = 'failed' AND (COALESCE(a.status, '') = 'superseded' "
            . "OR COALESCE(o.error_message, '') IN ('pix_attempt_superseded', 'boleto_attempt_superseded')))";
        if ($status === 'superseded') {
            $where[] = $superseded;
        } elseif (preg_match('/^[a-z_]{2,32}$/', $status) === 1) {
            $where[] = 'o.status = :status';
            $parameters['status'] = $status;
            if ($status === 'failed') {
                $where[] = 'NOT ' . $superseded;
            }
        }
        $cutoff = $this->operationPeriodCutoff($filters['period'] ?? '');
        if ($cutoff !== null) {
            $where[] = 'o.updated_at >= :period_cutoff';
            $parameters['period_cutoff'] = $cutoff;
        }
        $sql = 'SELECT o.id, o.attempts, o.created_at, o.started_at, o.finished_at, o.response_json, o.available_at, o.updated_at, o.operation_type, o.attempt_id, o.status, o.payload_json, o.error_message, '
            . 'a.invoice_id, a.method, a.status AS attempt_status FROM pagou_payment_operations o '
            . 'LEFT JOIN pagou_payment_attempts a ON a.id = o.attempt_id '
            . ($where === [] ? '' : 'WHERE ' . implode(' AND ', $where) . ' ')
            . 'ORDER BY o.updated_at DESC, o.id DESC LIMIT ' . max(1, min(100, $limit))
            . ' OFFSET ' . (($this->page($filters) - 1) * 50);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $this->rows($statement, function (array $row): array {
            $payload = $this->json((string) ($row['payload_json'] ?? ''));
            $invoiceId = (int) ($row['invoice_id'] ?? $payload['invoice_id'] ?? 0);
            $method = (string) ($row['method'] ?? $payload['method'] ?? '');

            return [
                $this->displayTime((string) $row['updated_at']),
                $this->operationLabel($row),
                $method === '' ? 'Não informado' : $this->label($method),
                $invoiceId > 0 ? '#' . $invoiceId : 'Sem fatura',
                $this->label($this->operationStatus($row)),
                (string) $row['attempts'],
                (string) ($row['attempt_id'] ?? ''),
                \Pagou\Whmcs\Presentation\OperationDetails::describe($row),
            ];
        });
    }

    private function operationPeriodCutoff(string $period): ?string
    {
        $timezone = new \DateTimeZone('America/Sao_Paulo');
        $cutoff = match ($period) {
            'today' => new \DateTimeImmutable('today', $timezone),
            '7d' => new \DateTimeImmutable('-7 days', $timezone),
            '30d' => new \DateTimeImmutable('-30 days', $timezone),
            '90d' => new \DateTimeImmutable('-90 days', $timezone),
            default => null,
        };

        return $cutoff?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row */
    private function operationStatus(array $row): string
    {
        if (
            (string) ($row['status'] ?? '') === 'failed'
            && ((string) ($row['attempt_status'] ?? '') === 'superseded'
            || in_array((string) ($row['error_message'] ?? ''), ['pix_attempt_superseded', 'boleto_attempt_superseded'], true))
        ) {
            return 'superseded';
        }

        return (string) ($row['status'] ?? '');
    }

    /**
     * @param array<string, string> $filters
     * @return list<list<string>>
     */
    private function webhooks(int $limit = 100, array $filters = []): array
    {
        $parameters = [];
        // Deliveries without a recognised type stay stored for audit, outside the default list.
        $where = "WHERE event_type <> 'unknown' ";
        if (preg_match('/^[a-z0-9._-]{2,64}$/', $filters['type'] ?? '') === 1) {
            $where = 'WHERE event_type = :event_type ';
            $parameters['event_type'] = $filters['type'];
        }
        if (preg_match('/^[1-9]\d*$/', $filters['invoice'] ?? '') === 1) {
            // Same proven link as the Fatura column: the attempt itself or its remote charge.
            $where .= 'AND EXISTS (SELECT 1 FROM pagou_payment_attempts i WHERE i.invoice_id = :invoice_id AND (i.id = w.attempt_id '
                . "OR (i.remote_id IS NOT NULL AND i.remote_id <> '' AND i.remote_id = w.provider_event_id))) ";
            $parameters['invoice_id'] = (int) $filters['invoice'];
        }
        $cutoff = $this->operationPeriodCutoff($filters['period'] ?? '');
        if ($cutoff !== null) {
            $where .= 'AND received_at_utc >= :period_cutoff ';
            $parameters['period_cutoff'] = $cutoff;
        }
        $sql = 'SELECT received_at_utc, event_type, provider_event_id, signature_valid, processing_status, event_key, '
            . '(SELECT CASE WHEN COUNT(DISTINCT a.invoice_id) = 1 THEN MAX(a.invoice_id) ELSE NULL END FROM pagou_payment_attempts a '
            . "WHERE a.id = w.attempt_id OR (a.remote_id IS NOT NULL AND a.remote_id <> '' AND a.remote_id = w.provider_event_id)) AS invoice_id "
            . 'FROM pagou_webhook_deliveries w ' . $where
            . 'ORDER BY received_at_utc DESC, id DESC LIMIT ' . max(1, min(100, $limit))
            . ' OFFSET ' . (($this->page($filters) - 1) * 50);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $this->rows($statement, fn (array $row): array => [
            $this->displayTime((string) $row['received_at_utc']),
            (string) $row['event_type'] === 'unknown' ? 'Tipo não reconhecido' : $this->label((string) $row['event_type']),
            (string) ($row['provider_event_id'] ?? 'não informado'),
            (int) $row['signature_valid'] === 1 ? 'válida' : 'inválida',
            $this->webhookTreatment($row),
            !empty($row['invoice_id']) ? '#' . (int) $row['invoice_id'] : 'Sem vínculo comprovado',
        ]);
    }

    /** @param array<string, mixed> $row */
    private function webhookTreatment(array $row): string
    {
        if ((string) $row['processing_status'] !== 'queued') {
            return $this->label((string) $row['processing_status']);
        }
        $status = $this->scalar(
            'SELECT status FROM pagou_payment_operations WHERE deduplication_key = :key',
            ['key' => hash('sha256', 'webhook:' . hash('sha256', (string) $row['event_key']))],
        );

        return $status === null ? 'Encaminhada; histórico indisponível' : $this->label($status);
    }

    /**
     * Gross receipts confirmed in the WHMCS ledger, grouped by economic identity.
     * Payment time, rather than a later synchronization time, determines the day.
     * @return array<string, int>
     */
    private function confirmedReceipts(?string $from = null, ?string $until = null): array
    {
        $sql = "SELECT amount_cents, metadata_json FROM pagou_ledger_entries WHERE entry_type = 'received_payment_transition'";
        $parameters = [];
        if ($from !== null && $until !== null) {
            $sql .= ' AND payment_at_utc >= :from_time AND payment_at_utc < :until_time';
            $parameters = ['from_time' => $from, 'until_time' => $until];
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $receipts = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $metadata = $this->json((string) $row['metadata_json']);
            $key = $metadata['economic_key'] ?? null;
            if (($metadata['status'] ?? '') === 'applied' && is_string($key) && $key !== '') {
                $receipts[$key] = (int) $row['amount_cents'];
                if ($from === null) {
                    break; // Initial setup needs only evidence of the first applied receipt.
                }
            }
        }

        return $receipts;
    }

    /** @return array<string, mixed> */
    private function reconciliation(): array
    {
        return [
            'pending' => $this->count(
                "SELECT COUNT(*) FROM pagou_payment_operations WHERE operation_type LIKE 'reconcile%' AND status IN ('pending', 'leased', 'retrying', 'uncertain')",
            ),
            'divergences' => $this->count(
                "SELECT COUNT(*) FROM pagou_reconciliation_findings WHERE status IN ('open', 'pending')",
            ),
            'runs' => $this->operations(50, ['scope' => 'reconciliation']),
            'coverage' => $this->reconciliationCoverage(),
            'lastReconciliationDisplay' => $this->displayTime($this->scalar(
                "SELECT MAX(updated_at) FROM pagou_payment_operations WHERE operation_type LIKE 'reconcile%' AND status = 'succeeded'",
            )),
        ];
    }

    /** @return list<list<string>> */
    private function findings(): array
    {
        $statement = $this->pdo->query(
            'SELECT f.created_at, f.finding_type, a.invoice_id, f.severity, f.status '
            . 'FROM pagou_reconciliation_findings f LEFT JOIN pagou_payment_attempts a ON a.id = f.attempt_id '
            . 'ORDER BY f.created_at DESC LIMIT 100'
        );

        return $this->rows($statement, fn (array $row): array => [
            $this->displayTime((string) $row['created_at']),
            $this->label((string) $row['finding_type']),
            isset($row['invoice_id']) ? '#' . (int) $row['invoice_id'] : 'sem fatura',
            $this->label((string) $row['severity']),
            $this->label((string) $row['status']),
        ]);
    }

    /** @return array<string, mixed> */
    private function card(): array
    {
        $ready = $this->cardReady();
        $settings = (new CentralSettingsStore($this->pdo))->values();
        $installments = max(1, min(12, (int) $settings['card_max_installments']));
        $events = $this->pdo->query(
            "SELECT o.updated_at, a.invoice_id, o.operation_type, o.status FROM pagou_payment_operations o "
            . "LEFT JOIN pagou_payment_attempts a ON a.id = o.attempt_id "
            . "WHERE o.operation_type LIKE 'card.%' ORDER BY o.updated_at DESC, o.id DESC LIMIT 100"
        );
        $operations = $this->pdo->query(
            'SELECT a.invoice_id, a.amount_cents, a.status, a.updated_at, c.provider_transaction_id, '
            . 'c.brand, c.last4, c.installments, c.capture_status FROM pagou_card_transactions c '
            . 'INNER JOIN pagou_payment_attempts a ON a.id = c.attempt_id '
            . 'ORDER BY a.updated_at DESC LIMIT 50'
        );

        return [
            'cardReady' => $ready,
            'availability' => $this->cardAvailability(),
            'installments' => $installments === 1 ? 'À vista (1 parcela)' : 'Configuração antiga de parcelamento: revise para pagamento à vista',
            'operations' => $this->cardOperationRows($operations),
            'events' => $this->rows($events, fn (array $row): array => [
                $this->displayTime((string) $row['updated_at']),
                isset($row['invoice_id']) ? '#' . (int) $row['invoice_id'] : 'Sem fatura',
                $this->label((string) $row['operation_type']),
                $this->label((string) $row['status']),
            ]),
        ];
    }

    /** @return list<array<string, scalar>> */
    private function cardOperationRows(\PDOStatement|false $statement): array
    {
        if ($statement === false) {
            return [];
        }
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $brand = trim((string) ($row['brand'] ?? ''));
            $last4 = trim((string) ($row['last4'] ?? ''));
            $state = strtolower((string) ($row['capture_status'] ?? $row['status'] ?? 'unknown'));
            $rows[] = [
                'updatedAt' => $this->displayTime((string) $row['updated_at']),
                'invoiceId' => (int) $row['invoice_id'],
                'chargeId' => (string) ($row['provider_transaction_id'] ?? ''),
                'card' => ($brand !== '' ? $brand . ' ' : '') . ($last4 !== '' ? 'final ' . $last4 : 'mascarado'),
                'amount' => 'R$ ' . number_format(((int) $row['amount_cents']) / 100, 2, ',', '.'),
                'state' => $state,
                'status' => $this->label($state),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $configured = $this->credentialConfigured();
        $lastValidated = $this->operationalSetting('credential_last_validated_utc');
        $apiState = $this->operationalSetting('diagnostics_api_state');

        return [
            'credentialConfigured' => $configured,
            'credentialState' => !$configured ? 'attention' : ($apiState === 'ready' ? 'ready' : 'attention'),
            'credentialValidatedAt' => $this->displayTime($lastValidated),
            'credentialFingerprint' => $this->operationalSetting('credential_fingerprint'),
            'settings' => (new CentralSettingsStore($this->pdo))->values(),
            'customFields' => $this->customFields(),
            'gatewayStatus' => $this->gatewayStatus(),
            'callback' => $this->callbackStatus(),
            'worker' => $this->workerStatus(),
            'timezone' => $this->timezoneStatus(),
            'cardReady' => $this->cardReady(),
        ];
    }

    /** @return array<string, mixed> */
    private function onboarding(): array
    {
        $settings = (new CentralSettingsStore($this->pdo))->values();
        $worker = $this->workerStatus();
        $callback = $this->callbackStatus();
        $gateways = $this->gatewayStatus();
        $activeGateways = count(array_filter(
            $gateways,
            static fn (array $gateway): bool => $gateway['active'],
        ));
        $credentialReady = $this->credentialConfigured()
            && $this->operationalSetting('diagnostics_api_state') === 'ready';
        $documentReady = trim((string) ($settings['cpf_field_id'] ?? '')) !== '';

        $steps = [
            $this->step(
                'addon',
                'Ativar o addon e liberar o acesso',
                'O addon está ativo e seu grupo administrativo possui acesso.',
                true,
                'Rever instalação',
                'configaddonmods.php',
            ),
            $this->step(
                'credential',
                'Conectar a conta Pagou',
                $credentialReady
                    ? 'Última consulta segura aprovada em ' . $this->displayTime($this->operationalSetting('diagnostics_api_checked_utc')) . '.'
                    : 'Informe a credencial única e valide a conexão com a Pagou.',
                $credentialReady,
                'Configurar credencial',
                'addonmodules.php?module=pagou_payments&view=settings#pagou-connection',
            ),
            $this->step(
                'documents',
                'Selecionar o campo de CPF ou CNPJ',
                $documentReady
                    ? 'O documento principal do cliente está associado a um campo do WHMCS.'
                    : 'Escolha o campo personalizado que contém o documento do cliente.',
                $documentReady,
                'Selecionar campo',
                'addonmodules.php?module=pagou_payments&view=settings#pagou-identification',
            ),
            $this->step(
                'worker',
                'Confirmar o processamento em segundo plano',
                (string) ($worker['detail'] ?? 'O worker ainda não foi verificado.'),
                ($worker['state'] ?? '') === 'ready',
                'Ver processamento',
                'addonmodules.php?module=pagou_payments&view=operations',
            ),
            $this->step(
                'callback',
                'Confirmar o endereço das notificações',
                $callback['detail'],
                $callback['state'] === 'ready',
                'Ver notificações',
                'addonmodules.php?module=pagou_payments&view=settings#pagou-notifications',
            ),
            $this->step(
                'gateways',
                'Configurar os meios de pagamento desejados',
                $activeGateways > 0
                    ? $activeGateways . ' gateway(s) Pagou ativo(s). A visibilidade continua sob controle do WHMCS.'
                    : 'Ative Pix e boleto pelo painel. A visibilidade para os clientes continua sob seu controle.',
                $activeGateways > 0,
                'Ativar meios',
                'addonmodules.php?module=pagou_payments&view=settings#pagou-gateways',
            ),
        ];
        $ready = count(array_filter(
            $steps,
            static fn (array $step): bool => ($step['state'] ?? '') === 'ready',
        ));
        $next = null;
        foreach ($steps as $step) {
            if (($step['state'] ?? '') !== 'ready') {
                $next = $step;
                break;
            }
        }

        return [
            'state' => $ready === count($steps) ? 'ready' : 'attention',
            'ready' => $ready,
            'total' => count($steps),
            'steps' => $steps,
            'next' => $next,
        ];
    }

    /** @return array<string, string> */
    private function step(
        string $id,
        string $title,
        string $detail,
        bool $ready,
        string $action,
        string $url,
    ): array {
        return compact('id', 'title', 'detail', 'action', 'url') + ['state' => $ready ? 'ready' : 'attention'];
    }

    /**
     * @param array<string, mixed> $onboarding
     * @return list<array{label:string,value:string,detail:string,state:string,url:string}>
     */
    private function readinessCards(array $onboarding): array
    {
        $steps = [];
        foreach ((array) ($onboarding['steps'] ?? []) as $step) {
            if (is_array($step) && isset($step['id'])) {
                $steps[(string) $step['id']] = $step;
            }
        }
        $gateways = $this->gatewayStatus();
        $active = count(array_filter(
            $gateways,
            static fn (array $gateway): bool => $gateway['active'],
        ));
        $callback = $this->callbackStatus();
        $lastWebhook = $this->scalar('SELECT MAX(received_at_utc) FROM pagou_webhook_deliveries');

        return [
            [
                'label' => 'Conexão Pagou',
                'value' => (($steps['credential']['state'] ?? '') === 'ready') ? 'Pronta' : 'Pendente',
                'detail' => (string) ($steps['credential']['detail'] ?? 'Aguardando configuração.'),
                'state' => (string) ($steps['credential']['state'] ?? 'attention'),
                'url' => 'addonmodules.php?module=pagou_payments&view=settings#pagou-connection',
            ],
            [
                'label' => 'Meios de pagamento',
                'value' => $active > 0 ? $active . ' ativo(s)' : 'Pendente',
                'detail' => $active > 0
                    ? 'A exposição aos clientes continua controlada individualmente no WHMCS.'
                    : 'Nenhum gateway Pagou foi ativado ainda.',
                'state' => $active > 0 ? 'ready' : 'attention',
                'url' => 'addonmodules.php?module=pagou_payments&view=settings#pagou-gateways',
            ],
            [
                'label' => 'Processamento',
                'value' => (($steps['worker']['state'] ?? '') === 'ready') ? 'Ativo' : 'Pendente',
                'detail' => (string) ($steps['worker']['detail'] ?? 'Aguardando execução.'),
                'state' => (string) ($steps['worker']['state'] ?? 'attention'),
                'url' => 'addonmodules.php?module=pagou_payments&view=operations',
            ],
            [
                'label' => 'Notificações',
                'value' => $callback['state'] === 'ready' ? 'Preparadas' : 'Revisar',
                'detail' => $lastWebhook === null
                    ? $callback['detail']
                    : 'Última notificação recebida em ' . $this->displayTime($lastWebhook) . '.',
                'state' => $callback['state'],
                'url' => 'addonmodules.php?module=pagou_payments&view=webhooks',
            ],
        ];
    }

    /** @return list<array{id:string,label:string,known:bool,active:bool,visible:bool,state:string,detail:string,url:string}> */
    private function gatewayStatus(): array
    {
        $definitions = [
            'pagou_pix' => 'Pix',
            'pagou_boleto' => 'Boleto',
            'pagou_creditcard' => 'Cartão de crédito',
        ];
        $settings = [];
        $known = true;
        try {
            $statement = $this->pdo->query(
                "SELECT gateway, setting, value FROM tblpaymentgateways WHERE gateway IN "
                . "('pagou_pix', 'pagou_boleto', 'pagou_creditcard')"
            );
            $known = $statement !== false;
            while ($statement !== false && ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $settings[(string) $row['gateway']][(string) $row['setting']] = (string) ($row['value'] ?? '');
            }
        } catch (\Throwable) {
            $settings = [];
            $known = false;
        }

        $gateways = [];
        foreach ($definitions as $id => $label) {
            $values = $settings[$id] ?? [];
            $active = $values !== [];
            $visible = $active && in_array(strtolower((string) ($values['visible'] ?? '')), ['on', '1', 'yes'], true);
            $gateways[] = [
                'id' => $id,
                'label' => $label,
                'known' => $known,
                'active' => $active,
                'visible' => $visible,
                'state' => $active ? 'ready' : 'attention',
                'detail' => !$active ? 'Ainda não ativado no WHMCS.' : ($visible
                    ? 'Ativo e disponível para os clientes elegíveis.'
                    : 'Ativo e oculto para teste controlado.'),
                'url' => 'configgateways.php',
            ];
        }

        return $gateways;
    }

    /** @return list<array{id:string,label:string}> */
    private function customFields(): array
    {
        try {
            $statement = $this->pdo->query(
                "SELECT id, fieldname FROM tblcustomfields WHERE type = 'client' ORDER BY fieldname, id"
            );
            $fields = [];
            while ($statement !== false && ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $fields[] = ['id' => (string) $id, 'label' => trim((string) ($row['fieldname'] ?? 'Campo #' . $id))];
                }
            }

            return $fields;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{state:string,url:string,detail:string} */
    private function callbackStatus(): array
    {
        $systemUrl = $this->configurationValue('SystemURL');
        if ($systemUrl === null || preg_match('#^https://#i', $systemUrl) !== 1) {
            return [
                'state' => 'attention',
                'url' => '',
                'detail' => 'A System URL HTTPS do WHMCS ainda não pôde ser confirmada.',
            ];
        }
        $url = rtrim($systemUrl, '/') . '/modules/gateways/callback/pagou.php';

        return [
            'state' => 'ready',
            'url' => $url,
            'detail' => 'Endereço HTTPS pronto para receber notificações da Pagou.',
        ];
    }

    /** @return array<string, scalar|null> */
    private function workerStatus(): array
    {
        $heartbeat = $this->workerHeartbeat();
        $pending = $this->count(
            "SELECT COUNT(*) FROM pagou_payment_operations WHERE status IN ('pending', 'leased', 'retrying', 'uncertain')",
        );
        $oldest = $this->scalar(
            "SELECT MIN(available_at) FROM pagou_payment_operations WHERE status IN ('pending', 'leased', 'retrying', 'uncertain')",
        );
        $fresh = $this->isRecent($heartbeat, 15);
        $root = defined('ROOTDIR') ? rtrim((string) constant('ROOTDIR'), DIRECTORY_SEPARATOR) : '';
        $command = $root === ''
            ? 'php /caminho/do/whmcs/modules/addons/pagou_payments/cron.php'
            : 'php ' . escapeshellarg($root . '/modules/addons/pagou_payments/cron.php');

        return [
            'state' => $fresh ? 'ready' : 'attention',
            'lastRunUtc' => $heartbeat,
            'lastRun' => $this->displayTime($heartbeat),
            'pending' => $pending,
            'oldestUtc' => $oldest,
            'oldest' => $oldest === null ? 'Nenhuma' : $this->displayTime($oldest),
            'command' => $command,
            'detail' => $heartbeat === null
                ? 'O worker separado ainda não registrou nenhuma execução.'
                : ($fresh
                    ? 'Última execução em ' . $this->displayTime($heartbeat) . ', com ' . $pending . ' operação(ões) na fila.'
                    : 'A última execução foi em ' . $this->displayTime($heartbeat) . ' e precisa ser revisada.'),
        ];
    }

    /** @return array{web:string,display:string,persistence:string,state:string,detail:string} */
    private function timezoneStatus(): array
    {
        $web = date_default_timezone_get();
        $known = in_array($web, ['UTC', 'America/Sao_Paulo'], true);

        return [
            'web' => $web,
            'display' => 'America/Sao_Paulo',
            'persistence' => 'UTC',
            'state' => $known ? 'ready' : 'attention',
            'detail' => $known
                ? 'Datas técnicas são persistidas em UTC e exibidas no horário de São Paulo.'
                : 'O PHP usa ' . $web . '; o módulo continuará convertendo explicitamente entre UTC e São Paulo.',
        ];
    }

    /** @return array<string, mixed> */
    private function reconciliationCoverage(): array
    {
        return (new PeriodicReconciliation($this->pdo, new \Pagou\Whmcs\Application\Async\OperationScheduler(
            new \Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox($this->pdo),
            new \Pagou\Whmcs\Application\Async\SystemAsyncClock(),
        )))->coverage();
    }

    /** @return array<string,mixed> */
    private function diagnostics(): array
    {
        $coverage = $this->reconciliationCoverage();
        $generatedAt = $this->displayTime(gmdate('Y-m-d H:i:s'));
        $worker = $this->workerStatus();
        $callback = $this->callbackStatus();
        $timezone = $this->timezoneStatus();
        $credentialConfigured = $this->credentialConfigured();
        $apiState = $this->operationalSetting('diagnostics_api_state');
        $apiChecked = $this->operationalSetting('diagnostics_api_checked_utc');
        $migrationCount = $this->safeCount('SELECT COUNT(*) FROM pagou_schema_migrations');
        $expectedMigrations = $this->expectedMigrations();
        $databaseReady = $migrationCount !== null;
        $gatewayStatus = $this->gatewayStatus();
        $activeGateways = count(array_filter(
            $gatewayStatus,
            static fn (array $gateway): bool => (bool) $gateway['active'],
        ));
        $cardInUse = count(array_filter(
            $gatewayStatus,
            static fn (array $gateway): bool => $gateway['id'] === 'pagou_creditcard' && $gateway['active'],
        )) > 0 || $this->safeCount("SELECT COUNT(*) FROM pagou_payment_attempts WHERE method = 'card'") !== 0;
        $lastWebhook = $this->scalar('SELECT MAX(received_at_utc) FROM pagou_webhook_deliveries');
        $invalidWebhooks = $this->safeCount(
            'SELECT COUNT(*) FROM pagou_webhook_deliveries WHERE signature_valid = 0',
        );
        $lastReconciliation = $this->scalar(
            "SELECT MAX(updated_at) FROM pagou_payment_operations WHERE operation_type LIKE 'reconcile%' AND status = 'succeeded'",
        );
        $findings = $this->safeCount(
            "SELECT COUNT(*) FROM pagou_reconciliation_findings WHERE status IN ('open', 'pending')",
        );
        $retention = $this->operationalSetting('retention_last_run_utc');
        $checks = [
            $this->check('runtime', 'php', 'PHP compatível', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ready' : 'attention', 'PHP ' . PHP_VERSION . ', mínimo 8.1.', 'Sobre', 'addonmodules.php?module=pagou_payments&view=about'),
            $this->check('runtime', 'extensions', 'Extensões obrigatórias', extension_loaded('curl') && extension_loaded('json') && extension_loaded('openssl') && extension_loaded('pdo') ? 'ready' : 'attention', $this->extensionSummary(), 'Ver ambiente', 'addonmodules.php?module=pagou_payments&view=about'),
            $this->check('storage', 'database', 'Banco e migrations', $databaseReady && $expectedMigrations !== null && $migrationCount >= $expectedMigrations ? 'ready' : 'attention', $databaseReady && $expectedMigrations !== null ? $migrationCount . ' de ' . $expectedMigrations . ' migrations registradas.' : 'Não foi possível consultar o esquema do módulo.', 'Ver diagnóstico', 'addonmodules.php?module=pagou_payments&view=diagnostics'),
            $this->check('connection', 'credential', 'Credencial protegida', $credentialConfigured ? 'ready' : 'attention', $credentialConfigured ? 'A credencial está armazenada pelo cofre nativo do WHMCS e nunca é exibida.' : 'Nenhuma credencial Pagou foi configurada.', 'Configurar credencial', 'addonmodules.php?module=pagou_payments&view=settings#pagou-connection'),
            $this->check('connection', 'api', 'Conectividade com a Pagou', !$credentialConfigured ? 'unavailable' : ($apiState === 'ready' ? 'ready' : 'attention'), !$credentialConfigured ? 'Configure a credencial antes de executar o teste.' : ($apiState === 'ready' ? 'Consulta segura aprovada em ' . $this->displayTime($apiChecked) . '.' : 'Execute o diagnóstico para confirmar a credencial e a conectividade.'), 'Executar diagnóstico', 'addonmodules.php?module=pagou_payments&view=diagnostics'),
            $this->check('processing', 'worker', 'Processamento automático', (string) $worker['state'], (string) $worker['detail'], 'Ver operações', 'addonmodules.php?module=pagou_payments&view=operations'),
            $this->check('processing', 'queue', 'Fila operacional', ($worker['pending'] ?? 0) > 0 && !$this->isRecent(is_string($worker['oldestUtc'] ?? null) ? $worker['oldestUtc'] : null, 60) ? 'attention' : 'ready', (int) ($worker['pending'] ?? 0) . ' operação(ões) aguardando. Mais antiga: ' . (string) ($worker['oldest'] ?? 'Nenhuma') . '.', 'Ver operações', 'addonmodules.php?module=pagou_payments&view=operations', false),
            $this->check('notifications', 'receiver_url', 'Endereço de notificações', (string) $callback['state'], (string) $callback['detail'], 'Ver configuração', 'addonmodules.php?module=pagou_payments&view=settings#pagou-notifications'),
            $this->check('notifications', 'webhook', 'Recebimento e assinatura', $lastWebhook === null ? 'unavailable' : (($invalidWebhooks ?? 0) > 0 ? 'attention' : 'ready'), $lastWebhook === null ? 'Nenhuma notificação foi recebida nesta instalação.' : 'Última em ' . $this->displayTime($lastWebhook) . '; ' . (int) ($invalidWebhooks ?? 0) . ' assinatura(s) inválida(s) registrada(s).', 'Ver notificações', 'addonmodules.php?module=pagou_payments&view=webhooks', false),
            $this->check('reconciliation', 'reconciliation', 'Conciliação financeira', ($coverage['stale'] > 0 || ($findings ?? 0) > 0) ? 'attention' : ($lastReconciliation === null ? 'unavailable' : 'ready'), $lastReconciliation === null ? 'Nenhuma conciliação foi concluída ainda.' : 'Última em ' . $this->displayTime($lastReconciliation) . '; ' . (int) ($findings ?? 0) . ' pendência(s) aberta(s). ' . $coverage['checked'] . ' de ' . $coverage['eligible'] . ' cobranças em acompanhamento conferidas nas últimas 24h; ' . $coverage['stale'] . ' aguardam conferência.', 'Ver conciliação', 'addonmodules.php?module=pagou_payments&view=reconciliation', false),
            $this->check('gateways', 'gateways', 'Meios de pagamento ativos', $activeGateways > 0 ? 'ready' : 'attention', $activeGateways > 0 ? $activeGateways . ' meio(s) ativo(s); a exibição para clientes é controlada individualmente.' : 'Nenhum meio de pagamento Pagou está ativo.', 'Ver meios', 'addonmodules.php?module=pagou_payments&view=settings#pagou-gateways'),
            $this->check('runtime', 'timezone', 'Timezone financeiro', (string) $timezone['state'], (string) $timezone['detail'], 'Ver configuração', 'addonmodules.php?module=pagou_payments&view=settings#pagou-timezone'),
            $this->check('storage', 'retention', 'Retenção operacional', $retention === null ? 'unavailable' : 'ready', $retention === null ? 'A rotina ainda não registrou uma execução.' : 'Última limpeza em ' . $this->displayTime($retention) . '.', 'Ver configuração', 'addonmodules.php?module=pagou_payments&view=settings#pagou-processing', false),
        ];
        if ($cardInUse) {
            $cardReady = $this->cardReady();
            $checks[] = $this->check(
                'gateways',
                'card',
                'Conexão técnica do cartão',
                $cardReady ? 'ready' : 'attention',
                $cardReady ? 'A instalação e a conexão técnica foram verificadas. A habilitação da conta é consultada separadamente no aplicativo Pagou.'
                    : 'A instalação ou a configuração do cartão precisa de verificação. Execute o diagnóstico e, se o aviso continuar, contate o suporte Pagou.',
                'Ver cartão',
                'addonmodules.php?module=pagou_payments&view=card',
                false,
            );
        }
        foreach ($checks as &$check) {
            if ($check['id'] === 'webhook') {
                $check['merchantTitle'] = 'Recebimento de notificações';
                $check['merchantDetail'] = $lastWebhook === null ? 'Nenhuma notificação foi recebida nesta instalação.'
                    : 'Última notificação em ' . $this->displayTime($lastWebhook) . '.'
                        . (($invalidWebhooks ?? 0) > 0 ? ' Há notificações que não passaram na verificação de segurança. Consulte os registros e contate o suporte.' : ' Recebimento verificado.');
            }
        }
        unset($check);
        $requiredAttention = array_filter(
            $checks,
            static fn (array $check): bool => (bool) ($check['required'] ?? true)
                && ($check['state'] ?? '') !== 'ready',
        );
        $readyCount = count(array_filter(
            $checks,
            static fn (array $check): bool => ($check['state'] ?? '') === 'ready',
        ));
        $attentionCount = count(array_filter(
            $checks,
            static fn (array $check): bool => ($check['state'] ?? '') === 'attention',
        ));
        $unavailableCount = count($checks) - $readyCount - $attentionCount;
        $onboarding = $this->onboarding();

        return [
            'overall' => $requiredAttention === [] ? 'ready' : 'attention',
            'generatedAt' => $generatedAt,
            'readyCount' => $readyCount,
            'attentionCount' => $attentionCount,
            'unavailableCount' => $unavailableCount,
            'checks' => $checks,
            'onboarding' => $onboarding,
            'supportReport' => $this->supportReport($generatedAt, $checks, $onboarding),
            'cardInUse' => $cardInUse,
        ];
    }

    private function cardAvailability(): string
    {
        foreach ($this->gatewayStatus() as $gateway) {
            if ($gateway['id'] === 'pagou_creditcard') {
                return !$gateway['known'] ? 'unknown' : (!$gateway['active'] ? 'inactive' : ($gateway['visible'] ? 'visible' : 'hidden'));
            }
        }

        return 'unknown';
    }

    private function cardReady(): bool
    {
        $settings = (new CentralSettingsStore($this->pdo))->values();
        return $this->cardInstalled() && $this->operationalSetting('diagnostics_card_state') === 'ready'
            && $settings['card_max_installments'] === '1' && $settings['card_auto_capture'] === '1';
    }

    private function cardInstalled(): bool
    {
        if (!extension_loaded('curl') || !extension_loaded('json') || !extension_loaded('openssl') || !extension_loaded('pdo')) {
            return false;
        }
        $statement = $this->pdo->prepare('SELECT 1 FROM pagou_schema_migrations WHERE version = :version LIMIT 1');
        $statement->execute(['version' => '013']);
        if ($statement->fetchColumn() === false) {
            return false;
        }
        $modules = dirname(__DIR__, 5);

        return is_readable($modules . '/gateways/pagou/card-input.php')
            && is_readable($modules . '/gateways/callback/pagou_creditcard.php');
    }

    /** @return array<string, scalar> */
    private function check(
        string $group,
        string $id,
        string $title,
        string $state,
        string $detail,
        string $action,
        string $url,
        bool $required = true,
    ): array {
        return compact('group', 'id', 'title', 'state', 'detail', 'action', 'url', 'required');
    }

    /**
     * @param list<array<string, scalar>> $checks
     * @param array<string, mixed> $onboarding
     */
    private function supportReport(string $generatedAt, array $checks, array $onboarding): string
    {
        $lines = [
            'Pagou para WHMCS, relatório seguro de diagnóstico',
            'Gerado em: ' . $generatedAt,
            'Versão do módulo: ' . $this->moduleVersion(),
            'PHP: ' . PHP_VERSION,
            'WHMCS: ' . ($this->whmcsVersion() ?? 'Indisponível'),
            'Banco: ' . (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'Configuração inicial: ' . (int) ($onboarding['ready'] ?? 0) . '/' . (int) ($onboarding['total'] ?? 0),
            '',
            'Verificações:',
        ];
        foreach ($checks as $check) {
            $lines[] = sprintf(
                '[%s] %s: %s',
                strtoupper((string) ($check['state'] ?? 'unavailable')),
                (string) ($check['title'] ?? 'Verificação'),
                (string) ($check['detail'] ?? ''),
            );
        }
        $settings = (new CentralSettingsStore($this->pdo))->values();
        $lines[] = '';
        $lines[] = 'Cartão de crédito, detalhes para suporte:';
        $lines[] = 'Disponibilidade no WHMCS: ' . match ($this->cardAvailability()) {
            'inactive' => 'desativado', 'hidden' => 'ativo e oculto', 'visible' => 'ativo e visível', default => 'não foi possível consultar',
        };
        try {
            $installed = $this->cardInstalled();
            $ready = $this->cardReady();
            $lines[] = 'Componentes do cartão: ' . ($installed ? 'instalados' : 'instalação incompleta');
            $lines[] = 'Diagnóstico e configuração local: ' . ($ready ? 'prontos' : 'requerem revisão');
        } catch (\Throwable) {
            $lines[] = 'Componentes e diagnóstico local do cartão: não foi possível consultar.';
        }
        $lines[] = 'Contrato de proteção: tokenização no navegador; número completo e CVV não são armazenados pelo módulo.';
        $lines[] = 'Retorno de autenticação: origem validada e sessão vinculada de uso único.';
        $lines[] = 'Fluxo 3DS no banco emissor: não avaliado por este relatório.';
        $lines[] = 'Máximo de parcelas configurado: ' . max(1, min(12, (int) $settings['card_max_installments']));
        $lines[] = 'Captura automática configurada: ' . ($settings['card_auto_capture'] === '1' ? 'sim' : 'não, opção incompatível');
        $lines[] = 'Credenciamento da conta Pagou: não consultado neste relatório.';
        $lines[] = '';
        $lines[] = 'Este relatório não contém credenciais, documentos, dados de cartão ou payloads financeiros.';

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    private function about(): array
    {
        $version = $this->moduleVersion();
        $channel = str_contains($version, 'dev')
            ? 'Desenvolvimento'
            : (preg_match('/(?:alpha|beta|rc)/i', $version) === 1 ? 'Pré-lançamento' : 'Estável');

        return [
            'version' => $version,
            'channel' => $channel,
            'publisher' => 'Pagou',
            'license' => 'MIT',
            'minimumPhp' => '8.1',
            'currentPhp' => PHP_VERSION,
            'minimumWhmcs' => '8.6',
            'currentWhmcs' => $this->whmcsVersion() ?? 'Indisponível',
            'currency' => 'BRL',
            'language' => 'Português do Brasil',
            'components' => ['Addon central', 'Pix', 'Boleto', 'Cartão de crédito', 'Widget do painel WHMCS'],
        ];
    }

    /** The packaged migration list is the expected schema, so new migrations need no second count. */
    private function expectedMigrations(): ?int
    {
        $path = dirname(__DIR__, 5) . '/addons/pagou_payments/migrations.php';
        try {
            $migrations = is_file($path) ? require $path : null;
        } catch (\Throwable) {
            return null;
        }

        return is_array($migrations) && $migrations !== [] ? count($migrations) : null;
    }

    private function moduleVersion(): string
    {
        $path = dirname(__DIR__, 5) . '/addons/pagou_payments/VERSION';
        $version = is_file($path) ? trim((string) file_get_contents($path)) : '';

        return preg_match('/^[0-9]+(?:\.[0-9]+)+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1
            ? $version
            : 'Indisponível';
    }

    private function whmcsVersion(): ?string
    {
        $global = $GLOBALS['CONFIG']['Version'] ?? null;
        if (is_scalar($global) && preg_match('/^[0-9]+(?:\.[0-9]+)+/', (string) $global) === 1) {
            return (string) $global;
        }

        return $this->configurationValue('Version');
    }

    private function configurationValue(string $setting): ?string
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT value FROM tblconfiguration WHERE setting = :setting LIMIT 1'
            );
            $statement->execute(['setting' => $setting]);
            $value = $statement->fetchColumn();

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extensionSummary(): string
    {
        $extensions = ['cURL' => 'curl', 'JSON' => 'json', 'OpenSSL' => 'openssl', 'PDO' => 'pdo'];
        $status = [];
        foreach ($extensions as $label => $extension) {
            $status[] = $label . ': ' . (extension_loaded($extension) ? 'disponível' : 'ausente');
        }

        return implode(', ', $status) . '.';
    }

    private function credentialConfigured(): bool
    {
        $environment = getenv('PAGOU_API_KEY');
        if (is_string($environment) && trim($environment) !== '') {
            return true;
        }

        return (new EncryptedCredentialStore($this->pdo))->configured();
    }

    private function workerHeartbeat(): ?string
    {
        return $this->operationalSetting('worker_last_run_utc');
    }

    private function operationalSetting(string $key): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT setting_value FROM pagou_settings WHERE setting_key = :key AND is_secret = 0 LIMIT 1'
        );
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, string> $filters */
    private function page(array $filters): int
    {
        $page = $filters['page'] ?? '1';

        return preg_match('/^[1-9]\d{0,3}$/', $page) === 1 ? (int) $page : 1;
    }

    /** @param array<string, scalar> $parameters */
    private function count(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, scalar> $parameters */
    private function scalar(string $sql, array $parameters = []): ?string
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
            $value = $statement->fetchColumn();
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function safeCount(string $sql): ?int
    {
        try {
            $statement = $this->pdo->query($sql);
            return $statement === false ? null : (int) $statement->fetchColumn();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isRecent(?string $utc, int $minutes): bool
    {
        if ($utc === null || trim($utc) === '') {
            return false;
        }
        try {
            $instant = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            $limit = new \DateTimeImmutable('-' . max(1, $minutes) . ' minutes', new \DateTimeZone('UTC'));

            return $instant >= $limit;
        } catch (\Throwable) {
            return false;
        }
    }

    private function displayTime(?string $utc): string
    {
        if ($utc === null || trim($utc) === '') {
            return 'Nunca';
        }
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('America/Sao_Paulo'))
                ->format('d/m/Y H:i');
        } catch (\Throwable) {
            return 'Indisponível';
        }
    }

    private function money(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }

    private function label(string $value): string
    {
        return \Pagou\Whmcs\Presentation\PaymentLabels::label($value);
    }

    /** @param array<string, mixed> $row */
    private function operationLabel(array $row): string
    {
        if ((string) ($row['operation_type'] ?? '') !== 'replace_boleto') {
            return $this->label((string) ($row['operation_type'] ?? ''));
        }
        $payload = $this->json((string) ($row['payload_json'] ?? ''));

        return (bool) ($payload['replace'] ?? false)
            ? 'Confirmar substituição do boleto'
            : 'Confirmar cancelamento do boleto';
    }

    /**
     * @param \PDOStatement|false $statement
     * @param callable(array<string, mixed>): list<string> $mapper
     * @return list<list<string>>
     */
    private function rows(\PDOStatement|false $statement, callable $mapper): array
    {
        if ($statement === false) {
            return [];
        }
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $mapper($row);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function json(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }
}
