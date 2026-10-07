<?php

declare(strict_types=1);

namespace Pagou\Payments\Admin\View;

use Pagou\Payments\Admin\Support\Html;

/** One identifier row of the invoice card: label, monospaced value and a compact copy button. */
final class CopyField
{
    public static function render(string $label, string $value, string $id): string
    {
        if ($value === '') {
            return '';
        }

        return '<div class="pagou-invoice-id"><span>' . Html::e($label) . '</span>'
            . '<code id="' . Html::e($id) . '" title="' . Html::e($value) . '">' . Html::e($value) . '</code>'
            . '<button type="button" class="pagou-invoice-copy" data-pagou-admin-copy="' . Html::e($id) . '" aria-label="Copiar ' . Html::e($label) . '" title="Copiar">'
            . Html::icon('copy', 15) . Html::icon('check', 15) . '</button></div>';
    }
}
