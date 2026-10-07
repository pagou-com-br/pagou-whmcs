<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Concurrency\Async;

use Pagou\Whmcs\Application\Async\InMemoryOperationOutbox;
use Pagou\Whmcs\Application\Async\JobPriority;
use Pagou\Whmcs\Application\Async\JobStatus;
use Pagou\Whmcs\Application\Async\OperationJob;
use Pagou\Whmcs\Application\Async\OperationType;
use PHPUnit\Framework\TestCase;

final class OperationOutboxConcurrencyTest extends TestCase
{
    public function testDeduplicationAllowsOnlyOneActiveFinancialIntent(): void
    {
        $outbox = new InMemoryOperationOutbox();
        $now = new \DateTimeImmutable('2026-08-22T10:00:00+00:00');

        self::assertTrue($outbox->enqueue($this->job('one', 'invoice:42:issue', $now)));
        self::assertFalse($outbox->enqueue($this->job('two', 'invoice:42:issue', $now)));
    }

    public function testExpiredLeaseCannotOverwriteNewWorkersOutcome(): void
    {
        $outbox = new InMemoryOperationOutbox();
        $now = new \DateTimeImmutable('2026-08-22T10:00:00+00:00');
        $outbox->enqueue($this->job('one', 'invoice:42:issue', $now));
        $leaseA = $outbox->claim('worker-a', $now, new \DateInterval('PT5S'));
        self::assertNotNull($leaseA);

        $later = $now->modify('+6 seconds');
        self::assertSame(1, $outbox->releaseExpiredLeases($later));
        $leaseB = $outbox->claim('worker-b', $later, new \DateInterval('PT5S'));
        self::assertNotNull($leaseB);
        self::assertFalse($outbox->complete('one', $leaseA->token));
        self::assertTrue($outbox->complete('one', $leaseB->token));
        self::assertSame(JobStatus::Succeeded, $outbox->find('one')?->status);
    }

    public function testClaimSelectsFinancialRecoveryBeforeDelivery(): void
    {
        $outbox = new InMemoryOperationOutbox();
        $now = new \DateTimeImmutable('2026-08-22T10:00:00+00:00');
        $outbox->enqueue($this->job('delivery', 'delivery:42', $now, JobPriority::Delivery));
        $outbox->enqueue($this->job('recovery', 'recovery:42', $now, JobPriority::FinancialRecovery));

        self::assertSame('recovery', $outbox->claim('worker', $now, new \DateInterval('PT5S'))?->job->id);
    }

    private function job(string $id, string $key, \DateTimeImmutable $now, JobPriority $priority = JobPriority::Issuance): OperationJob
    {
        return new OperationJob($id, OperationType::IssueBoleto, $key, $priority, [], $now, $now);
    }
}
