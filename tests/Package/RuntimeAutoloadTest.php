<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Package;

use Pagou\Payments\Admin\Controller;
use PHPUnit\Framework\TestCase;

final class RuntimeAutoloadTest extends TestCase
{
    public function testRuntimeBootstrapCanBeLoadedWithoutVendor(): void
    {
        $bootstrap = dirname(__DIR__, 2) . '/package/modules/gateways/pagou/bootstrap.php';

        require_once $bootstrap;

        self::assertTrue(defined('PAGOU_WHMCS_AUTOLOADER_REGISTERED'));
        self::assertTrue(class_exists(Controller::class));
    }

    public function testAddonNamespaceUsesTheLowercaseWhmcsAdminDirectory(): void
    {
        $bootstrap = (string) file_get_contents(
            dirname(__DIR__, 2) . '/package/modules/gateways/pagou/bootstrap.php',
        );

        self::assertStringContainsString(
            "'Pagou\\\\Payments\\\\Admin\\\\' => dirname(__DIR__, 2) . '/addons/pagou_payments/admin/'",
            $bootstrap,
        );
    }
}
