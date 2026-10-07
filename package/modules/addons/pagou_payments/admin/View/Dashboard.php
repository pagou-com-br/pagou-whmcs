<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\View;

use Pagou\Payments\Admin\Support\Html;
use Pagou\Payments\Admin\Support\ReportFormat as F;

/**
 * Daily overview. Module records (ledger, invoices, findings) and the
 * account-wide API card stay in separate blocks; an unavailable source is
 * shown as unavailable, never as zero.
 */
final class Dashboard
{
    /** @param array<string, mixed> $data */
    public function render(array $data, string $csrf): string
    {
        $onboarding = is_array($data['onboarding'] ?? null) ? $data['onboarding'] : [];
        $complete = ($onboarding['state'] ?? '') === 'ready';
        $merchant = !isset($data['reportError']) && is_array($data['merchant'] ?? null) ? $data['merchant'] : null;
        $account = is_array($data['account'] ?? null) ? (new AccountPanel())->render($data['account']) : '';

        return '<div class="pagou-dashboard">'
            . ($complete ? '' : $this->setupBanner($onboarding))
            . $this->header($data, $csrf)
            . $this->health($data)
            . (isset($data['reportError']) ? '<div class="pagou-notice pagou-notice--warning" role="status"><strong>Indicadores financeiros indisponíveis.</strong> '
                . Html::e((string) $data['reportError']) . '</div>' : '')
            . $this->kpis($data, $merchant)
            . '<div class="pagou-dashboard-grid"><div class="pagou-dashboard-main">' . $this->trend($merchant) . '</div>'
            . '<div class="pagou-dashboard-side">' . $account . $this->attention($data, $merchant) . '</div></div>'
            . '<div class="pagou-dashboard-grid pagou-dashboard-grid--even">' . $this->recent($merchant) . $this->openInvoices($merchant) . '</div>'
            . '<p class="pagou-report-note">Os indicadores e as listas do módulo usam somente os registros do módulo oficial Pagou, no horário de São Paulo, '
            . 'com valores brutos conciliados no WHMCS e sem descontar estornos. O histórico do módulo de terceiros não está incluído.</p>'
            . ($complete
                ? '<details class="pagou-card pagou-setup-review" data-pagou-preference="onboarding-complete"><summary>Roteiro de configuração ('
                    . (int) ($onboarding['ready'] ?? 0) . '/' . (int) ($onboarding['total'] ?? 0) . ')</summary>' . $this->guide($onboarding) . '</details>'
                : '')
            . '</div>';
    }

    /** @param array<string, mixed> $onboarding */
    private function setupBanner(array $onboarding): string
    {
        $ready = (int) ($onboarding['ready'] ?? 0);
        $total = max(1, (int) ($onboarding['total'] ?? 6));
        $next = is_array($onboarding['next'] ?? null) ? $onboarding['next'] : [];
        $percent = round(min($ready, $total) / $total * 100, 1);
        $title = (string) ($next['title'] ?? '');

        return '<section class="pagou-card pagou-intro pagou-setup-banner"><div class="pagou-setup-banner-main">'
            . '<div class="pagou-readiness-score"><strong>' . $ready . '<small>/' . $total . '</small></strong><span>etapas prontas</span></div>'
            . '<div class="pagou-setup-copy"><span class="pagou-eyebrow">CONFIGURAÇÃO INICIAL</span>'
            . '<h2>Conclua a configuração do módulo</h2><p>'
            . ($title !== '' ? '<strong>Próximo passo: ' . Html::e($title) . '.</strong> ' : '')
            . Html::e((string) ($next['detail'] ?? 'Siga o roteiro abaixo. Cada etapa é confirmada automaticamente.')) . '</p>'
            . '<div class="pagou-progress" role="progressbar" aria-label="Progresso da configuração inicial" aria-valuemin="0" aria-valuemax="'
            . $total . '" aria-valuenow="' . $ready . '"><span style="width:' . $percent . '%"></span></div></div>'
            . '<a class="btn btn-primary pagou-button" href="' . Html::e((string) ($next['url'] ?? 'addonmodules.php?module=pagou_payments&view=settings')) . '">'
            . Html::e((string) ($next['action'] ?? 'Continuar configuração')) . '</a></div>'
            . '<details class="pagou-setup-details" data-pagou-preference="onboarding"><summary>Ver o roteiro completo (' . $ready . '/' . $total . ')</summary>'
            . $this->guide($onboarding) . '</details></section>';
    }

    /** @param array<string, mixed> $data */
    private function header(array $data, string $csrf): string
    {
        $generated = (string) ($data['generatedAt'] ?? '');

        return '<section class="pagou-card pagou-intro pagou-dashboard-head"><div><span class="pagou-eyebrow">OPERAÇÃO DIÁRIA</span>'
            . '<h2>Resumo financeiro e operacional</h2><p>'
            . ($generated !== '' ? 'Atualizado em ' . Html::e($generated) . ', horário de São Paulo.' : 'Horário de São Paulo.')
            . '</p></div><nav class="pagou-action-bar" aria-label="Ações rápidas">'
            . Page::action('Executar conciliação', 'run-reconciliation', 'dashboard', $csrf)
            . '<a class="btn btn-default pagou-button" href="addonmodules.php?module=pagou_payments&amp;view=reports">' . Html::icon('activity') . 'Relatórios</a>'
            . '<a class="btn btn-default pagou-button" href="addonmodules.php?module=pagou_payments&amp;view=operations">' . Html::icon('list') . 'Ver histórico operacional</a>'
            . '<a class="btn btn-default pagou-button" href="https://docs.pagou.com.br" target="_blank" rel="noopener noreferrer">' . Html::icon('external') . 'Documentação</a>'
            . '</nav></section>';
    }

    /** @param array<string, mixed> $data */
    private function health(array $data): string
    {
        $cards = is_array($data['readinessCards'] ?? null) ? $data['readinessCards'] : [];
        $items = '';
        foreach ($cards as $card) {
            if (!is_array($card)) {
                continue;
            }
            $items .= $this->healthItem(
                ($card['state'] ?? '') === 'ready' ? 'ready' : 'attention',
                (string) ($card['label'] ?? 'Indicador'),
                (string) ($card['value'] ?? 'Indisponível'),
                (string) ($card['detail'] ?? ''),
                (string) ($card['url'] ?? '#'),
            );
        }
        if (isset($data['lastReconciliationDisplay'])) {
            $last = (string) $data['lastReconciliationDisplay'];
            $items .= $this->healthItem(
                $last === 'Nunca' ? 'neutral' : 'ready',
                'Última conciliação',
                $last,
                'Consulta concluída mais recente, no horário de São Paulo.',
                'addonmodules.php?module=pagou_payments&view=reconciliation',
            );
        }

        return $items === '' ? '' : '<nav class="pagou-health" aria-label="Saúde da operação">' . $items . '</nav>';
    }

    private function healthItem(string $state, string $label, string $value, string $detail, string $url): string
    {
        return '<a class="pagou-health-item is-' . Html::e($state) . '" href="' . Html::e($url) . '" title="' . Html::e($detail) . '">'
            . '<span class="pagou-health-dot" aria-hidden="true"></span><span class="pagou-health-label">' . Html::e($label)
            . '</span><strong>' . Html::e($value) . '</strong><span class="pagou-sr-only">. ' . Html::e($detail) . '</span></a>';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $merchant
     */
    private function kpis(array $data, ?array $merchant): string
    {
        $month = $merchant['receipts']['summary'] ?? null;
        $open = $merchant['open'] ?? null;
        $findings = (int) ($data['findings'] ?? 0);
        $tiles = [[
            'label' => 'Recebido pelo módulo hoje', 'icon' => 'wallet', 'tone' => 'success',
            'value' => (string) ($data['paymentsTodayValue'] ?? 'R$ 0,00'),
            'detail' => Html::e((string) ($data['paymentsToday'] ?? '0')) . ' recebimento(s) conciliado(s) no WHMCS',
        ]];
        if (is_array($month)) {
            $refunds = is_array($merchant['refunds']['statusAmounts'] ?? null) ? $merchant['refunds']['statusAmounts'] : [];
            // Refunds confirmed by Pagou this month, recorded in WHMCS or still being recorded.
            $refunded = (int) ($refunds['refund_done'] ?? 0) + (int) ($refunds['refund_confirmed'] ?? 0);
            $tiles[] = [
                'label' => 'Recebido pelo módulo no mês', 'icon' => 'calendar', 'tone' => 'success',
                'value' => F::money((int) $month['amount']),
                'detail' => $this->delta((int) $month['amount'], is_array($merchant['lastMonth'] ?? null) ? $merchant['lastMonth'] : [])
                    . ($refunded > 0 ? '<a class="pagou-kpi-net" href="addonmodules.php?module=pagou_payments&amp;view=reports&amp;report=refunds">Líquido '
                        . Html::e(F::money((int) $month['amount'] - $refunded)) . ' após ' . Html::e(F::money($refunded)) . ' em devoluções</a>' : ''),
            ];
        } else {
            $tiles[] = ['label' => 'Recebido pelo módulo no mês', 'icon' => 'calendar', 'value' => 'Indisponível', 'detail' => 'Consulta dos registros indisponível'];
        }
        if (is_array($open)) {
            [$overdueCount, $overdueAmount] = $this->overdue($open);
            $tiles[] = [
                'label' => 'A receber pelo módulo', 'icon' => 'clock', 'tone' => $overdueAmount > 0 ? 'warning' : '',
                'value' => F::money((int) $open['amount']),
                'detail' => (int) $open['count'] . ' fatura(s) em aberto' . ($overdueCount > 0
                    ? ' · <span class="pagou-text-warning">' . Html::e(F::money($overdueAmount)) . ' em atraso</span>'
                    : ' · nenhuma vencida'),
            ];
        } else {
            $tiles[] = ['label' => 'A receber pelo módulo', 'icon' => 'clock', 'value' => 'Indisponível', 'detail' => 'Consulta dos registros indisponível'];
        }
        $reference = $merchant['pending']['invoiceAmount'] ?? null;
        $tiles[] = [
            'label' => 'Pendências financeiras', 'icon' => 'alert', 'tone' => $findings > 0 ? 'warning' : 'success',
            'value' => (string) $findings,
            'detail' => $findings > 0
                ? 'Requerem conferência' . (is_int($reference) && $reference > 0 ? ' · ' . Html::e(F::money($reference)) . ' de referência' : '')
                : 'Nada aguardando conferência',
        ];

        $html = '<section class="pagou-module-summary" aria-labelledby="pagou-module-title">'
            . '<header class="pagou-module-heading"><h2 id="pagou-module-title">' . Html::icon('wallet', 17) . 'Neste módulo WHMCS</h2>'
            . '<p>Somente pagamentos, faturas e pendências acompanhados por este módulo.</p></header><div class="pagou-kpis">';
        foreach ($tiles as $tile) {
            $tone = in_array($tile['tone'] ?? '', ['success', 'warning', 'danger'], true) ? ' is-' . $tile['tone'] : '';
            // Detail fragments are escaped where they are built; they may carry an inline tone span.
            $html .= '<article class="pagou-kpi' . $tone . '"><span class="pagou-kpi-label">' . Html::icon($tile['icon'], 14)
                . Html::e($tile['label']) . '</span><strong>' . Html::e($tile['value']) . '</strong><small>' . $tile['detail'] . '</small></article>';
        }

        return $html . '</div></section>';
    }

    /** @param array<string, mixed> $lastMonth */
    private function delta(int $current, array $lastMonth): string
    {
        $period = isset($lastMonth['from'], $lastMonth['to'])
            ? $this->shortDate((string) $lastMonth['from']) . ' a ' . $this->shortDate((string) $lastMonth['to'])
            : 'o mesmo período do mês anterior';
        $previous = (int) ($lastMonth['amount'] ?? 0);
        if ($previous <= 0) {
            return 'Sem base de comparação com ' . Html::e($period);
        }
        $percent = ($current - $previous) / $previous * 100;
        $up = $percent >= 0;

        return '<span class="pagou-delta ' . ($up ? 'is-up' : 'is-down') . '">' . Html::icon($up ? 'trending-up' : 'trending-down', 13)
            . ($up ? '+' : '') . number_format($percent, 1, ',', '.') . '%</span> em relação a ' . Html::e($period);
    }

    /**
     * @param array<string, mixed> $open
     * @return array{int,int}
     */
    private function overdue(array $open): array
    {
        $current = $open['buckets']['A vencer / hoje'] ?? ['count' => 0, 'amount' => 0];

        return [(int) $open['count'] - (int) $current['count'], (int) $open['amount'] - (int) $current['amount']];
    }

    /** @param array<string, mixed>|null $merchant */
    private function trend(?array $merchant): string
    {
        $title = '<div class="pagou-card-title"><div><span class="pagou-eyebrow">ÚLTIMOS 30 DIAS</span><h2>Recebimentos conciliados por dia</h2></div>';
        if ($merchant === null || !is_array($merchant['trend'] ?? null)) {
            return '<section class="pagou-card pagou-trend-card">' . $title . '</div>'
                . $this->empty('alert', 'Gráfico indisponível nesta consulta.', 'Nenhum valor foi presumido. Tente novamente ou consulte o diagnóstico.') . '</section>';
        }
        $days = $merchant['trend'];
        $total = array_sum(array_column($days, 'amount'));
        $count = array_sum(array_column($days, 'count'));
        $title .= ($total > 0 ? '<div class="pagou-trend-total"><strong>' . Html::e(F::money($total)) . '</strong><small>' . $count
            . ' recebimento(s) · média de ' . Html::e(F::money((int) round($total / max(1, count($days))))) . ' por dia</small></div>' : '') . '</div>';
        $chart = $total > 0
            ? (new TrendChart())->render($days, 'Recebimentos diários dos últimos 30 dias.', true)
            : $this->empty('inbox', 'Nenhum recebimento conciliado nos últimos 30 dias.', 'Os valores aparecem aqui assim que um pagamento é conciliado no WHMCS.');
        $methods = is_array($merchant['receipts']['summary']['methods'] ?? null)
            ? $this->methods($merchant['receipts']['summary']['methods'], (int) $merchant['receipts']['summary']['amount'])
            : '';

        return '<section class="pagou-card pagou-trend-card">' . $title . $chart . $methods . '</section>';
    }

    /** @param array<string, mixed> $methods */
    private function methods(array $methods, int $total): string
    {
        $rows = '';
        foreach (['pix' => 'Pix', 'boleto' => 'Boleto', 'card' => 'Cartão', 'unknown' => 'Não identificado'] as $key => $label) {
            $method = is_array($methods[$key] ?? null) ? $methods[$key] : ['amount' => 0, 'count' => 0];
            if ($key === 'unknown' && (int) $method['count'] === 0) {
                continue;
            }
            $percent = $total > 0 ? round((int) $method['amount'] / $total * 100, 1) : 0;
            $rows .= '<li><span class="pagou-method-name">' . Html::e($label) . '</span>'
                . '<span class="pagou-meter" aria-hidden="true"><span style="width:' . $percent . '%"></span></span>'
                . '<span class="pagou-method-value"><strong>' . Html::e(F::money((int) $method['amount'])) . '</strong><small>'
                . (int) $method['count'] . ' · ' . number_format($percent, 1, ',', '.') . '%</small></span></li>';
        }

        return '<div class="pagou-methods"><h3>Neste mês, por meio de pagamento</h3>'
            . ($total > 0 ? '<ul>' . $rows . '</ul>' : '<p class="pagou-help">Ainda não há recebimentos conciliados neste mês.</p>') . '</div>';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $merchant
     */
    private function attention(array $data, ?array $merchant): string
    {
        $items = [];
        $findings = (int) ($data['findings'] ?? 0);
        if ($findings > 0) {
            $items[] = ['danger', 'alert', $findings . ' pendência(s) financeira(s)', 'Confira antes de qualquer baixa manual.', 'view=findings', 'Conferir'];
        }
        if (is_array($merchant['open'] ?? null)) {
            [$overdueCount, $overdueAmount] = $this->overdue($merchant['open']);
            if ($overdueCount > 0) {
                $items[] = ['warning', 'clock', $overdueCount . ' fatura(s) vencida(s)', F::money($overdueAmount) . ' em aberto após o vencimento.', 'view=reports&report=open&status=overdue', 'Ver faturas'];
            }
        }
        $worker = is_array($data['worker'] ?? null) ? $data['worker'] : [];
        if (isset($worker['state']) && $worker['state'] !== 'ready') {
            $items[] = ['danger', 'activity', 'Processamento em segundo plano parado', (string) ($worker['detail'] ?? ''), 'view=settings#pagou-processing', 'Revisar'];
        } elseif (($data['queueStale'] ?? false) === true) {
            $items[] = ['warning', 'clock', 'Operação aguardando há mais de 1 hora', 'Mais antiga: ' . (string) ($worker['oldest'] ?? 'indisponível') . '.', 'view=operations', 'Ver fila'];
        }
        $callback = is_array($data['callback'] ?? null) ? $data['callback'] : [];
        if (isset($callback['state']) && $callback['state'] !== 'ready') {
            $items[] = ['warning', 'bell', 'Endereço de notificações requer revisão', (string) ($callback['detail'] ?? ''), 'view=settings#pagou-notifications', 'Revisar'];
        }
        $card = (int) ($data['cardAttention'] ?? 0);
        if ($card > 0) {
            $items[] = ['warning', 'card', $card . ' cobrança(s) de cartão pedem ação', 'Autenticação, autorização ou contestação pendente.', 'view=card', 'Ver cartão'];
        }
        $boleto = (int) ($data['boletoPending'] ?? 0);
        if ($boleto > 0) {
            $items[] = ['info', 'barcode', $boleto . ' boleto(s) em preparação', 'Emissão, PDF ou envio por e-mail em andamento.', 'view=operations', 'Acompanhar'];
        }
        $waiting = (int) ($data['awaitingConfirmation'] ?? 0);
        if ($waiting > 0) {
            $items[] = ['info', 'receipt', $waiting . ' cobrança(s) aguardando pagamento', 'Emitidas e ainda sem resultado final.', 'view=payments', 'Ver cobranças'];
        }

        $list = '';
        foreach ($items as [$tone, $icon, $title, $detail, $target, $action]) {
            $list .= '<li class="is-' . $tone . '"><span class="pagou-attention-icon">' . Html::icon($icon, 15) . '</span>'
                . '<div><strong>' . Html::e($title) . '</strong><small>' . Html::e($detail) . '</small></div>'
                . '<a class="btn btn-default btn-sm pagou-button" href="addonmodules.php?module=pagou_payments&amp;' . Html::e($target) . '">'
                . Html::e($action) . '</a></li>';
        }

        return '<section class="pagou-card pagou-attention"><div class="pagou-card-title"><div><span class="pagou-eyebrow">PRIORIDADES</span>'
            . '<h2>Precisa da sua atenção</h2></div>' . ($items === [] ? Html::badge('Em dia', 'success') : Html::badge(count($items) . ' item(ns)', 'warning'))
            . '</div>' . ($list === ''
                ? $this->empty('check', 'Nada pendente agora.', 'Pendências, faturas vencidas e filas paradas aparecem aqui assim que surgirem.')
                : '<ul class="pagou-attention-list">' . $list . '</ul>')
            . '</section>';
    }

    /** @param array<string, mixed>|null $merchant */
    private function recent(?array $merchant): string
    {
        $title = '<div class="pagou-card-title"><div><span class="pagou-eyebrow">RECEBIMENTOS</span><h2>Últimos recebimentos conciliados</h2></div>'
            . '<a class="pagou-link" href="' . MerchantReports::url([]) . '">Ver relatório' . Html::icon('chevron-right', 14) . '</a></div>';
        $rows = is_array($merchant['recent'] ?? null) ? $merchant['recent'] : null;
        if ($rows === null) {
            return '<section class="pagou-card pagou-list-card">' . $title . $this->empty('alert', 'Lista indisponível nesta consulta.', 'Nenhum valor foi presumido.') . '</section>';
        }
        $list = '';
        foreach ($rows as $row) {
            $list .= '<li>' . $this->invoiceCell($row, F::label((string) $row['method']))
                . '<div class="pagou-activity-value"><strong>' . Html::e(F::money(isset($row['amount']) ? (int) $row['amount'] : null)) . '</strong><small>'
                . Html::e(F::date((string) $row['date'])) . '</small></div></li>';
        }

        return '<section class="pagou-card pagou-list-card">' . $title . ($list === ''
            ? $this->empty('inbox', 'Nenhum recebimento conciliado nos últimos 30 dias.', 'Cada pagamento confirmado e baixado no WHMCS aparece aqui.')
            : '<ul class="pagou-activity">' . $list . '</ul>') . '</section>';
    }

    /** @param array<string, mixed>|null $merchant */
    private function openInvoices(?array $merchant): string
    {
        $title = '<div class="pagou-card-title"><div><span class="pagou-eyebrow">A RECEBER</span><h2>Faturas em aberto mais antigas</h2></div>'
            . '<a class="pagou-link" href="' . MerchantReports::url(['report' => 'open']) . '">Ver todas' . Html::icon('chevron-right', 14) . '</a></div>';
        $rows = is_array($merchant['openRows'] ?? null) ? $merchant['openRows'] : null;
        if ($rows === null) {
            return '<section class="pagou-card pagou-list-card">' . $title . $this->empty('alert', 'Lista indisponível nesta consulta.', 'Nenhum valor foi presumido.') . '</section>';
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $list = '';
        foreach ($rows as $row) {
            $days = (int) ($row['days'] ?? 0);
            $due = (string) $row['date'];
            $when = $days > 0
                ? '<small class="pagou-text-warning">' . $days . ' dia(s) de atraso</small>'
                : '<small>' . ($due === $today ? 'Vence hoje' : 'Vence em ' . Html::e(F::date($due))) . '</small>';
            $list .= '<li>' . $this->invoiceCell($row, F::label((string) $row['method']))
                . '<div class="pagou-activity-value"><strong>' . Html::e(F::money(isset($row['amount']) ? (int) $row['amount'] : null)) . '</strong>' . $when . '</div></li>';
        }

        return '<section class="pagou-card pagou-list-card">' . $title . ($list === ''
            ? $this->empty('check', 'Nenhuma fatura em aberto nos gateways Pagou.', 'Faturas não pagas com cobrança do módulo aparecem aqui, das mais antigas para as mais recentes.')
            : '<ul class="pagou-activity">' . $list . '</ul>') . '</section>';
    }

    /** @param array<string, mixed> $row */
    private function invoiceCell(array $row, string $method): string
    {
        $id = (int) ($row['invoice'] ?? 0);
        $customer = (string) ($row['customer'] ?? '') !== ''
            ? (string) $row['customer']
            : ((int) ($row['client'] ?? 0) > 0 ? 'Cliente #' . (int) $row['client'] : 'Cliente não identificado');

        return '<div class="pagou-activity-main">' . ($id > 0
                ? '<a href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=' . $id . '">Fatura #' . $id . '</a>'
                : '<span>Sem fatura identificada</span>')
            . '<small>' . Html::e($customer) . ' · ' . Html::e($method) . '</small></div>';
    }

    private function empty(string $icon, string $title, string $detail): string
    {
        return '<div class="pagou-empty-state">' . Html::icon($icon, 20) . '<div><strong>' . Html::e($title) . '</strong><p>'
            . Html::e($detail) . '</p></div></div>';
    }

    private function shortDate(string $date): string
    {
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $value instanceof \DateTimeImmutable ? $value->format('d/m') : $date;
    }

    /** @param array<string, mixed> $onboarding */
    private function guide(array $onboarding): string
    {
        $steps = '';
        foreach ((array) ($onboarding['steps'] ?? []) as $position => $step) {
            if (!is_array($step)) {
                continue;
            }
            $ready = ($step['state'] ?? '') === 'ready';
            $steps .= '<li class="' . ($ready ? 'is-complete' : '') . '"><span class="pagou-step-number">'
                . ($ready ? Html::icon('check', 14) : Html::e((string) ((int) $position + 1))) . '</span><div><h3>'
                . Html::e((string) ($step['title'] ?? 'Etapa')) . ' '
                . Html::badge($ready ? 'Concluída' : 'Pendente', $ready ? 'success' : 'warning') . '</h3><p>'
                . Html::e((string) ($step['detail'] ?? '')) . '</p></div><a class="btn btn-default pagou-button" href="'
                . Html::e((string) ($step['url'] ?? '#')) . '">' . Html::e((string) ($step['action'] ?? 'Abrir')) . '</a></li>';
        }

        if (($onboarding['state'] ?? '') === 'ready') {
            return '<section class="pagou-setup-guide is-complete"><div class="pagou-card-title"><div>'
                . '<span class="pagou-eyebrow">INSTALAÇÃO PRONTA</span><h2>Configuração inicial concluída</h2>'
                . '<p>As seis etapas de configuração foram concluídas. A visibilidade dos meios de pagamento continua sob seu controle.</p></div>'
                . Html::badge('Concluído', 'success') . '</div>'
                . '<ol class="pagou-onboarding-steps">' . $steps . '</ol></section>';
        }

        return '<section class="pagou-setup-guide"><div class="pagou-card-title"><div><h2>Roteiro de configuração</h2>'
            . '<p>Siga esta ordem. O módulo confirma cada passo automaticamente e mantém o roteiro disponível depois da configuração.</p>'
            . '</div>' . Html::badge('Sem segredos na tela', 'neutral') . '</div><ol class="pagou-onboarding-steps">'
            . $steps . '</ol></section>';
    }
}
