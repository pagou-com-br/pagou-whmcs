<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use DateTimeImmutable;
use PDO;
use Pagou\Whmcs\Application\Async\{OperationScheduler, SystemAsyncClock};
use Pagou\Whmcs\Application\Runtime\{AddonSettings, PaymentAttemptStore, PeriodicReconciliation, WhmcsRuntime};
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class PeriodicTiersTest extends TestCase
{
    private PDO $pdo;
    private PaymentAttemptStore $store;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, duedate TEXT, status TEXT)');
        $this->store = new PaymentAttemptStore($this->pdo);
        // Due dates follow the clock: the button reads the current time itself.
        $this->now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function testEachChargeIsCheckedAsOftenAsItsGroupAsks(): void
    {
        // Needs attention: every 15 minutes, however old the invoice.
        $uncertain = $this->charge(1, '-700 days', 'uncertain', '-400 days', '-20 minutes');
        // Recent: every hour.
        $recentChecked = $this->charge(2, '-5 days', 'pending', '-10 days', '-30 minutes');
        $recentDue = $this->charge(3, '-5 days', 'pending', '-10 days', '-70 minutes');
        $upcoming = $this->charge(4, '+14 days', 'pending', '-10 days', '-70 minutes');
        // Old: about once a day.
        $oldChecked = $this->charge(5, '-540 days', 'pending', '-300 days', '-5 hours');
        $oldDue = $this->charge(6, '-540 days', 'pending', '-300 days', '-21 hours');
        $neverChecked = $this->charge(7, '-540 days', 'pending', '-300 days', null);
        // A new Pix for an old invoice may be paid right now: it counts as recent.
        $freshPix = $this->charge(8, '-1000 days', 'pending', '-1 day', '-70 minutes');

        self::assertSame(6, $this->periodic()->schedule(10, $this->now));
        self::assertEqualsCanonicalizing([$uncertain, $recentDue, $upcoming, $freshPix, $oldDue, $neverChecked], $this->scheduledAttempts());
        self::assertNotContains($recentChecked, $this->scheduledAttempts());
        self::assertNotContains($oldChecked, $this->scheduledAttempts());
    }

    public function testAChargeListedForRepeatedFailuresIsCheckedOnceADay(): void
    {
        $failing = $this->charge(1, '-5 days', 'uncertain', '-10 days', '-1 hour');
        $waiting = $this->charge(2, '-5 days', 'uncertain', '-10 days', '-21 hours');
        foreach (['reconciliation_keeps_failing' => $failing, 'pix_refund_not_recorded' => $waiting] as $type => $attempt) {
            $this->pdo->prepare("INSERT INTO pagou_reconciliation_findings (id, finding_key, attempt_id, severity, finding_type, status, details_json, detected_at_utc, created_at, updated_at) VALUES (?, ?, ?, 'medium', ?, 'open', '{}', '2026-11-05', '2026-11-05', '2026-11-05')")
                ->execute([$type, $type, $attempt, $type]);
        }

        // Checked an hour ago, it would be due again every 15 minutes without the listing.
        self::assertSame(1, $this->periodic()->schedule(10, $this->now));
        self::assertSame([$waiting], $this->scheduledAttempts());
    }

    public function testOldChargesKeepAFewPlacesWhileRecentOnesFillTheQueue(): void
    {
        $recent = [];
        foreach (range(1, 12) as $invoice) {
            $recent[] = $this->charge($invoice, '-5 days', 'pending', '-10 days', null);
        }
        $old = [];
        foreach (range(21, 25) as $invoice) {
            $old[] = $this->charge($invoice, '-540 days', 'pending', '-300 days', null);
        }

        self::assertSame(10, $this->periodic()->schedule(10, $this->now));
        $scheduled = $this->scheduledAttempts();
        self::assertCount(7, array_intersect($scheduled, $recent));
        self::assertCount(3, array_intersect($scheduled, $old));
    }

    public function testOldChargesTakeThePlacesTheRecentOnesLeave(): void
    {
        $this->charge(1, '-5 days', 'pending', '-10 days', null);
        foreach (range(21, 35) as $invoice) {
            $this->charge($invoice, '-540 days', 'pending', '-300 days', null);
        }

        self::assertSame(10, $this->periodic()->schedule(10, $this->now));
    }

    public function testTheButtonSkipsSettledPaidChargesAndChecksTheRecentOnesBeforeTheOldOnes(): void
    {
        $settled = $this->charge(1, '-5 days', 'paid', '-10 days', null);
        $paidInReview = $this->charge(2, '-5 days', 'paid', '-10 days', null);
        $this->pdo->prepare("INSERT INTO pagou_reconciliation_findings (id, finding_key, attempt_id, severity, finding_type, status, details_json, detected_at_utc, created_at, updated_at) VALUES ('review', 'review', ?, 'high', 'received_after_lookup_booking', 'open', '{}', '2026-11-05', '2026-11-05', '2026-11-05')")
            ->execute([$paidInReview]);
        $old = $this->charge(3, '-540 days', 'pending', '-300 days', null);
        $recent = $this->charge(4, '-5 days', 'pending', '-10 days', null);
        // The old charge was updated earlier: the old order would have taken it first.
        $this->pdo->prepare('UPDATE pagou_payment_attempts SET updated_at = ? WHERE id = ?')->execute([$this->now->modify('-540 days')->format('Y-m-d H:i:s'), $old]);
        $runtime = new WhmcsRuntime($this->pdo, new AddonSettings([]), new PdoOperationOutbox($this->pdo), static fn (): array => ['result' => 'success']);

        self::assertSame(2, $runtime->schedulePendingReconciliation(2));
        self::assertEqualsCanonicalizing([$paidInReview, $recent], $this->scheduledAttempts());
        $runtime->schedulePendingReconciliation(10);
        self::assertEqualsCanonicalizing([$paidInReview, $recent, $old], $this->scheduledAttempts());
        self::assertNotContains($settled, $this->scheduledAttempts());
    }

    private function periodic(): PeriodicReconciliation
    {
        return new PeriodicReconciliation($this->pdo, new OperationScheduler(new PdoOperationOutbox($this->pdo), new SystemAsyncClock()));
    }

    private function charge(int $invoice, string $due, string $status, string $created, ?string $lastCheck): string
    {
        $dueDate = $this->now->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->modify($due)->format('Y-m-d');
        $this->pdo->prepare("INSERT INTO tblinvoices VALUES (?, 20, ?, 'Unpaid')")->execute([$invoice, $dueDate]);
        $id = $this->store->ensureCurrent($invoice, 20, 'pix', 1000, $dueDate)['id'];
        $this->pdo->prepare('UPDATE pagou_payment_attempts SET remote_id = ?, status = ?, created_at = ? WHERE id = ?')
            ->execute(['remote-' . $invoice, $status, $this->now->modify($created)->format('Y-m-d H:i:s.u'), $id]);
        if ($lastCheck !== null) {
            $at = $this->now->modify($lastCheck)->format('Y-m-d H:i:s.u');
            $this->pdo->prepare("INSERT INTO pagou_payment_operations (id, operation_type, status, deduplication_key, priority, payload_json, available_at, created_at, updated_at, attempt_id) VALUES (?, 'reconcile_payment', 'succeeded', ?, 10, '{}', ?, ?, ?, ?)")
                ->execute(['check-' . $invoice, 'check-' . $invoice, $at, $at, $at, $id]);
        }

        return $id;
    }

    /** @return list<string> */
    private function scheduledAttempts(): array
    {
        return $this->pdo->query("SELECT attempt_id FROM pagou_payment_operations WHERE status <> 'succeeded'")->fetchAll(PDO::FETCH_COLUMN);
    }
}
