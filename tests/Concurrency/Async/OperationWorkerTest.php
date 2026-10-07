<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Concurrency\Async;

use Pagou\Whmcs\Application\Async\ExponentialBackoff;
use Pagou\Whmcs\Application\Async\HandlerOutcome;
use Pagou\Whmcs\Application\Async\HandlerRegistry;
use Pagou\Whmcs\Application\Async\InMemoryOperationOutbox;
use Pagou\Whmcs\Application\Async\JobPriority;
use Pagou\Whmcs\Application\Async\JobStatus;
use Pagou\Whmcs\Application\Async\OperationHandler;
use Pagou\Whmcs\Application\Async\OperationJob;
use Pagou\Whmcs\Application\Async\OperationScheduler;
use Pagou\Whmcs\Application\Async\OperationType;
use Pagou\Whmcs\Application\Async\OperationWorker;
use Pagou\Whmcs\Application\Async\RandomSource;
use Pagou\Whmcs\Application\Async\WorkerBudget;
use PHPUnit\Framework\TestCase;

final class OperationWorkerTest extends TestCase
{
    public function testUnknownRemoteOutcomeBecomesUncertainAndIsNeverBlindlyRetried(): void
    {
        $clock = new MutableAsyncClock(new \DateTimeImmutable('2026-08-22T10:00:00+00:00'));
        $outbox = new InMemoryOperationOutbox();
        $scheduler = new OperationScheduler($outbox, $clock);
        $scheduler->schedule('job-1', OperationType::IssueBoleto, 'boleto:42:1', JobPriority::Issuance, []);
        $worker = $this->worker($outbox, $clock, HandlerOutcome::uncertain('timeout_after_send'));

        $report = $worker->run('worker-a', new WorkerBudget(5, 20, 30));
        self::assertSame(1, $report->uncertain);
        self::assertSame(JobStatus::Uncertain, $outbox->find('job-1')?->status);
        self::assertSame(0, $worker->run('worker-b', new WorkerBudget(5, 20, 30))->claimed);
    }

    public function testRetryUsesBoundedBackoffThenFails(): void
    {
        $clock = new MutableAsyncClock(new \DateTimeImmutable('2026-08-22T10:00:00+00:00'));
        $outbox = new InMemoryOperationOutbox();
        (new OperationScheduler($outbox, $clock))->schedule('job-1', OperationType::IssueBoleto, 'boleto:42:1', JobPriority::Issuance, []);
        $worker = $this->worker($outbox, $clock, HandlerOutcome::retryable('temporary'));

        self::assertSame(1, $worker->run('worker-a', new WorkerBudget())->retried);
        self::assertSame(JobStatus::Retrying, $outbox->find('job-1')?->status);
        $clock->advance('+6 seconds');
        self::assertSame(1, $worker->run('worker-a', new WorkerBudget())->failed);
        self::assertSame(JobStatus::Failed, $outbox->find('job-1')?->status);
    }

    public function testNormalPendingPollsEveryTwoSecondsWithoutExhaustingErrorRetries(): void
    {
        $clock = new MutableAsyncClock(new \DateTimeImmutable('2026-08-22T10:00:00+00:00'));
        $outbox = new InMemoryOperationOutbox();
        (new OperationScheduler($outbox, $clock))->schedule('job-1', OperationType::IssueBoleto, 'one', JobPriority::Issuance, []);
        $worker = $this->worker($outbox, $clock, HandlerOutcome::pending('boleto_registration_pending'));
        for ($i = 0; $i < 20; $i++) {
            self::assertSame(1, $worker->run('interactive', new WorkerBudget())->retried);
            self::assertSame(0, $outbox->find('job-1')?->attempts);
            self::assertEquals($clock->now()->modify('+2 seconds'), $outbox->find('job-1')?->availableAt);
            self::assertSame(0, $worker->run('another-tab', new WorkerBudget())->claimed);
            $clock->advance('+2 seconds');
        }
        self::assertSame(1, $this->worker($outbox, $clock, HandlerOutcome::succeeded())->run('interactive', new WorkerBudget())->succeeded);
    }

    public function testRateLimitDeadlineWinsOverShortPolling(): void
    {
        $clock = new MutableAsyncClock(new \DateTimeImmutable('2026-08-22T10:00:00+00:00'));
        $outbox = new InMemoryOperationOutbox();
        (new OperationScheduler($outbox, $clock))->schedule('job-1', OperationType::IssueBoleto, 'one', JobPriority::Issuance, []);
        $deadline = $clock->now()->modify('+60 seconds');
        $worker = $this->worker($outbox, $clock, HandlerOutcome::retryable('limited', $deadline));
        self::assertSame(1, $worker->run('interactive', new WorkerBudget())->retried);
        self::assertEquals($deadline, $outbox->find('job-1')?->availableAt);
        $clock->advance('+59 seconds');
        self::assertSame(0, $worker->run('interactive', new WorkerBudget())->claimed);
    }

    private function worker(InMemoryOperationOutbox $outbox, MutableAsyncClock $clock, HandlerOutcome $outcome): OperationWorker
    {
        $handler = new class ($outcome) implements OperationHandler {
            public function __construct(private HandlerOutcome $outcome)
            {
            }
            public function type(): OperationType
            {
                return OperationType::IssueBoleto;
            }
            public function handle(OperationJob $job): HandlerOutcome
            {
                return $this->outcome;
            }
        };
        $random = new class implements RandomSource {
            public function int(int $minimum, int $maximum): int
            {
                return 0;
            }
        };

        return new OperationWorker($outbox, new HandlerRegistry([$handler]), new ExponentialBackoff($random, 5, 10, 2, 0), $clock, 2);
    }
}
