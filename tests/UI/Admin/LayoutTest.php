<?php

declare(strict_types=1);

namespace Pagou\Payments\Tests\UI\Admin;

use Pagou\Payments\Admin\View\Layout;
use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    public function testUsesThePagouVisualStructure(): void
    {
        $html = (new Layout())->render('<p>Conteúdo</p>', [
            'page' => 'settings',
            'title' => 'Título variável que não deve substituir o produto',
        ]);

        self::assertStringContainsString('class="pagou-admin pagou-shell"', $html);
        self::assertStringContainsString('class="pagou-frame"', $html);
        self::assertStringContainsString('class="pagou-frame-header"', $html);
        self::assertStringContainsString('class="pagou-product-mark"', $html);
        self::assertStringContainsString('<h2>Pagou para WHMCS</h2>', $html);
        self::assertStringContainsString('Pagamentos, conciliação e operação financeira em um único lugar.', $html);
        self::assertStringContainsString('<svg class="pagou-brand-logo" role="img" aria-label="Pagou"', $html);
        // System pages sit in their own compact group at the end of the tab row.
        self::assertMatchesRegularExpression('#<span class="pagou-tabs-system"><a class="pagou-tab pagou-tab--system is-active" href="[^"]*view=settings" aria-current="page" title="Configurações">#', $html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringContainsString('class="pagou-icon"', $html);
        self::assertMatchesRegularExpression('/admin\\.css\\?v=[a-f0-9]{12}/', $html);
        self::assertMatchesRegularExpression('/admin\\.js\\?v=[a-f0-9]{12}/', $html);
        self::assertStringNotContainsString('Módulo administrativo', $html);
        self::assertStringNotContainsString('Título variável que não deve substituir o produto', $html);
    }

    public function testEscapesNoticeContentAndTone(): void
    {
        $html = (new Layout())->render('', [
            'notice' => '<script>alert(1)</script>',
            'noticeTone' => 'info" onclick="alert(2)',
        ]);

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('pagou-notice--info', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('onclick=', $html);
    }

    public function testIncludesThePublicSafeAboutTab(): void
    {
        $html = (new Layout())->render('', ['page' => 'about']);

        self::assertStringContainsString('view=about', $html);
        self::assertStringContainsString('>Sobre</a>', $html);
        self::assertStringContainsString('class="pagou-tab pagou-tab--system is-active"', $html);
    }

    public function testActivityGroupsOperationsNotificationsAndReconciliation(): void
    {
        foreach (['operations' => 'Operações', 'webhooks' => 'Notificações', 'reconciliation' => 'Conciliação'] as $page => $label) {
            $html = (new Layout())->render('', ['page' => $page]);
            self::assertMatchesRegularExpression('#<a class="pagou-tab is-active" href="[^"]*view=operations" aria-current="page"><svg[^>]*>.*?</svg>Atividade</a>#s', $html);
            self::assertStringContainsString('aria-label="Seções da atividade"', $html);
            self::assertMatchesRegularExpression('#<a class="pagou-tab pagou-subtab is-active" href="[^"]*view=' . $page . '" aria-current="page"><svg[^>]*>.*?</svg>' . $label . '</a>#s', $html);
            self::assertSame(1, substr_count($html, 'pagou-tab is-active'));
        }
        $other = (new Layout())->render('', ['page' => 'payments']);
        self::assertStringNotContainsString('Seções da atividade', $other);
        foreach (['view=webhooks', 'view=reconciliation'] as $hidden) {
            self::assertStringNotContainsString($hidden, $other);
        }
    }

    public function testShowsOpenFindingsCountBesideTheirTab(): void
    {
        $html = (new Layout())->render('', ['page' => 'dashboard', 'badges' => ['findings' => 3, 'card' => 0]]);

        self::assertMatchesRegularExpression('#view=findings"><svg[^>]*>.*?</svg>Pendências<span class="pagou-tab-count"[^>]*><span class="pagou-sr-only">, </span>3<#s', $html);
        self::assertSame(1, substr_count($html, 'pagou-tab-count'));
        self::assertStringNotContainsString('data-pagou-density', $html);
        self::assertStringNotContainsString('data-pagou-share', $html);
    }
}
