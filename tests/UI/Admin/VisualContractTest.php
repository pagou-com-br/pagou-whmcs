<?php

declare(strict_types=1);

namespace Pagou\Payments\Tests\UI\Admin;

use PHPUnit\Framework\TestCase;

final class VisualContractTest extends TestCase
{
    public function testAdminCssPreservesTheSharedBlueVisualContract(): void
    {
        $path = dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/assets/admin.css';
        $css = file_get_contents($path);

        self::assertIsString($css);
        self::assertStringContainsString('--pagou-accent:#2856d9', $css);
        self::assertStringContainsString('--pagou-radius:14px', $css);
        self::assertStringContainsString('.pagou-frame-header::before', $css);
        self::assertStringContainsString('.pagou-product-mark', $css);
        self::assertStringContainsString('.pagou-tab.is-active', $css);
        self::assertStringContainsString('.pagou-shell .btn-primary', $css);
        self::assertStringContainsString('.pagou-shell .btn{align-items:center;display:inline-flex', $css);
        self::assertStringNotContainsString('.pagou-shell .btn{align-items:center;border-radius:', $css);
        self::assertStringContainsString('.pagou-onboarding-steps', $css);
        self::assertStringContainsString('.pagou-diagnostic-list', $css);
        self::assertStringContainsString('.pagou-about-grid', $css);
        self::assertStringContainsString('.pagou-connection-grid', $css);
        self::assertStringContainsString('.pagou-settings-pair--identity', $css);
        self::assertStringContainsString('.pagou-settings-pair--operation', $css);
        self::assertStringContainsString('.pagou-dashboard-grid', $css);
        self::assertStringContainsString('grid-template-columns:minmax(0,2fr) minmax(300px,1fr)', $css);
        self::assertStringContainsString('.pagou-kpis', $css);
        self::assertStringContainsString('.pagou-health', $css);
        self::assertStringContainsString('.pagou-action-bar', $css);
        // Single chart series in the brand blue, with a validated lighter step for dark mode.
        self::assertStringContainsString('--pagou-chart:#2856d9', $css);
        self::assertStringContainsString('--pagou-chart:#6a8bf2', $css);
        self::assertStringContainsString('.pagou-trend-bar{', $css);
        self::assertStringNotContainsString('.pagou-dashboard-hero-grid', $css);
        self::assertStringContainsString('grid-template-rows:repeat(3,auto)', $css);
        self::assertStringNotContainsString('.pagou-dashboard-aside', $css);
        self::assertStringContainsString('.pagou-diagnostic-grid', $css);
        self::assertStringContainsString('.pagou-diagnostic-support-grid', $css);
        self::assertStringContainsString('@media(max-width:1100px)', $css);
        self::assertStringContainsString('html.dark .pagou-shell', $css);
        self::assertStringNotContainsString('linear-gradient(110deg,#123f6a,#1c76bb)', $css);
        self::assertStringNotContainsString('Arial,sans-serif', $css);
        self::assertStringNotContainsString('@media (prefers-color-scheme: dark)', $css);
    }
}
