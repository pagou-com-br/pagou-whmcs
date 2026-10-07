<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Configuration;

use Pagou\Whmcs\Configuration\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class CapabilityRegistryTest extends TestCase
{
    public function testSplitIsDisabledByDefault(): void
    {
        $registry = new CapabilityRegistry();

        self::assertTrue($registry->isEnabled('pix'));
        self::assertTrue($registry->isEnabled('boleto'));
        self::assertTrue($registry->isEnabled('split_projection'));
        self::assertFalse($registry->isEnabled('split'));
        self::assertFalse($registry->isEnabled('split_card'));
    }

    public function testCardSplitCannotBeEnabledWithoutSplit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CapabilityRegistry(['split_card' => true]);
    }
}
