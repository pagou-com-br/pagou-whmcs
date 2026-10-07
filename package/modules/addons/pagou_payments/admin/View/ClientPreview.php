<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\View;

use Pagou\Payments\Admin\Support\Html;

/**
 * Illustrative client invoice card for the Pix and boleto settings. It uses the
 * client stylesheet inside an isolated frame and fictitious data only: the
 * code, line and QR image below cannot be paid.
 */
final class ClientPreview
{
    /** @param array<string, mixed> $settings */
    public function render(string $method, array $settings, string $formId): string
    {
        if (!in_array($method, ['pix', 'boleto'], true)) {
            return '';
        }
        $document = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<link rel="stylesheet" href="../modules/addons/pagou_payments/assets/client.css?v=' . Html::e($this->version()) . '">'
            . '<style>html,body{background:#f4f6fa;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;margin:0}body{padding:4px 12px}'
            . '[hidden]{display:none!important}.pagou-notes{white-space:pre-line}</style></head><body>'
            . ($method === 'pix' ? $this->pix($settings) : $this->boleto($settings)) . '</body></html>';

        return '<section class="pagou-card pagou-client-preview"><div class="pagou-card-title"><div><span class="pagou-eyebrow">PRÉVIA PARA O CLIENTE</span>'
            . '<h2>Como a cobrança aparece na fatura</h2><p>Atualiza enquanto você altera as opções de apresentação, antes de salvar. '
            . 'Dados fictícios; a aparência final também segue o tema do WHMCS.</p></div>' . Html::badge('Exemplo', 'neutral') . '</div>'
            . '<iframe class="pagou-client-preview-frame" title="Prévia da cobrança para o cliente" loading="lazy" sandbox="allow-same-origin"'
            . ' data-pagou-client-preview="' . Html::e($formId) . '" srcdoc="' . Html::e($document) . '"></iframe></section>';
    }

    /** @param array<string, mixed> $settings */
    private function pix(array $settings): string
    {
        $due = $this->enabled($settings, 'pix_due_enabled');

        return '<section class="pagou-payment pagou-payment--pix" data-pagou-state="ready">'
            . '<header class="pagou-payment__header"><strong>Pix</strong><span class="pagou-status">Aguardando pagamento</span></header>'
            . '<p class="pagou-due" data-preview-when="pix_due_enabled"' . ($due ? '' : ' hidden') . '>Vencimento: ' . $this->date('+5 days', 'd/m/Y') . '</p>'
            . '<p class="pagou-due" data-preview-unless="pix_due_enabled"' . ($due ? ' hidden' : '') . '>Vencimento: ' . $this->date('+1 day', 'd/m/Y H:i') . '</p>'
            . '<div class="pagou-qr-frame" data-preview-when="pix_show_qr"' . $this->hidden($settings, 'pix_show_qr') . '>'
            . '<img class="pagou-qr" alt="QR Code de exemplo" src="' . $this->qr() . '"></div>'
            . $this->copyField('Pix copia e cola', 'EXEMPLO-PIX-SEM-VALOR-NAO-PAGUE-0000000000000000', 'Copiar código Pix', 'pix_show_copy_paste', $settings)
            . $this->notes('pix', $settings)
            . '<p class="pagou-help">A confirmação do pagamento aparecerá automaticamente nesta página.</p></section>';
    }

    /** @param array<string, mixed> $settings */
    private function boleto(array $settings): string
    {
        return '<section class="pagou-payment pagou-payment--boleto" data-pagou-state="ready">'
            . '<header class="pagou-payment__header"><strong>Boleto</strong><span class="pagou-status">Aguardando pagamento</span></header>'
            . '<p class="pagou-due">Vencimento: ' . $this->date('+5 days', 'd/m/Y') . '</p>'
            . '<div class="pagou-document-actions" data-preview-when="boleto_show_pdf"' . $this->hidden($settings, 'boleto_show_pdf') . '>'
            . '<span class="pagou-button">' . Html::icon('barcode', 16) . 'Visualizar boleto</span>'
            . '<span class="pagou-document-download">' . Html::icon('download', 16) . 'Baixar boleto em PDF</span></div>'
            . $this->copyField('Linha digitável', '00000.00000 00000.000000 00000.000000 0 00000000000000', 'Copiar linha digitável', 'boleto_show_line', $settings)
            . '<div class="pagou-qr-frame" data-preview-when="boleto_show_qr"' . $this->hidden($settings, 'boleto_show_qr') . '>'
            . '<img class="pagou-qr" alt="QR Code de exemplo" src="' . $this->qr() . '"></div>'
            . $this->notes('boleto', $settings)
            . '<p class="pagou-help">O boleto e a confirmação do pagamento serão atualizados automaticamente nesta página.</p></section>';
    }

    /** @param array<string, mixed> $settings */
    private function copyField(string $label, string $value, string $button, string $toggle, array $settings): string
    {
        return '<div class="pagou-copy-group" data-preview-when="' . Html::e($toggle) . '"' . $this->hidden($settings, $toggle) . '>'
            . '<span class="pagou-copy-label">' . Html::e($label) . '</span><div class="pagou-copy-row">'
            . '<input class="pagou-copy-value" type="text" readonly tabindex="-1" value="' . Html::e($value) . '" aria-label="' . Html::e($label) . ' de exemplo">'
            . '<span class="pagou-copy-button">' . Html::e($button) . '</span></div></div>';
    }

    /** @param array<string, mixed> $settings */
    private function notes(string $method, array $settings): string
    {
        $text = trim((string) ($settings[$method . '_notes'] ?? ''));
        $visible = $this->enabled($settings, $method . '_show_notes') && $text !== '';

        return '<p class="pagou-notes" data-preview-when="' . $method . '_show_notes" data-preview-text="' . $method . '_notes"'
            . ($visible ? '' : ' hidden') . '>' . Html::e($text) . '</p>';
    }

    /** @param array<string, mixed> $settings */
    private function enabled(array $settings, string $key): bool
    {
        return in_array(strtolower((string) ($settings[$key] ?? '')), ['1', 'on', 'yes', 'true'], true);
    }

    /** @param array<string, mixed> $settings */
    private function hidden(array $settings, string $key): string
    {
        return $this->enabled($settings, $key) ? '' : ' hidden';
    }

    private function date(string $offset, string $format): string
    {
        return (new \DateTimeImmutable($offset, new \DateTimeZone('America/Sao_Paulo')))->format($format);
    }

    /** A fixed decorative pattern. It has no QR format data and cannot be scanned. */
    private function qr(): string
    {
        $bits = hash('sha256', 'pagou-client-preview') . hash('sha256', 'pagou-client-preview-2');
        $cells = '';
        for ($y = 0; $y < 21; $y++) {
            for ($x = 0; $x < 21; $x++) {
                $finder = ($x < 7 || $x > 13) && $y < 7 || $x < 7 && $y > 13;
                if ($finder) {
                    $ring = max(abs(($x > 13 ? $x - 17 : $x - 3)), abs(($y > 13 ? $y - 17 : $y - 3)));
                    $on = $ring !== 2;
                } else {
                    $on = (hexdec($bits[($y * 21 + $x) % strlen($bits)]) & 1) === 1;
                }
                $cells .= $on ? '<rect x="' . $x . '" y="' . $y . '" width="1" height="1"/>' : '';
            }
        }

        return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 25 25" shape-rendering="crispEdges">'
            . '<rect x="-2" y="-2" width="25" height="25" fill="#fff"/><g fill="#1d2d50">' . $cells . '</g></svg>');
    }

    private function version(): string
    {
        return substr(hash_file('sha256', dirname(__DIR__, 2) . '/assets/client.css') ?: 'client', 0, 12);
    }
}
