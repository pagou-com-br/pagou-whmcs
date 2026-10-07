<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Persistence\Async;

use DateInterval;
use DateTimeImmutable;
use PDO;
use Pagou\Whmcs\Application\Async\JobPriority;
use Pagou\Whmcs\Application\Async\OperationJob;
use Pagou\Whmcs\Application\Async\OperationType;
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class PdoOperationOutboxTest extends TestCase
{
    public function testDeduplicationAndPriorityAreDurable(): void
    {
        $outbox = $this->outbox();
        $now = $this->now();
        self::assertTrue($outbox->enqueue($this->job('low', 'dedup-low', JobPriority::Delivery, $now)));
        self::assertTrue($outbox->enqueue($this->job('high', 'dedup-high', JobPriority::Issuance, $now)));
        self::assertFalse($outbox->enqueue($this->job('duplicate', 'dedup-high', JobPriority::FinancialRecovery, $now)));

        $lease = $outbox->claim('worker-a', $now, new DateInterval('PT30S'));
        self::assertNotNull($lease);
        self::assertSame('high', $lease->job->id);
    }

    public function testOnlyOneWorkerCanClaimTheSameJobAndTransitionsAreFenced(): void
    {
        $outbox = $this->outbox();
        $now = $this->now();
        $outbox->enqueue($this->job('job-1', 'dedup-1', JobPriority::Issuance, $now));
        $lease = $outbox->claim('worker-a', $now, new DateInterval('PT30S'));

        self::assertNotNull($lease);
        self::assertNull($outbox->claim('worker-b', $now, new DateInterval('PT30S')));
        self::assertFalse($outbox->complete('job-1', 'not-the-token'));
        self::assertTrue($outbox->complete('job-1', $lease->token));
        self::assertFalse($outbox->complete('job-1', $lease->token));
    }

    public function testExpiredLeaseReturnsToRetryAndCannotBeCompletedByOldWorker(): void
    {
        $outbox = $this->outbox();
        $now = $this->now();
        $outbox->enqueue($this->job('job-1', 'dedup-1', JobPriority::Issuance, $now));
        $first = $outbox->claim('worker-a', $now, new DateInterval('PT10S'));
        self::assertNotNull($first);

        $afterExpiry = $now->add(new DateInterval('PT11S'));
        self::assertSame(1, $outbox->releaseExpiredLeases($afterExpiry));
        self::assertFalse($outbox->complete('job-1', $first->token));
        $second = $outbox->claim('worker-b', $afterExpiry, new DateInterval('PT10S'));
        self::assertNotNull($second);
        self::assertNotSame($first->token, $second->token);
        self::assertSame(2, $second->job->attempts);
    }

    public function testAttemptReferenceIsProjectedForOperationalDiagnosis(): void
    {
        $pdo = $this->database();
        $outbox = new PdoOperationOutbox($pdo);
        $now = $this->now();
        $job = new OperationJob(
            'job-with-attempt',
            OperationType::IssuePix,
            'dedup-attempt',
            JobPriority::Issuance,
            ['attempt_id' => 'attempt-123', 'invoice_id' => 1],
            $now,
            $now,
        );

        self::assertTrue($outbox->enqueue($job));
        self::assertSame(
            'attempt-123',
            $pdo->query("SELECT attempt_id FROM pagou_payment_operations WHERE id = 'job-with-attempt'")?->fetchColumn(),
        );
    }

    public function testInteractiveScopeClaimsOnlyItsInvoiceAndNeverEmail(): void
    {
        $pdo = $this->database();
        $store = new \Pagou\Whmcs\Application\Runtime\PaymentAttemptStore($pdo);
        $one = $store->ensureCurrent(10, 20, 'boleto', 1200, '2026-10-02');
        $two = $store->ensureCurrent(11, 21, 'boleto', 1200, '2026-10-02');
        $outbox = new PdoOperationOutbox($pdo);
        $now = $this->now();
        foreach ([['other', $two['id'], OperationType::IssueBoleto], ['email', $one['id'], OperationType::DeliverInvoiceEmail], ['pdf', $one['id'], OperationType::FetchBoletoPdf], ['own', $one['id'], OperationType::IssueBoleto]] as [$id, $attempt, $type]) {
            $outbox->enqueue(new OperationJob($id, $type, $id, JobPriority::Issuance, ['attempt_id' => $attempt], $now, $now));
        }
        $scoped = new PdoOperationOutbox($pdo, 10);
        $lease = $scoped->claim('interactive', $now, new DateInterval('PT30S'));
        self::assertNotNull($lease);
        self::assertSame('own', $lease->job->id);
        self::assertNull($scoped->claim('second-tab', $now, new DateInterval('PT30S')));
        self::assertSame('queued', $outbox->find('other')?->status->value);
        self::assertSame('queued', $outbox->find('email')?->status->value);
        self::assertSame('queued', $outbox->find('pdf')?->status->value);
        self::assertTrue($scoped->complete('own', $lease->token));
        self::assertNull($scoped->claim('interactive', $now, new DateInterval('PT30S')));
    }

    public function testInteractiveScopeDoesNotReclaimOtherInvoiceOrEmailLeases(): void
    {
        $pdo = $this->database();
        $store = new \Pagou\Whmcs\Application\Runtime\PaymentAttemptStore($pdo);
        $one = $store->ensureCurrent(10, 20, 'boleto', 1200, '2026-10-02');
        $two = $store->ensureCurrent(11, 21, 'boleto', 1200, '2026-10-02');
        $outbox = new PdoOperationOutbox($pdo);
        $now = $this->now();
        foreach ([['own', $one['id'], OperationType::IssueBoleto], ['other', $two['id'], OperationType::IssueBoleto], ['email', $one['id'], OperationType::DeliverInvoiceEmail], ['pdf', $one['id'], OperationType::FetchBoletoPdf]] as [$id, $attempt, $type]) {
            $outbox->enqueue(new OperationJob($id, $type, $id, JobPriority::Issuance, ['attempt_id' => $attempt], $now, $now));
            self::assertNotNull($outbox->claim('cron', $now, new DateInterval('PT1S')));
        }
        self::assertSame(1, (new PdoOperationOutbox($pdo, 10))->releaseExpiredLeases($now->add(new DateInterval('PT2S'))));
        self::assertSame('retrying', $outbox->find('own')?->status->value);
        self::assertSame('leased', $outbox->find('other')?->status->value);
        self::assertSame('leased', $outbox->find('email')?->status->value);
    }

    public function testImmediateInvoiceExecutionDoesNotRunGlobalMaintenanceOrEmail(): void
    {
        $pdo = $this->database();
        $store = new \Pagou\Whmcs\Application\Runtime\PaymentAttemptStore($pdo);
        $own = $store->ensureCurrent(10, 20, 'boleto', 1200, '2026-10-02');
        $other = $store->ensureCurrent(11, 21, 'boleto', 1200, '2026-10-02');
        $store->markStatus($own['id'], 'superseded');
        $outbox = new PdoOperationOutbox($pdo);
        $now = $this->now();
        foreach ([['own', $own['id'], OperationType::IssueBoleto], ['other', $other['id'], OperationType::IssueBoleto], ['email', $own['id'], OperationType::DeliverInvoiceEmail], ['pdf', $own['id'], OperationType::FetchBoletoPdf]] as [$id, $attempt, $type]) {
            $outbox->enqueue(new OperationJob($id, $type, $id, JobPriority::Issuance, ['attempt_id' => $attempt], $now, $now));
        }
        $runtime = new \Pagou\Whmcs\Application\Runtime\WhmcsRuntime($pdo, new \Pagou\Whmcs\Application\Runtime\AddonSettings([]), $outbox, static function (): array {
            self::fail('Immediate progress must not call WHMCS for an unrelated invoice or send email.');
        });
        self::assertSame(1, $runtime->advanceInvoice(10)->succeeded);
        self::assertSame('queued', $outbox->find('other')?->status->value);
        self::assertSame('queued', $outbox->find('email')?->status->value);
        self::assertSame('queued', $outbox->find('pdf')?->status->value);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM pagou_settings WHERE setting_key = 'worker_last_run_utc'")->fetchColumn());
    }

    public function testPendingDeferralIsFencedAndDoesNotConsumeAnErrorAttempt(): void
    {
        $outbox = $this->outbox();
        $now = $this->now();
        $outbox->enqueue($this->job('pending', 'pending', JobPriority::Issuance, $now));
        $lease = $outbox->claim('one', $now, new DateInterval('PT30S'));
        self::assertNotNull($lease);
        self::assertFalse($outbox->retry('pending', 'wrong-fence', $now->modify('+2 seconds'), 'pending', false));
        self::assertSame(1, $outbox->find('pending')?->attempts);
        self::assertTrue($outbox->retry('pending', $lease->token, $now->modify('+2 seconds'), 'pending', false));
        self::assertSame(0, $outbox->find('pending')?->attempts);
        self::assertNull($outbox->claim('two', $now->modify('+1 second'), new DateInterval('PT30S')));
        self::assertNotNull($outbox->claim('two', $now->modify('+2 seconds'), new DateInterval('PT30S')));
    }

    public function testInteractiveAccelerationNeverBypassesFailuresOrAnotherInvoice(): void
    {
        $pdo = $this->database();
        $store = new \Pagou\Whmcs\Application\Runtime\PaymentAttemptStore($pdo);
        $one = $store->ensureCurrent(10, 20, 'boleto', 1200, '2026-10-02');
        $two = $store->ensureCurrent(11, 21, 'boleto', 1200, '2026-10-02');
        $outbox = new PdoOperationOutbox($pdo);
        $now = $this->now();
        foreach ([['pending', $one['id'], 'boleto_registration_pending'], ['limited', $one['id'], 'boleto_lookup_failed'], ['other', $two['id'], 'boleto_registration_pending']] as [$id, $attempt, $reason]) {
            $outbox->enqueue(new OperationJob($id, OperationType::ReconcilePayment, $id, JobPriority::Issuance, ['attempt_id' => $attempt], $now, $now));
            $lease = $outbox->claim('background', $now, new DateInterval('PT30S'));
            self::assertNotNull($lease);
            self::assertTrue($outbox->retry($id, $lease->token, $now->modify('+60 seconds'), $reason, false));
        }
        $scoped = new PdoOperationOutbox($pdo, 10);
        $scoped->expeditePending($now->modify('+1 second'));
        self::assertNull($scoped->claim('early', $now->modify('+1 second'), new DateInterval('PT30S')));
        $scoped->expeditePending($now->modify('+3 seconds'));
        self::assertSame('pending', $scoped->claim('interactive', $now->modify('+3 seconds'), new DateInterval('PT30S'))?->job->id);
        self::assertEquals($now->modify('+60 seconds'), $outbox->find('limited')?->availableAt);
        self::assertEquals($now->modify('+60 seconds'), $outbox->find('other')?->availableAt);
    }

    private function outbox(): PdoOperationOutbox
    {
        return new PdoOperationOutbox($this->database());
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 4) . '/package/modules/addons/pagou_payments/migrations.php');

        return $pdo;
    }

    private function job(string $id, string $deduplicationKey, JobPriority $priority, DateTimeImmutable $now): OperationJob
    {
        return new OperationJob($id, OperationType::IssueBoleto, $deduplicationKey, $priority, ['invoice_id' => 1], $now, $now);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
