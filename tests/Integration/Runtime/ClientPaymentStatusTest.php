<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\ClientPaymentStatus;
use Pagou\Whmcs\Application\Runtime\PaymentAttemptStore;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class ClientPaymentStatusTest extends TestCase
{
    public function testStatusRequiresOwnershipOrAuthorizedInvoiceAdmin(): void
    {
        [$pdo, $store] = $this->fixture();
        $status = new ClientPaymentStatus($pdo);
        self::assertSame(['status' => 'forbidden'], $status->read(10, 'pix', 0, false));
        self::assertSame(['status' => 'not_found'], $status->read(10, 'pix', 21, false));
        self::assertSame(['status' => 'not_found'], $status->read(11, 'pix', 0, true));
        self::assertSame(['status' => 'forbidden'], $status->read(10, 'unknown', 20, false));
        $snapshot = $status->read(10, 'pix', 20, false);
        self::assertSame('ok', $snapshot['status']);
        self::assertSame($snapshot, $status->read(10, 'pix', 0, true));
        self::assertSame(hash('sha256', json_encode($store->displayForInvoice(10, 'pix'), JSON_THROW_ON_ERROR)), $snapshot['revision']);
        self::assertArrayNotHasKey('copyPaste', $snapshot);
    }

    public function testReadsWebhookProjectionAndNativeInvoiceChangesWithoutWriting(): void
    {
        [$pdo, $store, $attempt] = $this->fixture();
        $status = new ClientPaymentStatus($pdo);
        $initial = $status->read(10, 'pix', 20, false);
        $store->complete($attempt, 'test-remote', 'paid', ['state' => 'paid']);
        $paid = $status->read(10, 'pix', 20, false);
        self::assertSame('processing', $paid['state']);
        self::assertNotSame($initial['revision'], $paid['revision']);
        self::assertSame('unpaid', $paid['invoiceState']);
        $pdo->exec("UPDATE tblinvoices SET status = 'Paid' WHERE id = 10");
        $changes = $pdo->query('SELECT total_changes()')->fetchColumn();
        self::assertSame('paid', $status->read(10, 'pix', 20, false)['invoiceState']);
        // A native invoice paid by another method cannot confirm this Pix receipt.
        self::assertSame('processing', $status->read(10, 'pix', 20, false)['state']);
        self::assertSame($changes, $pdo->query('SELECT total_changes()')->fetchColumn());
    }

    public function testCardPollingIsAuthorizedReadOnlyAndTracksDelayedConfirmation(): void
    {
        [$pdo] = $this->fixture();
        $status = new ClientPaymentStatus($pdo);
        self::assertSame('not_started', $status->read(10, 'card', 20, false)['state']);
        $store = new \Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardAttemptStore($pdo);
        $attempt = $store->begin(10, 20, 1200, 'private-card-reference', 1);
        self::assertSame('dispatching', $status->read(10, 'card', 20, false)['state']);
        self::assertSame(['status' => 'not_found'], $status->read(10, 'card', 99, false));
        $store->uncertain($attempt['id']);
        $before = $status->read(10, 'card', 20, false);
        $store->complete($attempt['id'], new \Pagou\Whmcs\Payment\Card\Dto\CardCharge('private-remote', \Pagou\Whmcs\Payment\Card\CardStatus::Paid, 1200), 'private-card-reference', null, null, 1);
        $changes = $pdo->query('SELECT total_changes()')->fetchColumn();
        $paid = $status->read(10, 'card', 0, true);
        self::assertSame('paid', $paid['state']);
        self::assertNotSame($before['revision'], $paid['revision']);
        self::assertSame($changes, $pdo->query('SELECT total_changes()')->fetchColumn());
        self::assertStringNotContainsString('private-', json_encode($paid));
    }

    /** @return array{PDO, PaymentAttemptStore, string} */
    private function fixture(): array
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT)');
        $pdo->exec("INSERT INTO tblinvoices VALUES (10, 20, 'Unpaid')");
        $store = new PaymentAttemptStore($pdo);
        $attempt = $store->ensureCurrent(10, 20, 'pix', 1200, '2026-10-02');
        $store->complete($attempt['id'], 'test-remote', 'pending', ['state' => 'pending', 'copyPaste' => 'test-only']);

        return [$pdo, $store, $attempt['id']];
    }
}
