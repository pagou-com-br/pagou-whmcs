<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\InvoicePdf;

use Pagou\Whmcs\Infrastructure\Whmcs\PrivateStorage;
use Pagou\Whmcs\InvoicePdf\{IntegrationService, TemplateIntegration};
use PHPUnit\Framework\TestCase;

final class TemplateIntegrationTest extends TestCase
{
    private string $directory;
    private string $template;
    private TemplateIntegration $integration;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pagou-template-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/web/templates/custom', 0700, true);
        $this->template = $this->directory . '/web/templates/custom/invoicepdf.tpl';
        file_put_contents($this->template, "<?php\n// Merchant customization\n\$existingCalls++;\n");
        $this->integration = new TemplateIntegration($this->directory . '/web', new PrivateStorage($this->directory . '/private'));
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testExistingGatewayStillRunsAndRemovePreservesLaterMerchantEdits(): void
    {
        $before = file_get_contents($this->template);
        $state = $this->integration->inspect('custom');
        $this->integration->change('custom', $state['hash'], true);
        self::assertSame($before, str_replace(TemplateIntegration::snippet(), '', file_get_contents($this->template)));
        $existingCalls = 0;
        foreach (['pagouboleto', 'pagoupix', 'iugu', 'mercadopago', 'banktransfer'] as $paymentmodule) {
            include $this->template;
        }
        self::assertSame(5, $existingCalls);
        self::assertCount(1, glob($this->directory . '/private/pdf-template-backups/*/*.tpl'));
        file_put_contents($this->template, "\n// Later merchant edit\n", FILE_APPEND);
        $state = $this->integration->inspect('custom');
        $this->integration->change('custom', $state['hash'], false);
        self::assertSame($before . "\n// Later merchant edit\n", file_get_contents($this->template));
    }

    public function testRepeatedInstallationDoesNotDuplicateTheBridge(): void
    {
        foreach ([1, 2] as $_) {
            $state = $this->integration->inspect('custom');
            $this->integration->change('custom', $state['hash'], true);
        }
        self::assertSame(1, substr_count(file_get_contents($this->template), '// BEGIN PAGOU'));
    }

    public function testTemplateChangedSincePreviewIsNeverOverwritten(): void
    {
        $state = $this->integration->inspect('custom');
        file_put_contents($this->template, "\n// Concurrent edit", FILE_APPEND);
        $edited = file_get_contents($this->template);
        try {
            $this->integration->change('custom', $state['hash'], true);
            self::fail('Stale preview must be rejected.');
        } catch (\RuntimeException) {
            self::assertSame($edited, file_get_contents($this->template));
        }
    }

    public function testModifiedBridgeCannotBeRemovedOrOverwrittenAutomatically(): void
    {
        $state = $this->integration->inspect('custom');
        $this->integration->change('custom', $state['hash'], true);
        file_put_contents($this->template, str_replace('v1', 'v2', file_get_contents($this->template)));
        $this->expectException(\RuntimeException::class);
        $this->integration->inspect('custom');
    }

    public function testBridgeAfterAnEarlyReturnIsNotReportedAsInstalled(): void
    {
        file_put_contents($this->template, "<?php\nreturn;\n" . TemplateIntegration::snippet());
        $this->expectException(\RuntimeException::class);
        $this->integration->inspect('custom');
    }

    public function testStrictTypesAndOriginalCrLfBytesArePreserved(): void
    {
        $source = "<?php\r\ndeclare(strict_types=1);\r\n// Custom\r\n\$a=1;\r\n";
        file_put_contents($this->template, $source);
        $this->integration->change('custom', hash('sha256', $source), true);
        token_get_all(file_get_contents($this->template), TOKEN_PARSE);
        self::assertSame($source, str_replace(TemplateIntegration::snippet(), '', file_get_contents($this->template)));
    }

    public function testSymlinkAndTraversalCannotRedirectTheInstaller(): void
    {
        rename($this->template, $this->directory . '/original.tpl');
        symlink($this->directory . '/original.tpl', $this->template);
        foreach (['custom', '../custom'] as $theme) {
            try {
                $this->integration->inspect($theme);
                self::fail('Unsafe path accepted.');
            } catch (\RuntimeException) {
                self::assertFileExists($this->directory . '/original.tpl');
            }
        }
    }

    public function testChangedThemeRequiresNewPreviewAndIsDiagnosedAsNotIntegrated(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE tblconfiguration (setting TEXT, value TEXT)');
        $pdo->exec("INSERT INTO tblconfiguration VALUES ('Template','custom'),('EnablePDFInvoices','on')");
        $oldStorage = getenv('PAGOU_PRIVATE_STORAGE_DIR');
        putenv('PAGOU_PRIVATE_STORAGE_DIR=' . $this->directory . '/private');
        try {
            $service = new IntegrationService($pdo, $this->directory . '/web');
            $state = $service->inspect();
            self::assertFalse($service->usesNativeAttachment());
            $service->change('custom', $state['hash'], true);
            self::assertTrue($service->usesNativeAttachment());
            mkdir($this->directory . '/web/templates/newtheme');
            file_put_contents($this->directory . '/web/templates/newtheme/invoicepdf.tpl', "<?php\n// New theme\n");
            $pdo->exec("UPDATE tblconfiguration SET value='newtheme' WHERE setting='Template'");
            self::assertFalse($service->inspect()['installed']);
            self::assertFalse($service->usesNativeAttachment());
            $this->expectException(\RuntimeException::class);
            $service->change('custom', $state['hash'], false);
        } finally {
            putenv(is_string($oldStorage) ? 'PAGOU_PRIVATE_STORAGE_DIR=' . $oldStorage : 'PAGOU_PRIVATE_STORAGE_DIR');
        }
    }
}
