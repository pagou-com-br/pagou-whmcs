<?php

declare(strict_types=1);

namespace Pagou\Payments\Tests\UI\Admin;

use Pagou\Payments\Admin\Support\Html;
use PHPUnit\Framework\TestCase;

final class HtmlTest extends TestCase
{
    public function testEscapesTagsAndQuotes(): void
    {
        self::assertSame('&lt;strong&gt;&quot;x&quot;&lt;/strong&gt;', Html::e('<strong>"x"</strong>'));
    }

    public function testNormalizesUnknownBadgeTone(): void
    {
        self::assertStringContainsString('pagou-badge--neutral', Html::badge('Teste', 'unexpected'));
    }

    public function testRendersOnlyKnownInlineIcons(): void
    {
        $icon = Html::icon('dashboard', 20);

        self::assertStringContainsString('class="pagou-icon"', $icon);
        self::assertStringContainsString('width="20"', $icon);
        self::assertStringContainsString('aria-hidden="true"', $icon);
        self::assertStringContainsString('<rect', Html::icon('qr-code'));
        self::assertStringContainsString('<path', Html::icon('barcode'));
        self::assertSame('', Html::icon('unexpected'));
    }
}
