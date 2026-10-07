<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\PaymentAttemptStore;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class PaymentAttemptStoreTest extends TestCase
{
    public function testUnchangedInvoiceReusesTheCurrentAttempt(): void
    {
        $store = new PaymentAttemptStore($this->pdo());

        $first = $store->ensureCurrent(10, 20, 'pix', 1250, '2026-09-10');
        $second = $store->ensureCurrent(10, 20, 'pix', 1250, '2026-09-10');

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, $second['revision']);
        self::assertSame([], $second['superseded']);
    }

    public function testChangedInvoiceCreatesANewRevisionAndSupersedesTheOldCharge(): void
    {
        $pdo = $this->pdo();
        $store = new PaymentAttemptStore($pdo);
        $first = $store->ensureCurrent(10, 20, 'boleto', 1250, '2026-09-10');
        $store->complete($first['id'], 'remote-old', 'ready', ['state' => 'ready']);

        $second = $store->ensureCurrent(10, 20, 'boleto', 1500, '2026-09-11');

        self::assertTrue($second['created']);
        self::assertSame(2, $second['revision']);
        self::assertSame(
            [['id' => $first['id'], 'method' => 'boleto', 'remote_id' => 'remote-old']],
            $second['superseded'],
        );
        self::assertSame('cancel_requested', $store->find($first['id'])['status'] ?? null);
    }

    public function testGatewayChangeKeepsTheOtherChargePayableAndReusesItOnTheWayBack(): void
    {
        $pdo = $this->pdo();
        $store = new PaymentAttemptStore($pdo);
        $first = $store->ensureCurrent(10, 20, 'pix', 1250, '2026-09-10');
        $store->complete($first['id'], 'remote-pix', 'ready', ['state' => 'ready']);

        // A boleto sent by e-mail must keep working after the customer looks at Pix, and vice versa.
        $second = $store->ensureCurrent(10, 20, 'boleto', 1250, '2026-09-10');
        self::assertTrue($second['created']);
        self::assertSame(2, $second['revision']);
        self::assertSame([], $second['superseded']);
        self::assertSame('ready', $store->find($first['id'])['status']);
        self::assertSame([$first['id']], array_column($store->activeOtherMethods(10, 'boleto'), 'id'));

        $back = $store->ensureCurrent(10, 20, 'pix', 1250, '2026-09-10');
        self::assertFalse($back['created']);
        self::assertSame($first['id'], $back['id']);
        self::assertSame(1, $back['revision']);

        // A changed amount replaces only the charge of the same method.
        $changed = $store->ensureCurrent(10, 20, 'pix', 1500, '2026-09-10');
        self::assertTrue($changed['created']);
        self::assertSame(3, $changed['revision']);
        self::assertSame([['id' => $first['id'], 'method' => 'pix', 'remote_id' => 'remote-pix']], $changed['superseded']);
        self::assertSame('queued', $store->find($second['id'])['status']);
        // A charge never issued at Pagou is retired without a remote cancellation.
        self::assertSame([], $store->supersedeAttempts(10, [$second['id']]));
        self::assertSame('superseded', $store->find($second['id'])['status']);
    }

    public function testSplitSnapshotIsReplacedAsOneProjectionWithoutDuplicatingAllocations(): void
    {
        $pdo = $this->pdo();
        $store = new PaymentAttemptStore($pdo);
        $attempt = $store->ensureCurrent(10, 20, 'pix', 1250);
        $snapshot = [
            'schema' => 'pagou-payment-split-v1',
            'fee' => '1.00',
            'status' => 'completed',
            'allocations' => [['id' => 'allocation-1', 'resolved_value' => '5.00']],
        ];

        $store->complete($attempt['id'], 'remote-pix', 'paid', ['state' => 'paid'], $snapshot);
        $store->complete($attempt['id'], 'remote-pix', 'paid', ['state' => 'paid'], $snapshot);

        $row = $store->find($attempt['id']);
        self::assertNotNull($row);
        $stored = json_decode((string) $row['split_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $stored['allocations']);
        self::assertSame('allocation-1', $stored['allocations'][0]['id']);
    }

    public function testDisplayProjectionDoesNotOverwriteCanonicalProviderResponse(): void
    {
        $pdo = $this->pdo();
        $store = new PaymentAttemptStore($pdo);
        $attempt = $store->ensureCurrent(10, 20, 'boleto', 1250, '2026-09-10');
        $canonical = json_encode(['artifacts' => ['pdf_url' => 'https://example.test/boleto.pdf']], JSON_THROW_ON_ERROR);
        $pdo->prepare('UPDATE pagou_payment_attempts SET response_json = :response WHERE id = :id')->execute([
            'response' => $canonical,
            'id' => $attempt['id'],
        ]);

        $store->complete($attempt['id'], 'remote-boleto', 'ready', ['state' => 'ready', 'pdfUrl' => '/download']);

        self::assertSame($canonical, $store->find($attempt['id'])['response_json'] ?? null);
        self::assertSame('/download', $store->displayForInvoice(10, 'boleto')['pdfUrl'] ?? null);
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);

        return $pdo;
    }
}
