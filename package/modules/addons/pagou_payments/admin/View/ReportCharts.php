<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\View;

use Pagou\Payments\Admin\Support\Html;
use Pagou\Payments\Admin\Support\ReportFormat as F;

/** Charts use the complete filtered projection, never just the current table page. */
final class ReportCharts
{
    /** @param array<string,mixed> $report */
    public function render(array $report, string $kind): string
    {
        [$title, $description, $empty] = match ($kind) {
            'open' => ['Saldo por vencimento e atraso', 'Saldo atual das faturas, após créditos e pagamentos registrados.', 'Nenhuma fatura em aberto corresponde aos filtros.'],
            'attempts' => ['Cobranças e tentativas por situação', 'Quantidade de tentativas no período, incluindo substituições e cancelamentos.', 'Nenhuma tentativa corresponde aos filtros.'],
            'refunds' => ['Devoluções por situação', 'Valor das devoluções no período, agrupado pela situação atual.', 'Nenhuma devolução corresponde aos filtros.'],
            'pending' => ['Pendências por tipo', 'Quantidade de pendências abertas agora, incluindo registros sem valor identificado.', 'Nenhuma pendência aberta corresponde aos filtros.'],
            default => ['Recebimentos por dia', 'Valores brutos conciliados, por dia do pagamento. Horário de São Paulo.', 'Nenhum recebimento conciliado no período e nos filtros escolhidos.'],
        };
        $html = '<section class="pagou-card pagou-report-chart"><div class="pagou-card-title"><div><h2>'
            . Html::e($title) . '</h2><p>' . Html::e($description) . '</p></div></div>';
        if ((int) ($report['total'] ?? 0) === 0) {
            return $html . '<p class="pagou-empty-state">' . Html::icon('inbox', 18) . Html::e($empty) . '</p></section>';
        }
        $summary = $report['summary'];
        if ($kind === 'receipts') {
            $days = $report['daily'] ?? [];
            $content = $days === [] ? $this->unavailable() : (new TrendChart())->render($days, 'Recebimentos diários do período filtrado.');
        } else {
            $series = [];
            if ($kind === 'open') {
                foreach ($summary['buckets'] ?? [] as $label => $bucket) {
                    $series[] = ['label' => (string) $label, 'value' => (int) $bucket['amount'], 'count' => (int) $bucket['count']];
                }
            } elseif ($kind === 'refunds') {
                foreach ($summary['statuses'] ?? [] as $key => $count) {
                    $series[] = ['label' => F::label((string) $key), 'value' => (int) ($summary['statusAmounts'][$key] ?? 0), 'count' => (int) $count];
                }
                usort($series, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);
            } else {
                $counts = [];
                foreach ($summary[$kind === 'attempts' ? 'statuses' : 'types'] ?? [] as $key => $count) {
                    $label = F::label((string) $key);
                    $counts[$label] = ($counts[$label] ?? 0) + (int) $count;
                }
                ksort($counts);
                arsort($counts);
                foreach ($counts as $label => $count) {
                    $series[] = ['label' => $label, 'value' => $count, 'count' => $count];
                }
            }
            $content = $series === [] ? $this->unavailable() : $this->bars($series, $kind);
        }

        return $html . $content . '<p class="pagou-card-caption">Considera todos os registros dos filtros, em todas as páginas da tabela.</p></section>';
    }

    /** @param list<array{label:string,value:int,count:int}> $series */
    private function bars(array $series, string $kind): string
    {
        $scale = max(1, max(array_column($series, 'value')));
        $unit = match ($kind) {
            'open' => 'fatura(s)', 'attempts' => 'tentativa(s)', 'refunds' => 'devolução(ões)', default => 'pendência(s)',
        };
        $money = in_array($kind, ['open', 'refunds'], true);
        $html = '<ul class="pagou-report-bars">';
        foreach ($series as $point) {
            $width = max(0, min(100, round($point['value'] / $scale * 100, 2)));
            $value = $money ? F::money($point['value']) : number_format($point['value'], 0, ',', '.');
            $detail = ($money ? number_format($point['count'], 0, ',', '.') . ' ' : '') . $unit;
            $html .= '<li><span class="pagou-report-bar-label">' . Html::e($point['label']) . '</span>'
                . '<span class="pagou-meter" aria-hidden="true"><span style="width:' . $width . '%"></span></span>'
                . '<span class="pagou-report-bar-value"><strong>' . Html::e($value) . '</strong><small>' . Html::e($detail) . '</small></span></li>';
        }

        return $html . '</ul>';
    }

    private function unavailable(): string
    {
        return '<p class="pagou-empty-state">' . Html::icon('alert', 18) . 'Resumo visual indisponível nesta consulta.</p>';
    }
}
