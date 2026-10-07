<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Domain;

use Pagou\Whmcs\Domain\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function testItRemovesNestedSensitiveData(): void
    {
        self::assertSame(
            ['token' => '[redacted]', 'request' => ['cvv' => '[redacted]', 'reference' => 'safe']],
            Redactor::context(['token' => 'do-not-log', 'request' => ['cvv' => '123', 'reference' => 'safe']]),
        );
    }
}
