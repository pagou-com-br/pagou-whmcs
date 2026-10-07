<?php

declare(strict_types=1);

namespace Pagou\Payments\Tests\UI\Admin;

use WHMCS\Module\Widget\PagouOfficialSummary as DashboardWidget;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WHMCS\User\Admin;

#[RunTestsInSeparateProcesses]
final class DashboardWidgetTest extends TestCase
{
    protected function setUp(): void
    {
        require dirname(__DIR__, 2) . '/Fixtures/Widget/WhmcsRuntime.php';
        require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/DashboardWidget.php';
        Admin::$current = new Admin();
    }

    public function testDeniedAccessNeverReturnsCachedFinancialData(): void
    {
        foreach (['finance', 'module', 'disabled', 'anonymous'] as $denial) {
            Admin::$current = new Admin();
            if ($denial === 'anonymous') {
                Admin::$current = null;
            } elseif ($denial === 'disabled') {
                Admin::$current->isDisabled = true;
            } else {
                Admin::$current->$denial = false;
            }
            $_SESSION = ['adminid' => 1, 'pagou_dashboard_snapshot' => ['balance' => ['value' => ['available' => 999999]]]];
            $widget = new DashboardWidget();
            self::assertFalse(DashboardWidget::allowed());
            self::assertSame([], $widget->getData());
            self::assertSame('', $widget->generateOutput(['environment' => 'PRIVATE']));
            self::assertSame('', $widget->render(true));
            self::assertArrayNotHasKey('pagou_dashboard_snapshot', $_SESSION);
        }
    }

    public function testAuthorizedOutputContainsFinancialSummaryAndScopedLinks(): void
    {
        self::assertTrue(DashboardWidget::allowed());
        $data = [
            'configured' => true, 'environment' => 'Produção',
            'balance' => ['value' => ['available' => 137735, 'held' => 2000], 'asOf' => 1789671600],
            'summary' => ['value' => ['today_amount' => 15000, 'today_count' => 2, 'month_amount' => 85000, 'month_count' => 10,
                'day' => '2026-09-17', 'month' => '2026-09-01'], 'asOf' => 1789671600],
            'findings' => ['value' => ['pending' => 3]],
        ];
        $html = (new DashboardWidget())->generateOutput($data);
        self::assertStringContainsString('R$ 1.377,35', $html);
        self::assertStringContainsString('Valor retido: R$ 20,00', $html);
        self::assertStringContainsString('R$ 150,00', $html);
        self::assertStringContainsString('3 pendência(s)', $html);
        self::assertStringContainsString('Recebimentos da conta Pagou', $html);
        self::assertStringNotContainsString('report=receipts', $html);
        self::assertStringContainsString('Relatórios do módulo', $html);
        self::assertStringNotContainsString('<script', $html);
    }
}
