<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class RuntimePackageTest extends TestCase
{
    public function testRuntimeSourceDoesNotRequireComposerVendorDirectory(): void
    {
        $modules = dirname(__DIR__, 2) . '/package/modules';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($modules));

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            self::assertStringNotContainsString(
                'vendor/autoload',
                (string) file_get_contents($file->getPathname()),
                $file->getPathname(),
            );
        }
    }

    public function testRuntimeSourceAvoidsPhp82ReadonlyClassSyntax(): void
    {
        $modules = dirname(__DIR__, 2) . '/package/modules';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($modules));

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            self::assertDoesNotMatchRegularExpression(
                '/\breadonly\s+class\b/',
                (string) file_get_contents($file->getPathname()),
                $file->getPathname(),
            );
        }
    }
}
