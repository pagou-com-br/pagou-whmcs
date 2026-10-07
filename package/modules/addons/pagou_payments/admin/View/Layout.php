<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\View;

use Pagou\Payments\Admin\Support\Html;

final class Layout
{
    /** @param array<string, mixed> $context */
    public function render(string $content, array $context): string
    {
        $page = (string) ($context['page'] ?? 'dashboard');
        $assetRoot = dirname(__DIR__, 2) . '/assets';
        $cssVersion = $this->assetVersion($assetRoot . '/admin.css');
        $jsVersion = $this->assetVersion($assetRoot . '/admin.js');
        // Daily work first; the module's own system pages stay compact at the end.
        $tabs = [
            'dashboard' => ['Visão geral', 'dashboard'],
            'payments' => ['Pagamentos', 'receipt'],
            'reports' => ['Relatórios', 'trending-up'],
            'operations' => ['Atividade', 'activity'],
            'findings' => ['Pendências', 'alert'],
            'card' => ['Cartão', 'card'],
        ];
        $system = [
            'settings' => ['Configurações', 'settings'],
            'diagnostics' => ['Diagnóstico', 'shield'],
            'about' => ['Sobre', 'info'],
        ];
        $section = match ($page) {
            'charge' => 'payments',
            'webhooks', 'reconciliation' => 'operations',
            default => $page,
        };

        $badges = is_array($context['badges'] ?? null) ? $context['badges'] : [];
        $link = static function (string $key, string $label, string $icon, string $class) use ($section, $badges): string {
            $active = $key === $section;
            $count = (int) ($badges[$key] ?? 0);
            return '<a class="' . $class . ($active ? ' is-active' : '') . '" href="addonmodules.php?module=pagou_payments&amp;view=' . Html::e($key) . '"'
                . ($active ? ' aria-current="page"' : '') . ($class !== 'pagou-tab' ? ' title="' . Html::e($label) . '"' : '') . '>' . Html::icon($icon) . Html::e($label)
                . ($count > 0 ? '<span class="pagou-tab-count" title="' . $count . ' em aberto"><span class="pagou-sr-only">, </span>' . ($count > 99 ? '99+' : $count)
                    . '<span class="pagou-sr-only"> em aberto</span></span>' : '')
                . '</a>';
        };
        $nav = '';
        foreach ($tabs as $key => [$label, $icon]) {
            $nav .= $link($key, $label, $icon, 'pagou-tab');
        }
        $nav .= '<span class="pagou-tabs-system">';
        foreach ($system as $key => [$label, $icon]) {
            $nav .= $link($key, $label, $icon, 'pagou-tab pagou-tab--system');
        }
        $nav .= '</span>';
        $subnav = '';
        if ($section === 'operations') {
            $subnav = '<nav class="pagou-tabs pagou-subtabs" aria-label="Seções da atividade">';
            foreach (['operations' => ['Operações', 'activity'], 'webhooks' => ['Notificações', 'bell'], 'reconciliation' => ['Conciliação', 'refresh']] as $key => [$label, $icon]) {
                $subnav .= '<a class="pagou-tab pagou-subtab' . ($key === $page ? ' is-active' : '') . '" href="addonmodules.php?module=pagou_payments&amp;view=' . $key . '"'
                    . ($key === $page ? ' aria-current="page"' : '') . '>' . Html::icon($icon, 15) . Html::e($label) . '</a>';
            }
            $subnav .= '</nav>';
        }
        $redirect = isset($context['redirect']) ? '<a data-pagou-post-redirect href="' . Html::e($context['redirect']) . '">Continuar para a tela atualizada</a>' : '';
        $notice = '';
        if (isset($context['notice'])) {
            $tone = (string) ($context['noticeTone'] ?? 'info');
            $tone = in_array($tone, ['success', 'danger', 'warning', 'info'], true) ? $tone : 'info';
            $notice = '<div class="pagou-notice pagou-notice--' . Html::e($tone) . '" role="status">' . Html::e($context['notice']) . '</div>';
        }

        return '<link rel="stylesheet" href="../modules/addons/pagou_payments/assets/admin.css?v=' . Html::e($cssVersion) . '">'
            . '<link rel="stylesheet" href="../modules/addons/pagou_payments/assets/payment-parties.css?v=' . Html::e($this->assetVersion($assetRoot . '/payment-parties.css')) . '">'
            . '<div class="pagou-admin pagou-shell" data-pagou-admin><section class="pagou-frame">'
            . '<header class="pagou-frame-header"><div class="pagou-frame-title">'
            . '<span class="pagou-product-mark">' . Html::icon('wallet', 20) . '</span>'
            . '<div><h2>Pagou para WHMCS</h2><p>Pagamentos, conciliação e operação financeira em um único lugar.</p></div></div>'
            . '<form class="pagou-search" role="search" method="get" action="addonmodules.php" data-pagou-no-loading>'
            . '<input type="hidden" name="module" value="pagou_payments"><input type="hidden" name="view" value="search">'
            . '<label class="pagou-sr-only" for="pagou-search-input">Buscar fatura, cliente, CPF/CNPJ, ID Pagou ou E2E</label>'
            . Html::icon('search', 15)
            . '<input id="pagou-search-input" type="search" name="q" maxlength="64" autocomplete="off" required value="' . Html::e((string) ($context['searchTerm'] ?? '')) . '"'
            . ' placeholder="Fatura, cliente, CPF/CNPJ, ID Pagou ou E2E" aria-keyshortcuts="/"></form>'
            . Html::logo('pagou-brand-logo') . '</header>'
            . '<nav class="pagou-tabs" aria-label="Navegação do Pagou Payments">' . $nav . '</nav>'
            . '<main class="pagou-main">' . $redirect . $notice . $subnav . $content . '</main></section>' . $this->loadingMarkup() . '</div>'
            . '<script src="../modules/addons/pagou_payments/assets/admin.js?v=' . Html::e($jsVersion) . '" defer></script>';
    }

    private function loadingMarkup(): string
    {
        return '<div class="pagou-loading-layer" aria-hidden="true">'
            . '<div class="pagou-loading-panel" role="status" aria-live="polite">'
            . '<span class="pagou-pixel-loader pagou-pixel-loader-panel" aria-hidden="true">'
            . str_repeat('<span class="pagou-pixel-cell"></span>', 9) . '</span>'
            . '<span class="pagou-loading-copy"><span class="pagou-loading-headline">'
            . '<strong class="pagou-loading-label" data-pagou-label="Consultando a Pagou">Consultando a Pagou</strong>'
            . '<span class="pagou-loading-elapsed" aria-hidden="true">0.0s</span></span>'
            . '<small>Isso pode levar alguns segundos.</small></span></div></div>';
    }

    private function assetVersion(string $path): string
    {
        $hash = is_file($path) ? hash_file('sha256', $path) : false;

        return is_string($hash) ? substr($hash, 0, 12) : '1';
    }
}
