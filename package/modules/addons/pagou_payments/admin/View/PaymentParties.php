<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\View;

use Pagou\Payments\Admin\Support\Html;

/** Administrative-only identity presentation, shared by invoice and addon history. */
final class PaymentParties
{
    /** @param array<string,mixed> $row */
    public static function party(array $row, bool $actual): string
    {
        $name = (string) ($row[$actual ? 'payerName' : 'issuedName'] ?? '');
        $document = (string) ($row[$actual ? 'payerDocument' : 'issuedDocument'] ?? '');
        $missing = $actual ? 'Pagador não informado' : 'Dados da emissão indisponíveis';
        $html = Html::e($name !== '' ? $name : $missing);
        if ($document !== '') {
            $label = (strlen($document) === 11 ? 'CPF: ' : 'CNPJ: ') . Html::e(self::document($document));
            // The payer's document opens the addon search, which also finds invoices of other clients paid by it.
            $html .= $actual && preg_match('/^[0-9]{11}$|^[0-9]{14}$/D', $document) === 1
                ? '<small><a class="pagou-party-search" href="addonmodules.php?module=pagou_payments&amp;view=search&amp;q=' . $document . '" target="_blank" rel="noopener"'
                    . ' title="Buscar faturas pagas por este documento">' . $label . Html::icon('search', 12) . '</a></small>'
                : '<small>' . $label . '</small>';
        }
        if ($actual && !empty($row['thirdParty'])) {
            $html .= '<small class="pagou-party-note">Pago por outro CPF/CNPJ</small>';
        }
        return $html;
    }

    /** @param list<array<string,mixed>> $rows */
    public static function render(array $rows, bool $invoice = false): string
    {
        if ($rows === []) {
            return '';
        }
        $html = '<section class="pagou-parties' . ($invoice ? ' pagou-parties--inline' : '') . '">'
            . ($invoice ? '' : '<h3>Identificação da cobrança e do pagamento</h3>');
        foreach ($rows as $index => $row) {
            $paid = !empty($row['paid']);
            $html .= '<article class="pagou-party-record"><div class="pagou-party-grid"><div><h4>Cobrança emitida para</h4>' . self::party($row, false) . '</div><div><h4>Pagamento realizado por</h4>';
            if (!$paid) {
                $html .= '<span class="pagou-party-empty">Ainda sem recebimento confirmado.</span>';
            } elseif (empty($row['payerName']) && empty($row['payerDocument'])) {
                $html .= '<span class="pagou-party-empty">' . (($row['method'] ?? '') === 'boleto' ? 'Não informado neste recebimento. Boleto só identifica o pagador quando há confirmação do Pix associado.' : 'Não informado no recebimento confirmado.') . '</span>';
            } else {
                $html .= self::party($row, true);
            }
            $html .= '</div></div><div class="pagou-party-references">';
            foreach (['pagouId' => 'ID Pagou', 'pixId' => 'ID Pix do boleto', 'transactionId' => 'ID da transação', 'e2e' => 'E2E Pix'] as $key => $label) {
                $value = (string) ($row[$key] ?? '');
                if ($value === '') {
                    continue;
                }
                $id = 'pagou-party-' . $index . '-' . $key;
                if ($invoice) {
                    $html .= CopyField::render($label, $value, $id);
                    continue;
                }
                $html .= '<div><span>' . $label . '</span><code id="' . $id . '" title="' . Html::e($value) . '">' . Html::e($value) . '</code>'
                    . '<button type="button" data-pagou-copy="' . $id . '" aria-label="Copiar ' . $label . '">Copiar</button></div>';
            }
            $html .= '</div></article>';
        }
        return $html . '</section>';
    }

    private static function document(string $value): string
    {
        if (preg_match('/^[0-9]{11}$/D', $value) === 1) {
            return substr($value, 0, 3) . '.' . substr($value, 3, 3) . '.' . substr($value, 6, 3) . '-' . substr($value, 9);
        }
        if (preg_match('/^[0-9]{14}$/D', $value) === 1) {
            return substr($value, 0, 2) . '.' . substr($value, 2, 3) . '.' . substr($value, 5, 3) . '/' . substr($value, 8, 4) . '-' . substr($value, 12);
        }
        return $value;
    }
}
