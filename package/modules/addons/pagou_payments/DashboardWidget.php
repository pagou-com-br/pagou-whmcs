<?php

declare(strict_types=1);

namespace WHMCS\Module\Widget;

use Pagou\Payments\Admin\Widget\AccountSummary;
use Pagou\Payments\Admin\Widget\View;

final class PagouOfficialSummary extends \WHMCS\Module\AbstractWidget
{
    protected $title = 'Pagou, resumo financeiro';
    protected $description = 'Saldo e recebimentos da conta Pagou, com pendências deste módulo.';
    protected $weight = 155;
    protected $columns = 1;
    protected $cache = false;
    protected $requiredPermission = 'View Income Totals';
    private bool $refresh = false;

    public static function allowed(): bool
    {
        return AccountSummary::allowed();
    }

    public function render($forceRefresh = false)
    {
        if (!self::allowed()) {
            unset($_SESSION['pagou_dashboard_snapshot']);
            return '';
        }
        $this->refresh = (bool) $forceRefresh;
        return parent::render($forceRefresh);
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        if (!self::allowed()) {
            return [];
        }

        return AccountSummary::read($this->refresh);
    }

    public function generateOutput($data): string
    {
        return self::allowed() ? (new View())->render(is_array($data) ? $data : []) : '';
    }
}
