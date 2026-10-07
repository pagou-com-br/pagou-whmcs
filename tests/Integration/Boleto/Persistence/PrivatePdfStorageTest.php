<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Boleto\Persistence;

use Pagou\Whmcs\Infrastructure\Whmcs\PrivateStorage;
use Pagou\Whmcs\Payment\Boleto\Infrastructure\PrivatePdfStorage;
use PHPUnit\Framework\TestCase;

final class PrivatePdfStorageTest extends TestCase
{
    public function testItStoresValidatedPdfOutsideTheProvidedWebRoot(): void
    {
        $directory = sys_get_temp_dir() . '/pagou-boleto-' . bin2hex(random_bytes(5));
        try {
            $storage = new PrivatePdfStorage(new PrivateStorage($directory, $directory . '-web'));
            self::assertSame('boleto/charge-1.pdf', $storage->put('boleto/charge-1.pdf', '%PDF-1.7 test', 'application/pdf'));
            self::assertTrue($storage->has('boleto/charge-1.pdf'));
            self::assertFileDoesNotExist($directory . '-web/boleto/charge-1.pdf');
        } finally {
            if (is_file($directory . '/boleto/charge-1.pdf')) {
                unlink($directory . '/boleto/charge-1.pdf');
            }
            if (is_dir($directory . '/boleto')) {
                rmdir($directory . '/boleto');
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
