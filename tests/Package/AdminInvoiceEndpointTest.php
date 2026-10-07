<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Package;

use PHPUnit\Framework\TestCase;

final class AdminInvoiceEndpointTest extends TestCase
{
    public function testNativeManageInvoicePermissionAllowsReplacementAndImmediateProgress(): void
    {
        $response = $this->request([]);
        self::assertSame(200, $response['http']);
        self::assertSame('ok', $response['body']['status']);
        self::assertSame([['invoice' => 406689, 'replace' => true], ['advance' => 406689]], $response['effects']);
    }

    public function testUnauthorizedActorsAndInvalidRequestsNeverProduceFinancialEffects(): void
    {
        foreach (
            [
            [['anonymous' => true], 403],
            [['disabled' => true], 403],
            [['module' => false], 403],
            [['permissions' => ['View Invoice']], 403],
            [['permissions' => []], 403],
            [['input' => ['token' => 'incorrect']], 400],
            [['input' => ['expected_attempt' => 'obsolete']], 409],
            ] as [$scenario, $http]
        ) {
            $response = $this->request($scenario);
            self::assertSame($http, $response['http'], json_encode($scenario));
            self::assertSame([], $response['effects']);
        }
    }

    public function testAuthenticatedGetRemainsReadOnly(): void
    {
        $response = $this->request(['method' => 'GET']);
        self::assertSame(200, $response['http']);
        self::assertSame([], $response['effects']);
        self::assertStringContainsString('Gerar novo boleto', $response['body']['html']);
    }

    public function testNativeRefundEndpointRequiresAllPermissionsAndCsrfAndNeverAcceptsInitiation(): void
    {
        $base = ['permissions' => ['Manage Invoice', 'Refund Invoice Payments'], 'input' => ['action' => 'refresh']];
        foreach (
            [['permissions' => ['Manage Invoice']], ['module' => false], ['disabled' => true], ['anonymous' => true],
            ['input' => ['action' => 'refresh', 'token' => 'invalid']], ['input' => ['action' => 'request']],
            ['input' => ['action' => 'refresh', 'transaction_id' => 'invalid']]] as $override
        ) {
            $response = $this->request(array_replace($base, $override), 'native-refund.php');
            self::assertNotSame(200, $response['http']);
            self::assertSame([], $response['effects']);
        }
        $response = $this->request($base, 'native-refund.php');
        self::assertSame(200, $response['http']);
        self::assertSame([['refresh' => 406689, 'account' => 22, 'action' => 'refresh']], $response['effects']);
        $response = $this->request($base + ['method' => 'GET'], 'native-refund.php');
        self::assertSame([], $response['effects']);
        self::assertTrue($response['body']['supported']);
    }

    /** @param array<string,mixed> $scenario @return array<string,mixed> */
    private function request(array $scenario, string $endpoint = 'invoice-action.php'): array
    {
        $root = dirname(__DIR__, 2);
        $temporary = sys_get_temp_dir() . '/pagou-admin-endpoint-' . bin2hex(random_bytes(6));
        mkdir($temporary . '/modules/addons/pagou_payments', 0700, true);
        mkdir($temporary . '/modules/gateways/pagou', 0700, true);
        copy($root . '/tests/Fixtures/Whmcs/admin-invoice-endpoint.php', $temporary . '/init.php');
        copy($root . '/package/modules/addons/pagou_payments/' . $endpoint, $temporary . '/modules/addons/pagou_payments/' . $endpoint);
        file_put_contents($temporary . '/modules/gateways/pagou/bootstrap.php', '<?php require ' . var_export($root . '/package/modules/gateways/pagou/bootstrap.php', true) . ';');
        try {
            $process = proc_open([PHP_BINARY, $temporary . '/modules/addons/pagou_payments/' . $endpoint, json_encode($scenario, JSON_THROW_ON_ERROR)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temporary, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($temporary);
        }
    }
}
