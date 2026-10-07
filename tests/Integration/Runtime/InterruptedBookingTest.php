<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\{AddonSettings, ClientPaymentStatus, PaymentAttemptStore, PaymentIdentityStore, WhmcsRuntime};
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\{PdoInvoiceClientResolver, PdoLedgerRepository, PdoReconciliationFindingRepository};
use Pagou\Whmcs\Payment\Ledger\ReceivedPayment;
use PHPUnit\Framework\TestCase;

/**
 * WHMCS books the payment and marks the invoice paid before running every
 * InvoicePaid hook inside the same call. The module must report the payment at
 * once and settle its ledger even if that call never returns to it.
 */
final class InterruptedBookingTest extends TestCase
{
    private PDO $pdo;
    private string $attempt;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, total TEXT, credit TEXT, status TEXT)');
        $this->pdo->exec('CREATE TABLE tblaccounts (invoiceid INTEGER, transid TEXT, gateway TEXT, amountin TEXT, amountout TEXT)');
        $this->pdo->exec("INSERT INTO tblinvoices VALUES (19, 7, '10.00', '0.00', 'Unpaid')");
        $store = new PaymentAttemptStore($this->pdo);
        $this->attempt = $store->ensureCurrent(19, 7, 'pix', 1000, '2026-09-20')['id'];
        $store->complete($this->attempt, 'pix-original', 'paid', ['state' => 'paid']);
        (new PaymentIdentityStore($this->pdo))->snapshot($this->attempt, ['name' => 'Cliente', 'document' => '52998224725']);
        // The ledger claimed and began the booking; the process stopped while WHMCS ran its hooks.
        $ledger = $this->ledger();
        $key = EconomicPaymentKey::fromRemotePayment(19, 'pagou', 'receipt-19');
        $paidAt = new \DateTimeImmutable('2026-10-07T02:19:00Z');
        $payment = new ReceivedPayment($key, 'evt', 19, 1000, 'pagou', 'receipt-19', $paidAt, 'pix', 'pix-original', 'kk6g232xel65a0daee4dd13kk3087227315');
        $ledger->begin($ledger->claim($payment)->key);
    }

    public function testABookedPaymentIsConfirmedAtOnceForTheClientAndTheAdministrator(): void
    {
        $status = new ClientPaymentStatus($this->pdo);
        self::assertSame('processing', $status->read(19, 'pix', 7, false)['state']);
        self::assertFalse((new PaymentIdentityStore($this->pdo))->forInvoice(19)[0]['paid']);

        $this->book();
        self::assertSame('paid', $status->read(19, 'pix', 7, false)['state']);
        self::assertTrue((new PaymentIdentityStore($this->pdo))->forInvoice(19)[0]['paid']);
    }

    public function testTheWorkerSettlesTheInterruptedBookingWithoutBookingAgain(): void
    {
        $this->book();
        $calls = [];
        $runtime = $this->runtime($calls);
        // A booking still in progress elsewhere is left alone during the grace period.
        $runtime->runWorker('worker-1');
        self::assertSame(0, $this->transitions('applied'));
        $this->age(300);
        $runtime->runWorker('worker-2');
        self::assertSame(1, $this->transitions('applied'));
        self::assertNotContains('AddInvoicePayment', array_column($calls, 0));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn());
    }

    public function testAnInterruptedBookingMissingInWhmcsIsQuarantinedAndNeverRetried(): void
    {
        $calls = [];
        $runtime = $this->runtime($calls);
        $this->age(300);
        $runtime->runWorker('worker-1');
        self::assertSame(0, $this->transitions('quarantined'));
        $this->age(1200);
        $runtime->runWorker('worker-2');
        self::assertSame(1, $this->transitions('quarantined'));
        self::assertSame(0, $this->transitions('applied'));
        self::assertNotContains('AddInvoicePayment', array_column($calls, 0));
        self::assertSame('processing', (new ClientPaymentStatus($this->pdo))->read(19, 'pix', 7, false)['state']);
    }

    private function book(): void
    {
        $this->pdo->exec("INSERT INTO tblaccounts VALUES (19, 'kk6g232xel65a0daee4dd13kk3087227315', 'pagou_pix', '10.00', '0.00')");
        $this->pdo->exec("UPDATE tblinvoices SET status = 'Paid'");
    }

    private function age(int $seconds): void
    {
        $at = gmdate('Y-m-d H:i:s', time() - $seconds);
        $this->pdo->prepare("UPDATE pagou_ledger_entries SET created_at = ? WHERE entry_type = 'received_payment_transition'")->execute([$at]);
    }

    private function transitions(string $status): int
    {
        $count = 0;
        foreach ($this->pdo->query("SELECT metadata_json FROM pagou_ledger_entries WHERE entry_type = 'received_payment_transition'")->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $count += (json_decode((string) $json, true)['status'] ?? '') === $status ? 1 : 0;
        }

        return $count;
    }

    private function ledger(): PdoLedgerRepository
    {
        return new PdoLedgerRepository($this->pdo, new PdoInvoiceClientResolver($this->pdo), new PdoReconciliationFindingRepository($this->pdo));
    }

    /** @param list<array{string, array<string, mixed>}> $calls */
    private function runtime(array &$calls): WhmcsRuntime
    {
        return new WhmcsRuntime($this->pdo, new AddonSettings([]), new PdoOperationOutbox($this->pdo), static function (string $command, array $params) use (&$calls): array {
            $calls[] = [$command, $params];
            return ['result' => 'success'];
        });
    }
}
