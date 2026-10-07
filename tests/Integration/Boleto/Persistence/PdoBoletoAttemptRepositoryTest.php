<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Boleto\Persistence;

use PDO;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoArtifacts;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;
use Pagou\Whmcs\Payment\Boleto\Infrastructure\PdoBoletoAttemptRepository;
use Pagou\Whmcs\Payment\Split\PaymentSplitResponseMapper;
use PHPUnit\Framework\TestCase;

final class PdoBoletoAttemptRepositoryTest extends TestCase
{
    public function testItPersistsAndRehydratesArtifactsAndReplacementState(): void
    {
        $repository = new PdoBoletoAttemptRepository($this->pdo(), 44);
        $attempt = new BoletoAttempt(
            'attempt-1',
            '123',
            2,
            1099,
            '2026-09-10',
            str_repeat('a', 64),
            BoletoAttempt::READY,
            'charge-1',
            new BoletoArtifacts('00190.1', '0019', '000201', 'image', 'https://fatura.pagou.com.br/boleto/charge-1.pdf', 'boleto/charge-1.pdf'),
            'attempt-0',
            null,
            null,
            (new PaymentSplitResponseMapper())->projection([
                'fee' => '1.00',
                'status' => 4,
                'allocations' => [[
                    'id' => 'allocation-1',
                    'customer_id' => 'recipient-1',
                    'type' => 'fixed',
                    'value' => '5.00',
                    'resolved_value' => '5.00',
                    'status' => 4,
                ]],
            ]),
        );
        $repository->save($attempt);

        $stored = $repository->get('attempt-1');
        self::assertNotNull($stored);
        self::assertSame(BoletoAttempt::READY, $stored->status);
        self::assertSame(2, $stored->revision);
        $artifacts = $stored->artifacts;
        self::assertNotNull($artifacts);
        self::assertSame('00190.1', $artifacts->digitableLine);
        self::assertSame('boleto/charge-1.pdf', $artifacts->localPdfKey);
        self::assertNotNull($stored->split);
        self::assertCount(1, $stored->split->allocations);
        self::assertSame('recipient-1', $stored->split->allocations[0]->customerId);

        $repository->save($attempt->cancelled());
        self::assertSame(BoletoAttempt::CANCELLED, $repository->get('attempt-1')?->status);
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 4) . '/package/modules/addons/pagou_payments/migrations.php');
        return $pdo;
    }
}
