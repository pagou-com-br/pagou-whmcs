<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Boleto\Persistence;

use Pagou\Whmcs\Application\Async\AsyncClock;
use Pagou\Whmcs\Application\Async\InMemoryOperationOutbox;
use Pagou\Whmcs\Application\Async\OperationScheduler;
use Pagou\Whmcs\Application\Async\OperationType;
use Pagou\Whmcs\Payment\Boleto\Infrastructure\OperationOutboxJobQueue;
use PHPUnit\Framework\TestCase;

final class OperationOutboxJobQueueTest extends TestCase
{
    public function testItMapsBoletoWorkToDurableOutboxOperations(): void
    {
        $outbox = new InMemoryOperationOutbox();
        $queue = new OperationOutboxJobQueue(new OperationScheduler($outbox, new BoletoFixedClock()));
        $queue->enqueue('boleto.cache_pdf', ['attempt_id' => 'attempt-1'], 'boleto:pdf:attempt-1');

        $lease = $outbox->claim('worker-1', new \DateTimeImmutable('2026-08-22T12:00:00Z'), new \DateInterval('PT30S'));
        self::assertNotNull($lease);
        self::assertSame(OperationType::FetchBoletoPdf, $lease->job->type);
        self::assertSame('attempt-1', $lease->job->payload['attempt_id']);
    }
}

final class BoletoFixedClock implements AsyncClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-22T12:00:00Z');
    }
}
