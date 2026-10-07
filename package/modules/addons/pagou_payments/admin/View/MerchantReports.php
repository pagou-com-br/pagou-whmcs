<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\View;

use Pagou\Payments\Admin\Support\Html;
use Pagou\Payments\Admin\Support\ReportFormat as F;
use Pagou\Whmcs\Application\Reporting\ReportFilter;

final class MerchantReports
{
    private const KINDS = ['receipts' => 'Recebimentos', 'open' => 'Faturas em aberto', 'attempts' => 'Cobranças e tentativas', 'refunds' => 'Devoluções', 'pending' => 'Pendências'];

    /**
     * @param array<string,mixed> $data
     * @param string $view Tab that owns the filters; "findings" embeds only the pending report.
     */
    public function report(array $data, string $csrf, string $view = 'reports'): string
    {
        $embedded = $view === 'findings';
        $report = $data['report'] ?? [];
        $filters = $report['filter'] ?? $data['reportFilters'] ?? [];
        $kind = $embedded ? 'pending' : (string) ($filters['report'] ?? 'receipts');
        if (!isset(self::KINDS[$kind])) {
            $kind = 'receipts';
        }
        $navigation = '';
        if (!$embedded) {
            $navigation = '<nav class="pagou-tabs pagou-report-tabs" aria-label="Tipo de relatório">';
            $icons = ['receipts' => 'wallet', 'open' => 'clock', 'attempts' => 'receipt', 'refunds' => 'refresh', 'pending' => 'alert'];
            foreach (self::KINDS as $key => $label) {
                $values = $filters;
                unset($values['status'], $values['page']);
                if ($key !== 'receipts') {
                    unset($values['document'], $values['party']);
                }
                $values['report'] = $key;
                $navigation .= '<a class="pagou-tab' . ($key === $kind ? ' is-active' : '') . '" href="' . self::url($values) . '"'
                    . ($key === $kind ? ' aria-current="page"' : '') . '>' . Html::icon($icons[$key]) . Html::e($label) . '</a>';
            }
            $navigation .= '</nav>';
        }
        $intro = $embedded ? '' : Page::header('Relatórios', 'Registros do módulo oficial, com exportação para a sua conferência.');
        $summary = $report['summary'] ?? null;
        $filtered = array_filter(array_intersect_key($filters, array_flip(['q', 'client', 'invoice', 'method', 'status'])), static fn ($value): bool => (string) $value !== '');
        if ($embedded && !isset($data['reportError']) && is_array($summary) && (int) ($report['total'] ?? 0) === 0 && $filtered === []) {
            // Nothing open and nothing filtered: one clear state instead of empty filters, figures and tables.
            return '<section class="pagou-card pagou-all-clear">' . Html::icon('check-circle', 22) . '<div><h2>Nenhuma pendência aberta.</h2>'
                . '<p>Os registros do módulo estão conciliados. Consulta feita em ' . Html::e(F::date((string) ($report['asOf'] ?? ''))) . ', no horário de São Paulo.</p></div></section>';
        }
        $html = $intro . $navigation . $this->filters($filters, $kind, $view);
        if (isset($data['reportError'])) {
            return $html . $this->error((string) $data['reportError']);
        }
        if ($report === []) {
            return $html . $this->error('Os dados ainda não estão disponíveis.');
        }
        $summary = $report['summary'];
        $metrics = match ($kind) {
            'receipts' => [
                ['label' => 'Recebido no período', 'value' => F::money($summary['amount']), 'detail' => $summary['count'] . ' recebimento(s) conciliado(s)', 'tone' => 'success'],
                ['label' => 'Média por recebimento', 'value' => $summary['average'] === null ? 'Sem recebimentos' : F::money($summary['average']), 'detail' => 'Valor bruto dividido pelo número de recebimentos'],
                ['label' => 'Período anterior', 'value' => F::money($report['previous']['amount']), 'detail' => $this->comparison($summary['amount'], $report['previous']['amount'])],
            ],
            'open' => [
                ['label' => 'Saldo em aberto agora', 'value' => F::money($summary['amount']), 'detail' => $summary['count'] . ' fatura(s), sem duplicação'],
                ['label' => 'Saldo vencido', 'value' => F::money($summary['amount'] - $summary['buckets']['A vencer / hoje']['amount']), 'detail' => 'Após crédito e pagamentos registrados', 'tone' => 'warning'],
            ],
            'refunds' => $this->refundMetrics($summary),
            'pending' => [
                ['label' => 'Pendências abertas', 'value' => (string) $summary['count'], 'detail' => $summary['unknown'] . ' sem valor identificado'],
                ['label' => 'Valor de referência', 'value' => F::money($summary['invoiceAmount']), 'detail' => 'Maior valor envolvido por fatura, sem duplicação'],
            ],
            default => [
                ['label' => 'Tentativas no período', 'value' => (string) $summary['count'], 'detail' => 'Inclui substituições e cancelamentos'],
                ['label' => 'Faturas distintas', 'value' => (string) $summary['invoiceCount'], 'detail' => 'O valor nominal das tentativas não é receita'],
            ],
        };
        $html .= '<div class="pagou-report-metrics">' . Page::metrics($metrics) . '</div>';
        $notes = [$this->basis($kind, $filters)];
        if ($kind === 'receipts') {
            $html .= $this->methodSplit($summary['methods'], $summary['amount']);
            $previousFilter = new ReportFilter($filters);
            [$previousFrom, $previousUntil] = $previousFilter->bounds(true);
            $previousEnd = (new \DateTimeImmutable($previousUntil, new \DateTimeZone('UTC')))->modify('-1 second');
            $notes[] = 'Comparação com ' . F::date($previousFrom) . ' até ' . F::date($previousEnd->format('c')) . ', com os mesmos filtros. O período atual pode estar incompleto.';
        }
        $html .= (new ReportCharts())->render($report, $kind);
        $page = (int) ($filters['page'] ?? 1);
        $export = '<form method="post" action="' . self::url($filters, $view) . '" data-pagou-no-loading>' . $csrf
            . '<input type="hidden" name="action" value="export-report"><button type="submit" class="btn btn-default pagou-button">'
            . Html::icon('download') . 'Baixar CSV completo</button></form>';
        $count = (int) $report['total'] . ' registro(s) no filtro' . ((int) $report['pages'] > 1 ? ', página ' . $page . ' de ' . (int) $report['pages'] : '') . '.';
        $empty = 'Nenhum registro corresponde a estes filtros. O histórico do módulo de terceiros não é importado.';
        $head = ['count' => $count, 'actions' => $export, 'cards' => $embedded];
        $html .= $this->rows($report['rows'], $kind, $empty, $embedded ? 'Pendências abertas' : self::KINDS[$kind], $head);
        $html .= '<nav class="pagou-report-pagination" aria-label="Páginas do relatório">';
        if ($page > 1) {
            $html .= '<a class="btn btn-default pagou-button" href="' . self::url(array_replace($filters, ['page' => (string) ($page - 1)]), $view) . '">Anterior</a>';
        }
        if ($page < $report['pages']) {
            $html .= '<a class="btn btn-default pagou-button" href="' . self::url(array_replace($filters, ['page' => (string) ($page + 1)]), $view) . '">Próxima</a>';
        }
        $notes[] = 'Horário de São Paulo. Histórico somente do módulo oficial, sem importação do módulo de terceiros. Primeira tentativa registrada: '
            . F::date($report['firstAt']) . '. Consulta: ' . F::date($report['asOf']) . '. O CSV inclui todas as páginas do filtro; limite de 50.000 registros de origem por consulta.';
        $list = '';
        foreach ($notes as $note) {
            $list .= '<li>' . Html::e($note) . '</li>';
        }

        return $html . '</nav><details class="pagou-report-notes"><summary>' . Html::icon('info', 14) . 'Como estes números são calculados</summary><ul>' . $list . '</ul></details>';
    }

    /** @param array<string,mixed> $data */
    public function detail(array $data): string
    {
        if (isset($data['reportError'])) {
            return $this->error((string) $data['reportError']);
        }
        $detail = $data['detail'] ?? [];
        if ($detail === []) {
            return $this->error('Informe uma fatura com registros no módulo.');
        }
        $html = $this->chargeHeader($detail);
        if ($detail['pending'] !== []) {
            $html .= $this->rows($detail['pending'], 'pending', '', 'Pendências abertas desta fatura (' . count($detail['pending']) . ')');
        }
        $timeline = $this->timeline($detail);
        $html .= '<div class="pagou-charge-layout"><div class="pagou-charge-main">'
            . ($timeline !== '' ? $timeline : '<section class="pagou-card"><p class="pagou-empty-state">' . Html::icon('inbox', 18) . 'Sem eventos registrados para esta fatura.</p></section>')
            . '</div><aside class="pagou-charge-side">' . $this->chargeSummary($detail['identities'] ?? [], $detail['attempts'] ?? [], $detail['pixRefunds'] ?? []) . '</aside></div>';
        $counts = '';
        foreach ($detail['counts'] as $label => $count) {
            $counts .= '<div><dt>' . Html::e((string) $label) . '</dt><dd>' . (int) $count . '</dd></div>';
        }
        $records = (int) $detail['counts']['Operações'] + (int) $detail['counts']['Notificações'] + (int) $detail['counts']['Eventos financeiros']
            + (int) ($detail['counts']['Devoluções Pix'] ?? 0) + (int) $detail['counts']['Estornos'];
        $html .= '<details class="pagou-detail-records"><summary>' . Html::icon('list', 15) . 'Registros detalhados <span>' . $records . ' registro(s): operações, notificações, eventos financeiros, devoluções e estornos</span></summary>'
            . '<dl class="pagou-inline-stats">' . $counts . '</dl>'
            . '<p class="pagou-help">Até 100 registros mais recentes por seção. Eventos financeiros são etapas do mesmo recebimento e não devem ser somados. '
            . 'Notificações e operações exibem apenas vínculos comprovados; detalhes antigos podem estar indisponíveis pela retenção.</p>';
        $operations = [];
        foreach ($detail['operations'] as $row) {
            $operations[] = [F::date($row['updated_at']), F::label($row['operation_type']), F::label($row['status']), (string) $row['attempts'], \Pagou\Whmcs\Presentation\OperationDetails::describe($row), $row['attempt_id']];
        }
        $html .= '<p>Os tempos abaixo representam espera local e a última execução do módulo, incluindo consultas e processamento. Quando disponível, a duração HTTP é informada separadamente e inclui a rede até a API.</p>';
        $html .= Page::table('Operações (' . $detail['counts']['Operações'] . ')', ['Atualizada em', 'Operação', 'Resultado', 'Execuções', 'Detalhes e tempos', 'Tentativa vinculada'], $operations);
        $notifications = [];
        foreach ($detail['notifications'] as $row) {
            $notifications[] = [F::date($row['received_at_utc']), F::label($row['event_type']), (int) $row['signature_valid'] === 1 ? 'Válida' : 'Não validada', F::label($row['processing_status']), F::label($row['job_status'])];
        }
        $html .= Page::table('Notificações (' . $detail['counts']['Notificações'] . ')', ['Recebida em', 'Evento', 'Assinatura', 'Entrada', 'Conciliação'], $notifications);
        $ledger = [];
        foreach ($detail['ledger'] as $row) {
            $ledger[] = [F::date($row['date']), F::date($row['paymentDate']), F::money((int) $row['amount']) . ' (' . $row['currency'] . ')', F::label($row['status']), (string) $row['reference']];
        }
        $html .= Page::table('Eventos financeiros (' . $detail['counts']['Eventos financeiros'] . ')', ['Registrado em', 'Pagamento em', 'Valor do evento', 'Etapa', 'ID do pagamento'], $ledger);
        $refunds = [];
        foreach ($detail['refunds'] as $row) {
            $refunds[] = [F::date($row['requested_at_utc']), F::money((int) $row['amount_cents']), F::label($row['status']), (string) ($row['provider_refund_id'] ?? 'Não confirmado')];
        }
        $pixRefunds = [];
        foreach ($detail['pixRefunds'] ?? [] as $row) {
            $partial = (int) $row['amount_cents'] < (int) $row['receipt_cents'];
            $pixRefunds[] = [F::date($row['requested_at']), F::money((int) $row['amount_cents']) . ($partial ? ' (parcial)' : ' (total)'), self::pixRefundLabel((string) $row['status']),
                F::date($row['confirmed_at'] ?? null), (string) ($row['provider_refund_id'] ?? 'Não confirmada')];
        }
        $html .= Page::table('Devoluções Pix (' . (int) ($detail['counts']['Devoluções Pix'] ?? 0) . ')', ['Solicitada em', 'Valor', 'Situação', 'Confirmada em', 'Referência Pagou'], $pixRefunds, 'Nenhuma devolução Pix registrada no módulo para esta fatura.');
        $html .= Page::table('Estornos de cartão (' . $detail['counts']['Estornos'] . ')', ['Solicitado em', 'Valor solicitado', 'Resultado registrado', 'Referência Pagou'], $refunds, 'Nenhum estorno de cartão registrado no módulo para esta fatura.');
        $html .= '</details>';
        return $html;
    }

    /** @param array<string,mixed> $detail */
    private function chargeHeader(array $detail): string
    {
        $id = (int) $detail['invoiceId'];
        $invoice = $detail['invoice'];
        $report = '<a class="btn btn-default pagou-button" href="' . self::url(['report' => 'attempts', 'invoice' => (string) $id]) . '">' . Html::icon('activity') . 'Relatório</a>';
        $total = null;
        if (!is_array($invoice)) {
            $title = '<h2>Fatura #' . $id . '</h2>';
            $meta = '<p class="pagou-charge-meta">A fatura não está mais disponível no WHMCS. O histórico local foi preservado.</p>';
            $actions = $report;
        } else {
            $customer = trim((string) $invoice['companyname']) ?: trim($invoice['firstname'] . ' ' . $invoice['lastname']);
            $statusLabel = F::label((string) $invoice['status']);
            $gateway = match ((string) $invoice['paymentmethod']) {
                'pagou_pix' => 'Pix Pagou', 'pagou_boleto' => 'Boleto Pagou', 'pagou_creditcard' => 'Cartão Pagou',
                default => (string) $invoice['paymentmethod'],
            };
            try {
                $total = \Pagou\Whmcs\Domain\Money::fromDecimal((string) $invoice['total'])->centavos();
            } catch (\Throwable) {
                $total = null;
            }
            $client = (int) $invoice['userid'];
            $title = '<h2>Fatura #' . $id . ' ' . Html::badge($statusLabel, (string) $invoice['status'] === 'Unpaid' ? 'warning' : Page::tone($statusLabel)) . '</h2>';
            $meta = '<p class="pagou-charge-meta"><a href="clientssummary.php?userid=' . $client . '">' . Html::e($customer !== '' ? $customer : 'Cliente #' . $client) . '</a>'
                . ($gateway !== '' ? '<span>' . Html::e($gateway) . '</span>' : '')
                . '<span>Vencimento ' . Html::e(F::date((string) $invoice['duedate'])) . '</span></p>';
            $actions = '<a class="btn btn-default pagou-button" href="invoices.php?action=edit&amp;id=' . $id . '">' . Html::icon('external') . 'Abrir no WHMCS</a>'
                . '<a class="btn btn-default pagou-button" href="clientssummary.php?userid=' . $client . '">Cliente #' . $client . '</a>' . $report;
        }

        return '<section class="pagou-card pagou-charge-head"><div class="pagou-charge-head-main"><div class="pagou-charge-title">'
            . '<span class="pagou-eyebrow">HISTÓRICO DA FATURA</span>' . $title . $meta . '</div>'
            . '<div class="pagou-actions">' . $actions . '</div></div>' . $this->chargeFigures($detail, $total) . '</section>';
    }

    /**
     * Amounts that matter day to day. Only applied receipts count as received,
     * and only refunds confirmed by Pagou as refunded.
     * @param array<string,mixed> $detail
     */
    private function chargeFigures(array $detail, ?int $total): string
    {
        $received = 0;
        $last = '';
        foreach ($detail['ledger'] ?? [] as $row) {
            if ((string) $row['status'] === 'applied') {
                $received += (int) $row['amount'];
                $last = max($last, (string) $row['paymentDate']);
            }
        }
        $refunded = 0;
        $pending = false;
        foreach ($detail['pixRefunds'] ?? [] as $row) {
            if (in_array((string) $row['status'], ['applied', 'confirmed', 'applying'], true)) {
                $refunded += (int) $row['amount_cents'];
                $pending = $pending || (string) $row['status'] !== 'applied';
            }
        }
        foreach ($detail['refunds'] ?? [] as $row) {
            if (in_array((string) $row['status'], ['reversed', 'refunded'], true)) {
                $refunded += (int) $row['amount_cents'];
            }
        }
        $tiles = [
            ['Valor da fatura', $total === null ? 'Indisponível' : F::money($total), 'Total atual no WHMCS', ''],
            ['Recebido', F::money($received), $last !== '' ? 'Último em ' . F::date($last) : 'Nenhum recebimento confirmado', $received > 0 ? 'success' : ''],
            ['Devolvido', F::money($refunded), $refunded === 0 ? 'Nenhuma devolução'
                : ($refunded >= $received ? 'Devolução total' : 'Devolução parcial') . ($pending ? ', registro pendente no WHMCS' : ''), $refunded > 0 ? 'warning' : ''],
        ];
        $html = '<dl class="pagou-charge-figures">';
        foreach ($tiles as [$label, $value, $detailText, $tone]) {
            $html .= '<div' . ($tone !== '' ? ' class="is-' . $tone . '"' : '') . '><dt>' . Html::e($label) . '</dt><dd>' . Html::e($value) . '</dd><small>' . Html::e($detailText) . '</small></div>';
        }

        return $html . '</dl>';
    }

    /**
     * The charge that counts, once: who was charged, who paid and every identifier.
     * Earlier attempts follow, collapsed, for reference only.
     * @param list<array<string,mixed>> $identities
     * @param list<array<string,mixed>> $attempts
     * @param list<array<string,mixed>> $refunds
     */
    private function chargeSummary(array $identities, array $attempts, array $refunds): string
    {
        $identity = $identities[0] ?? null;
        $current = $attempts[0] ?? null;
        foreach ($attempts as $attempt) {
            if ($identity !== null && (string) ($attempt['remote_id'] ?? '') !== '' && (string) $attempt['remote_id'] === (string) ($identity['pagouId'] ?? '')) {
                $current = $attempt;
                break;
            }
        }
        if ($current === null && $identity === null) {
            return '';
        }
        $html = '<section class="pagou-card pagou-charge-panel pagou-charge-current"' . ($current !== null ? ' id="attempt-' . Html::e((string) $current['id']) . '"' : '') . '>';
        if ($current !== null) {
            $html .= '<div class="pagou-charge-current-head"><h3>' . self::methodIcon((string) $current['method'], 16) . Html::e(F::label((string) $current['method'])) . '</h3>'
                . Page::status(F::label((string) $current['status'])) . '</div>'
                . '<p class="pagou-charge-current-meta">' . Html::e(F::money((int) $current['amount_cents'])) . '<span>Criada em ' . Html::e(F::date((string) $current['created_at'])) . '</span></p>';
        } else {
            $html .= '<div class="pagou-charge-current-head"><h3>Cobrança</h3></div>';
        }
        if ($identity !== null) {
            $payer = empty($identity['paid'])
                ? '<span class="pagou-party-empty">Ainda sem recebimento confirmado.</span>'
                : (empty($identity['payerName']) && empty($identity['payerDocument'])
                    ? '<span class="pagou-party-empty">Não informado no recebimento confirmado.</span>'
                    : PaymentParties::party($identity, true));
            $html .= '<div class="pagou-charge-parties">'
                . '<div><span class="pagou-charge-label">Cobrança emitida para</span><p>' . PaymentParties::party($identity, false) . '</p></div>'
                . '<div><span class="pagou-charge-label">Pagamento realizado por</span><p>' . $payer . '</p></div></div>';
        }
        $remote = (string) ($current['remote_id'] ?? ($identity['pagouId'] ?? ''));
        $html .= '<div class="pagou-charge-ids">'
            . self::idRow('ID Pagou', $remote, 'pagou-charge-current-pagou')
            . self::idRow('ID Pix do boleto', (string) ($identity['pixId'] ?? ''), 'pagou-charge-current-pix')
            . self::idRow('ID da transação', (string) ($identity['transactionId'] ?? ''), 'pagou-charge-current-transaction')
            . self::idRow('E2E Pix', (string) ($identity['e2e'] ?? ''), 'pagou-charge-current-e2e');
        foreach ($refunds as $index => $refund) {
            $html .= self::idRow('ID do reembolso', (string) ($refund['provider_refund_id'] ?? ''), 'pagou-charge-refund-' . $index);
        }
        $html .= self::idRow('ID local', (string) ($current['id'] ?? ''), 'pagou-charge-current-local') . '</div></section>';

        $previous = '';
        $open = '';
        foreach ($attempts as $attempt) {
            if ($current !== null && $attempt['id'] === $current['id']) {
                continue;
            }
            $id = (string) $attempt['id'];
            // Charges of another method stay payable until the invoice is paid.
            $stillOpen = in_array((string) $attempt['method'], ['pix', 'boleto'], true)
                && in_array((string) $attempt['status'], ['queued', 'pending', 'ready', 'active', 'awaiting_registration', 'uncertain'], true);
            $item = '<li id="attempt-' . Html::e($id) . '"><details><summary>'
                . '<span class="pagou-charge-previous-method">' . self::methodIcon((string) $attempt['method'], 14) . Html::e(F::label((string) $attempt['method'])) . '</span>'
                . '<span class="pagou-charge-previous-amount">' . Html::e(F::money((int) $attempt['amount_cents'])) . Html::icon('chevron-down', 13) . '</span>'
                . '<span class="pagou-charge-previous-state">' . Html::e(F::label((string) $attempt['status'])) . '</span>'
                . '<small>' . Html::e(F::date((string) $attempt['created_at'])) . '</small></summary>'
                . self::idRow('ID Pagou', (string) ($attempt['remote_id'] ?? ''), 'pagou-charge-attempt-' . $id . '-remote')
                . self::idRow('ID local', $id, 'pagou-charge-attempt-' . $id . '-local')
                . '</details></li>';
            if ($stillOpen) {
                $open .= $item;
            } else {
                $previous .= $item;
            }
        }
        if ($open !== '') {
            $html .= '<section class="pagou-card pagou-charge-panel pagou-charge-previous"><h3>Também em aberto <span>' . substr_count($open, '<li ') . '</span></h3>'
                . '<p class="pagou-charge-open-note">Continua valendo para pagamento até a fatura ser paga.</p><ul>' . $open . '</ul></section>';
        }
        if ($previous !== '') {
            $count = substr_count($previous, '<li ');
            $html .= '<section class="pagou-card pagou-charge-panel pagou-charge-previous"><h3>Tentativas anteriores <span>' . $count . '</span></h3>'
                . '<ul>' . $previous . '</ul></section>';
        }

        return $html;
    }

    private static function methodIcon(string $method, int $size): string
    {
        return match ($method) {
            'pix' => Html::icon('qr-code', $size), 'boleto' => Html::icon('barcode', $size), 'card' => Html::icon('card', $size), default => '',
        };
    }

    private static function idRow(string $label, string $value, string $id): string
    {
        if ($value === '') {
            return '';
        }

        return '<div class="pagou-id-row"><span>' . Html::e($label) . '</span><code id="' . Html::e($id) . '" title="' . Html::e($value) . '">' . Html::e($value) . '</code>'
            . '<button type="button" class="pagou-id-copy" data-pagou-copy="' . Html::e($id) . '" aria-label="Copiar ' . Html::e($label) . '" title="Copiar">'
            . Html::icon('copy', 14) . Html::icon('check', 14) . '</button></div>';
    }

    /**
     * Every module event of the invoice in one chronological list. It only
     * restates the records below; nothing here is a new source of truth.
     * @param array<string,mixed> $detail
     */
    private function timeline(array $detail): string
    {
        $events = [];
        $add = static function (?string $at, string $title, string $text, string $tone, string $icon) use (&$events): void {
            if ($at === null || $at === '') {
                return;
            }
            try {
                $time = (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->getTimestamp();
            } catch (\Throwable) {
                return;
            }
            $events[] = ['time' => $time, 'at' => $at, 'title' => $title, 'text' => $text, 'tone' => $tone, 'icon' => $icon];
        };
        foreach ($detail['attempts'] ?? [] as $row) {
            $status = F::label((string) $row['status']);
            $title = match ((string) $row['method']) {
                'pix' => 'Cobrança Pix criada', 'boleto' => 'Cobrança por boleto criada', 'card' => 'Cobrança no cartão criada', default => 'Cobrança criada',
            };
            $add((string) $row['created_at'], $title, 'Valor ' . F::money((int) $row['amount_cents']) . '. Situação atual: ' . $status . '.', 'neutral', 'receipt');
        }
        foreach ($detail['operations'] ?? [] as $row) {
            $status = F::label((string) $row['status']);
            $runs = (int) $row['attempts'];
            $add((string) $row['updated_at'], F::label((string) $row['operation_type']), $status . ($runs > 1 ? ', após ' . $runs . ' execuções' : '') . '.', Page::tone($status), 'activity');
        }
        foreach ($detail['notifications'] ?? [] as $row) {
            $type = (string) $row['event_type'] === 'unknown' ? 'tipo não reconhecido' : F::label((string) $row['event_type']);
            $valid = (int) $row['signature_valid'] === 1;
            $add((string) $row['received_at_utc'], 'Notificação recebida: ' . $type, ($valid ? 'Assinatura válida' : 'Assinatura não validada') . '. Conciliação: ' . F::label((string) $row['job_status']) . '.', $valid ? 'info' : 'warning', 'bell');
        }
        foreach ($detail['ledger'] ?? [] as $row) {
            $status = (string) $row['status'];
            [$title, $tone] = match ($status) {
                'applied' => ['Baixa registrada no WHMCS', 'success'],
                'received' => ['Pagamento recebido na Pagou', 'info'],
                'quarantined' => ['Recebimento requer conferência', 'warning'],
                default => ['Evento financeiro: ' . F::label($status), 'neutral'],
            };
            $add((string) $row['date'], $title, F::money((int) $row['amount']) . ((string) $row['reference'] !== '' ? '. ID do pagamento ' . $row['reference'] : '') . '.', $tone, 'wallet');
        }
        foreach ($detail['pixRefunds'] ?? [] as $row) {
            $amount = F::money((int) $row['amount_cents']) . ((int) $row['amount_cents'] < (int) $row['receipt_cents'] ? ', parcial' : ', total');
            $add((string) $row['requested_at'], 'Devolução Pix solicitada', $amount . '.', 'info', 'refresh');
            $add($row['confirmed_at'] ?? null, 'Devolução Pix confirmada pela Pagou', $amount . '.', 'success', 'refresh');
            $status = (string) $row['status'];
            if (in_array($status, ['applied', 'review', 'rejected', 'uncertain'], true)) {
                $add((string) $row['updated_at'], match ($status) {
                    'applied' => 'Devolução registrada no WHMCS', 'review' => 'Devolução em conferência',
                    'rejected' => 'Devolução não aceita pela Pagou', default => 'Resultado da devolução incerto',
                }, $status === 'applied' ? $amount . '.' : 'Não solicite outra devolução antes de conferir.', $status === 'applied' ? 'success' : ($status === 'rejected' ? 'danger' : 'warning'), 'refresh');
            }
        }
        foreach ($detail['refunds'] ?? [] as $row) {
            $add((string) $row['requested_at_utc'], 'Estorno de cartão solicitado', F::money((int) $row['amount_cents']) . '. Resultado: ' . F::label((string) $row['status']) . '.', 'info', 'card');
            $add($row['completed_at_utc'] ?? null, 'Estorno de cartão concluído', F::money((int) $row['amount_cents']) . '.', 'success', 'card');
        }
        foreach ($detail['pending'] ?? [] as $row) {
            $add((string) $row['date'], 'Pendência aberta: ' . F::label((string) $row['type']), (string) $row['guidance'], 'warning', 'alert');
        }
        if ($events === []) {
            return '';
        }
        usort($events, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);
        $total = count($events);
        $items = '';
        $day = '';
        foreach (array_slice($events, 0, 60) as $event) {
            // Events are grouped by São Paulo day; each item keeps only its time.
            [$date, $time] = array_pad(explode(' ', F::date($event['at']), 2), 2, '');
            if ($date !== $day) {
                $day = $date;
                $items .= '<li class="pagou-timeline-day"><span>' . Html::e($date) . '</span></li>';
            }
            $items .= '<li class="is-' . Html::e($event['tone']) . '"><span class="pagou-timeline-dot" aria-hidden="true">' . Html::icon($event['icon'], 14) . '</span>'
                . '<div><time datetime="' . Html::e(gmdate('c', $event['time'])) . '">' . Html::e($time !== '' ? $time : $date) . '</time>'
                . '<strong>' . Html::e($event['title']) . '</strong><small>' . Html::e($event['text']) . '</small></div></li>';
        }

        return '<section class="pagou-card pagou-timeline-card"><div class="pagou-card-title"><div><h2>Linha do tempo</h2>'
            . '<p>Eventos desta fatura no módulo, do mais recente ao mais antigo, no horário de São Paulo.'
            . ($total > 60 ? ' Mostrando os 60 mais recentes de ' . $total . '.' : '') . '</p></div></div>'
            . '<ol class="pagou-timeline">' . $items . '</ol></section>';
    }

    private static function pixRefundLabel(string $status): string
    {
        return match ($status) {
            'applied' => 'Registrada no WHMCS', 'confirmed', 'applying' => 'Confirmada, registro pendente no WHMCS',
            'review' => 'Em conferência', 'rejected' => 'Não aceita', 'uncertain' => 'Resultado incerto', default => 'Em andamento',
        };
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array{count?:string,actions?:string,cards?:bool} $head
     */
    private function rows(array $rows, string $kind, string $empty = 'Nenhum registro corresponde a estes filtros. O histórico do módulo de terceiros não é importado.', string $title = '', array $head = []): string
    {
        $heads = ['Data', 'Fatura / cliente', 'Meio', match ($kind) {
            'open' => 'Saldo atual', 'attempts' => 'Valor nominal', 'refunds' => 'Valor devolvido', default => 'Valor',
        }, 'Situação'];
        if ($kind === 'refunds') {
            $heads[] = 'Alcance';
            $heads[] = 'Referência Pagou';
        }
        if ($kind === 'receipts') {
            $heads[] = 'Cobrança emitida para';
            $heads[] = 'Pagamento realizado por';
        }
        if ($kind === 'pending') {
            $heads[] = 'O que conferir';
        }
        if ($rows === []) {
            return Page::emptyCard($title, $empty);
        }
        $titleBlock = $title === '' ? '' : '<div class="pagou-card-title"><div><h2>' . Html::e($title) . '</h2>'
            . (isset($head['count']) ? '<p>' . Html::e($head['count']) . '</p>' : '') . '</div>' . ($head['actions'] ?? '') . '</div>';
        if ($kind === 'pending' && !empty($head['cards'])) {
            return '<section class="pagou-card pagou-table-card">' . $titleBlock . $this->findingCards($rows) . '</section>';
        }
        $html = '<section class="pagou-card pagou-table-card">' . $titleBlock
            . '<div class="pagou-table-wrap"><table class="pagou-table pagou-report-table"><thead><tr>';
        foreach ($heads as $head) {
            $html .= '<th scope="col">' . Html::e($head) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $id = (int) $row['invoice'];
            $link = $id > 0 ? '<a href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=' . $id . '">Fatura #' . $id . '</a>' : 'Sem fatura identificada';
            $customer = $row['customer'] !== '' ? $row['customer'] : ($row['client'] > 0 ? 'Cliente #' . $row['client'] : 'Cliente não identificado');
            $status = Page::status(F::label($row['status']));
            if ($kind === 'pending') {
                $status = Html::e(F::label($row['type'])) . ' ' . Page::status(F::label($row['severity']));
            }
            $late = $kind === 'open' && isset($row['days']) && (int) $row['days'] > 0;
            $html .= '<tr' . ($id > 0 ? ' data-pagou-row-href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=' . $id . '"' : '') . '><td>' . Html::e(F::date($row['date'])) . (isset($row['days']) ? '<small' . ($late ? ' class="pagou-text-warning"' : '') . '>' . (int) $row['days'] . ' dia(s)' . ($kind === 'open' ? ' de atraso' : ' em aberto') . '</small>' : '')
                . '</td><td>' . $link . '<small>' . Html::e($customer) . '</small></td><td>' . Html::e(F::label($row['method']))
                . '</td><td class="pagou-amount">' . Html::e(F::money($row['amount'])) . '</td><td>' . $status . '</td>';
            if ($kind === 'receipts') {
                $html .= '<td>' . PaymentParties::party($row, false) . '</td><td>' . PaymentParties::party($row, true) . '</td>';
            }
            if ($kind === 'pending') {
                $html .= '<td>' . Html::e($row['guidance']) . '</td>';
            }
            if ($kind === 'refunds') {
                $html .= '<td>' . (($row['partial'] ?? false) ? 'Parcial<small>de ' . Html::e(F::money((int) $row['receipt'])) . ' recebidos</small>' : 'Total')
                    . '</td><td>' . ((string) $row['reference'] !== '' ? Page::shortId((string) $row['reference']) : 'Ainda não confirmada') . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div></section>';
    }

    /** @param array<string,string> $values */
    public static function url(array $values, string $view = 'reports'): string
    {
        $view = in_array($view, ['reports', 'findings'], true) ? $view : 'reports';
        return Html::e('addonmodules.php?' . http_build_query(['module' => 'pagou_payments', 'view' => $view] + $values));
    }

    /** @param array<string,string> $values */
    private function filters(array $values, string $kind, string $view = 'reports'): string
    {
        $dated = in_array($kind, ['receipts', 'attempts', 'refunds'], true);
        $html = '<form method="get" class="pagou-card pagou-report-filters"><input type="hidden" name="module" value="pagou_payments">'
            . '<input type="hidden" name="view" value="' . Html::e($view === 'findings' ? 'findings' : 'reports') . '"><input type="hidden" name="report" value="' . Html::e($kind) . '">';
        if ($dated) {
            $html .= $this->periods($values, $kind, $view);
            foreach (['from' => 'De', 'to' => 'Até'] as $key => $label) {
                $html .= '<label>' . $label . '<input type="date" name="' . $key . '" value="' . Html::e($values[$key] ?? '') . '" required></label>';
            }
        }
        $html .= '<label>Nome do cliente<input type="search" name="q" maxlength="64" value="' . Html::e($values['q'] ?? '') . '" placeholder="Nome ou empresa"></label>';
        // Dated reports keep the less used criteria behind "Mais filtros".
        $more = $dated ? ' class="is-secondary"' : '';
        $html .= $this->select('method', 'Meio', ['' => 'Todos os meios', 'pix' => 'Pix', 'boleto' => 'Boleto', 'card' => 'Cartão', 'unknown' => 'Não identificado'], $values['method'] ?? '');
        $html .= $this->select('status', 'Situação', ReportFilter::statuses($kind), $values['status'] ?? '');
        if ($kind === 'receipts') {
            $html .= '<label' . $more . '>CPF ou CNPJ<input type="search" name="document" maxlength="18" autocomplete="off" value="' . Html::e($values['document'] ?? '') . '" placeholder="Documento completo"></label>';
            $html .= $this->select('party', 'Pesquisar documento de', ['either' => 'Emissão ou pagador', 'issued' => 'Cobrança emitida para', 'payer' => 'Quem pagou o Pix'], $values['party'] ?? 'either', $dated);
        }
        foreach (['client' => 'ID do cliente', 'invoice' => 'Número da fatura'] as $key => $label) {
            $html .= '<label' . $more . '>' . $label . '<input type="number" min="1" max="9999999999" name="' . $key . '" value="' . Html::e($values[$key] ?? '') . '" placeholder="Todos"></label>';
        }
        return $html . '<div class="pagou-report-filter-actions"><button class="btn btn-primary pagou-button" type="submit">Filtrar</button><a class="btn btn-default pagou-button" href="' . self::url($view === 'findings' ? [] : ['report' => $kind], $view) . '">Limpar</a></div></form>';
    }

    /**
     * Preset ranges applied with one click, in São Paulo calendar days.
     * @param array<string,string> $values
     */
    private function periods(array $values, string $kind, string $view): string
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('America/Sao_Paulo'));
        $ranges = [
            'Hoje' => [$today, $today],
            '7 dias' => [$today->modify('-6 days'), $today],
            '30 dias' => [$today->modify('-29 days'), $today],
            'Este mês' => [$today->modify('first day of this month'), $today],
            'Mês anterior' => [$today->modify('first day of previous month'), $today->modify('last day of previous month')],
            '90 dias' => [$today->modify('-89 days'), $today],
        ];
        $kept = array_intersect_key($values, array_flip(['q', 'client', 'invoice', 'method', 'status', 'document', 'party']));
        $html = '<nav class="pagou-chips pagou-report-periods" aria-label="Períodos rápidos">' . Html::icon('calendar', 14);
        foreach ($ranges as $label => [$from, $to]) {
            $range = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
            $active = ($values['from'] ?? '') === $range['from'] && ($values['to'] ?? '') === $range['to'];
            $html .= '<a class="pagou-chip' . ($active ? ' is-active' : '') . '" href="'
                . self::url(['report' => $kind] + $range + array_filter($kept, static fn (string $value): bool => $value !== ''), $view) . '"'
                . ($active ? ' aria-current="true"' : '') . '>' . Html::e($label) . '</a>';
        }

        return $html . '</nav>';
    }

    /** @param array<string,string> $options */
    private function select(string $name, string $label, array $options, string $selected, bool $secondary = false): string
    {
        $html = '<label' . ($secondary ? ' class="is-secondary"' : '') . '>' . Html::e($label) . '<select name="' . Html::e($name) . '">';
        foreach ($options as $value => $text) {
            $html .= '<option value="' . Html::e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . Html::e($text) . '</option>';
        }
        return $html . '</select></label>';
    }

    /**
     * Share of each method in the received total, as one bar under the figures.
     * @param array<string,mixed> $methods
     */
    private function methodSplit(array $methods, int $total): string
    {
        $bar = '';
        $legend = '';
        foreach ($methods as $key => $method) {
            if ($key === 'unknown' && $method['count'] === 0) {
                continue;
            }
            $percent = $total > 0 ? round($method['amount'] / $total * 100, 1) : 0;
            if ($percent > 0) {
                $bar .= '<span class="is-' . Html::e((string) $key) . '" style="width:' . $percent . '%"></span>';
            }
            $legend .= '<li class="is-' . Html::e((string) $key) . '"><span class="pagou-split-dot" aria-hidden="true"></span><strong>' . Html::e(F::label((string) $key)) . '</strong>'
                . '<span>' . Html::e(F::money($method['amount'])) . '</span><small>' . $method['count'] . ' recebimento(s), ' . number_format($percent, 1, ',', '.') . '%</small></li>';
        }

        return '<section class="pagou-card pagou-method-split" aria-label="Recebimentos por meio"><div class="pagou-split-bar" aria-hidden="true">' . $bar . '</div>'
            . '<ul class="pagou-split-legend">' . $legend . '</ul></section>';
    }

    /**
     * Open findings as cards: what is wrong, what to check and where to act.
     * @param list<array<string,mixed>> $rows
     */
    private function findingCards(array $rows): string
    {
        $html = '<ul class="pagou-finding-list">';
        foreach ($rows as $row) {
            $id = (int) $row['invoice'];
            $severity = F::label((string) $row['severity']);
            $tone = Page::tone($severity);
            $customer = $row['customer'] !== '' ? $row['customer'] : ($row['client'] > 0 ? 'Cliente #' . $row['client'] : 'Cliente não identificado');
            $days = isset($row['days']) ? (int) $row['days'] : null;
            $actions = $id > 0
                ? '<a class="btn btn-default pagou-button" href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=' . $id . '">Ver histórico</a>'
                    . '<a class="btn btn-default pagou-button" href="invoices.php?action=edit&amp;id=' . $id . '">' . Html::icon('external') . 'Abrir fatura</a>'
                : '';
            $html .= '<li class="pagou-finding is-' . Html::e($tone) . '"><div class="pagou-finding-main"><div class="pagou-finding-head"><strong>' . Html::e(F::label((string) $row['type'])) . '</strong>'
                . Page::status($severity) . '</div><p class="pagou-finding-guidance">' . Html::e((string) $row['guidance']) . '</p>'
                . '<p class="pagou-finding-meta"><span>' . ($id > 0 ? 'Fatura #' . $id : 'Sem fatura identificada') . ', ' . Html::e($customer) . '</span>'
                . '<span>' . Html::e(F::label((string) $row['method'])) . '</span><span>' . Html::e(F::money($row['amount'])) . '</span>'
                . '<span>Aberta em ' . Html::e(F::date($row['date'])) . ($days !== null && $days > 0 ? ', há ' . $days . ' dia(s)' : '') . '</span></p></div>'
                . ($actions !== '' ? '<div class="pagou-finding-actions">' . $actions . '</div>' : '') . '</li>';
        }

        return $html . '</ul>';
    }

    /**
     * @param array<string,mixed> $summary
     * @return list<array<string,string>>
     */
    private function refundMetrics(array $summary): array
    {
        $amounts = is_array($summary['statusAmounts'] ?? null) ? $summary['statusAmounts'] : [];
        $counts = is_array($summary['statuses'] ?? null) ? $summary['statuses'] : [];
        $done = (int) ($amounts['refund_done'] ?? 0) + (int) ($amounts['refund_confirmed'] ?? 0);
        $waiting = (int) ($counts['refund_confirmed'] ?? 0);
        $review = (int) ($counts['refund_review'] ?? 0);

        return [
            ['label' => 'Devolvido no período', 'value' => F::money($done),
                'detail' => ((int) ($counts['refund_done'] ?? 0) + $waiting) . ' devolução(ões) confirmada(s) pela Pagou'
                    . ($waiting > 0 ? ', ' . $waiting . ' com registro pendente no WHMCS' : '')],
            ['label' => 'Em andamento', 'value' => F::money((int) ($amounts['refund_progress'] ?? 0)),
                'detail' => (int) ($counts['refund_progress'] ?? 0) . ' aguardando a confirmação da Pagou'],
            ['label' => 'Em conferência', 'value' => (string) $review, 'tone' => $review > 0 ? 'danger' : '',
                'detail' => $review > 0 ? 'Confira antes de qualquer nova solicitação' : 'Nenhuma devolução aguardando conferência'],
        ];
    }

    private function comparison(int $current, int $previous): string
    {
        if ($previous <= 0) {
            return 'Sem base para comparação percentual';
        }
        $percent = ($current - $previous) / $previous * 100;
        return ($percent > 0 ? '+' : '') . number_format($percent, 1, ',', '.') . '% em relação ao intervalo anterior';
    }

    /** @param array<string,string> $filters */
    private function basis(string $kind, array $filters): string
    {
        $text = match ($kind) {
            'open' => 'Fotografia do saldo atual, sem filtro de período. Apenas faturas não pagas dos gateways oficiais, com registro no módulo. Desconta crédito e pagamentos líquidos registrados no WHMCS. Vencimento hoje não é atraso.',
            'pending' => 'Pendências atualmente abertas, sem filtro de período. O valor de referência usa o maior valor conhecido por fatura; não representa prejuízo nem saldo devido. Pendências sem valor identificado aparecem separadamente.',
            'refunds' => 'Devoluções de Pix e estornos de cartão solicitados por este módulo, pela data de confirmação da Pagou ou, enquanto não confirmados, pela data da solicitação. Valores em BRL. A Pagou aceita uma devolução por Pix, parcial ou total.',
            'attempts' => 'Tentativas criadas no período, com sua situação atual. Uma fatura pode aparecer várias vezes. Estes valores não representam recebimentos ou saldo a receber.',
            default => 'Pagamentos de ' . F::date($filters['from'] ?? '') . ' a ' . F::date($filters['to'] ?? '') . ', conciliados no WHMCS, uma vez por identidade financeira. Valores brutos BRL, sem deduzir taxas ou estornos. Pix embutido no boleto é contabilizado como boleto.',
        };
        return $text;
    }

    private function error(string $message): string
    {
        return '<div class="pagou-notice pagou-notice--warning" role="status"><strong>Relatório indisponível.</strong> ' . Html::e($message) . '</div>';
    }
}
