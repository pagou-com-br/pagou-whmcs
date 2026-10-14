<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Package;

use PHPUnit\Framework\TestCase;

final class ClientDocumentEndpointTest extends TestCase
{
    public function testTheSignedInClientSavesTheDocumentOfTheInvoiceShown(): void
    {
        $response = $this->request([]);

        self::assertSame(200, $response['http']);
        self::assertSame(['status' => 'ok'], $response['body']);
        self::assertSame([['invoice' => 10, 'method' => 'pix', 'client' => 20, 'document' => '529.982.247-25']], $response['effects']);
    }

    public function testAnonymousForgedOrReadRequestsNeverReachTheProfile(): void
    {
        foreach ([[['anonymous' => true], 403], [['input' => ['token' => 'forged']], 403], [['input' => ['invoice' => '11']], 403], [['method' => 'GET'], 405]] as [$scenario, $http]) {
            $response = $this->request($scenario);
            self::assertSame($http, $response['http'], json_encode($scenario));
            self::assertSame([], $response['effects']);
        }
    }

    public function testARefusalKeepsItsReasonAndAPlainFormReturnsToTheInvoice(): void
    {
        $refused = $this->request(['result' => ['status' => 'invalid', 'message' => 'Este CPF ou CNPJ não é válido. Confira os números e tente de novo.']]);
        self::assertSame(422, $refused['http']);
        self::assertSame('invalid', $refused['body']['status']);

        // Without the page script the browser posts the form and comes back to the invoice.
        $plain = $this->request(['accept' => 'text/html']);
        self::assertSame(303, $plain['http']);
        self::assertCount(1, $plain['effects']);
    }

    /** @param array<string,mixed> $scenario @return array<string,mixed> */
    private function request(array $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $temporary = sys_get_temp_dir() . '/pagou-document-endpoint-' . bin2hex(random_bytes(6));
        mkdir($temporary . '/modules/addons/pagou_payments', 0700, true);
        mkdir($temporary . '/modules/gateways/pagou', 0700, true);
        copy($root . '/tests/Fixtures/Whmcs/client-document-endpoint.php', $temporary . '/init.php');
        copy($root . '/package/modules/addons/pagou_payments/document.php', $temporary . '/modules/addons/pagou_payments/document.php');
        file_put_contents($temporary . '/modules/gateways/pagou/bootstrap.php', '<?php require ' . var_export($root . '/package/modules/gateways/pagou/bootstrap.php', true) . ';');
        try {
            $process = proc_open([PHP_BINARY, $temporary . '/modules/addons/pagou_payments/document.php', json_encode($scenario, JSON_THROW_ON_ERROR)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);

            return json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temporary, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($temporary);
        }
    }
}
