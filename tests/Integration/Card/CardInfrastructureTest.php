<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Card;

use PDO;
use Pagou\Whmcs\Application\Async\InMemoryOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Payment\Card\Infrastructure\OutboxCardReconciliationScheduler;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardOperationJournal;
use PHPUnit\Framework\TestCase;

final class CardInfrastructureTest extends TestCase
{
    public function testJournalUsesHashesAndPersistsOnlyAllowlistedContext(): void
    {
        $pdo = $this->pdo();
        $journal = new PdoCardOperationJournal($pdo);
        $key = str_repeat('x', 77);
        $journal->started('card.charge.create', $key, ['attempt_id' => 'attempt-1', 'customer_id' => 'customer-1', 'pan' => 'never-store', 'token' => 'never-store']);
        $journal->succeeded('card.charge.create', $key, ['remote_id' => 'charge-1', 'status' => 'paid', 'three_ds_token' => 'never-store']);
        $row = $pdo->query('SELECT idempotency_key, deduplication_key, request_json, response_json FROM pagou_payment_operations')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(64, strlen($row['idempotency_key']));
        self::assertSame(64, strlen($row['deduplication_key']));
        self::assertSame(['attempt_id' => 'attempt-1', 'customer_id' => 'customer-1'], json_decode($row['request_json'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['remote_id' => 'charge-1', 'status' => 'paid'], json_decode($row['response_json'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testUncertainCardOperationSchedulesDeduplicatedLocalReconciliation(): void
    {
        $outbox = new InMemoryOperationOutbox();
        $scheduler = new OutboxCardReconciliationScheduler($outbox);
        $scheduler->schedule('card.charge.create', 'key-1', ['attempt_id' => 'attempt-1', 'charge_id' => 'charge-1', 'pan' => 'never-store']);
        $lease = $outbox->claim('worker-1', new \DateTimeImmutable('now', new \DateTimeZone('UTC')), new \DateInterval('PT30S'));
        self::assertNotNull($lease);
        self::assertSame('reconcile_uncertain_operation', $lease->job->type->value);
        self::assertSame('attempt-1', $lease->job->payload['attempt_id']);
        self::assertSame('charge-1', $lease->job->payload['charge_id']);
        self::assertSame(64, strlen($lease->job->deduplicationKey));
        self::assertArrayNotHasKey('pan', $lease->job->payload);
    }

    public function testJournalAcceptsAnIdempotentTerminalReplay(): void
    {
        $pdo = $this->pdo();
        $journal = new PdoCardOperationJournal($pdo);

        $journal->started('card.charge.capture', 'same-key', ['charge_id' => 'charge-1']);
        $journal->succeeded('card.charge.capture', 'same-key', ['remote_id' => 'charge-1']);
        $journal->started('card.charge.capture', 'same-key', ['charge_id' => 'charge-1']);
        $journal->succeeded('card.charge.capture', 'same-key', ['remote_id' => 'charge-1']);

        self::assertSame('succeeded', $pdo->query('SELECT status FROM pagou_payment_operations')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM pagou_payment_operations')->fetchColumn());
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        return $pdo;
    }
}
