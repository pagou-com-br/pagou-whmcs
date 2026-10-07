<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Pix\Persistence;

use PDO;
use Pagou\Whmcs\Application\Async\InMemoryOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Payment\Pix\Infrastructure\OutboxReconciliationScheduler;
use Pagou\Whmcs\Payment\Pix\Infrastructure\PdoOperationJournal;
use PHPUnit\Framework\TestCase;

final class PdoOperationJournalTest extends TestCase
{
    public function testJournalPersistsOnlyAllowlistedContextAndTransitions(): void
    {
        $pdo = $this->pdo();
        $journal = new PdoOperationJournal($pdo);
        $journal->started('pix.create', 'key-123', [
            'attempt_id' => 'attempt-1', 'invoice_id' => '42', 'payer' => ['document' => 'never-store-this'],
        ]);
        $journal->succeeded('pix.create', 'key-123', ['remote_id' => 'pix-1', 'status' => 'pending', 'token' => 'never-store-this']);

        $statement = $pdo->query("SELECT status, request_json, response_json FROM pagou_payment_operations WHERE idempotency_key = 'key-123'");
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('succeeded', $row['status']);
        self::assertSame(['attempt_id' => 'attempt-1', 'invoice_id' => '42'], json_decode($row['request_json'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['remote_id' => 'pix-1', 'status' => 'pending'], json_decode($row['response_json'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testJournalFailsClosedWithoutAttemptCorrelation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PdoOperationJournal($this->pdo()))->started('pix.create', 'key-123', ['invoice_id' => '42']);
    }

    public function testUncertainOperationQueuesLocalReconciliation(): void
    {
        $outbox = new InMemoryOperationOutbox();
        (new OutboxReconciliationScheduler($outbox))->schedule('pix.create', 'key-123', ['attempt_id' => 'attempt-1', 'pix_id' => 'pix-1']);

        $lease = $outbox->claim('worker-1', new \DateTimeImmutable('now', new \DateTimeZone('UTC')), new \DateInterval('PT30S'));
        self::assertNotNull($lease);
        self::assertSame('reconcile_uncertain_operation', $lease->job->type->value);
        self::assertSame('attempt-1', $lease->job->payload['attempt_id']);
        self::assertSame('pix-1', $lease->job->payload['pix_id']);
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 4) . '/package/modules/addons/pagou_payments/migrations.php');
        return $pdo;
    }
}
