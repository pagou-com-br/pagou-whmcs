<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\Widget;

use DateTimeImmutable;
use DateTimeZone;
use Pagou\Payments\Admin\Support\Html;

final class View
{
    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        $style = '<style>' . file_get_contents(dirname(__DIR__, 2) . '/assets/widget.css') . '</style>';
        $html = $style . '<section class="pagou-home-widget" aria-label="Resumo financeiro Pagou">' . $this->loadingMarkup();
        if (($data['unavailable'] ?? false) || $data === []) {
            return $html . '<p>Resumo indisponível. Consulte o diagnóstico do módulo.</p>' . $this->links() . '</section>';
        }
        $balance = $data['balance'] ?? [];
        if (($balance['pending'] ?? false) || ($data['summary']['pending'] ?? false)) {
            $html .= '<span data-pagou-widget-refresh hidden></span>';
        }
        $summary = $data['summary'] ?? [];
        $html .= '<header class="pagou-home-header"><div class="pagou-home-balance">'
            . '<span>Saldo disponível</span><strong' . (isset($balance['value']) ? ' class="pagou-home-amount"' : '') . '>'
            . (isset($balance['value']) ? $this->money((int) $balance['value']['available']) : (($balance['pending'] ?? false) ? 'Consultando…' : 'Indisponível')) . '</strong>';
        if (isset($balance['value']) && (int) $balance['value']['held'] > 0) {
            $html .= '<span>Valor retido: ' . $this->money((int) $balance['value']['held']) . '</span>';
        }
        if (!($data['configured'] ?? false)) {
            $html .= '<small>Configure a credencial no addon para consultar a conta.</small>';
        } elseif ($balance['pending'] ?? false) {
            $html .= '<small>Atualizando o saldo da conta Pagou…</small>';
        } elseif ($balance['error'] ?? false) {
            $html .= '<small class="pagou-home-warning">' . (isset($balance['value'])
                ? 'Não foi possível atualizar. Exibindo o último saldo consultado.'
                : 'Consulta temporariamente indisponível. Tente atualizar novamente.') . '</small>';
        }
        $html .= '<small>' . $this->timestamp($balance['asOf'] ?? null, 'Saldo consultado') . '</small></div>'
            . '<div class="pagou-home-brand">' . Html::logo('pagou-home-logo')
            . '<span class="pagou-home-environment">' . Html::e($data['environment'] ?? '') . '</span></div></header>';
        $html .= '<div class="pagou-home-section-label">Recebimentos da conta Pagou</div>';
        if (isset($summary['value'])) {
            $value = $summary['value'];
            $html .= '<div class="pagou-home-receipts">';
            foreach (['today' => 'Hoje', 'month' => 'Neste mês'] as $key => $label) {
                $html .= '<div class="pagou-home-receipt"><span>' . $label . '</span><strong>'
                    . $this->money((int) $value[$key . '_amount']) . '</strong><small>'
                    . (int) $value[$key . '_count'] . ' recebimento(s)</small></div>';
            }
            $html .= '</div>';
        } else {
            $html .= '<p>' . (($summary['pending'] ?? false) ? 'Consultando recebimentos da conta…' : 'Recebimentos da conta indisponíveis nesta consulta.') . '</p>';
        }
        if ($summary['error'] ?? false) {
            $html .= '<p class="pagou-home-warning">Não foi possível atualizar os recebimentos da conta.'
                . (isset($summary['value']) ? ' Exibindo a última consulta.' : '') . '</p>';
        }
        $html .= '<p class="pagou-home-caption pagou-home-receipts-time">' . $this->timestamp($summary['asOf'] ?? null, 'Recebimentos consultados')
            . '</p><p class="pagou-home-caption">Recebimentos brutos de toda a conta Pagou, antes de taxas e estornos. '
            . 'Cartão incluído após liquidação. Horário de São Paulo.</p>';
        $findings = $data['findings'] ?? [];
        if (isset($findings['value'])) {
            $pending = (int) $findings['value']['pending'];
            $html .= '<a class="pagou-home-pending" href="addonmodules.php?module=pagou_payments&amp;view=findings">'
                . Html::icon($pending > 0 ? 'alert' : 'check', 16)
                . '<span>' . ($pending > 0 ? $pending . ' pendência(s) no módulo WHMCS' : 'Nenhuma pendência aberta no módulo WHMCS')
                . '</span>' . Html::icon('external', 15) . '</a>';
        } else {
            $html .= '<p>Pendências do módulo WHMCS indisponíveis.</p>';
        }
        if (($findings['error'] ?? false) && isset($findings['value'])) {
            $html .= '<p class="pagou-home-warning">Pendências não atualizadas. '
                . $this->timestamp($findings['asOf'] ?? null, 'Última consulta') . '</p>';
        }
        return $html . $this->links() . '<small class="pagou-home-refresh">Atualização a cada consulta após 2 min. Use ↻ para atualizar agora.</small></section>';
    }

    private function loadingMarkup(): string
    {
        return '<div class="pagou-widget-loading-layer" aria-hidden="true">'
            . '<div class="pagou-widget-loading-panel" role="status" aria-live="polite">'
            . '<span class="pagou-widget-pixel-loader" aria-hidden="true">'
            . str_repeat('<span class="pagou-widget-pixel-cell"></span>', 9) . '</span>'
            . '<span class="pagou-widget-loading-copy"><span class="pagou-widget-loading-headline">'
            . '<strong class="pagou-widget-loading-label" data-pagou-label="Consultando a Pagou">Consultando a Pagou</strong>'
            . '<span class="pagou-widget-loading-elapsed" aria-hidden="true">0.0s</span></span>'
            . '<small>Isso pode levar alguns segundos.</small></span></div></div>';
    }

    private function money(int $amount): string
    {
        return 'R$ ' . number_format(intdiv($amount, 100), 0, ',', '.') . ',' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    private function timestamp(mixed $stamp, string $label): string
    {
        if (!is_int($stamp)) {
            return 'Sem consulta concluída.';
        }
        $date = (new DateTimeImmutable('@' . $stamp))->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        return $label . ': ' . $date->format('d/m/Y H:i') . ' (São Paulo).';
    }

    private function links(): string
    {
        return '<nav class="pagou-home-links" aria-label="Atalhos Pagou">'
            . '<a class="pagou-home-primary" href="addonmodules.php?module=pagou_payments">Abrir módulo</a>'
            . '<a href="addonmodules.php?module=pagou_payments&amp;view=reports">Relatórios do módulo</a></nav>';
    }
}
