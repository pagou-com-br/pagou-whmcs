<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\View;

use Pagou\Payments\Admin\Support\Html;
use Pagou\Payments\Admin\Support\ReportFormat as F;

/** Daily series shared by the overview and filtered reports. */
final class TrendChart
{
    private const WEEKDAYS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

    /** @param list<array{date:string,amount:int,count:int}> $days */
    public function render(array $days, string $description, bool $endsToday = false): string
    {
        if ($days === []) {
            return '';
        }
        $max = max(array_column($days, 'amount'));
        $scale = $this->niceCeiling($max);
        $peak = $days[0];
        $bars = '';
        $rows = '';
        $last = count($days) - 1;
        foreach ($days as $index => $day) {
            if ($day['amount'] > $peak['amount']) {
                $peak = $day;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day['date']) ?: new \DateTimeImmutable($day['date']);
            $label = self::WEEKDAYS[(int) $date->format('w')] . ', ' . $date->format('d/m/Y');
            $height = $day['amount'] > 0 ? max(1.5, round($day['amount'] / $scale * 100, 2)) : 0;
            $bars .= '<li class="' . trim(($day['amount'] > 0 ? '' : 'is-empty') . ($endsToday && $index === $last ? ' is-today' : '')) . '"'
                . ' data-label="' . Html::e($endsToday && $index === $last ? 'Hoje, ' . $date->format('d/m/Y') : $label) . '"'
                . ' data-value="' . Html::e(F::money($day['amount'])) . '" data-count="' . (int) $day['count'] . ' recebimento(s)">'
                . '<span class="pagou-trend-bar" style="height:' . $height . '%"></span></li>';
            $rows .= '<tr><td>' . Html::e($label) . '</td><td>' . (int) $day['count'] . '</td><td>' . Html::e(F::money($day['amount'])) . '</td></tr>';
        }
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $days[0]['date']);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $days[$last]['date']);
        $middle = \DateTimeImmutable::createFromFormat('!Y-m-d', $days[intdiv($last, 2)]['date']);
        $axisFormat = substr($days[0]['date'], 0, 4) === substr($days[$last]['date'], 0, 4) ? 'd/m' : 'd/m/y';
        $peakDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $peak['date']);
        $summary = $description . ' Maior valor em '
            . ($peakDate instanceof \DateTimeImmutable ? $peakDate->format('d/m') : $peak['date']) . ': ' . F::money($peak['amount'])
            . '. Use as setas para percorrer os dias.';

        return '<figure class="pagou-trend" data-pagou-trend>'
            . '<div class="pagou-trend-plot" tabindex="0" role="group" aria-label="' . Html::e($summary) . '">'
            . '<div class="pagou-trend-grid" aria-hidden="true">'
            . '<span style="bottom:100%"><em>' . Html::e(F::compactMoney($scale)) . '</em></span>'
            . '<span style="bottom:50%"><em>' . Html::e(F::compactMoney(intdiv($scale, 2))) . '</em></span>'
            . '<span style="bottom:0"><em>R$ 0</em></span></div>'
            . '<ol class="pagou-trend-bars' . (count($days) > 90 ? ' is-dense' : '') . '" aria-hidden="true">' . $bars . '</ol>'
            . '<div class="pagou-trend-tooltip" role="status" hidden></div></div>'
            . '<div class="pagou-trend-axis" aria-hidden="true"><span>'
            . ($first instanceof \DateTimeImmutable ? $first->format($axisFormat) : '') . '</span>'
            . ($last > 1 ? '<span>' . ($middle instanceof \DateTimeImmutable ? $middle->format($axisFormat) : '') . '</span>' : '')
            . ($last > 0 ? '<span>' . ($endsToday ? 'Hoje' : ($end instanceof \DateTimeImmutable ? $end->format($axisFormat) : '')) . '</span>' : '') . '</div>'
            . '<details class="pagou-trend-table"><summary>Ver os valores em tabela</summary><div class="pagou-table-wrap">'
            . '<table class="pagou-table"><thead><tr><th scope="col">Dia</th><th scope="col">Recebimentos</th><th scope="col">Valor conciliado</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></details></figure>';
    }

    /** Rounds the axis up to 1, 2, 2,5 or 5 times a power of ten, in cents. */
    private function niceCeiling(int $cents): int
    {
        if ($cents <= 100) {
            return 100;
        }
        $magnitude = 10 ** (int) floor(log10($cents));
        foreach ([1, 2, 2.5, 5, 10] as $step) {
            if ($cents <= $step * $magnitude) {
                return (int) ($step * $magnitude);
            }
        }

        return 10 * $magnitude;
    }
}
