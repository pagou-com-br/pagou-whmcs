<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit\Card;

use Pagou\Whmcs\Payment\Card\CardApiClient;
use Pagou\Whmcs\Payment\Card\CardPaymentService;
use Pagou\Whmcs\Payment\Card\CardReconciler;
use Pagou\Whmcs\Payment\Card\CardTransport;
use Pagou\Whmcs\Payment\Card\Contracts\CardOperationJournal;
use Pagou\Whmcs\Payment\Card\Contracts\CardReconciliationScheduler;
use Pagou\Whmcs\Payment\Card\Dto\CardChargeRequest;
use Pagou\Whmcs\Payment\Card\Exception\CardUncertainOperation;
use Pagou\Whmcs\Payment\Card\IdempotencyKey;
use PHPUnit\Framework\TestCase;

final class CardPaymentServiceTest extends TestCase
{
    public function testSuccessfulChargeIsJournaledBeforeAndAfterRemoteCall(): void
    {
        $journal = new RecordingCardJournal();
        $scheduler = new RecordingCardScheduler();
        $transport = new class implements CardTransport {
            public function request(
                string $method,
                string $path,
                array $headers = [],
                ?array $body = null,
            ): array {
                return ['id' => 'charge-1', 'status' => 'paid', 'amount' => 100];
            }
        };
        $api = new CardApiClient($transport);
        $service = new CardPaymentService($api, new CardReconciler($api), $journal, $scheduler);
        $result = $service->charge(new CardChargeRequest(100, 'customer-1', 'card-1', 'invoice-1'), IdempotencyKey::create('charge', 'invoice-1'));
        self::assertSame('charge-1', $result->id);
        self::assertSame(['started', 'succeeded'], $journal->events);
        self::assertSame([], $scheduler->events);
    }

    public function testChargeMapsCaptureInstallmentsAndSoftDescriptor(): void
    {
        $body = [];
        $transport = new class ($body) implements CardTransport {
            /** @param array<string, mixed> $body */
            public function __construct(private array &$body)
            {
            }
            public function request(string $method, string $path, array $headers = [], ?array $body = null): array
            {
                $this->body = $body ?? [];

                return ['id' => 'charge-2', 'status' => 'authorized', 'amount' => 2500];
            }
        };
        $api = new CardApiClient($transport);
        $api->createCharge(
            new CardChargeRequest(2500, 'customer-1', 'card-1', 'invoice-2', 6, false, [], '2026-08-23', 'PAGOU LOJA'),
            IdempotencyKey::create('charge', 'invoice-2'),
        );

        self::assertSame(6, $body['installments']);
        self::assertFalse($body['installments_capture']);
        self::assertSame('PAGOU LOJA', $body['soft_descriptor']);
    }

    public function testDifferentConfirmedAmountNeverReturnsSuccessToNativeCapture(): void
    {
        $transport = new class implements CardTransport {
            public function request(string $method, string $path, array $headers = [], ?array $body = null): array
            {
                return ['id' => 'remote-charge', 'status' => 'captured', 'value' => 1001];
            }
        };
        $journal = new RecordingCardJournal();
        $scheduler = new RecordingCardScheduler();
        $api = new CardApiClient($transport);
        $service = new CardPaymentService($api, new CardReconciler($api), $journal, $scheduler);
        foreach (['charge', 'chargeRecurring'] as $method) {
            try {
                $service->$method(new CardChargeRequest(1000, 'customer', 'card', 'invoice'), IdempotencyKey::create('charge', $method));
                self::fail('A different amount must never be reported as paid.');
            } catch (CardUncertainOperation) {
                self::assertTrue(true);
            }
        }
        self::assertSame(['started', 'succeeded', 'started', 'succeeded'], $journal->events);
        self::assertSame(['card.charge.create', 'card.charge.recurring'], $scheduler->events);
    }

    public function testUnsupportedOptionsNeverReachJournalOrProvider(): void
    {
        $transport = new class implements CardTransport {
            public int $calls = 0;
            public function request(string $method, string $path, array $headers = [], ?array $body = null): array
            {
                $this->calls++;
                return [];
            }
        };
        $journal = new RecordingCardJournal();
        $scheduler = new RecordingCardScheduler();
        $api = new CardApiClient($transport);
        $service = new CardPaymentService($api, new CardReconciler($api), $journal, $scheduler);
        foreach (['charge', 'chargeRecurring'] as $method) {
            foreach ([[1, false], [2, true]] as [$installments, $capture]) {
                try {
                    $service->$method(new CardChargeRequest(100, 'customer', 'card', 'invoice', $installments, $capture), IdempotencyKey::create('charge', 'invoice'));
                    self::fail('Unsupported option must be rejected before dispatch.');
                } catch (\InvalidArgumentException $error) {
                    self::assertStringContainsString('à vista com captura automática', $error->getMessage());
                }
            }
        }
        try {
            $service->capture('charge-1', IdempotencyKey::create('capture', 'charge-1'));
            self::fail('Delayed capture must remain unavailable.');
        } catch (\LogicException) {
            self::assertSame(0, $transport->calls);
        }
        self::assertSame([], $journal->events);
        self::assertSame([], $scheduler->events);
    }

    public function testRecurringPaymentUsesSavedReferenceWithoutCvvAndNewInvoiceIdentity(): void
    {
        $transport = new class implements CardTransport {
            public array $requests = [];
            public function request(string $method, string $path, array $headers = [], ?array $body = null): array
            {
                $this->requests[] = [$method, $path, $headers, $body];
                return ['id' => 'charge-' . count($this->requests), 'status' => 'captured', 'value' => 2500];
            }
        };
        $api = new CardApiClient($transport);
        $service = new CardPaymentService($api, new CardReconciler($api), new RecordingCardJournal(), new RecordingCardScheduler());
        foreach (['invoice-1', 'invoice-2'] as $invoice) {
            $result = $service->chargeRecurring(new CardChargeRequest(2500, 'customer', 'saved-card', $invoice), IdempotencyKey::create('charge', $invoice));
            self::assertTrue($result->status->permitsAutomaticInvoiceSettlement());
        }
        self::assertNotSame($transport->requests[0][2]['Idempotency-Key'], $transport->requests[1][2]['Idempotency-Key']);
        foreach ($transport->requests as [$method, $path, $headers, $body]) {
            self::assertSame('POST', $method);
            self::assertSame('/v1/creditcard/charges', $path);
            self::assertSame('saved-card', $body['card_id']);
            self::assertSame(1, $body['installments']);
            self::assertTrue($body['installments_capture']);
            self::assertArrayNotHasKey('cvv', $body);
            self::assertArrayNotHasKey('card_token', $body);
        }
    }

    public function testAnotherCustomerOrCardNeverCompletesInitialOrRecurringPayment(): void
    {
        foreach (['customer_id', 'card_id'] as $field) {
            $transport = new class ($field) implements CardTransport {
                public function __construct(private string $field)
                {
                }
                public function request(string $method, string $path, array $headers = [], ?array $body = null): array
                {
                    return ['id' => 'charge-1', 'status' => 'captured', 'value' => 100, $this->field => 'other'];
                }
            };
            $api = new CardApiClient($transport);
            $scheduler = new RecordingCardScheduler();
            $service = new CardPaymentService($api, new CardReconciler($api), new RecordingCardJournal(), $scheduler);
            foreach (['charge', 'chargeRecurring'] as $method) {
                try {
                    $service->$method(new CardChargeRequest(100, 'customer-1', 'card-1', 'invoice'), IdempotencyKey::create('charge', $method));
                    self::fail('Mismatched ownership was accepted.');
                } catch (CardUncertainOperation) {
                    self::assertTrue(true);
                }
            }
            self::assertSame(['card.charge.create', 'card.charge.recurring'], $scheduler->events);
        }
    }

    public function testDeletedRemoteCardIsSuccessAndTimeoutIsNot(): void
    {
        foreach ([404, 0] as $code) {
            $transport = new class ($code) implements CardTransport {
                public function __construct(private int $code)
                {
                }
                public function request(string $method, string $path, array $headers = [], ?array $body = null): array
                {
                    throw new \Pagou\Whmcs\Infrastructure\Http\ApiException('fixture', $this->code === 404 ? 'not_found' : 'transport', $this->code);
                }
            };
            $api = new CardApiClient($transport);
            $journal = new RecordingCardJournal();
            $scheduler = new RecordingCardScheduler();
            $service = new CardPaymentService($api, new CardReconciler($api), $journal, $scheduler);
            try {
                $service->deleteCard('saved-card', IdempotencyKey::create('delete', 'saved-card'));
                self::assertSame(404, $code);
                self::assertSame(['started', 'succeeded'], $journal->events);
            } catch (CardUncertainOperation) {
                self::assertSame(0, $code);
                self::assertSame(['card.card.delete'], $scheduler->events);
            }
        }
    }

    public function testFailureBecomesUncertainAndIsScheduled(): void
    {
        $journal = new RecordingCardJournal();
        $scheduler = new RecordingCardScheduler();
        $transport = new class implements CardTransport {
            public function request(
                string $method,
                string $path,
                array $headers = [],
                ?array $body = null,
            ): array {
                throw new \RuntimeException('timeout');
            }
        };
        $api = new CardApiClient($transport);
        $service = new CardPaymentService($api, new CardReconciler($api), $journal, $scheduler);
        $this->expectException(CardUncertainOperation::class);
        try {
            $service->charge(
                new CardChargeRequest(100, 'customer-1', 'card-1', 'invoice-1'),
                IdempotencyKey::create('charge', 'invoice-1'),
            );
        } finally {
            self::assertSame(['started', 'uncertain'], $journal->events);
            self::assertSame(['card.charge.create'], $scheduler->events);
        }
    }
}

final class RecordingCardJournal implements CardOperationJournal
{
    /** @var list<string> */
    public array $events = [];

    public function started(string $operation, string $idempotencyKey, array $context): void
    {
        $this->events[] = 'started';
    }

    public function succeeded(string $operation, string $idempotencyKey, array $result): void
    {
        $this->events[] = 'succeeded';
    }

    public function uncertain(string $operation, string $idempotencyKey, array $context): void
    {
        $this->events[] = 'uncertain';
    }

    public function failed(string $operation, string $idempotencyKey, array $context): void
    {
        $this->events[] = 'failed';
    }
}

final class RecordingCardScheduler implements CardReconciliationScheduler
{
    /** @var list<string> */
    public array $events = [];

    public function schedule(string $operation, string $idempotencyKey, array $context): void
    {
        $this->events[] = $operation;
    }
}
