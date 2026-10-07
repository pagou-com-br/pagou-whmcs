<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\{AddonSettings, PaymentAttemptStore, WhmcsRuntime};
use Pagou\Whmcs\Application\Webhook\{HmacWebhookVerifier, WebhookEndpointService, WebhookEventParser};
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\Webhook\{OutboxWebhookEventHandler, PdoWebhookInbox};
use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\PaymentTransactionResolver;
use Pagou\Whmcs\Payment\Pix\Mapper\PixResponseMapper;
use Pagou\Whmcs\Payment\Boleto\Api\BoletoResponseMapper;
use Pagou\Whmcs\Payment\Boleto\Infrastructure\PdoBoletoAttemptRepository;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;
use PHPUnit\Framework\TestCase;

final class CurrentPaymentPipelineTest extends TestCase
{
    public function testSignedPixEnvelopeAndResignedRetryApplyOnlyOneNativePayment(): void
    {
        $pdo = $this->pdo();
        $attempts = new PaymentAttemptStore($pdo);
        $attempt = $attempts->ensureCurrent(19, 7, 'pix', 1000, '2026-09-20');
        $attempts->complete($attempt['id'], 'pix-original', 'ready', ['state' => 'ready']);
        $body = ['name' => 'qrcode.completed', 'data' => ['id' => 'pix-original', 'transaction_id' => 'receipt-19', 'amount' => 10, 'e2e_id' => 'E-receipt-19', 'payer' => ['name' => 'Pagador comprovado', 'document' => '11144477735']]];
        (new \Pagou\Whmcs\Application\Runtime\PaymentIdentityStore($pdo))->snapshot($attempt['id'], ['name' => 'Nome na emissão', 'document' => '52998224725']);
        $this->receive($pdo, $body, 0);
        $this->receive($pdo, $body, 1);
        $runtime = $this->runtime($pdo, 'pix');
        $runtime->runWorker('worker-1');
        $runtime->runWorker('worker-2');

        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn());
        self::assertSame('10.00', $pdo->query('SELECT amountin FROM tblaccounts')->fetchColumn());
        self::assertSame('Paid', $pdo->query('SELECT status FROM tblinvoices')->fetchColumn());
        self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM pagou_webhook_deliveries WHERE processing_status = 'succeeded'")->fetchColumn());
        // New native receipts carry the Pagou receipt identifier; the ledger keeps its economic hash.
        self::assertSame('receipt-19', $pdo->query('SELECT transid FROM tblaccounts')->fetchColumn());
        self::assertSame('pagou_pix', $pdo->query('SELECT gateway FROM tblaccounts')->fetchColumn());
        $resolver = new PaymentTransactionResolver($pdo);
        self::assertSame('pix-original', $resolver->resolve(19, 'receipt-19', 'pix')['remote_id']);
        $historical = EconomicPaymentKey::fromRemotePayment(19, 'pagou', 'receipt-19')->value;
        try {
            $resolver->resolve(19, $historical, 'pix');
            self::fail('A hash without its native transaction must not resolve.');
        } catch (\LogicException) {
            self::assertTrue(true);
        }
        // Receipts recorded before this release keep the hash in WHMCS and must still resolve for refunds.
        $pdo->prepare('UPDATE tblaccounts SET transid = ? WHERE invoiceid = 19')->execute([$historical]);
        self::assertSame('pix-original', $resolver->resolve(19, $historical, 'pix')['remote_id']);
        $identity = (new \Pagou\Whmcs\Application\Runtime\PaymentIdentityStore($pdo))->forInvoice(19);
        self::assertCount(1, $identity);
        self::assertSame('Pagador comprovado', $identity[0]['payerName']);
        self::assertTrue($identity[0]['thirdParty']);
        $client = $runtime->renderInvoice(['invoiceid' => 19, 'amount' => '10.00'], 'pix');
        self::assertStringNotContainsString('Pagador comprovado', $client);
        self::assertStringNotContainsString('11144477735', $client);
        self::assertStringContainsString('Pagamento confirmado', $client);
        self::assertStringContainsString('Sua fatura está paga.', $client);
        self::assertStringNotContainsString('Vencimento:', $client);
        self::assertSame('paid', (new \Pagou\Whmcs\Application\Runtime\ClientPaymentStatus($pdo))->read(19, 'pix', 7, false)['state']);
    }

    public function testInvalidPaymentDateCannotShowSuccessAndTheOriginalReceiptCanBeRetried(): void
    {
        $pdo = $this->pdo();
        $attempts = new PaymentAttemptStore($pdo);
        $attempt = $attempts->ensureCurrent(19, 7, 'pix', 1000, '2026-09-20');
        $attempts->complete($attempt['id'], 'pix-original', 'ready', ['state' => 'ready']);
        $this->receive($pdo, ['name' => 'qrcode.completed', 'data' => ['id' => 'pix-original', 'transaction_id' => 'receipt-19', 'amount' => 10]], 0);
        $runtime = new WhmcsRuntime($pdo, new AddonSettings(['worker_max_jobs' => '1']), new PdoOperationOutbox($pdo), static function (): array {
            self::fail('An invalid date must not reach the native payment API.');
        }, static fn (string $method, string $id) => (new PixResponseMapper())->charge([
            'id' => $id, 'status' => 4, 'amount' => 10, 'paid_at' => '2026-02-30T12:00:00Z',
        ]));
        $runtime->runWorker();
        self::assertSame('Unpaid', $pdo->query('SELECT status FROM tblinvoices')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_ledger_entries')->fetchColumn());
        $html = $runtime->renderInvoice(['invoiceid' => 19, 'amount' => '10.00'], 'pix');
        self::assertStringContainsString('Confirmando pagamento', $html);
        self::assertStringNotContainsString('Pagamento confirmado', $html);
        self::assertStringNotContainsString('Vencimento:', $html);
        $status = new \Pagou\Whmcs\Application\Runtime\ClientPaymentStatus($pdo);
        self::assertSame('processing', $status->read(19, 'pix', 7, false)['state']);

        $pdo->exec("UPDATE pagou_payment_operations SET available_at = '2020-01-01' WHERE status = 'retrying'");
        $this->runtime($pdo, 'pix')->runWorker();
        self::assertSame('Paid', $pdo->query('SELECT status FROM tblinvoices')->fetchColumn());
        self::assertSame('paid', $status->read(19, 'pix', 7, false)['state']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn());
        self::assertSame('2026-09-17 12:00:00.116000', $pdo->query("SELECT payment_at_utc FROM pagou_ledger_entries WHERE entry_type = 'received_payment'")->fetchColumn());
    }

    /** @return array<string, array{string, int}> */
    public static function retiredBoletos(): array
    {
        // Paid at the bank before the invoice was paid by Pix, then cancelled by the module:
        // the receipt only arrives on settlement, while Pagou may still report the cancellation.
        return [
            'replaced' => ['superseded', 4],
            'cancellation requested' => ['cancel_requested', 4],
            'cancelled' => ['cancelled', 4],
            'cancelled and still reported cancelled' => ['cancelled', 3],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('retiredBoletos')]
    public function testLateBoletoOnInvoiceAlreadyPaidElsewhereStillUsesNativePaymentOnce(string $localStatus, int $remoteStatus): void
    {
        $pdo = $this->pdo();
        $pdo->exec("UPDATE tblinvoices SET status = 'Paid'");
        // A payment from another gateway stays attributed to that gateway.
        $pdo->exec("INSERT INTO tblaccounts (invoiceid, transid, gateway, amountin, amountout) VALUES (19, 'mercadopago-original', 'mercadopago', '10.00', '0.00')");
        $attempts = new PaymentAttemptStore($pdo);
        $attempt = $attempts->ensureCurrent(19, 7, 'boleto', 1000, '2026-09-20');
        (new PdoBoletoAttemptRepository($pdo, 7))->save(new BoletoAttempt($attempt['id'], '19', 1, 1000, '2026-09-20', $attempt['idempotency_key'], remoteId: 'late-boleto'));
        // A cancellation request or replaced payment method must not erase a real receipt.
        $attempts->complete($attempt['id'], 'late-boleto', $localStatus, ['state' => $localStatus]);
        $body = ['name' => 'charge.paid', 'data' => [
            'id' => 'late-boleto', 'transaction_id' => 'second-real-receipt', 'amount' => ['paid' => 10],
            'paid_in' => '2026-09-17T12:00:00Z',
        ]];
        $this->receive($pdo, $body, 0);
        $this->receive($pdo, $body, 1);
        $calls = [];
        $runtime = new WhmcsRuntime($pdo, new AddonSettings(['worker_max_jobs' => '1']), new PdoOperationOutbox($pdo), static function (string $command, array $params) use ($pdo, &$calls): array {
            $calls[] = [$command, $params];
            if ($command === 'AddInvoicePayment') {
                self::assertSame(19, $params['invoiceid']);
                self::assertSame('10.00', $params['amount']);
                self::assertSame('pagou_boleto', $params['gateway']);
                self::assertSame('Paid', $pdo->query('SELECT status FROM tblinvoices WHERE id = 19')->fetchColumn());
                $pdo->prepare('INSERT INTO tblaccounts (invoiceid, transid, gateway, amountin, amountout) VALUES (?, ?, ?, ?, ?)')->execute([19, $params['transid'], $params['gateway'], $params['amount'], '0.00']);
            }
            return ['result' => 'success'];
        }, static fn (string $method, string $id) => (new BoletoResponseMapper())->charge([
            'id' => $id, 'amount' => 10, 'status' => $remoteStatus, 'paid_at' => $remoteStatus === 4 ? '2026-09-17T12:00:00Z' : null,
            'payload' => ['line' => 'line', 'bar_code' => 'barcode'],
        ]));
        $runtime->runWorker();
        $runtime->runWorker();
        self::assertCount(1, array_filter($calls, static fn (array $call): bool => $call[0] === 'AddInvoicePayment'));
        self::assertNotContains('AddCredit', array_column($calls, 0));
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM tblaccounts WHERE invoiceid = 19')->fetchColumn());
        self::assertSame('10.00', $pdo->query("SELECT amountin FROM tblaccounts WHERE transid = 'mercadopago-original'")->fetchColumn());
        self::assertSame('mercadopago', $pdo->query("SELECT gateway FROM tblaccounts WHERE transid = 'mercadopago-original'")->fetchColumn());
        self::assertSame('second-real-receipt', $pdo->query("SELECT transid FROM tblaccounts WHERE gateway = 'pagou_boleto'")->fetchColumn());
        // The real WHMCS owns excess-credit creation. This fixture verifies delegation,
        // invoice identity and replay safety, not the live WHMCS credit implementation.
    }

    public function testBoletoUsesActuallyPaidAmountAndKeepsPaidState(): void
    {
        $pdo = $this->pdo();
        $attempts = new PaymentAttemptStore($pdo);
        $attempt = $attempts->ensureCurrent(19, 7, 'boleto', 1000, '2026-09-20');
        (new PdoBoletoAttemptRepository($pdo, 7))->save(new BoletoAttempt($attempt['id'], '19', 1, 1000, '2026-09-20', $attempt['idempotency_key'], remoteId: 'boleto-19'));
        $attempts->complete($attempt['id'], 'boleto-19', 'ready', ['state' => 'ready']);
        $this->receive($pdo, ['name' => 'charge.paid', 'data' => [
            'id' => 'boleto-19', 'transaction_id' => 'receipt-boleto-19', 'amount' => ['paid' => 12.50],
            'paid_in' => '2026-09-17T12:00:00Z', 'payment_type' => 'PIX',
        ]], 0);
        $calls = [];
        $runtime = $this->runtime($pdo, 'boleto', $calls);
        $runtime->runWorker();
        self::assertSame('12.50', $pdo->query('SELECT amountin FROM tblaccounts')->fetchColumn());
        self::assertSame('paid', $attempts->find($attempt['id'])['status']);
        // Fine and interest paid above the nominal 10.00 settle the invoice as an item, not as client credit.
        self::assertSame([['[Pagou] Multa e juros por atraso (receipt-boleto-19)', '2.50']], $pdo->query('SELECT description, amount FROM tblinvoiceitems')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame(1250.0, round((float) $pdo->query('SELECT total FROM tblinvoices WHERE id = 19')->fetchColumn() * 100));
        $order = array_column($calls, 0);
        self::assertLessThan(array_search('AddInvoicePayment', $order, true), array_search('UpdateInvoice', $order, true));
        // A replay never adds the item again.
        $runtime->runWorker();
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM tblinvoiceitems')->fetchColumn());
    }

    public function testBoletoPaidThroughItsEmbeddedPixIsBookedWithTheTxidAndNeverCancelled(): void
    {
        $pdo = $this->pdo();
        $attempts = new PaymentAttemptStore($pdo);
        $attempt = $attempts->ensureCurrent(19, 7, 'boleto', 1000, '2026-09-20');
        (new PdoBoletoAttemptRepository($pdo, 7))->save(new BoletoAttempt($attempt['id'], '19', 1, 1000, '2026-09-20', $attempt['idempotency_key'], remoteId: 'boleto-19'));
        $attempts->complete($attempt['id'], 'boleto-19', 'ready', ['state' => 'ready']);
        (new \Pagou\Whmcs\Application\Runtime\PaymentIdentityStore($pdo))->observeCharge($attempt['id'], 'boleto', ['payload' => ['qrcode_id' => 'pix-embedded']]);
        $txid = 'kk6g232xel65a0daee4dd13kk3089590120';
        // Pagou reports this payment only through the embedded Pix; the boleto stays active remotely.
        $this->receive($pdo, ['name' => 'qrcode.completed', 'data' => ['id' => 'pix-embedded', 'external_id' => $txid, 'transaction_id' => 'qr-payment-19', 'amount' => 10, 'e2e_id' => 'E-19']], 0);
        $calls = [];
        $lookups = [];
        $runtime = new WhmcsRuntime($pdo, new AddonSettings(['worker_max_jobs' => '5']), new PdoOperationOutbox($pdo), static function (string $command, array $params) use ($pdo, &$calls): array {
            $calls[] = [$command, $params];
            if ($command === 'AddInvoicePayment') {
                $pdo->prepare('INSERT INTO tblaccounts (invoiceid, transid, gateway, amountin, amountout) VALUES (?, ?, ?, ?, ?)')->execute([$params['invoiceid'], $params['transid'], $params['gateway'], $params['amount'], '0.00']);
                $pdo->exec("UPDATE tblinvoices SET status = 'Paid'");
            }
            return ['result' => 'success'];
        }, static function (string $method, string $id) use (&$lookups, $txid) {
            $lookups[] = [$method, $id];
            return $method === 'boleto'
                ? (new BoletoResponseMapper())->charge(['id' => $id, 'amount' => 10, 'status' => 2, 'fee' => 2.49, 'payload' => ['line' => 'line', 'bar_code' => 'barcode', 'qrcode_id' => 'pix-embedded']])
                : (new PixResponseMapper())->charge(['id' => $id, 'amount' => 10, 'status' => 4, 'paid_at' => '2026-09-17T12:00:00Z', 'external_id' => $txid, 'fee' => 2.49]);
        });
        $runtime->runWorker('worker-1');
        $runtime->runWorker('worker-2');

        $payments = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'AddInvoicePayment'));
        self::assertCount(1, $payments);
        self::assertSame($txid, $payments[0][1]['transid']);
        self::assertSame('pagou_boleto', $payments[0][1]['gateway']);
        self::assertSame('10.00', $payments[0][1]['amount']);
        self::assertSame('2.49', $payments[0][1]['fees']);
        // The embedded Pix is confirmed remotely before booking.
        self::assertContains(['pix', 'pix-embedded'], $lookups);
        self::assertSame('paid', $attempts->find($attempt['id'])['status']);
        // The boleto that received the payment is never cancelled, and no false finding is opened.
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM pagou_payment_operations WHERE operation_type = 'cancel_boleto'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_reconciliation_findings')->fetchColumn());
        self::assertSame('paid', (new \Pagou\Whmcs\Application\Runtime\ClientPaymentStatus($pdo))->read(19, 'boleto', 7, false)['state']);
    }

    public function testEmbeddedPixWithoutRemoteConfirmationIsNotBooked(): void
    {
        $pdo = $this->pdo();
        $attempts = new PaymentAttemptStore($pdo);
        $attempt = $attempts->ensureCurrent(19, 7, 'boleto', 1000, '2026-09-20');
        (new PdoBoletoAttemptRepository($pdo, 7))->save(new BoletoAttempt($attempt['id'], '19', 1, 1000, '2026-09-20', $attempt['idempotency_key'], remoteId: 'boleto-19'));
        $attempts->complete($attempt['id'], 'boleto-19', 'ready', ['state' => 'ready']);
        (new \Pagou\Whmcs\Application\Runtime\PaymentIdentityStore($pdo))->observeCharge($attempt['id'], 'boleto', ['payload' => ['qrcode_id' => 'pix-embedded']]);
        $this->receive($pdo, ['name' => 'qrcode.completed', 'data' => ['id' => 'pix-embedded', 'external_id' => 'kk1', 'transaction_id' => 'qr-payment-19', 'amount' => 10]], 0);
        $calls = [];
        $runtime = new WhmcsRuntime($pdo, new AddonSettings(['worker_max_jobs' => '5']), new PdoOperationOutbox($pdo), static function (string $command, array $params) use (&$calls): array {
            $calls[] = [$command, $params];
            return ['result' => 'success'];
        }, static fn (string $method, string $id) => $method === 'boleto'
            ? (new BoletoResponseMapper())->charge(['id' => $id, 'amount' => 10, 'status' => 2, 'payload' => ['line' => 'line', 'bar_code' => 'barcode']])
            : (new PixResponseMapper())->charge(['id' => $id, 'amount' => 10, 'status' => 2]));
        $runtime->runWorker('worker-1');
        self::assertNotContains('AddInvoicePayment', array_column($calls, 0));
        self::assertNotSame('paid', $attempts->find($attempt['id'])['status']);
    }

    public function testBoletoPaidByBarcodeRecordsTheChargeFee(): void
    {
        $pdo = $this->pdo();
        $attempts = new PaymentAttemptStore($pdo);
        $attempt = $attempts->ensureCurrent(19, 7, 'boleto', 1000, '2026-09-20');
        (new PdoBoletoAttemptRepository($pdo, 7))->save(new BoletoAttempt($attempt['id'], '19', 1, 1000, '2026-09-20', $attempt['idempotency_key'], remoteId: 'boleto-19'));
        $attempts->complete($attempt['id'], 'boleto-19', 'ready', ['state' => 'ready']);
        $this->receive($pdo, ['name' => 'charge.paid', 'data' => [
            'id' => 'boleto-19', 'transaction_id' => '2d60d1dc-1c3b-49f6-8c0d-63baf5569434', 'amount' => ['paid' => 10], 'paid_in' => '2026-09-17T12:00:00Z',
        ]], 0);
        $calls = [];
        $this->runtime($pdo, 'boleto', $calls, ['fee' => 2.49])->runWorker();
        $payments = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'AddInvoicePayment'));
        self::assertSame('2d60d1dc-1c3b-49f6-8c0d-63baf5569434', $payments[0][1]['transid']);
        self::assertSame('2.49', $payments[0][1]['fees']);
    }

    public function testPaidRemoteStatusWithoutReceiptDoesNotInventPayment(): void
    {
        $pdo = $this->pdo();
        $attempts = new PaymentAttemptStore($pdo);
        $attempt = $attempts->ensureCurrent(19, 7, 'pix', 1000, '2026-09-20');
        $attempts->complete($attempt['id'], 'pix-original', 'ready', ['state' => 'ready']);
        $runtime = $this->runtime($pdo, 'pix');
        self::assertSame(1, $runtime->schedulePendingReconciliation());
        $runtime->runWorker();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn());
        self::assertSame('payment_evidence_missing', $pdo->query('SELECT finding_type FROM pagou_reconciliation_findings')->fetchColumn());
        self::assertSame('processing', $attempts->displayForInvoice(19, 'pix')['state']);
    }

    public function testInvalidSignatureNeverEntersTheFinancialQueue(): void
    {
        $pdo = $this->pdo();
        $endpoint = $this->endpoint($pdo);
        $endpoint->receive(['X-Pagou-Timestamp' => (string) time(), 'X-Pagou-Signature' => str_repeat('0', 64)], '{"name":"charge.paid","data":{}}');
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_webhook_deliveries')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_payment_operations')->fetchColumn());
    }

    public function testRefundAndUnknownEventsNeverCreatePositivePayments(): void
    {
        $pdo = $this->pdo();
        $attempts = new PaymentAttemptStore($pdo);
        $attempt = $attempts->ensureCurrent(19, 7, 'pix', 1000, '2026-09-20');
        $attempts->complete($attempt['id'], 'pix-original', 'ready', ['state' => 'ready']);
        foreach (['qrcode.refunded', 'future.unknown'] as $offset => $event) {
            $this->receive($pdo, ['name' => $event, 'data' => ['id' => 'pix-original', 'transaction_id' => 'not-a-receipt', 'amount' => 10]], $offset);
        }
        $this->runtime($pdo, 'pix')->runWorker();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn());
    }

    public function testRefundResolverKeepsOriginalReceiptAfterInvoiceRevision(): void
    {
        $pdo = $this->pdo();
        $attempts = new PaymentAttemptStore($pdo);
        $first = $attempts->ensureCurrent(19, 7, 'pix', 1000, '2026-09-20');
        $attempts->complete($first['id'], 'pix-original', 'ready', ['state' => 'ready']);
        $this->receive($pdo, ['name' => 'qrcode.completed', 'data' => ['id' => 'pix-original', 'transaction_id' => 'receipt-19', 'amount' => 10]], 0);
        $this->runtime($pdo, 'pix')->runWorker();
        $next = $attempts->ensureQueued(19, 7, 'pix', 2000, 2, '2026-09-21');
        $attempts->complete($next['id'], 'pix-new', 'ready', ['state' => 'ready']);
        $transaction = (string) $pdo->query('SELECT transid FROM tblaccounts')->fetchColumn();
        self::assertSame($first['id'], (new PaymentTransactionResolver($pdo))->resolve(19, $transaction, 'pix')['id']);
        $this->expectException(\LogicException::class);
        (new PaymentTransactionResolver($pdo))->resolve(20, $transaction, 'pix');
    }

    public function testTerminalDisplayHidesPaymentArtifactsAndRefreshesAutomatically(): void
    {
        $pdo = $this->pdo();
        $attempts = new PaymentAttemptStore($pdo);
        $attempt = $attempts->ensureCurrent(19, 7, 'pix', 1000, '2026-09-20');
        $pdo->exec("UPDATE tblinvoices SET status = 'Paid' WHERE id = 19");
        foreach (['paid', 'cancelled', 'failed', 'processing'] as $state) {
            $attempts->complete($attempt['id'], 'pix-original', $state, ['state' => $state, 'copyPaste' => 'do-not-pay-this', 'amount' => '10.00']);
            $html = $this->runtime($pdo, 'pix')->renderInvoice(['invoiceid' => 19, 'amount' => '10.00'], 'pix');
            self::assertStringNotContainsString('do-not-pay-this', $html);
            self::assertStringNotContainsString('data-pagou-refresh', $html);
            self::assertStringContainsString('data-pagou-status-url', $html);
            self::assertStringContainsString('data-pagou-revision', $html);
            self::assertStringContainsString('data-pagou-invoice-state="paid"', $html);
        }
    }

    public function testCardInvoiceRejectsStaleAmountAndWrongOwnerBeforeRemoteEffects(): void
    {
        $pdo = $this->pdo();
        foreach ([['userid' => 8], ['status' => 'Paid'], ['paymentmethod' => 'pagoupix'], ['balance' => '20.00']] as $change) {
            $invoice = array_replace(['userid' => 7, 'status' => 'Unpaid', 'paymentmethod' => 'pagou_creditcard', 'balance' => '10.00'], $change);
            $runtime = new WhmcsRuntime($pdo, new AddonSettings([]), new PdoOperationOutbox($pdo), static fn (): array => ['result' => 'success'] + $invoice);
            try {
                $runtime->assertCardInvoice(19, 7, 1000);
                self::fail('A stale or unauthorized session was accepted.');
            } catch (\LogicException) {
                self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_payment_attempts')->fetchColumn());
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function receive(PDO $pdo, array $payload, int $offset): void
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $time = (string) (time() + $offset);
        $this->endpoint($pdo)->receive(['X-Pagou-Timestamp' => $time, 'X-Pagou-Signature' => hash_hmac('sha256', $time . $body, 'synthetic-secret')], $body);
    }

    public function testPixUsesTheTxidLocalDateAndProviderFeeInTheNativePayment(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('America/Sao_Paulo');
        try {
            $pdo = $this->pdo();
            $attempts = new PaymentAttemptStore($pdo);
            $attempt = $attempts->ensureCurrent(19, 7, 'pix', 1000, '2026-09-20');
            $attempts->complete($attempt['id'], 'pix-original', 'ready', ['state' => 'ready']);
            $txid = 'kk6g232xel65a0daee4dd13kk3087227315';
            $body = ['name' => 'qrcode.completed', 'data' => ['id' => 'pix-original', 'external_id' => $txid, 'transaction_id' => 'receipt-19', 'amount' => 10, 'e2e_id' => 'E-receipt-19']];
            $this->receive($pdo, $body, 0);
            $calls = [];
            $runtime = $this->runtime($pdo, 'pix', $calls, ['fee' => 0.99]);
            $runtime->runWorker('worker-1');
            // A re-signed retry of the same payment never books it twice, even with the new identifier.
            $this->receive($pdo, $body, 1);
            $runtime->runWorker('worker-2');

            $payments = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'AddInvoicePayment'));
            self::assertCount(1, $payments);
            self::assertSame($txid, $payments[0][1]['transid']);
            self::assertSame('0.99', $payments[0][1]['fees']);
            // 12:00 UTC is 09:00 in São Paulo, the time zone of the WHMCS installation.
            self::assertSame('2026-09-17 09:00:00', $payments[0][1]['date']);
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn());
            // The ledger identity is unchanged; the txid is recorded beside it.
            $metadata = json_decode((string) $pdo->query("SELECT metadata_json FROM pagou_ledger_entries WHERE entry_type = 'received_payment'")->fetchColumn(), true);
            self::assertSame('receipt-19', $metadata['remote_payment_id']);
            self::assertSame($txid, $metadata['native_transaction_id']);
            self::assertSame(99, $metadata['fee_cents']);
            // Refunds and the client status resolve the receipt through the txid.
            self::assertSame('pix-original', (new PaymentTransactionResolver($pdo))->resolve(19, $txid, 'pix')['remote_id']);
            self::assertSame('paid', (new \Pagou\Whmcs\Application\Runtime\ClientPaymentStatus($pdo))->read(19, 'pix', 7, false)['state']);
            $port = new \Pagou\Whmcs\Infrastructure\Whmcs\NativeWhmcsFinancialPort($pdo, static fn (): array => []);
            $key = EconomicPaymentKey::fromRemotePayment(19, 'pagou', 'receipt-19');
            self::assertTrue($port->paymentAlreadyApplied($key, strtoupper($txid)));
            self::assertFalse($port->paymentAlreadyApplied($key));
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function testAcknowledgedNotificationIsAppliedWithoutWaitingForTheWorker(): void
    {
        $pdo = $this->pdo();
        $attempts = new PaymentAttemptStore($pdo);
        $attempt = $attempts->ensureCurrent(19, 7, 'pix', 1000, '2026-09-20');
        $attempts->complete($attempt['id'], 'pix-original', 'ready', ['state' => 'ready']);
        $body = json_encode(['name' => 'qrcode.completed', 'data' => ['id' => 'pix-original', 'external_id' => 'kk1', 'transaction_id' => 'receipt-19', 'amount' => 10]], JSON_THROW_ON_ERROR);
        $time = (string) time();
        $receipt = $this->endpoint($pdo)->receive(['X-Pagou-Timestamp' => $time, 'X-Pagou-Signature' => hash_hmac('sha256', $time . $body, 'synthetic-secret')], $body);
        self::assertSame('processed', $receipt->outcome);
        $calls = [];
        $runtime = $this->runtime($pdo, 'pix', $calls);
        self::assertNotNull($runtime->advanceWebhook((string) $receipt->deliveryKey));
        self::assertSame('Paid', $pdo->query('SELECT status FROM tblinvoices')->fetchColumn());
        self::assertSame('kk1', $pdo->query('SELECT transid FROM tblaccounts')->fetchColumn());
        // The cron run afterwards finds nothing left to book.
        $runtime->runWorker('worker-1');
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn());
        self::assertNull($runtime->advanceWebhook('unknown-delivery'));
    }

    public function testPixWithoutTxidOrFeeKeepsThePaymentIdentifier(): void
    {
        $pdo = $this->pdo();
        $attempts = new PaymentAttemptStore($pdo);
        $attempt = $attempts->ensureCurrent(19, 7, 'pix', 1000, '2026-09-20');
        $attempts->complete($attempt['id'], 'pix-original', 'ready', ['state' => 'ready']);
        $this->receive($pdo, ['name' => 'qrcode.completed', 'data' => ['id' => 'pix-original', 'external_id' => 'bad txid!', 'transaction_id' => 'receipt-19', 'amount' => 10]], 0);
        $calls = [];
        $this->runtime($pdo, 'pix', $calls, ['fee' => 'n/a'])->runWorker('worker-1');
        $payments = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'AddInvoicePayment'));
        self::assertCount(1, $payments);
        self::assertSame('receipt-19', $payments[0][1]['transid']);
        self::assertArrayNotHasKey('fees', $payments[0][1]);
    }

    private function endpoint(PDO $pdo): WebhookEndpointService
    {
        return new WebhookEndpointService(new HmacWebhookVerifier('synthetic-secret'), new WebhookEventParser(), new PdoWebhookInbox($pdo), new OutboxWebhookEventHandler($pdo, new PdoOperationOutbox($pdo)));
    }

    /**
     * @param list<array{string, array<string, mixed>}> $calls
     * @param array<string, mixed> $detail
     */
    private function runtime(PDO $pdo, string $method, array &$calls = [], array $detail = []): WhmcsRuntime
    {
        return new WhmcsRuntime($pdo, new AddonSettings(['worker_max_jobs' => '1']), new PdoOperationOutbox($pdo), static function (string $command, array $params) use ($pdo, &$calls): array {
            $calls[] = [$command, $params];
            if ($command === 'UpdateInvoice') {
                foreach ($params['newitemamount'] ?? [] as $index => $amount) {
                    $pdo->prepare('INSERT INTO tblinvoiceitems (invoiceid, description, amount) VALUES (?, ?, ?)')->execute([$params['invoiceid'], $params['newitemdescription'][$index], $amount]);
                    $pdo->prepare('UPDATE tblinvoices SET total = total + ? WHERE id = ?')->execute([$amount, $params['invoiceid']]);
                }
            }
            if ($command === 'AddInvoicePayment') {
                $pdo->prepare('INSERT INTO tblaccounts (invoiceid, transid, gateway, amountin, amountout) VALUES (:invoice, :transid, :gateway, :amount, 0)')->execute(['invoice' => $params['invoiceid'], 'transid' => $params['transid'], 'gateway' => $params['gateway'], 'amount' => $params['amount']]);
                $pdo->exec("UPDATE tblinvoices SET status = 'Paid'");
            }
            return ['result' => 'success'];
        }, static function (string $requestedMethod, string $id) use ($method, $detail) {
            self::assertSame($method, $requestedMethod);
            $response = ['id' => $id, 'amount' => 10, 'status' => 4, 'paid_at' => '2026-09-17T12:00:00.116Z', 'payload' => ['line' => 'line', 'bar_code' => 'barcode']] + $detail;
            return $method === 'pix' ? (new PixResponseMapper())->charge($response) : (new BoletoResponseMapper())->charge($response);
        });
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, total TEXT, credit TEXT, status TEXT)');
        $pdo->exec('CREATE TABLE tblaccounts (invoiceid INTEGER, transid TEXT UNIQUE, gateway TEXT NOT NULL, amountin TEXT, amountout TEXT)');
        $pdo->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INTEGER, description TEXT, amount TEXT)');
        $pdo->exec("INSERT INTO tblinvoices VALUES (19, 7, '10.00', '0.00', 'Unpaid')");
        return $pdo;
    }
}
