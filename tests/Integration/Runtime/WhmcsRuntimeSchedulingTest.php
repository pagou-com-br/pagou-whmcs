<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\AddonSettings;
use Pagou\Whmcs\Application\Runtime\PaymentAttemptStore;
use Pagou\Whmcs\Application\Runtime\WhmcsRuntime;
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class WhmcsRuntimeSchedulingTest extends TestCase
{
    public function testConfiguredFeeBecomesAnIdempotentInvoiceItem(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $updated = false;
        $updateCalls = [];
        $runtime = new WhmcsRuntime(
            $pdo,
            new AddonSettings(['pix_fee_percent' => '5.00', 'pix_fee_fixed' => '2.00']),
            new PdoOperationOutbox($pdo),
            static function (string $command, array $parameters) use (&$updated, &$updateCalls): array {
                if ($command === 'UpdateInvoice') {
                    $updated = true;
                    $updateCalls[] = $parameters;

                    return ['result' => 'success'];
                }

                return $updated
                    ? ['result' => 'success', 'invoiceid' => 10, 'userid' => 20, 'balance' => '107.00', 'items' => ['item' => [[
                        'id' => 91, 'description' => '[Pagou] Acréscimo Pix', 'amount' => '7.00',
                    ]]]]
                    : ['result' => 'success', 'invoiceid' => 10, 'userid' => 20, 'balance' => '100.00', 'items' => ['item' => []]];
            },
        );

        self::assertSame(10700, $runtime->configuredAmountForInvoice(10, 'pix')->centavos());
        self::assertSame(10700, $runtime->configuredAmountForInvoice(10, 'pix')->centavos());
        self::assertCount(1, $updateCalls);
        self::assertSame(['[Pagou] Acréscimo Pix'], $updateCalls[0]['newitemdescription']);
        self::assertSame(['7.00'], $updateCalls[0]['newitemamount']);
    }

    public function testChangedInvoiceCancelsPreviousRemoteChargeBeforeIssuingReplacement(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $store = new PaymentAttemptStore($pdo);
        $old = $store->ensureCurrent(10, 20, 'boleto', 1000, '2026-09-10');
        $store->complete($old['id'], 'remote-old', 'ready', ['state' => 'ready']);
        $runtime = new WhmcsRuntime(
            $pdo,
            new AddonSettings([]),
            new PdoOperationOutbox($pdo),
            static fn (string $command, array $parameters): array => [
                'result' => 'success',
                'invoiceid' => 10,
                'userid' => 20,
                'paymentmethod' => 'pagou_boleto',
                'status' => 'Unpaid',
                'balance' => '12.00',
                'duedate' => '2026-09-11',
            ],
        );

        self::assertTrue($runtime->scheduleInvoice(10));
        $types = $pdo->query(
            'SELECT operation_type FROM pagou_payment_operations ORDER BY priority DESC'
        );
        self::assertNotFalse($types);
        self::assertSame(['cancel_boleto'], $types->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('cancel_requested', $store->find($old['id'])['status'] ?? null);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM pagou_payment_attempts')->fetchColumn());
    }

    public function testSwitchingMethodOrGatewayKeepsTheBoletoSentToTheCustomerPayable(): void
    {
        $pdo = $this->database();
        $store = new PaymentAttemptStore($pdo);
        $boleto = $store->ensureCurrent(10, 20, 'boleto', 1000, '2026-09-11');
        $store->complete($boleto['id'], 'remote-boleto', 'ready', ['state' => 'ready']);

        // The customer looks at Pix: the Pix is issued, the boleto from the e-mail stays valid.
        $this->runtime($pdo, 'pagou_pix', '10.00', '2026-09-11')->scheduleInvoice(10);
        self::assertSame('ready', $store->find($boleto['id'])['status']);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM pagou_payment_operations WHERE operation_type LIKE 'cancel_%'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM pagou_payment_attempts WHERE method = 'pix'")->fetchColumn());

        // Another gateway does not cancel Pagou charges either.
        self::assertFalse($this->runtime($pdo, 'paypal', '10.00', '2026-09-11')->scheduleInvoice(10));
        self::assertSame('ready', $store->find($boleto['id'])['status']);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM pagou_payment_operations WHERE operation_type LIKE 'cancel_%'")->fetchColumn());

        // Back to boleto: the same boleto is reused, nothing new is issued.
        self::assertFalse($this->runtime($pdo, 'pagou_boleto', '10.00', '2026-09-11')->scheduleInvoice(10));
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM pagou_payment_attempts WHERE method = 'boleto'")->fetchColumn());
    }

    public function testAChangedAmountOrDueDateRetiresTheOutdatedChargeOfTheOtherMethod(): void
    {
        foreach ([['12.00', '2026-09-11'], ['10.00', '2026-09-20']] as [$balance, $due]) {
            foreach (['pagou_pix', 'paypal'] as $gateway) {
                $pdo = $this->database();
                $store = new PaymentAttemptStore($pdo);
                $boleto = $store->ensureCurrent(10, 20, 'boleto', 1000, '2026-09-11');
                $store->complete($boleto['id'], 'remote-boleto', 'ready', ['state' => 'ready']);

                $this->runtime($pdo, $gateway, $balance, $due)->scheduleInvoice(10);

                self::assertSame('cancel_requested', $store->find($boleto['id'])['status'], $gateway . ' ' . $balance . ' ' . $due);
                self::assertSame('cancel_boleto', $pdo->query("SELECT operation_type FROM pagou_payment_operations WHERE operation_type LIKE 'cancel_%'")->fetchColumn());
            }
        }
    }

    public function testAnInvoiceCancelledOnAnotherGatewayStillCancelsItsPagouCharges(): void
    {
        $pdo = $this->database();
        $store = new PaymentAttemptStore($pdo);
        $boleto = $store->ensureCurrent(10, 20, 'boleto', 1000, '2026-09-11');
        $store->complete($boleto['id'], 'remote-boleto', 'ready', ['state' => 'ready']);
        $runtime = new WhmcsRuntime($pdo, new AddonSettings([]), new PdoOperationOutbox($pdo), static fn (string $command, array $parameters): array => [
            'result' => 'success', 'invoiceid' => 10, 'userid' => 20, 'paymentmethod' => 'paypal', 'status' => 'Cancelled',
            'balance' => '10.00', 'total' => '10.00', 'duedate' => '2026-09-11', 'items' => ['item' => []],
        ]);

        self::assertFalse($runtime->scheduleInvoice(10));
        self::assertSame('cancel_requested', $store->find($boleto['id'])['status']);
    }

    public function testCancellingTheChargeOnTheCardLeavesNoPagouChargePayable(): void
    {
        $pdo = $this->database();
        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, paymentmethod TEXT, status TEXT)');
        $pdo->exec("INSERT INTO tblinvoices VALUES (10, 20, 'pagou_boleto', 'Unpaid')");
        $store = new PaymentAttemptStore($pdo);
        $pix = $store->ensureCurrent(10, 20, 'pix', 1000, '2026-09-11');
        $store->complete($pix['id'], 'remote-pix', 'ready', ['state' => 'ready']);
        $boleto = $store->ensureCurrent(10, 20, 'boleto', 1000, '2026-09-11');
        $store->complete($boleto['id'], 'remote-boleto', 'ready', ['state' => 'ready']);

        self::assertTrue($this->runtime($pdo, 'pagou_boleto', '10.00', '2026-09-11')->requestInvoiceCancellation(10, 'Cancelamento pelo administrador.', 1));

        self::assertSame('cancel_requested', $store->find($boleto['id'])['status']);
        self::assertSame('cancel_requested', $store->find($pix['id'])['status']);
        $types = $pdo->query("SELECT operation_type FROM pagou_payment_operations ORDER BY operation_type")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['cancel_boleto', 'cancel_pix'], $types);
    }

    public function testPaidInvoiceCancelsTheOpenBoletoOnceAndAcceptsOneAlreadyCancelled(): void
    {
        $pdo = $this->database();
        $store = new PaymentAttemptStore($pdo);
        $boleto = $store->ensureCurrent(10, 20, 'boleto', 1000, '2026-09-11');
        $store->complete($boleto['id'], 'remote-boleto', 'ready', ['state' => 'ready']);
        $api = static fn (string $command, array $parameters): array => ['result' => 'success'];
        $cancelled = static fn (string $method, string $id) => (new \Pagou\Whmcs\Payment\Boleto\Api\BoletoResponseMapper())->charge(['id' => $id, 'amount' => 10, 'status' => 3, 'payload' => ['line' => 'line', 'bar_code' => 'barcode']]);
        $runtime = new WhmcsRuntime($pdo, new AddonSettings(['worker_max_jobs' => '5']), new PdoOperationOutbox($pdo), $api, $cancelled);

        // The InvoicePaid hook asks first; the payment follow-up must not ask again.
        self::assertSame(1, $runtime->closePaidInvoice(10));
        (new \Pagou\Whmcs\Payment\Ledger\Infrastructure\OutboxFollowUpQueue($pdo, new PdoOperationOutbox($pdo)))->cancelSiblings(10, \Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey::fromRemotePayment(10, 'pagou', 'pix-receipt'));
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM pagou_payment_operations WHERE operation_type = 'cancel_boleto'")->fetchColumn());

        // Pagou refuses to cancel twice; a boleto it reports cancelled is a finished cancellation.
        $runtime->runWorker();
        self::assertSame('succeeded', $pdo->query("SELECT status FROM pagou_payment_operations WHERE operation_type = 'cancel_boleto'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM pagou_payment_operations WHERE status = 'uncertain'")->fetchColumn());
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');

        return $pdo;
    }

    private function runtime(PDO $pdo, string $gateway, string $balance, string $due): WhmcsRuntime
    {
        return new WhmcsRuntime($pdo, new AddonSettings([]), new PdoOperationOutbox($pdo), static fn (string $command, array $parameters): array => [
            'result' => 'success', 'invoiceid' => 10, 'userid' => 20, 'paymentmethod' => $gateway, 'status' => 'Unpaid',
            'balance' => $balance, 'total' => $balance, 'duedate' => $due, 'items' => ['item' => []], 'firstname' => 'Cliente', 'lastname' => 'Teste',
        ]);
    }

    public function testCancelledInvoiceNeverCreatesAnotherCharge(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $runtime = new WhmcsRuntime(
            $pdo,
            new AddonSettings([]),
            new PdoOperationOutbox($pdo),
            static fn (string $command, array $parameters): array => [
                'result' => 'success',
                'invoiceid' => 10,
                'userid' => 20,
                'paymentmethod' => 'pagou_pix',
                'status' => 'Cancelled',
                'balance' => '10.00',
                'duedate' => '2026-09-11',
                'items' => ['item' => []],
            ],
        );

        self::assertFalse($runtime->scheduleInvoice(10));
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_payment_attempts')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_payment_operations')->fetchColumn());
    }

    public function testCancelledInvoiceSchedulesCancellationForItsActiveBoleto(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $store = new PaymentAttemptStore($pdo);
        $attempt = $store->ensureCurrent(10, 20, 'boleto', 1000, '2026-09-10');
        $store->complete($attempt['id'], 'remote-boleto', 'ready', ['state' => 'ready']);
        $runtime = new WhmcsRuntime(
            $pdo,
            new AddonSettings([]),
            new PdoOperationOutbox($pdo),
            static fn (string $command, array $parameters): array => [
                'result' => 'success',
                'invoiceid' => 10,
                'userid' => 20,
                'paymentmethod' => 'pagou_boleto',
                'status' => 'Cancelled',
                'balance' => '10.00',
                'duedate' => '2026-09-11',
                'items' => ['item' => []],
            ],
        );

        self::assertFalse($runtime->scheduleInvoice(10));
        self::assertSame('cancel_requested', $store->find($attempt['id'])['status'] ?? null);
        self::assertSame(
            'cancel_boleto',
            $pdo->query('SELECT operation_type FROM pagou_payment_operations')->fetchColumn(),
        );
        $payload = json_decode(
            (string) $pdo->query('SELECT payload_json FROM pagou_payment_operations')->fetchColumn(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(10, $payload['invoice_id'] ?? null);
    }

    public function testAdminSummaryMarksAnInvoiceOutsideTheConfiguredRangeAsUnavailable(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $pdo->exec(
            "CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, paymentmethod TEXT NOT NULL, total TEXT NOT NULL, credit TEXT NOT NULL)"
        );
        $pdo->exec("INSERT INTO tblinvoices (id, paymentmethod, total, credit) VALUES (10, 'pagou_pix', '10.00', '0.00')");
        $runtime = new WhmcsRuntime(
            $pdo,
            new AddonSettings(['pix_min_amount' => '15.00']),
            new PdoOperationOutbox($pdo),
            static fn (): array => ['result' => 'success'],
        );

        $summary = $runtime->adminSummary(10);

        self::assertSame('unavailable', $summary['state'] ?? null);
        self::assertSame('', $summary['attemptId'] ?? null);
        self::assertSame('', $summary['remoteId'] ?? null);
    }

    public function testBoletoKeepsProviderMinimumWhenMerchantLimitIsBlank(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $runtime = new WhmcsRuntime(
            $pdo,
            new AddonSettings(['boleto_min_amount' => '']),
            new PdoOperationOutbox($pdo),
            static fn (string $command, array $parameters): array => [
                'result' => 'success',
                'invoiceid' => 10,
                'userid' => 20,
                'balance' => '4.99',
                'items' => ['item' => []],
            ],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boleto disponível para faturas a partir de R$ 5.00');

        $runtime->configuredAmountForInvoice(10, 'boleto');
    }

    public function testSupersededBoletoIssuanceFinishesWithoutAFalseFailure(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $store = new PaymentAttemptStore($pdo);
        $attempt = $store->ensureCurrent(10, 20, 'boleto', 1000, '2026-09-10');
        $outbox = new PdoOperationOutbox($pdo);
        $scheduler = new \Pagou\Whmcs\Application\Async\OperationScheduler($outbox, new \Pagou\Whmcs\Application\Async\SystemAsyncClock());
        self::assertTrue($scheduler->issueBoleto(
            '44444444-4444-4444-8444-444444444444',
            $attempt['id'],
            1,
            ['attempt_id' => $attempt['id'], 'invoice_id' => 10, 'method' => 'boleto'],
        ));
        $store->markStatus($attempt['id'], 'superseded');
        $apiCalled = false;
        $runtime = new WhmcsRuntime(
            $pdo,
            new AddonSettings([]),
            $outbox,
            static function () use (&$apiCalled): array {
                $apiCalled = true;

                return ['result' => 'success'];
            },
        );

        $report = $runtime->runWorker('test-worker');

        self::assertSame(1, $report->succeeded);
        self::assertSame(0, $report->failed);
        self::assertFalse($apiCalled);
        self::assertSame('succeeded', $pdo->query('SELECT status FROM pagou_payment_operations')->fetchColumn());
    }

    public function testPixIssuanceRunsImmediatelyWithoutCreatingAnIssuanceJob(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $runtime = new WhmcsRuntime(
            $pdo,
            new AddonSettings([]),
            new PdoOperationOutbox($pdo),
            static function (string $command, array $parameters): array {
                if ($command === 'GetClientsDetails') {
                    return [
                        'result' => 'success',
                        'firstname' => 'Cliente',
                        'lastname' => 'Teste',
                    ];
                }

                return [
                    'result' => 'success',
                    'invoiceid' => 10,
                    'userid' => 20,
                    'paymentmethod' => 'pagou_pix',
                    'status' => 'Unpaid',
                    'balance' => '10.00',
                    'duedate' => '2026-09-11',
                    'items' => ['item' => []],
                ];
            },
        );

        self::assertFalse($runtime->scheduleInvoice(10));
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_payment_operations')->fetchColumn());
        self::assertSame('failed', $pdo->query('SELECT status FROM pagou_payment_attempts')->fetchColumn());
    }

    public function testPixInvoiceUsesCompactCopyActionAndPaymentReadyStatus(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $store = new PaymentAttemptStore($pdo);
        $attempt = $store->ensureCurrent(10, 20, 'pix', 1000, '2026-09-10');
        $copyPaste = '00020101021226910014br.gov.bcb.pix';
        $store->complete($attempt['id'], 'remote-pix', 'pending', [
            'state' => 'pending',
            'amount' => '10.00',
            'copyPaste' => $copyPaste,
            'qrCodeImageUrl' => 'data:image/png;base64,AAAA',
        ]);
        $runtime = new WhmcsRuntime(
            $pdo,
            new AddonSettings([]),
            new PdoOperationOutbox($pdo),
            static fn (): array => ['result' => 'success'],
        );

        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, status TEXT)');
        $pdo->exec("INSERT INTO tblinvoices VALUES (10, 'Unpaid')");
        $html = $runtime->renderInvoice(['invoiceid' => 10, 'amount' => '10.00'], 'pix');

        self::assertStringContainsString('Aguardando pagamento', $html);
        self::assertStringContainsString('client.css?v=', $html);
        self::assertStringContainsString('client.js?v=', $html);
        self::assertStringContainsString('pagou-qr-frame', $html);
        self::assertStringContainsString('Copiar código Pix', $html);
        self::assertStringContainsString('data-pagou-copy-target=', $html);
        self::assertStringContainsString('value="' . $copyPaste . '"', $html);
        self::assertStringNotContainsString('<textarea', $html);
        self::assertStringNotContainsString('Em processamento', $html);
        self::assertStringNotContainsString('data-pagou-refresh', $html);
        self::assertStringContainsString('automaticamente nesta página', $html);
        self::assertStringNotContainsString('Valor da cobrança', $html);
    }

    public function testBoletoInvoiceKeepsOnlyBoletoActionsAndReusesAddonBarcodeIcon(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $store = new PaymentAttemptStore($pdo);
        $attempt = $store->ensureCurrent(10, 20, 'boleto', 1000, '2026-09-10');
        $store->complete($attempt['id'], 'remote-boleto', 'ready', [
            'state' => 'ready',
            'amount' => '10.00',
            'digitableLine' => '00190.12345',
            'copyPaste' => '00020101021226910014br.gov.bcb.pix',
        ]);
        $runtime = new WhmcsRuntime(
            $pdo,
            new AddonSettings([]),
            new PdoOperationOutbox($pdo),
            static fn (): array => ['result' => 'success'],
        );

        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, status TEXT)');
        $pdo->exec("INSERT INTO tblinvoices VALUES (10, 'Unpaid')");
        $html = $runtime->renderInvoice(['invoiceid' => 10, 'amount' => '10.00'], 'boleto');

        self::assertStringContainsString('Copiar linha digitável', $html);
        self::assertStringContainsString('Linha digitável copiada.', $html);
        self::assertStringNotContainsString('Copiar código Pix', $html);
        self::assertSame(1, substr_count($html, 'data-pagou-copy-target='));
        self::assertStringNotContainsString('<label>Linha digitável</label><input', $html);
        self::assertStringNotContainsString('Preparando seu boleto', $html);
        self::assertStringNotContainsString('data-pagou-progress-token=', $html);
        self::assertStringContainsString('https://fatura.pagou.com.br/boleto/remote-boleto', $html);
        self::assertStringContainsString('https://fatura.pagou.com.br/boleto/pdf/remote-boleto', $html);
        $store->complete($attempt['id'], 'remote-boleto', 'awaiting_registration', ['state' => 'awaiting_registration']);
        $pending = $runtime->renderInvoice(['invoiceid' => 10, 'amount' => '10.00'], 'boleto');
        self::assertStringContainsString('Preparando seu boleto', $pending);
        self::assertStringContainsString('data-pagou-progress-token=', $pending);
        self::assertStringNotContainsString('Visualizar boleto', $pending);
        $pdf = 'modules/addons/pagou_payments/download.php?attempt=' . $attempt['id'];
        $store->complete($attempt['id'], 'remote-boleto', 'ready', [
            'state' => 'ready', 'amount' => '10.00', 'pdfUrl' => $pdf,
            'remoteId' => 'stale-display-id',
            'qrCodeImageUrl' => 'data:image/png;base64,AAAA',
        ]);
        $ready = $runtime->renderInvoice(['invoiceid' => 10, 'amount' => '10.00'], 'boleto');
        self::assertStringContainsString(\Pagou\Payments\Admin\Support\Html::icon('barcode', 16) . 'Visualizar boleto', $ready);
        self::assertStringContainsString('Baixar boleto em PDF', $ready);
        self::assertStringContainsString('href="https://fatura.pagou.com.br/boleto/remote-boleto"', $ready);
        self::assertStringContainsString('href="https://fatura.pagou.com.br/boleto/pdf/remote-boleto"', $ready);
        self::assertStringNotContainsString('href="' . $pdf . '"', $ready);
        self::assertStringNotContainsString('inline=1', $ready);
        self::assertSame(2, substr_count($ready, 'target="_blank" rel="noopener"'));
        self::assertStringNotContainsString('Preparando seu boleto', $ready);
        self::assertStringNotContainsString('data-pagou-progress-token=', $ready);
        self::assertStringNotContainsString('QR Code Pix', $ready);
    }

    public function testAdminSummaryPrioritizesCurrentAttemptStatusOverStaleArtifacts(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, paymentmethod TEXT NOT NULL)');
        $pdo->exec("INSERT INTO tblinvoices (id, paymentmethod) VALUES (10, 'pagou_boleto')");
        $store = new PaymentAttemptStore($pdo);
        $attempt = $store->ensureCurrent(10, 20, 'boleto', 1000, '2026-09-10');
        $store->complete($attempt['id'], 'remote-boleto', 'ready', ['state' => 'ready']);
        $store->markStatus($attempt['id'], 'cancel_requested');
        $runtime = new WhmcsRuntime(
            $pdo,
            new AddonSettings([]),
            new PdoOperationOutbox($pdo),
            static fn (): array => ['result' => 'success'],
        );

        self::assertSame('cancel_requested', $runtime->adminSummary(10)['state'] ?? null);
    }

    public function testFailedLocalBoletoIssuanceCanBeSafelyResumedBeforeRemoteIdentityExists(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $runtime = new WhmcsRuntime(
            $pdo,
            new AddonSettings([]),
            new PdoOperationOutbox($pdo),
            static fn (string $command, array $parameters): array => [
                'result' => 'success',
                'invoiceid' => 10,
                'userid' => 20,
                'paymentmethod' => 'pagou_boleto',
                'status' => 'Unpaid',
                'balance' => '10.00',
                'duedate' => '2026-09-11',
                'items' => ['item' => []],
            ],
        );

        self::assertTrue($runtime->scheduleInvoice(10));
        self::assertSame(
            'issue_boleto',
            $pdo->query('SELECT operation_type FROM pagou_payment_operations')->fetchColumn(),
        );
        $pdo->exec("UPDATE pagou_payment_operations SET status = 'failed', attempt_id = NULL, error_message = 'local failure'");

        self::assertSame(1, $runtime->resumeRecoverableOperations());
        $row = $pdo->query('SELECT status, attempt_id, error_message FROM pagou_payment_operations')?->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('retrying', $row['status']);
        self::assertNotEmpty($row['attempt_id']);
        self::assertNull($row['error_message']);
    }

    public function testFailedBoletoCancellationConfirmationRecoversItsInvoiceCorrelation(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $store = new PaymentAttemptStore($pdo);
        $attempt = $store->ensureCurrent(10, 20, 'boleto', 1000, '2026-09-10');
        $store->complete($attempt['id'], 'remote-boleto', 'cancel_requested', ['state' => 'cancel_requested']);
        $now = '2026-08-23 19:15:00.000000';
        $statement = $pdo->prepare(
            'INSERT INTO pagou_payment_operations '
            . '(id, attempt_id, operation_type, status, deduplication_key, priority, payload_json, error_message, available_at, created_at, updated_at) '
            . 'VALUES (:id, :attempt_id, :operation_type, :status, :deduplication_key, :priority, :payload_json, :error_message, :available_at, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => '11111111-1111-4111-8111-111111111111',
            'attempt_id' => $attempt['id'],
            'operation_type' => 'replace_boleto',
            'status' => 'failed',
            'deduplication_key' => str_repeat('b', 64),
            'priority' => 100,
            'payload_json' => json_encode([
                'attempt_id' => $attempt['id'],
                'invoice_id' => 0,
                'remote_id' => 'remote-boleto',
                'replace' => false,
            ], JSON_THROW_ON_ERROR),
            'error_message' => 'boleto_cancel_confirmation_invalid',
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $runtime = new WhmcsRuntime(
            $pdo,
            new AddonSettings([]),
            new PdoOperationOutbox($pdo),
            static fn (): array => ['result' => 'success'],
        );

        self::assertSame(1, $runtime->resumeRecoverableOperations());
        $row = $pdo->query(
            "SELECT status, payload_json FROM pagou_payment_operations WHERE operation_type = 'replace_boleto'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('retrying', $row['status']);
        $payload = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(10, $payload['invoice_id'] ?? null);
        self::assertSame('remote-boleto', $payload['remote_id'] ?? null);
    }

    public function testBoletoPdfWithoutLocalArtifactCanBeSafelyResumed(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $store = new PaymentAttemptStore($pdo);
        $attempt = $store->ensureCurrent(10, 20, 'boleto', 1000, '2026-09-10');
        $store->complete($attempt['id'], 'remote-boleto', 'ready', [
            'state' => 'ready',
            'pdfUrl' => '',
        ]);
        $pdo->prepare('UPDATE pagou_payment_attempts SET response_json = :response WHERE id = :id')->execute([
            'id' => $attempt['id'],
            'response' => json_encode([
                'state' => 'ready',
                'digitableLine' => '00190.12345',
                'barcode' => '00190',
                'copyPaste' => '000201...',
                'qrCodeImageUrl' => 'data:image/png;base64,AAAA',
            ], JSON_THROW_ON_ERROR),
        ]);
        $pdo->prepare(
            "INSERT INTO pagou_payment_operations "
            . '(id, attempt_id, operation_type, status, deduplication_key, priority, payload_json, attempts, '
            . 'available_at, created_at, updated_at, version) '
            . "VALUES ('pdf-job', :attempt_id, 'fetch_boleto_pdf', 'succeeded', 'pdf-dedup', 20, :payload, 3, "
            . "'2026-09-10 00:00:00', '2026-09-10 00:00:00', '2026-09-10 00:00:00', 1)"
        )->execute([
            'attempt_id' => $attempt['id'],
            'payload' => json_encode(['attempt_id' => $attempt['id'], 'invoice_id' => 10], JSON_THROW_ON_ERROR),
        ]);
        $runtime = new WhmcsRuntime(
            $pdo,
            new AddonSettings([]),
            new PdoOperationOutbox($pdo),
            static fn (): array => ['result' => 'success'],
        );

        self::assertSame(1, $runtime->resumeRecoverableOperations());
        $row = $pdo->query("SELECT status, attempts, attempt_id FROM pagou_payment_operations WHERE id = 'pdf-job'")
            ?->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('retrying', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertSame($attempt['id'], $row['attempt_id']);
        $response = json_decode((string) ($store->find($attempt['id'])['response_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://fatura.pagou.com.br/api/generatePDF/remote-boleto', $response['artifacts']['pdf_url'] ?? null);
    }
}
