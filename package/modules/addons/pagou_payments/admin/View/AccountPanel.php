<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\View;

use Pagou\Payments\Admin\Support\Html;
use Pagou\Payments\Admin\Support\ReportFormat as F;

/**
 * Account-wide values consulted on the Pagou API. They describe the whole
 * Pagou account and are kept visually apart from the module's local records.
 */
final class AccountPanel
{
    public const FRAGMENT_URL = 'addonmodules.php?module=pagou_payments&view=dashboard&fragment=account';

    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        $balance = is_array($data['balance'] ?? null) ? $data['balance'] : [];
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $pending = ($balance['pending'] ?? false) === true || ($summary['pending'] ?? false) === true;
        $body = '';
        if (($data['unavailable'] ?? false) === true || $data === []) {
            $body = '<p class="pagou-account-message">Consulta da conta indisponível no momento. Confira o diagnóstico do módulo.</p>';
        } elseif (($data['configured'] ?? false) !== true) {
            $body = '<p class="pagou-account-message">Configure a credencial para consultar o saldo da conta Pagou.</p>'
                . '<a class="btn btn-default pagou-button" href="addonmodules.php?module=pagou_payments&amp;view=settings#pagou-connection">Configurar credencial</a>';
        } else {
            $body = $this->balance($balance) . $this->receipts($summary);
        }

        return '<section class="pagou-card pagou-account" data-pagou-account data-pagou-account-src="'
            . Html::e(self::FRAGMENT_URL) . '"' . ($pending ? ' data-pagou-account-pending' : '') . ' aria-live="polite">'
            . '<header class="pagou-account-heading"><div class="pagou-account-brand">' . Html::logo('pagou-account-logo')
            . (($data['configured'] ?? false) === true
                ? '<button type="button" class="btn btn-default btn-sm pagou-button pagou-icon-button" data-pagou-account-refresh aria-label="Atualizar valores da conta Pagou" title="Atualizar">'
                    . Html::icon('refresh', 14) . '</button>'
                : '')
            . '</div><h2>Conta Pagou completa</h2><p>Inclui outros módulos e integrações.</p></header>' . $body
            . '<p class="pagou-card-caption">Toda a conta Pagou, consultada na API. Valores brutos, antes de taxas e estornos; cartão após liquidação.'
            . '</p></section>';
    }

    /** @param array<string, mixed> $balance */
    private function balance(array $balance): string
    {
        $value = is_array($balance['value'] ?? null) ? $balance['value'] : null;
        if ($value === null) {
            return '<div class="pagou-account-balance"><span>Saldo disponível</span><strong class="pagou-account-muted">'
                . (($balance['pending'] ?? false) === true ? $this->loading('Consultando saldo') : 'Indisponível')
                . '</strong></div>';
        }
        $html = '<div class="pagou-account-balance"><span>Saldo disponível</span><strong class="pagou-account-amount">'
            . Html::e(F::money((int) $value['available'])) . '</strong>';
        if ((int) $value['held'] > 0) {
            $html .= '<small>Valor retido: ' . Html::e(F::money((int) $value['held'])) . '</small>';
        }
        if (($balance['error'] ?? false) === true) {
            $html .= '<small class="pagou-text-warning">Não foi possível atualizar. Exibindo o último saldo consultado.</small>';
        }

        return $html . '<small>' . Html::e($this->timestamp($balance['asOf'] ?? null)) . '</small></div>';
    }

    /** @param array<string, mixed> $summary */
    private function receipts(array $summary): string
    {
        $value = is_array($summary['value'] ?? null) ? $summary['value'] : null;
        if ($value === null) {
            return '<p class="pagou-account-message">'
                . (($summary['pending'] ?? false) === true ? $this->loading('Consultando recebimentos da conta') : 'Recebimentos da conta indisponíveis nesta consulta.')
                . '</p>';
        }
        $html = '<dl class="pagou-account-receipts">';
        foreach (['today' => 'Total recebido hoje', 'month' => 'Total recebido no mês'] as $key => $label) {
            $html .= '<div><dt>' . $label . '</dt><dd><strong>' . Html::e(F::money((int) $value[$key . '_amount'])) . '</strong><small>'
                . (int) $value[$key . '_count'] . ' recebimento(s)</small></dd></div>';
        }
        $html .= '</dl>';
        if (($summary['error'] ?? false) === true) {
            $html .= '<p class="pagou-text-warning">Não foi possível atualizar os recebimentos da conta. Exibindo a última consulta.</p>';
        }

        return $html;
    }

    private function loading(string $label): string
    {
        return '<span class="pagou-inline-loading"><span class="pagou-pixel-loader pagou-pixel-loader-inline" aria-hidden="true">'
            . str_repeat('<span class="pagou-pixel-cell"></span>', 9) . '</span>' . Html::e($label) . '…</span>';
    }

    private function timestamp(mixed $stamp): string
    {
        if (!is_int($stamp)) {
            return 'Sem consulta concluída.';
        }
        $date = (new \DateTimeImmutable('@' . $stamp))->setTimezone(new \DateTimeZone('America/Sao_Paulo'));

        return 'Consultado em ' . $date->format('d/m/Y H:i') . ' (São Paulo).';
    }
}
