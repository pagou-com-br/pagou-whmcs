<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\View;

use Pagou\Payments\Admin\Support\Html;

final class Page
{
    /** Unique ids for detail rows across every table of the page. */
    private static int $detailRows = 0;

    /** @param list<array{label:string,value:string,detail?:string,tone?:string}> $metrics */
    public static function metrics(array $metrics): string
    {
        $html = '<section class="pagou-metrics">';
        foreach ($metrics as $metric) {
            $tone = isset($metric['tone']) && in_array($metric['tone'], ['success', 'warning', 'danger'], true)
                ? ' is-' . $metric['tone']
                : '';
            $html .= '<article class="pagou-metric' . $tone . '"><span>' . Html::e($metric['label']) . '</span><strong>' . Html::e($metric['value']) . '</strong>';
            if (isset($metric['detail'])) {
                $html .= '<small>' . Html::e($metric['detail']) . '</small>';
            }
            $html .= '</article>';
        }
        return $html . '</section>';
    }

    /** Columns whose operator labels are states and read better as badges. */
    private const STATUS_HEADERS = ['Situação', 'Resultado', 'Conciliação', 'Assinatura', 'Estado', 'Gravidade', 'Entrada', 'Etapa', 'Resultado registrado'];
    /** Technical identifiers shortened in lists; the detail page keeps them whole. */
    private const SHORT_ID_HEADERS = ['Tentativa', 'Tentativa vinculada', 'Identidade do recurso'];

    /**
     * Presentation tone for a translated state label. Unknown labels stay
     * neutral, so a new state is never shown as a success by default.
     */
    public static function tone(string $label): string
    {
        $label = mb_strtolower(trim($label));
        return match (true) {
            in_array($label, ['pago', 'paga', 'liquidado', 'concluída', 'concluído', 'conciliado no whmcs', 'válida', 'processado', 'recebimento registrado', 'verificada', 'instalada', 'ativa', 'ativo', 'capturado', 'devolvida'], true) => 'success',
            in_array($label, ['aguardando pagamento', 'na fila', 'em processamento', 'em andamento', 'em execução', 'aguardando registro', 'aguardando registro bancário', 'nova tentativa', 'solicitação em envio', 'em aberto', 'aberta', 'autorizado', 'autorizada', 'cancelamento solicitado', 'baixa em processamento', 'aguardando nova consulta', 'pronta', 'recebida'], true) => 'info',
            in_array($label, ['aguardando conciliação', 'resultado incerto', 'requer autenticação', 'autenticação pendente', 'requer conferência', 'contestado', 'contestada', 'média', 'vencida', 'homologação pendente', 'revisão necessária', 'pendente', 'estado desconhecido', 'histórico indisponível', 'reembolso parcial', 'não validada', 'não paga', 'em conferência', 'confirmada, registro pendente no whmcs'], true) => 'warning',
            in_array($label, ['falhou', 'falha', 'inválida', 'rejeitada', 'crítica', 'alta', 'bloqueada', 'não aceita'], true) => 'danger',
            default => 'neutral',
        };
    }

    /**
     * One-line page header: the tab already names the screen, so no card or eyebrow.
     */
    public static function header(string $title, string $description, string $actions = ''): string
    {
        return '<header class="pagou-page-head"><div class="pagou-page-head-copy"><h2>' . Html::e($title) . '</h2><p>' . Html::e($description) . '</p></div>'
            . ($actions !== '' ? '<div class="pagou-actions">' . $actions . '</div>' : '') . '</header>';
    }

    /** Empty result inside a titled card, without an empty table head. */
    public static function emptyCard(string $title, string $message, string $footer = ''): string
    {
        return '<section class="pagou-card pagou-table-card is-empty">' . ($title !== '' ? '<div class="pagou-card-title"><h2>' . Html::e($title) . '</h2></div>' : '')
            . '<p class="pagou-empty-state">' . Html::icon('inbox', 18) . '<span>' . Html::e($message) . '</span></p>' . $footer . '</section>';
    }

    /** Copy button for an identifier shown shortened next to it. */
    public static function copyButton(string $value, string $label = 'ID'): string
    {
        return '<button type="button" class="pagou-id-copy pagou-id-copy--inline" data-pagou-copy-value="' . Html::e($value) . '" aria-label="Copiar ' . Html::e($label) . '" title="Copiar">'
            . Html::icon('copy', 13) . Html::icon('check', 13) . '</button>';
    }

    /** Icon for a notification type, read from its translated label. */
    private static function eventIcon(string $label): string
    {
        $label = mb_strtolower($label);
        $icon = match (true) {
            str_contains($label, 'devol') || str_contains($label, 'estorn') || str_contains($label, 'reemb') => 'refresh',
            str_contains($label, 'pix') => 'qr-code',
            str_contains($label, 'boleto') => 'barcode',
            str_contains($label, 'cartão') || str_contains($label, 'cobrança de cartão') => 'card',
            default => 'bell',
        };

        return Html::icon($icon, 14);
    }

    public static function status(string $label): string
    {
        return Html::badge($label, self::tone($label));
    }

    /** Short, monospaced reference that keeps the full value available on hover. */
    public static function shortId(string $value): string
    {
        $short = strlen($value) > 12 ? substr($value, 0, 8) . '…' : $value;
        return '<code class="pagou-id" title="' . Html::e($value) . '">' . Html::e($short) . '</code>';
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param array{key?:callable(list<string>):?string,column?:string,label?:callable(int):string,all?:string} $grouping
     *        Consecutive rows with the same key collapse under the first one, which offers to show them.
     */
    public static function table(string $title, array $headers, array $rows, string $empty = 'Nenhum registro encontrado.', bool $invoiceLinks = false, string $footer = '', array $grouping = []): string
    {
        if ($rows === []) {
            return self::emptyCard($title, $empty, $footer);
        }
        $headOf = [];
        $members = [];
        $previous = null;
        foreach ($rows as $index => $row) {
            $key = isset($grouping['key']) ? ($grouping['key'])($row) : null;
            if ($key !== null && $index > 0 && $key === $previous) {
                $headOf[$index] = $headOf[$index - 1];
                $members[$headOf[$index]]++;
            } else {
                $headOf[$index] = $index;
                $members[$index] = 0;
            }
            $previous = $key;
        }
        $groupPrefix = 'pagou-group-' . (++self::$detailRows);
        $head = '';
        foreach ($headers as $header) {
            // Details open in a full-width row below; their column keeps only the toggle.
            $isDetail = in_array($header, ['Orientação', 'Detalhes e tempos'], true);
            $head .= '<th scope="col"' . ($isDetail ? ' class="pagou-col-toggle"' : '') . '>' . ($isDetail ? '<span class="pagou-sr-only">' . Html::e($header) . '</span>' : Html::e($header)) . '</th>';
        }
        $body = '';
        foreach ($rows as $rowIndex => $row) {
            $cells = '';
            $invoice = '';
            foreach ($row as $value) {
                if (preg_match('/^#([1-9][0-9]*)$/D', $value, $match) === 1) {
                    $invoice = $match[1];
                    break;
                }
            }
            $rowId = '';
            $details = '';
            $attention = false;
            $groupId = $groupPrefix . '-' . $headOf[$rowIndex];
            $isMember = $headOf[$rowIndex] !== $rowIndex;
            foreach ($row as $index => $cell) {
                $header = $headers[$index] ?? '';
                $content = Html::e($cell);
                if ($header === 'Tipo recebido') {
                    $content = '<span class="pagou-method-cell">' . self::eventIcon($cell) . $content . '</span>';
                }
                if ($invoiceLinks && preg_match('/^#([1-9][0-9]*)$/D', $cell, $match) === 1) {
                    $content = '<a class="pagou-invoice-link" href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=' . (int) $match[1] . '">' . $content . '</a>';
                }
                if (in_array($header, self::STATUS_HEADERS, true) && trim($cell) !== '') {
                    $content = self::status($cell);
                    $attention = $attention || (in_array($header, ['Resultado', 'Conciliação'], true) && in_array(self::tone($cell), ['warning', 'danger'], true));
                }
                if (!$isMember && $members[$rowIndex] > 0 && $header === ($grouping['column'] ?? '')) {
                    $label = isset($grouping['label']) ? ($grouping['label'])($members[$rowIndex]) : '+' . $members[$rowIndex];
                    $content .= ' <button type="button" class="pagou-group-toggle" aria-expanded="false" data-pagou-group="' . $groupId . '">' . Html::e($label) . '</button>';
                }
                $isAttempt = in_array($header, ['ID local', 'Tentativa', 'Tentativa vinculada'], true) && preg_match('/^[a-f0-9-]{36}$/D', $cell) === 1;
                $short = in_array($header, self::SHORT_ID_HEADERS, true) && strlen($cell) > 12;
                if ($short) {
                    $content = self::shortId($cell);
                }
                if ($isAttempt) {
                    $target = '#attempt-' . $cell;
                    if ($invoice !== '') {
                        $target = 'addonmodules.php?module=pagou_payments&view=charge&invoice=' . $invoice . $target;
                    }
                    $content = '<a href="' . Html::e($target) . '">' . $content . '</a>';
                    if ($header === 'ID local') {
                        $rowId = ' id="attempt-' . Html::e($cell) . '"';
                    }
                }
                if ($short) {
                    $content = '<span class="pagou-id-cell">' . $content . self::copyButton($cell, $header) . '</span>';
                }
                if (in_array($header, ['Orientação', 'Detalhes e tempos'], true)) {
                    if (trim($cell) === '') {
                        $cells .= '<td class="pagou-col-toggle"></td>';
                        continue;
                    }
                    $detailId = 'pagou-row-details-' . (++self::$detailRows);
                    $details = '<tr class="pagou-row-details" id="' . $detailId . '" hidden><td colspan="' . count($headers) . '"><p class="pagou-operation-details">' . $content . '</p></td></tr>';
                    $content = '<button type="button" class="pagou-row-toggle" aria-expanded="false" aria-controls="' . $detailId . '">Detalhes' . Html::icon('chevron-down', 13) . '</button>';
                    $cells .= '<td class="pagou-col-toggle">' . $content . '</td>';
                    continue;
                }
                $cells .= '<td>' . $content . '</td>';
            }
            $href = $invoiceLinks && $invoice !== '' ? ' data-pagou-row-href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=' . (int) $invoice . '"' : '';
            $classes = trim(($attention ? 'is-attention' : '') . ($isMember ? ' pagou-row-grouped' : ''));
            $body .= '<tr' . $rowId . $href . ($classes !== '' ? ' class="' . $classes . '"' : '') . ($isMember ? ' data-pagou-group-member="' . $groupId . '" hidden' : '') . '>' . $cells . '</tr>' . $details;
        }
        $grouped = array_filter($members) !== [];
        $all = $grouped && isset($grouping['all']) ? '<button type="button" class="pagou-link-button" data-pagou-group-all aria-pressed="false">' . Html::e($grouping['all']) . '</button>' : '';
        return '<section class="pagou-card pagou-table-card"><div class="pagou-card-title"><h2>' . Html::e($title) . '</h2>' . $all . '</div><div class="pagou-table-wrap"><table class="pagou-table"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>' . $footer . '</section>';
    }

    /** @param array<string, scalar> $fields */
    public static function action(
        string $label,
        string $action,
        string $view,
        string $csrfField,
        bool $enabled = true,
        bool $confirm = true,
        array $fields = [],
        string $style = 'primary',
    ): string {
        $disabled = $enabled ? '' : ' disabled aria-disabled="true"';
        $confirmation = $confirm ? ' data-pagou-confirm="' . Html::e($label) . '"' : '';
        $overlay = in_array($action, [
            'run-diagnostics', 'test-credential', 'run-reconciliation', 'refresh-pending', 'resume-queue',
            'card-capture', 'card-reverse', 'card-cancel', 'card-retry', 'card-reconcile',
        ], true) ? ' data-pagou-loading-overlay' : '';
        $hidden = '';
        foreach ($fields as $name => $value) {
            if (preg_match('/^[a-z0-9_]{1,64}$/', $name) === 1) {
                $hidden .= '<input type="hidden" name="' . Html::e($name) . '" value="' . Html::e((string) $value) . '">';
            }
        }
        return '<form method="post" class="pagou-inline-form"' . $confirmation . $overlay . '>' . $csrfField
            . '<input type="hidden" name="view" value="' . Html::e($view) . '"><input type="hidden" name="action" value="' . Html::e($action) . '">'
            . $hidden
            . '<button class="btn ' . ($style === 'default' ? 'btn-default' : 'btn-primary') . ' pagou-button" type="submit"' . $disabled . '>' . Html::e($label) . '</button></form>';
    }
}
