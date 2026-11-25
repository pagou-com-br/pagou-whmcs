<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pagou\Whmcs\Application\Async\{OperationScheduler, SystemAsyncClock};
use Pagou\Whmcs\Application\Runtime\{PaymentAttemptStore, PeriodicReconciliation};
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Tests\Support\Runtime\{PeriodicSelectionFixture, RecordingPdo};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The periodic selection reads in short steps and picks exactly what its former single statement picked. */
final class PeriodicSelectionTest extends TestCase
{
    private RecordingPdo $pdo;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = new RecordingPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, duedate TEXT NULL, status TEXT)');
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** @return iterable<string, array{int, int}> */
    public static function datasets(): iterable
    {
        foreach ([11, 23, 37, 41, 59] as $seed) {
            yield 'seed ' . $seed => [$seed, 300];
        }
        // More due dates than one read takes, and more charges than the largest limit.
        yield 'many charges' => [73, 900];
    }

    #[DataProvider('datasets')]
    public function testTheSteppedSelectionPicksWhatTheFormerStatementPicked(int $seed, int $charges): void
    {
        PeriodicSelectionFixture::seed($this->pdo, $this->now, $seed, $charges);
        $periodic = $this->periodic();
        foreach ([1, 3, 10, 25, 40] as $limit) {
            self::assertSame([], PeriodicSelectionFixture::differences($this->pdo, $periodic, $this->now, $limit));
        }
        // The data must reach every group, or the comparison proves little.
        $selected = $periodic->dueCharges($this->now, 25);
        self::assertNotEmpty($selected['near']);
        self::assertNotEmpty($selected['old']);
    }

    #[DataProvider('datasets')]
    public function testCoverageCountsWhatTheFormerStatementCounted(int $seed, int $charges): void
    {
        PeriodicSelectionFixture::seed($this->pdo, $this->now, $seed, $charges);
        $this->pdo->statements = [];

        $coverage = $this->periodic()->coverage();
        $statements = $this->pdo->statements;

        self::assertSame(PeriodicSelectionFixture::legacyCoverage($this->pdo), $coverage);
        self::assertGreaterThan(0, $coverage['checked']);
        self::assertGreaterThan(0, $coverage['stale']);
        // One read of the day's checks, not one lookup per charge.
        self::assertSame([], array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'attempt_id = a.id') && str_contains($sql, 'pagou_payment_operations'),
        )));
    }

    public function testInvoicesAreReadOnlyByIdAFewHundredAtATime(): void
    {
        $store = new PaymentAttemptStore($this->pdo);
        foreach (range(1, 450) as $invoice) {
            $this->pdo->prepare("INSERT INTO tblinvoices VALUES (?, 20, '2020-01-01', 'Unpaid')")->execute([$invoice]);
            $id = $store->ensureCurrent($invoice, 20, 'pix', 1000, '2020-01-01')['id'];
            $this->pdo->prepare("UPDATE pagou_payment_attempts SET remote_id = ?, status = 'pending', created_at = '2020-01-01 00:00:00.000000' WHERE id = ?")
                ->execute(['remote-' . $invoice, $id]);
        }
        $this->pdo->statements = [];

        self::assertCount(25, $this->periodic()->dueCharges($this->now, 25)['old']);

        $reads = $this->pdo->touching('tblinvoices');
        self::assertGreaterThan(1, count($reads));
        foreach ($reads as $sql) {
            self::assertMatchesRegularExpression('/^SELECT id, duedate FROM tblinvoices WHERE id IN \(\?(, \?){0,199}\)$/', $sql);
        }
    }

    public function testTheHistoryOfAChargeCheckedWithinTheDayIsNotReadAgain(): void
    {
        $store = new PaymentAttemptStore($this->pdo);
        foreach (range(1, 30) as $invoice) {
            $this->pdo->prepare("INSERT INTO tblinvoices VALUES (?, 20, '2020-01-01', 'Unpaid')")->execute([$invoice]);
            $id = $store->ensureCurrent($invoice, 20, 'pix', 1000, '2020-01-01')['id'];
            $this->pdo->prepare("UPDATE pagou_payment_attempts SET remote_id = ?, status = 'pending', created_at = '2020-01-01 00:00:00.000000' WHERE id = ?")
                ->execute(['remote-' . $invoice, $id]);
            // A long history, with the last check five hours ago.
            foreach (['-300 days', '-200 days', '-100 days', '-5 hours'] as $n => $offset) {
                $this->check($id, $invoice . '-' . $n, $offset);
            }
        }
        $this->pdo->statements = [];

        self::assertSame(['near' => [], 'old' => []], $this->periodic()->dueCharges($this->now, 25));
        self::assertSame([], array_values(array_filter(
            $this->pdo->touching('pagou_payment_operations'),
            static fn (string $sql): bool => str_contains($sql, 'attempt_id IN'),
        )));
    }

    public function testChargesNotCheckedWithinTheDayKeepTheLongestWaitingFirst(): void
    {
        $store = new PaymentAttemptStore($this->pdo);
        $ids = [];
        foreach (['-3 days', null, '-40 days', '-21 hours', '-10 days'] as $invoice => $lastCheck) {
            $this->pdo->prepare("INSERT INTO tblinvoices VALUES (?, 20, '2020-01-01', 'Unpaid')")->execute([$invoice + 1]);
            $id = $store->ensureCurrent($invoice + 1, 20, 'pix', 1000, '2020-01-01')['id'];
            $this->pdo->prepare("UPDATE pagou_payment_attempts SET remote_id = ?, status = 'pending', created_at = '2020-01-01 00:00:00.000000' WHERE id = ?")
                ->execute(['remote-' . $invoice, $id]);
            if ($lastCheck !== null) {
                $this->check($id, (string) $invoice, '-400 days');
                $this->check($id, $invoice . '-last', $lastCheck);
            }
            $ids[$lastCheck ?? 'never'] = $id;
        }

        $old = array_column($this->periodic()->dueCharges($this->now, 25)['old'], 'id');

        self::assertSame([$ids['never'], $ids['-40 days'], $ids['-10 days'], $ids['-3 days'], $ids['-21 hours']], $old);
    }

    public function testARunStillGoingAfterAMinuteIsNotOverlappedByTheNext(): void
    {
        $this->seedWithAnEmptyQueue(11);
        $overlapping = null;
        $this->pdo->before = function (string $sql) use (&$overlapping): void {
            if ($overlapping !== null || !str_contains($sql, 'tblinvoices')) {
                return;
            }
            $overlapping = 0;
            // As if the first run had been reading for 70 seconds when the next minute starts.
            $this->pdo->exec("UPDATE pagou_leases SET lease_until = datetime(lease_until, '-70 seconds')");
            $overlapping = $this->periodic()->schedule(15, $this->now);
        };

        self::assertGreaterThan(0, $this->periodic()->schedule(15, $this->now));
        self::assertSame(0, $overlapping);
    }

    public function testTheNextRunWaitsAMinuteFromTheStartOfTheLastOneEvenAfterAnError(): void
    {
        $this->seedWithAnEmptyQueue(11);
        $startedAt = time();
        $this->pdo->before = static function (string $sql): void {
            if (str_contains($sql, 'FROM tblinvoices')) {
                throw new \RuntimeException('database went away');
            }
        };
        try {
            $this->periodic()->schedule(15, $this->now);
            self::fail('The error must reach the worker, which logs it.');
        } catch (\RuntimeException) {
        }
        $this->pdo->before = null;

        $until = (string) $this->pdo->query("SELECT lease_until FROM pagou_leases WHERE name = 'periodic-pix-boleto'")->fetchColumn();
        // Fifty seconds from the start, so the cron of the next minute always runs.
        self::assertLessThanOrEqual(gmdate('Y-m-d H:i:s', $startedAt + 51), $until);
        self::assertGreaterThanOrEqual(gmdate('Y-m-d H:i:s', $startedAt + 50), $until);
        self::assertSame(0, $this->periodic()->schedule(15, $this->now));
    }

    public function testAChargeIsQueuedOnceAcrossTheQuarterHour(): void
    {
        $this->seedWithAnEmptyQueue(23);
        $quarter = (intdiv($this->now->getTimestamp(), 900) + 1) * 900;
        $first = $this->periodic()->schedule(10, (new DateTimeImmutable('@' . ($quarter - 1)))->setTimezone(new DateTimeZone('UTC')));
        $this->pdo->exec("UPDATE pagou_leases SET lease_until = '2000-01-01 00:00:00'");
        $second = $this->periodic()->schedule(10, (new DateTimeImmutable('@' . ($quarter + 1)))->setTimezone(new DateTimeZone('UTC')));

        self::assertGreaterThan(0, $first);
        self::assertGreaterThan(0, $second);
        // The checks of the first run are still queued: the second takes other charges.
        self::assertSame($first + $second, (int) $this->pdo->query("SELECT COUNT(DISTINCT attempt_id) FROM pagou_payment_operations WHERE status = 'queued'")->fetchColumn());
    }

    /** Random charges whose earlier checks all ended, so the queue has room. */
    private function seedWithAnEmptyQueue(int $seed): void
    {
        PeriodicSelectionFixture::seed($this->pdo, $this->now, $seed, 300);
        $this->pdo->exec("UPDATE pagou_payment_operations SET status = 'succeeded' WHERE status IN ('queued', 'pending', 'leased', 'retrying')");
    }

    private function periodic(): PeriodicReconciliation
    {
        return new PeriodicReconciliation($this->pdo, new OperationScheduler(new PdoOperationOutbox($this->pdo), new SystemAsyncClock()));
    }

    private function check(string $attemptId, string $key, string $offset): void
    {
        $at = $this->now->modify($offset)->format('Y-m-d H:i:s.u');
        $this->pdo->prepare("INSERT INTO pagou_payment_operations (id, operation_type, status, deduplication_key, priority, payload_json, available_at, created_at, updated_at, attempt_id) VALUES (?, 'reconcile_payment', 'succeeded', ?, 10, '{}', ?, ?, ?, ?)")
            ->execute(['check-' . $key, 'check-' . $key, $at, $at, $at, $attemptId]);
    }
}
