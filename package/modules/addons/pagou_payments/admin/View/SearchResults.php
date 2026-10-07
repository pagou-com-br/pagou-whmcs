<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\View;

use Pagou\Payments\Admin\Support\Html;
use Pagou\Payments\Admin\Support\ReportFormat as F;

/** Results of the header search. Every result opens the invoice history. */
final class SearchResults
{
    private const NAME_FIELDS = ['Nome do cliente', 'Nome na emissão'];

    /** @param array<string,mixed> $data */
    public function render(array $data): string
    {
        $search = is_array($data['search'] ?? null) ? $data['search'] : [];
        $term = (string) ($search['term'] ?? '');
        $results = is_array($search['results'] ?? null) ? $search['results'] : [];
        $html = '<section class="pagou-card pagou-intro pagou-toolbar-card"><div><span class="pagou-eyebrow">BUSCA</span><h2>'
            . ($term === '' ? 'Buscar no módulo' : 'Resultados para “' . Html::e($term) . '”') . '</h2>'
            . '<p>Pesquise pelo número da fatura, nome do cliente, CPF ou CNPJ, ID Pagou da cobrança ou do recebimento, ou E2E do Pix.</p></div></section>';
        if (is_string($search['error'] ?? null)) {
            return $html . '<div class="pagou-notice pagou-notice--warning" role="status">' . Html::e($search['error']) . '</div>';
        }
        if ($term === '') {
            return $html . '<p class="pagou-report-note">Digite o termo no campo de busca do cabeçalho. Atalho: tecla /.</p>';
        }
        if ($results === []) {
            return $html . '<section class="pagou-card"><p class="pagou-empty-state">' . Html::icon('inbox', 18)
                . 'Nenhuma fatura com registros neste módulo corresponde à busca. Faturas de outros meios de pagamento não aparecem aqui.</p></section>';
        }
        $items = '';
        foreach ($results as $result) {
            $items .= $this->item($result);
        }
        $single = count($results) === 1 && array_diff($results[0]['matched'] ?? [], self::NAME_FIELDS) !== [];

        return $html . ($single
                ? '<a class="pagou-sr-only" data-pagou-auto-open href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=' . (int) $results[0]['invoice'] . '">Abrir a fatura encontrada</a>'
                : '')
            . '<section class="pagou-card pagou-table-card"><div class="pagou-card-title"><div><h2>' . count($results) . ' fatura(s) encontrada(s)</h2>'
            . '<p>Ordenadas da fatura mais recente para a mais antiga.' . (($search['more'] ?? false) === true ? ' Mostrando as 20 primeiras; refine a busca para ver outras.' : '') . '</p></div></div>'
            . '<ul class="pagou-search-results">' . $items . '</ul></section>';
    }

    /** @param array<string,mixed> $result */
    private function item(array $result): string
    {
        $id = (int) $result['invoice'];
        $customer = (string) $result['customer'] !== '' ? (string) $result['customer'] : ((int) $result['client'] > 0 ? 'Cliente #' . (int) $result['client'] : 'Fatura indisponível no WHMCS');
        $meta = [Html::e($customer)];
        if ((string) $result['method'] !== '') {
            $meta[] = Html::e(F::label((string) $result['method'])) . ': ' . Html::e(F::label((string) $result['attemptStatus']));
        }
        if (is_string($result['total'] ?? null)) {
            try {
                $meta[] = 'Total ' . Html::e(F::money(\Pagou\Whmcs\Domain\Money::fromDecimal($result['total'])->centavos()));
            } catch (\Throwable) {
                // An unexpected native total is omitted rather than guessed.
            }
        }
        if ((string) $result['dueDate'] !== '') {
            $meta[] = 'Vencimento ' . Html::e(F::date((string) $result['dueDate']));
        }
        $status = (string) $result['invoiceStatus'];
        $label = $status === '' ? '' : F::label($status);

        return '<li><a class="pagou-search-result" href="addonmodules.php?module=pagou_payments&amp;view=charge&amp;invoice=' . $id . '">'
            . '<span class="pagou-search-title"><strong>Fatura #' . $id . '</strong>' . ($label === '' ? '' : Html::badge($label, $status === 'Unpaid' ? 'warning' : Page::tone($label))) . '</span>'
            . '<span class="pagou-search-meta">' . implode('<span aria-hidden="true"> · </span>', $meta) . '</span>'
            . '<span class="pagou-search-match">Encontrada por: ' . Html::e(implode(', ', array_map('strval', $result['matched'] ?? []))) . '</span>'
            . '</a></li>';
    }
}
