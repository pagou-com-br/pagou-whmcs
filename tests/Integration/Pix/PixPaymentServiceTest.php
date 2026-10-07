<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Pix;

use Pagou\Whmcs\Payment\Pix\Contracts\HttpClient;
use Pagou\Whmcs\Payment\Pix\Contracts\HttpResponse;
use Pagou\Whmcs\Payment\Pix\Contracts\OperationJournal;
use Pagou\Whmcs\Payment\Pix\Contracts\ReconciliationScheduler;
use Pagou\Whmcs\Payment\Pix\Dto\CreatePixRequest;
use Pagou\Whmcs\Payment\Pix\Exception\PixUncertainOperation;
use Pagou\Whmcs\Payment\Pix\Mapper\PixPayloadMapper;
use Pagou\Whmcs\Payment\Pix\Mapper\PixResponseMapper;
use Pagou\Whmcs\Payment\Pix\PagouPixClient;
use Pagou\Whmcs\Payment\Pix\PixPaymentService;
use Pagou\Whmcs\Payment\Pix\Value\Money;
use PHPUnit\Framework\TestCase;

final class PixPaymentServiceTest extends TestCase
{
    public function testSuccessfulCreationJournalsTheRemoteIdentifier(): void
    {
        $journal = new MemoryJournal();
        $service = $this->service(new PixHttp(201, ['id' => 'pix-42', 'status' => 'pending', 'amount' => '12.34', 'pix' => ['copy_paste' => 'payload']]), $journal, new MemoryReconciliation());

        $charge = $service->create(new CreatePixRequest('42', 'attempt-42', 'key-42', new Money(1234), ['name' => 'Cliente de Teste', 'document' => '***'], 'Invoice 42', 3600));
        self::assertSame('pix-42', $charge->id);
        self::assertSame(['succeeded', 'pix.create', 'key-42'], $journal->events[1]);
    }

    public function testTimeoutBecomesUncertainAndSchedulesReconciliation(): void
    {
        $journal = new MemoryJournal();
        $reconciliation = new MemoryReconciliation();
        $service = $this->service(new PixHttp(504, ['message' => 'timeout']), $journal, $reconciliation);
        $this->expectException(PixUncertainOperation::class);
        try {
            $service->create(new CreatePixRequest('42', 'attempt-42', 'key-42', new Money(100), ['name' => 'Cliente de Teste', 'document' => '***'], 'Invoice 42', 3600));
        } finally {
            self::assertSame(['pix.create', 'key-42'], $reconciliation->scheduled[0]);
            self::assertSame('uncertain', $journal->events[1][0]);
        }
    }

    private function service(HttpClient $http, OperationJournal $journal, ReconciliationScheduler $reconciliation): PixPaymentService
    {
        return new PixPaymentService(new PagouPixClient($http, 'key'), new PixPayloadMapper(), new PixResponseMapper(), $journal, $reconciliation);
    }
}

final class PixHttp implements HttpClient
{
    /** @param array<string, mixed> $response */
    public function __construct(private int $status, private array $response)
    {
    }
    public function request(string $method, string $path, array $headers = [], ?array $json = null): HttpResponse
    {
        return new HttpResponse($this->status, $this->response);
    }
}

final class MemoryJournal implements OperationJournal
{
    /** @var list<array{string, string, string}> */ public array $events = [];
    public function started(string $operation, string $idempotencyKey, array $context): array
    {
        $this->events[] = ['started', $operation, $idempotencyKey];
        return ['status' => 'claimed', 'result' => []];
    }
    public function rejected(string $operation, string $idempotencyKey, int $httpStatus): void
    {
        $this->events[] = ['failed', $operation, $idempotencyKey];
    }
    public function succeeded(string $operation, string $idempotencyKey, array $result): void
    {
        $this->events[] = ['succeeded', $operation, $idempotencyKey];
    }
    public function uncertain(string $operation, string $idempotencyKey, array $context): void
    {
        $this->events[] = ['uncertain', $operation, $idempotencyKey];
    }
}

final class MemoryReconciliation implements ReconciliationScheduler
{
    /** @var list<array{string, string}> */ public array $scheduled = [];
    public function schedule(string $operation, string $idempotencyKey, array $context): void
    {
        $this->scheduled[] = [$operation, $idempotencyKey];
    }
}
