<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Pix;

use PDO;
use Pagou\Whmcs\Application\Async\InMemoryOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Payment\Pix\Contracts\{HttpClient, HttpResponse};
use Pagou\Whmcs\Payment\Pix\Dto\{CreatePixRequest, RefundPixRequest};
use Pagou\Whmcs\Payment\Pix\Exception\{PixApiException, PixUncertainOperation};
use Pagou\Whmcs\Payment\Pix\Infrastructure\{OutboxReconciliationScheduler, PdoOperationJournal};
use Pagou\Whmcs\Payment\Pix\Mapper\{PixPayloadMapper, PixResponseMapper};
use Pagou\Whmcs\Payment\Pix\{PagouPixClient, PixPaymentService};
use Pagou\Whmcs\Payment\Pix\Value\Money;
use PHPUnit\Framework\TestCase;

final class PixReplayTest extends TestCase
{
    private PDO $pdo;
    private ReplayHttp $http;
    private PixPaymentService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->http = new ReplayHttp();
        $this->service = new PixPaymentService(new PagouPixClient($this->http, 'synthetic'), new PixPayloadMapper(), new PixResponseMapper(), new PdoOperationJournal($this->pdo), new OutboxReconciliationScheduler(new InMemoryOperationOutbox()));
    }

    public function testCompletedRefundAndCancellationAreNotSentAgain(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->service->refund($this->refund('intent-1'));
            $this->service->cancel('attempt-1', 'pix-1', 'cancel-1');
        }
        self::assertCount(2, $this->http->calls);
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM pagou_payment_operations WHERE status = 'succeeded'")->fetchColumn());
    }

    public function testRetentionPreservesSafeReplayEvidenceAndCannotReopenARefund(): void
    {
        $this->service->refund($this->refund('retained'));
        $this->pdo->exec("UPDATE pagou_payment_operations SET updated_at = '2000-01-01'");
        (new \Pagou\Whmcs\Application\Runtime\RetentionService($this->pdo))->run();
        $this->service->refund($this->refund('retained'));
        self::assertCount(1, $this->http->calls);
        $this->expectException(\DomainException::class);
        $this->service->refund($this->refund('retained', 200));
    }

    public function testTwoExplicitRefundIntentsWithTheSameAmountAreDistinct(): void
    {
        $this->service->refund($this->refund('intent-1'));
        $this->service->refund($this->refund('intent-2'));
        self::assertCount(2, $this->http->calls);
    }

    public function testSameIntentWithDifferentAmountIsRejectedBeforeTransport(): void
    {
        $this->service->refund($this->refund('intent-1'));
        try {
            $this->service->refund($this->refund('intent-1', 200));
            self::fail('Conflicting intent must fail.');
        } catch (\DomainException) {
            self::assertCount(1, $this->http->calls);
        }
    }

    public function testUncertainRefundIsNotRepeatedAndDeduplicatesRecovery(): void
    {
        $this->http->status = 504;
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->service->refund($this->refund('uncertain'));
                self::fail('Timeout must remain uncertain.');
            } catch (PixUncertainOperation) {
                self::assertCount(1, $this->http->calls);
            }
        }
    }

    public function testConfirmedRejectionIsNotSentAgain(): void
    {
        $this->http->status = 422;
        for ($i = 0; $i < 2; $i++) {
            try {
                $this->service->refund($this->refund('invalid'));
                self::fail('Provider rejected the request.');
            } catch (PixApiException $error) {
                self::assertSame(422, $error->status);
            }
        }
        self::assertCount(1, $this->http->calls);
    }

    public function testLocalFailureAfterRemoteSuccessRemainsUncertainEvenIfRecoveryStorageFails(): void
    {
        $journal = new class ($this->pdo) implements \Pagou\Whmcs\Payment\Pix\Contracts\OperationJournal {
            private PdoOperationJournal $store;
            public function __construct(PDO $pdo)
            {
                $this->store = new PdoOperationJournal($pdo);
            }
            public function started(string $operation, string $key, array $context): array
            {
                return $this->store->started($operation, $key, $context);
            }
            public function succeeded(string $operation, string $key, array $result): void
            {
                throw new \RuntimeException('synthetic persistence failure');
            }
            public function uncertain(string $operation, string $key, array $context): void
            {
                throw new \RuntimeException('synthetic persistence failure');
            }
            public function rejected(string $operation, string $key, int $status): void
            {
                throw new \RuntimeException('not expected');
            }
        };
        $scheduler = new class implements \Pagou\Whmcs\Payment\Pix\Contracts\ReconciliationScheduler {
            public function schedule(string $operation, string $key, array $context): void
            {
                throw new \RuntimeException('synthetic queue failure');
            }
        };
        $service = new PixPaymentService(new PagouPixClient($this->http, 'synthetic'), new PixPayloadMapper(), new PixResponseMapper(), $journal, $scheduler);
        for ($i = 0; $i < 2; $i++) {
            try {
                $service->refund($this->refund('local-failure'));
                self::fail('Unknown outcome cannot be reported as safe to repeat.');
            } catch (PixUncertainOperation) {
                self::assertCount(1, $this->http->calls);
            }
        }
    }

    public function testConcurrentClaimCannotExecuteMutationOrDowngradeSuccess(): void
    {
        $journal = new PdoOperationJournal($this->pdo);
        self::assertSame('claimed', $journal->started('pix.cancel', 'busy', ['attempt_id' => 'attempt-1'])['status']);
        try {
            $this->service->cancel('attempt-1', 'pix-1', 'busy');
            self::fail('The first caller owns this operation.');
        } catch (PixUncertainOperation) {
            self::assertSame([], $this->http->calls);
        }
        $journal->succeeded('pix.cancel', 'busy', []);
        $journal->uncertain('pix.cancel', 'busy', []);
        $journal->succeeded('pix.cancel', 'busy', []);
        self::assertSame('succeeded', $journal->started('pix.cancel', 'busy', ['attempt_id' => 'attempt-1'])['status']);
    }

    public function testCreationReplayLooksUpTheOriginalChargeWithoutCreatingAnother(): void
    {
        $request = new CreatePixRequest('1', 'attempt-1', 'create-1', new Money(100), ['name' => 'Synthetic', 'document' => '***'], 'Synthetic', 3600);
        $this->http->status = 201;
        self::assertSame('pix-1', $this->service->create($request)->id);
        self::assertSame('pix-1', $this->service->create($request)->id);
        self::assertSame(['POST', 'GET'], $this->http->calls);
    }

    private function refund(string $key, int $amount = 100): RefundPixRequest
    {
        return new RefundPixRequest('pix-1', 'attempt-1', $key, 1, new Money($amount), 'Synthetic');
    }
}

final class ReplayHttp implements HttpClient
{
    public int $status = 204;
    /** @var list<string> */ public array $calls = [];
    public function request(string $method, string $path, array $headers = [], ?array $json = null): HttpResponse
    {
        $this->calls[] = $method;
        return new HttpResponse($this->status, ['id' => 'pix-1', 'status' => 'pending', 'amount' => '1.00', 'pix' => ['copy_paste' => 'synthetic']]);
    }
}
