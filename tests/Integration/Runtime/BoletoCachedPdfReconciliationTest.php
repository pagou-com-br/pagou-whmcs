<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use DateTimeImmutable;
use PDO;
use Pagou\Whmcs\Application\Async\{JobPriority, OperationJob, OperationResult, OperationType};
use Pagou\Whmcs\Application\Runtime\{AddonSettings, PaymentAttemptStore, WhmcsRuntime};
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Payment\Boleto\Domain\{BoletoArtifacts, BoletoAttempt, BoletoCharge};
use Pagou\Whmcs\Payment\Boleto\Infrastructure\PdoBoletoAttemptRepository;
use PHPUnit\Framework\TestCase;

final class BoletoCachedPdfReconciliationTest extends TestCase
{
    public function testReconciliationKeepsDownloadedPdfAndDoesNotScheduleItAgain(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $repository = new PdoBoletoAttemptRepository($pdo, 20);
        $attempt = $this->cachedAttempt();
        $repository->save($attempt);
        $runtime = new WhmcsRuntime($pdo, new AddonSettings([]), new PdoOperationOutbox($pdo), static function (): array {
            self::fail('Refreshing boleto artifacts must not issue a charge or send email.');
        }, static fn (): BoletoCharge => new BoletoCharge('remote', 'registered', 1200, new BoletoArtifacts('line', 'barcode', 'pix'), '2026-10-02', null));
        $now = new DateTimeImmutable('now');
        $job = new OperationJob('reconcile', OperationType::ReconcilePayment, 'lookup', JobPriority::Issuance, ['attempt_id' => 'attempt'], $now, $now);
        for ($i = 0; $i < 2; $i++) {
            $outcome = (new \ReflectionMethod($runtime, 'reconcileOperation'))->invoke($runtime, $job);
            self::assertSame(OperationResult::Succeeded, $outcome->result);
            self::assertSame('boleto/remote.pdf', $repository->get('attempt')?->artifacts?->localPdfKey);
            self::assertSame('modules/addons/pagou_payments/download.php?attempt=attempt', (new PaymentAttemptStore($pdo))->displayForInvoice(10, 'boleto')['pdfUrl']);
        }
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM pagou_payment_operations WHERE operation_type='fetch_boleto_pdf'")->fetchColumn());
    }

    public function testPaidChargeWithoutWebhookEvidenceNeverInventsInvoiceCredit(): void
    {
        foreach (['pix', 'boleto'] as $method) {
            $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            (new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
            $store = new PaymentAttemptStore($pdo);
            $attempt = $store->ensureCurrent(10, 20, $method, 1200, '2026-10-02');
            $store->complete($attempt['id'], 'remote', 'ready', ['state' => 'ready']);
            $runtime = new WhmcsRuntime($pdo, new AddonSettings([]), new PdoOperationOutbox($pdo), static function (): array {
                self::fail('A read without signed economic evidence must not credit the invoice.');
            }, static fn () => $method === 'boleto'
                ? new BoletoCharge('remote', 'paid', 1200, new BoletoArtifacts('line', 'barcode'), '2026-10-02', null)
                : (new \Pagou\Whmcs\Payment\Pix\Mapper\PixResponseMapper())->charge(['id' => 'remote', 'status' => 'completed', 'amount' => '12.00']));
            $now = new DateTimeImmutable('now');
            $job = new OperationJob('reconcile', OperationType::ReconcilePayment, 'lookup', JobPriority::Maintenance, ['attempt_id' => $attempt['id']], $now, $now);
            for ($i = 0; $i < 2; $i++) {
                $outcome = (new \ReflectionMethod($runtime, 'reconcileOperation'))->invoke($runtime, $job);
                self::assertSame(OperationResult::Uncertain, $outcome->result);
                self::assertSame('payment_evidence_missing', $outcome->reason);
            }
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_ledger_entries')->fetchColumn());
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM pagou_reconciliation_findings')->fetchColumn());
            self::assertSame('processing', $store->displayForInvoice(10, $method)['state']);
        }
    }

    public function testPdfOfAnotherRemoteChargeIsNeverReused(): void
    {
        $other = new BoletoCharge('different-remote', 'registered', 1200, new BoletoArtifacts('line', 'barcode'), '2026-10-02', null);
        self::assertNull($this->cachedAttempt()->issued($other)->artifacts?->localPdfKey);
    }

    public function testInteractiveRegistrationReleasesInvoiceWhilePdfRemainsForBackgroundWorker(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, status TEXT)');
        $pdo->exec("INSERT INTO tblinvoices VALUES (10, 'Unpaid')");
        $repository = new PdoBoletoAttemptRepository($pdo, 20);
        $repository->save(new BoletoAttempt('attempt', '10', 1, 1200, '2026-10-02', 'idem', BoletoAttempt::AWAITING_REGISTRATION, 'remote'));
        $outbox = new PdoOperationOutbox($pdo);
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $outbox->enqueue(new OperationJob('refresh', OperationType::ReconcilePayment, 'refresh', JobPriority::Issuance, ['attempt_id' => 'attempt'], $now, $now));
        $lookups = 0;
        $runtime = new WhmcsRuntime($pdo, new AddonSettings([]), $outbox, static function (): array {
            self::fail('Interactive registration must not send email.');
        }, static function () use (&$lookups): BoletoCharge {
            return ++$lookups === 1
                ? new BoletoCharge('remote', 'processing', 1200, new BoletoArtifacts(), '2026-10-02', null)
                : new BoletoCharge('remote', 'registered', 1200, new BoletoArtifacts('line', 'barcode'), '2026-10-02', null);
        });
        self::assertSame(1, $runtime->advanceInvoice(10)->retried);
        self::assertSame(0, $outbox->find('refresh')?->attempts);
        self::assertSame(0, $runtime->advanceInvoice(10)->claimed);
        self::assertStringContainsString('Preparando seu boleto', $runtime->renderInvoice(['invoiceid' => 10, 'amount' => '12.00'], 'boleto'));
        $pdo->exec("UPDATE pagou_payment_operations SET available_at='2000-01-01 00:00:00' WHERE id='refresh'");
        self::assertSame(1, $runtime->advanceInvoice(10)->succeeded);
        self::assertSame(2, $lookups);
        self::assertSame('queued', $pdo->query("SELECT status FROM pagou_payment_operations WHERE operation_type='fetch_boleto_pdf'")->fetchColumn());
        self::assertNull($repository->get('attempt')?->artifacts?->localPdfKey);
        $html = $runtime->renderInvoice(['invoiceid' => 10, 'amount' => '12.00'], 'boleto');
        self::assertStringContainsString('https://fatura.pagou.com.br/boleto/remote', $html);
        self::assertStringContainsString('data-pagou-connection role="status" aria-live="polite" hidden', $html);
        self::assertStringContainsString('https://fatura.pagou.com.br/boleto/pdf/remote', $html);
        self::assertStringNotContainsString('Preparando seu boleto', $html);
        self::assertStringNotContainsString('data-pagou-progress-token=', $html);
    }

    private function cachedAttempt(): BoletoAttempt
    {
        return new BoletoAttempt('attempt', '10', 1, 1200, '2026-10-02', 'idem', BoletoAttempt::READY, 'remote', new BoletoArtifacts('line', 'barcode', 'pix', null, 'https://fatura.pagou.com.br/api/generatePDF/remote', 'boleto/remote.pdf'));
    }
}
