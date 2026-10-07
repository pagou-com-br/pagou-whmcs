<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Async\{OperationScheduler, SystemAsyncClock};
use Pagou\Whmcs\Application\Runtime\{AddonSettings, WhmcsRuntime};
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Payment\Boleto\Api\{BoletoClient, BoletoPayloadMapper, BoletoResponseMapper, CreateBoletoRequest};
use Pagou\Whmcs\Payment\Boleto\Application\BoletoEmissionHandler;
use Pagou\Whmcs\Payment\Boleto\Contracts\{HttpClient, HttpResponse};
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;
use Pagou\Whmcs\Payment\Boleto\Infrastructure\{OperationOutboxJobQueue, PdoBoletoAttemptRepository};
use PHPUnit\Framework\TestCase;

final class BoletoRegistrationSchedulingTest extends TestCase
{
    public function testEmissionAndRuntimeShareOneRegistrationOperation(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $repository = new PdoBoletoAttemptRepository($pdo, 20);
        $repository->save(new BoletoAttempt('attempt', '10', 1, 1200, '2026-10-02', 'idem'));
        $outbox = new PdoOperationOutbox($pdo);
        $queue = new OperationOutboxJobQueue(new OperationScheduler($outbox, new SystemAsyncClock()));
        $http = new class implements HttpClient {
            public int $requests = 0;
            public function request(string $method, string $path, array $headers = [], ?array $json = null): HttpResponse
            {
                $this->requests++;
                return new HttpResponse(201, ['id' => 'remote', 'amount' => 12, 'status' => 'processing']);
            }
        };
        $handler = new BoletoEmissionHandler($repository, new BoletoClient($http, new BoletoPayloadMapper(), new BoletoResponseMapper()), $queue);
        $attempt = $handler->handle('attempt', new CreateBoletoRequest('10', 'idem', 1200, '2026-10-02', [
            'name' => 'Test', 'document' => '123', 'zip' => '01001000', 'street' => 'R', 'city' => 'S',
            'state' => 'SP', 'number' => '1', 'neighborhood' => 'C',
        ], 'Fatura', 1, 'reference'));
        self::assertSame(BoletoAttempt::AWAITING_REGISTRATION, $attempt->status);
        $runtime = new WhmcsRuntime($pdo, new AddonSettings([]), $outbox, static fn (): array => []);
        $followUp = new \ReflectionMethod($runtime, 'scheduleBoletoArtifacts');
        $followUp->invoke($runtime, $attempt);
        $followUp->invoke($runtime, $attempt);
        self::assertSame(1, $http->requests);
        self::assertSame(['reconcile_payment'], $pdo->query('SELECT operation_type FROM pagou_payment_operations')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('attempt', $pdo->query('SELECT attempt_id FROM pagou_payment_operations')->fetchColumn());
    }
}
