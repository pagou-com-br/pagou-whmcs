<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit;

use Pagou\Whmcs\Application\Runtime\PixQrLink;
use PHPUnit\Framework\TestCase;

final class PixQrLinkTest extends TestCase
{
    private const ATTEMPT = '5cd50977-c577-45f3-9e2e-9ce2abf8caf8';

    public function testSignedAddressNamesOneChargeAndDeadline(): void
    {
        $link = new PixQrLink(str_repeat('k', 64));
        parse_str($link->query(self::ATTEMPT, 2000), $query);
        self::assertSame(self::ATTEMPT, $query['a']);
        self::assertTrue($link->valid($query['a'], $query['e'], $query['s'], 1000));
        // Another charge, a later deadline, an old address or another installation are refused.
        self::assertFalse($link->valid('6cd50977-c577-45f3-9e2e-9ce2abf8caf8', $query['e'], $query['s'], 1000));
        self::assertFalse($link->valid($query['a'], '3000', $query['s'], 1000));
        self::assertFalse($link->valid($query['a'], $query['e'], $query['s'], 2001));
        self::assertFalse((new PixQrLink(str_repeat('x', 64)))->valid($query['a'], $query['e'], $query['s'], 1000));
        foreach (['', '../attempt', self::ATTEMPT . "\n"] as $attempt) {
            self::assertFalse($link->valid($attempt, $query['e'], $query['s'], 1000));
        }
        self::assertFalse($link->valid($query['a'], '-1', $query['s'], 0));
    }

    public function testKeyComesFromTheWhmcsInstallationSecret(): void
    {
        $previous = $GLOBALS['cc_encryption_hash'] ?? null;
        try {
            unset($GLOBALS['cc_encryption_hash']);
            self::assertNull(PixQrLink::fromWhmcs());
            $GLOBALS['cc_encryption_hash'] = 'short';
            self::assertNull(PixQrLink::fromWhmcs());
            $GLOBALS['cc_encryption_hash'] = str_repeat('s', 40);
            $first = PixQrLink::fromWhmcs();
            self::assertNotNull($first);
            parse_str($first->query(self::ATTEMPT, 2000), $query);
            self::assertTrue(PixQrLink::fromWhmcs()->valid(self::ATTEMPT, '2000', $query['s'], 1000));
            // The address never carries the installation secret itself.
            self::assertStringNotContainsString(str_repeat('s', 40), $first->query(self::ATTEMPT, 2000));
        } finally {
            if ($previous === null) {
                unset($GLOBALS['cc_encryption_hash']);
            } else {
                $GLOBALS['cc_encryption_hash'] = $previous;
            }
        }
    }
}
