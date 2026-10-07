<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pagou\Payments\Admin\Support\ReportFormat;
use Pagou\Whmcs\Application\Async\AsyncClock;
use Pagou\Whmcs\Application\Async\SystemAsyncClock;
use Pagou\Whmcs\Configuration\CentralSettingsStore;
use Pagou\Whmcs\Infrastructure\Persistence\LeaseRepository;
use Pagou\Whmcs\Support\Uuid;

/**
 * Notifies WHMCS administrators through the Local API SendAdminEmail command.
 * Messages carry counts, labels, invoice numbers and amounts only: never
 * credentials, customer data or provider payloads.
 */
final class AdminAlerts
{
    private const GATEWAYS = ['pagou_pix', 'pagou_boleto', 'pagou_creditcard'];
    private const WORKER_STALE_MINUTES = 15;
    private const REMINDER_HOURS = 24;
    private const RETRY_MINUTES = 30;
    private const TRACKED_LIMIT = 1000;
    private const LISTED_LIMIT = 20;
    private const LEASE = 'admin-alerts';
    private const LEASE_SECONDS = 300;
    private const ADDON = 'Addons > Pagou para WHMCS';
    private const SUBJECTS = ['worker' => 'worker', 'findings' => 'pendências financeiras', 'refunds' => 'reembolsos Pix'];

    /** @var Closure(string, array<string, mixed>): array<string, mixed> */
    private Closure $localApi;

    /** @var Closure(string): void */
    private Closure $log;

    private AsyncClock $clock;

    private OperationalSettings $settings;

    /**
     * @param callable(string, array<string, mixed>): array<string, mixed> $localApi
     * @param (callable(string): void)|null $log
     */
    public function __construct(
        private readonly PDO $pdo,
        callable $localApi,
        ?AsyncClock $clock = null,
        private readonly ?string $adminFolder = null,
        ?callable $log = null,
    ) {
        $this->localApi = Closure::fromCallable($localApi);
        $this->log = $log === null
            ? static function (string $message): void {
                if (function_exists('logActivity')) {
                    logActivity($message);
                }
            }
            : Closure::fromCallable($log);
        $this->clock = $clock ?? new SystemAsyncClock();
        $this->settings = new OperationalSettings($pdo);
    }

    public static function fromRuntime(): self
    {
        return new self(RuntimeFactory::pdo(), RuntimeFactory::localApi(), null, self::detectAdminFolder());
    }

    /** The custom admin directory comes from configuration.php ($customadminpath); unknown means no link. */
    public static function detectAdminFolder(): ?string
    {
        $candidates = [];
        try {
            if (is_callable(['App', 'get_admin_folder_name'])) {
                $candidates[] = call_user_func(['App', 'get_admin_folder_name']);
            }
        } catch (\Throwable) {
            // The text path in the message is enough without a direct link.
        }
        $candidates[] = $GLOBALS['customadminpath'] ?? null;
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', trim($candidate)) === 1) {
                return trim($candidate);
            }
        }

        return null;
    }

    /** @return array{worker:string,findings:string,refunds:string} */
    public function run(): array
    {
        if (((new CentralSettingsStore($this->pdo))->values()['admin_alerts_enabled'] ?? '1') !== '1') {
            return ['worker' => 'disabled', 'findings' => 'disabled', 'refunds' => 'disabled'];
        }
        $leases = new LeaseRepository($this->pdo);
        $owner = Uuid::v4();
        if (!$leases->acquire(self::LEASE, $owner, self::LEASE_SECONDS)) {
            return ['worker' => 'busy', 'findings' => 'busy', 'refunds' => 'busy'];
        }
        try {
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
            $report = [];
            foreach (['worker', 'findings', 'refunds'] as $name) {
                try {
                    $condition = match ($name) {
                        'worker' => $this->workerCondition($now),
                        'findings' => $this->findingsCondition(),
                        default => $this->refundsCondition(),
                    };
                    $report[$name] = $this->evaluate($name, $condition, $now);
                } catch (\Throwable $exception) {
                    ($this->log)('Pagou Payments: verificação do alerta de ' . self::SUBJECTS[$name] . ' indisponível. ' . $exception::class);
                    $report[$name] = 'failed';
                }
            }

            return ['worker' => $report['worker'], 'findings' => $report['findings'], 'refunds' => $report['refunds']];
        } finally {
            $leases->release(self::LEASE, $owner);
        }
    }

    /** @param array{items:array<string, array<string, mixed>>, total:int, last?:?DateTimeImmutable}|null $condition */
    private function evaluate(string $name, ?array $condition, DateTimeImmutable $now): string
    {
        $key = 'admin_alert_' . $name;
        $state = $this->state($key);
        if ($condition === null || $condition['items'] === []) {
            if ($state['exists']) {
                $this->settings->set($key, '');
            }

            return 'idle';
        }
        $current = array_map('strval', array_keys($condition['items']));
        $alerted = array_values(array_intersect($state['ids'], $current));
        $new = array_values(array_diff($current, $alerted));
        if ($state['retry_after'] !== null && $state['retry_after'] > $now) {
            return 'deferred';
        }
        $reminder = $new === [];
        $sentAt = $state['sent_at'];
        if ($reminder && $sentAt !== null && $sentAt > $now->modify('-' . self::REMINDER_HOURS . ' hours')) {
            if ($alerted !== $state['ids']) {
                $this->write($key, $sentAt, $alerted, null);
            }

            return 'waiting';
        }
        $selected = array_intersect_key($condition['items'], array_flip($reminder ? $current : $new));
        [$subject, $paragraphs, $view] = match ($name) {
            'worker' => $this->workerMessage($condition['last'] ?? null, $reminder),
            'findings' => $this->findingsMessage($selected, $condition['total'], $reminder),
            default => $this->refundsMessage($selected, $condition['total'], $reminder),
        };
        try {
            $this->send($subject, $this->html($paragraphs, $view));
        } catch (\Throwable $exception) {
            $this->write($key, $sentAt, $alerted, $now->modify('+' . self::RETRY_MINUTES . ' minutes'));
            ($this->log)(sprintf(
                'Pagou Payments: não foi possível enviar o alerta de %s aos administradores. Nova tentativa em até %d minutos. %s',
                self::SUBJECTS[$name],
                self::RETRY_MINUTES,
                $exception::class,
            ));

            return 'failed';
        }
        $this->write($key, $now, $current, null);
        ($this->log)('Pagou Payments: alerta de ' . self::SUBJECTS[$name] . ' enviado aos administradores.');

        return $reminder ? 'reminded' : 'alerted';
    }

    /** @return array{items:array<string, array<string, mixed>>, total:int, last:?DateTimeImmutable}|null */
    private function workerCondition(DateTimeImmutable $now): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM tblpaymentgateways WHERE gateway IN (' . implode(', ', array_fill(0, count(self::GATEWAYS), '?')) . ') LIMIT 1'
        );
        $statement->execute(self::GATEWAYS);
        if ($statement->fetchColumn() === false) {
            return null;
        }
        $last = null;
        $heartbeat = $this->settings->get('worker_last_run_utc');
        if ($heartbeat !== null) {
            try {
                $last = new DateTimeImmutable($heartbeat, new DateTimeZone('UTC'));
            } catch (\Throwable) {
                // An unreadable heartbeat is treated as a worker that never ran.
            }
        }
        if ($last !== null && $last >= $now->modify('-' . self::WORKER_STALE_MINUTES . ' minutes')) {
            return null;
        }

        return ['items' => ['worker' => []], 'total' => 1, 'last' => $last];
    }

    /** @return array{items:array<string, array<string, mixed>>, total:int} */
    private function findingsCondition(): array
    {
        $statement = $this->pdo->query(
            'SELECT f.id, f.finding_type, f.details_json, a.invoice_id FROM pagou_reconciliation_findings f '
            . 'LEFT JOIN pagou_payment_attempts a ON a.id = f.attempt_id '
            . "WHERE f.status IN ('open', 'pending') ORDER BY f.detected_at_utc DESC, f.id DESC LIMIT " . self::TRACKED_LIMIT
        );
        $items = [];
        $economicKeys = [];
        while ($statement !== false && ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $invoice = (int) ($row['invoice_id'] ?? 0);
            $economicKey = null;
            if ($invoice < 1) {
                // Ledger findings have no attempt; only their economic identity is read.
                $details = json_decode((string) ($row['details_json'] ?? ''), true);
                $candidate = is_array($details) ? ($details['economic_key'] ?? null) : null;
                $economicKey = is_string($candidate) && $candidate !== '' ? $candidate : null;
                if ($economicKey !== null) {
                    $economicKeys[] = $economicKey;
                }
            }
            $items[(string) $row['id']] = [
                'type' => (string) ($row['finding_type'] ?? ''),
                'invoice' => $invoice > 0 ? $invoice : null,
                'economic_key' => $economicKey,
            ];
        }
        $ledger = [];
        foreach (array_chunk(array_values(array_unique($economicKeys)), 200) as $keys) {
            $lookup = $this->pdo->prepare(
                "SELECT idempotency_key, invoice_id FROM pagou_ledger_entries WHERE entry_type = 'received_payment' "
                . 'AND idempotency_key IN (' . implode(', ', array_fill(0, count($keys), '?')) . ')'
            );
            $lookup->execute($keys);
            while (($entry = $lookup->fetch(PDO::FETCH_ASSOC)) !== false) {
                $ledger[(string) $entry['idempotency_key']] = (int) $entry['invoice_id'];
            }
        }
        foreach ($items as $id => $item) {
            $resolved = $item['economic_key'] !== null ? ($ledger[$item['economic_key']] ?? 0) : 0;
            if ($item['invoice'] === null && $resolved > 0) {
                $items[$id]['invoice'] = $resolved;
            }
            unset($items[$id]['economic_key']);
        }

        return ['items' => $items, 'total' => $this->count("SELECT COUNT(*) FROM pagou_reconciliation_findings WHERE status IN ('open', 'pending')")];
    }

    /** @return array{items:array<string, array<string, mixed>>, total:int} */
    private function refundsCondition(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, invoice_id, amount_cents FROM pagou_pix_refunds WHERE status = 'review' "
            . 'ORDER BY updated_at DESC, id DESC LIMIT ' . self::TRACKED_LIMIT
        );
        $items = [];
        while ($statement !== false && ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $items[(string) $row['id']] = ['invoice' => (int) $row['invoice_id'], 'amount' => (int) $row['amount_cents']];
        }

        return ['items' => $items, 'total' => $this->count("SELECT COUNT(*) FROM pagou_pix_refunds WHERE status = 'review'")];
    }

    /** @return array{string, list<string>, string} */
    private function workerMessage(?DateTimeImmutable $last, bool $reminder): array
    {
        return [
            ($reminder ? 'Lembrete Pagou para WHMCS: ' : 'Pagou para WHMCS: ') . 'worker sem execução recente',
            [
                $last === null
                    ? 'O worker do Pagou para WHMCS ainda não registrou nenhuma execução.'
                    : 'A última execução do worker do Pagou para WHMCS foi em ' . $this->displayTime($last) . ' (horário de Brasília).',
                'Sem o worker, a emissão de cobranças, a confirmação de pagamentos e a conciliação ficam paradas.',
                'Confira o agendamento do cron do módulo em ' . self::ADDON . ' > Diagnóstico.',
            ],
            'diagnostics',
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @return array{string, list<string>, string}
     */
    private function findingsMessage(array $items, int $total, bool $reminder): array
    {
        $count = count($items);
        $types = [];
        $invoices = [];
        $unidentified = 0;
        foreach ($items as $item) {
            $label = $this->findingLabel((string) ($item['type'] ?? ''));
            $types[$label] = ($types[$label] ?? 0) + 1;
            $invoice = $item['invoice'] ?? null;
            if (is_int($invoice) && $invoice > 0) {
                $invoices[$invoice] = true;
            } else {
                $unidentified++;
            }
        }
        arsort($types);
        $typeParts = [];
        foreach ($types as $label => $amount) {
            $typeParts[] = $label . ' (' . $amount . ')';
        }
        $invoiceLine = $invoices === []
            ? 'Faturas: nenhuma fatura identificada.'
            : 'Faturas: ' . $this->invoiceList(array_keys($invoices)) . '.';
        if ($invoices !== [] && $unidentified > 0) {
            $invoiceLine .= ' Sem fatura identificada: ' . $unidentified . '.';
        }
        if ($reminder) {
            $subject = 'Lembrete Pagou para WHMCS: ' . $total . ($total === 1 ? ' pendência financeira em aberto' : ' pendências financeiras em aberto');
            $intro = $total === 1 ? 'Ainda há 1 pendência financeira em aberto.' : 'Ainda há ' . $total . ' pendências financeiras em aberto.';
        } else {
            $subject = 'Pagou para WHMCS: ' . $count . ($count === 1 ? ' nova pendência financeira' : ' novas pendências financeiras');
            $intro = $count === 1
                ? 'Foi registrada 1 nova pendência financeira que precisa de conferência.'
                : 'Foram registradas ' . $count . ' novas pendências financeiras que precisam de conferência.';
        }
        $paragraphs = [$intro, 'Tipos: ' . implode(', ', $typeParts) . '.', $invoiceLine];
        if (!$reminder) {
            $paragraphs[] = 'Total de pendências em aberto: ' . $total . '.';
        }
        $paragraphs[] = 'Confira em ' . self::ADDON . ' > Pendências antes de qualquer ação manual.';

        return [$subject, $paragraphs, 'findings'];
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @return array{string, list<string>, string}
     */
    private function refundsMessage(array $items, int $total, bool $reminder): array
    {
        $count = $reminder ? $total : count($items);
        uasort($items, static fn (array $left, array $right): int => (int) ($left['invoice'] ?? 0) <=> (int) ($right['invoice'] ?? 0));
        $lines = [];
        foreach (array_slice($items, 0, self::LISTED_LIMIT) as $item) {
            $lines[] = 'Fatura #' . (int) ($item['invoice'] ?? 0) . ': ' . ReportFormat::money((int) ($item['amount'] ?? 0));
        }
        if (count($items) > self::LISTED_LIMIT) {
            $lines[] = 'E mais ' . (count($items) - self::LISTED_LIMIT) . '.';
        }
        if ($reminder) {
            $subject = 'Lembrete Pagou para WHMCS: ' . $count . ($count === 1 ? ' reembolso Pix aguarda conferência' : ' reembolsos Pix aguardam conferência');
            $intro = $count === 1
                ? 'Ainda há 1 reembolso Pix aguardando conferência.'
                : 'Ainda há ' . $count . ' reembolsos Pix aguardando conferência.';
        } else {
            $subject = $count === 1
                ? 'Pagou para WHMCS: reembolso Pix requer conferência'
                : 'Pagou para WHMCS: ' . $count . ' reembolsos Pix requerem conferência';
            $intro = $count === 1
                ? 'O Pagou para WHMCS marcou 1 reembolso Pix para conferência.'
                : 'O Pagou para WHMCS marcou ' . $count . ' reembolsos Pix para conferência.';
        }

        return [$subject, [
            $intro . ($count === 1 ? ' Não solicite outra devolução para esta fatura.' : ' Não solicite outra devolução para estas faturas.'),
            implode("\n", $lines),
            'Confira o registro na fatura do WHMCS e a aba Pendências em ' . self::ADDON . '.',
        ], 'findings'];
    }

    /** @param list<int> $invoices */
    private function invoiceList(array $invoices): string
    {
        sort($invoices);
        $listed = array_map(static fn (int $invoice): string => '#' . $invoice, array_slice($invoices, 0, self::LISTED_LIMIT));
        $hidden = count($invoices) - count($listed);

        return implode(', ', $listed) . ($hidden > 0 ? ' e mais ' . $hidden : '');
    }

    private function findingLabel(string $type): string
    {
        $label = ReportFormat::label($type);

        // Internal codes without a Portuguese label are not shown to the administrator.
        return $label === ucfirst(str_replace(['_', '.'], ' ', trim($type))) ? 'Outra pendência financeira' : $label;
    }

    /** @param list<string> $paragraphs */
    private function html(array $paragraphs, string $view): string
    {
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $html .= '<p>' . str_replace("\n", '<br>', htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        }
        $url = $this->adminUrl($view);
        if ($url !== null) {
            $safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<p>Acesso direto: <a href="' . $safe . '">' . $safe . '</a></p>';
        }

        return $html;
    }

    private function adminUrl(string $view): ?string
    {
        if ($this->adminFolder === null || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $this->adminFolder) !== 1) {
            return null;
        }
        try {
            $statement = $this->pdo->prepare("SELECT value FROM tblconfiguration WHERE setting = 'SystemURL' LIMIT 1");
            $statement->execute();
            $systemUrl = trim((string) $statement->fetchColumn());
        } catch (\Throwable) {
            return null;
        }
        if (
            preg_match('#^https?://[^\s"\'<>]+$#i', $systemUrl) !== 1
            || filter_var($systemUrl, FILTER_VALIDATE_URL) === false
            || parse_url($systemUrl, PHP_URL_QUERY) !== null
            || parse_url($systemUrl, PHP_URL_FRAGMENT) !== null
        ) {
            return null;
        }

        return rtrim($systemUrl, '/') . '/' . $this->adminFolder . '/addonmodules.php?module=pagou_payments&view=' . $view;
    }

    private function send(string $subject, string $html): void
    {
        $result = ($this->localApi)('SendAdminEmail', [
            'customsubject' => $subject,
            'custommessage' => $html,
            'type' => 'system',
        ]);
        if (($result['result'] ?? null) !== 'success') {
            throw new \RuntimeException('WHMCS did not confirm the admin notification.');
        }
    }

    /** @return array{exists:bool, sent_at:?DateTimeImmutable, ids:list<string>, retry_after:?DateTimeImmutable} */
    private function state(string $key): array
    {
        $raw = $this->settings->get($key);
        $state = ['exists' => $raw !== null, 'sent_at' => null, 'ids' => [], 'retry_after' => null];
        $decoded = $raw === null ? null : json_decode($raw, true);
        if (!is_array($decoded)) {
            return $state;
        }
        foreach (['sent_at', 'retry_after'] as $field) {
            $value = $decoded[$field] ?? null;
            if (is_string($value) && $value !== '') {
                try {
                    $state[$field] = new DateTimeImmutable($value, new DateTimeZone('UTC'));
                } catch (\Throwable) {
                    // Unreadable timestamps behave as absent and allow a new alert.
                }
            }
        }
        if (is_array($decoded['ids'] ?? null)) {
            $state['ids'] = array_values(array_filter($decoded['ids'], 'is_string'));
        }

        return $state;
    }

    /** @param list<string> $ids */
    private function write(string $key, ?DateTimeImmutable $sentAt, array $ids, ?DateTimeImmutable $retryAfter): void
    {
        $this->settings->set($key, json_encode([
            'sent_at' => $sentAt?->format('Y-m-d H:i:s'),
            'ids' => $ids,
            'retry_after' => $retryAfter?->format('Y-m-d H:i:s'),
        ], JSON_THROW_ON_ERROR));
    }

    private function count(string $sql): int
    {
        $statement = $this->pdo->query($sql);

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    private function displayTime(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i');
    }
}
