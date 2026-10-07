<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Package;

use PHPUnit\Framework\TestCase;

final class CardCallbackTest extends TestCase
{
    public function testReplayedOrUnauthorizedCallbackCannotFailTheActiveSession(): void
    {
        foreach ([['status' => 'processing'], ['status' => 'processing', 'bad_secret' => true]] as $scenario) {
            $result = $this->request($scenario);
            self::assertSame('processing', $result['session']['status']);
            self::assertSame([], $result['effects']);
        }
        $result = $this->request(['status' => 'completed']);
        self::assertSame([], $result['effects']);
        self::assertStringContainsString('https:\/\/merchant.example\/cliente\/viewinvoice.php?id=19', $result['body']);
        self::assertStringNotContainsString('failed-redirect', $result['body']);
    }

    public function testSavingTheCardCannotHideAConfirmedPayment(): void
    {
        $result = $this->request(['save_failure' => true]);
        self::assertSame(['register', 'charge', 'save-invoice-card', 'reconcile'], $result['effects']);
        self::assertSame('paid-redirect', $result['body']);
        self::assertTrue(json_decode($result['session']['result_json'], true)['paid']);
    }

    public function testCreateAndUpdateUseNativeStorageWithoutCharging(): void
    {
        $created = $this->request(['workflow' => 'create']);
        self::assertSame(['register'], $created['effects']);
        self::assertCount(2, $created['native']);
        self::assertSame('completed', $created['session']['status']);
        $updated = $this->request(['workflow' => 'update']);
        self::assertSame(['register', 'delete'], $updated['effects']);
        self::assertSame('new', $updated['native'][0]['reference']);
        self::assertSame('completed', $updated['session']['status']);
    }

    public function testStaleOrFailedReplacementPreservesTheOldCard(): void
    {
        $stale = $this->request(['workflow' => 'update', 'stale' => true]);
        self::assertSame([], $stale['effects']);
        self::assertSame('old', $stale['native'][0]['reference']);
        $failed = $this->request(['workflow' => 'update', 'save_failure' => true]);
        self::assertSame(['register'], $failed['effects']);
        self::assertSame('old', $failed['native'][0]['reference']);
        self::assertSame('failed', $failed['session']['status']);
    }

    public function testUncertainStorageDoesNotClaimPaymentOrCardSuccess(): void
    {
        $result = $this->request(['workflow' => 'create', 'uncertain' => true]);
        self::assertSame(['register'], $result['effects']);
        self::assertStringContainsString('Confirmação pendente', $result['body']);
        self::assertStringNotContainsString('Cartão salvo', $result['body']);
        self::assertSame('create', json_decode($result['session']['result_json'], true)['workflow']);
    }

    /** @param array<string,mixed> $scenario @return array<string,mixed> */
    private function request(array $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $temporary = sys_get_temp_dir() . '/pagou-card-callback-' . bin2hex(random_bytes(6));
        mkdir($temporary . '/modules/gateways/callback', 0700, true);
        mkdir($temporary . '/modules/gateways/pagou', 0700, true);
        copy($root . '/tests/Fixtures/Whmcs/card-callback.php', $temporary . '/init.php');
        copy($root . '/package/modules/gateways/callback/pagou_creditcard.php', $temporary . '/modules/gateways/callback/pagou_creditcard.php');
        file_put_contents($temporary . '/modules/gateways/pagou/bootstrap.php', '<?php require ' . var_export($root . '/vendor/autoload.php', true) . ';');
        try {
            $process = proc_open([PHP_BINARY, $temporary . '/modules/gateways/callback/pagou_creditcard.php', json_encode($scenario, JSON_THROW_ON_ERROR), $root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
