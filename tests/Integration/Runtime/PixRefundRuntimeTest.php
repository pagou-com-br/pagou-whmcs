<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\{AddonSettings, ClientPaymentStatus, PaymentAttemptStore, PeriodicReconciliation, PixRefundRuntime, PixRefundView, RetentionService, WhmcsRuntime};
use Pagou\Whmcs\Infrastructure\Persistence\{LeaseRepository, MigrationRunner};
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Payment\Pix\Dto\{PixCharge, RefundPixRequest};
use Pagou\Whmcs\Payment\Pix\Exception\PixApiException;
use Pagou\Whmcs\Payment\Pix\Infrastructure\PdoPixRefundStore;
use Pagou\Whmcs\Payment\Pix\Mapper\PixResponseMapper;
use PHPUnit\Framework\TestCase;

final class PixRefundRuntimeTest extends TestCase
{
    private PDO $pdo;
    private string $attempt;
    private PixRefundRuntime $runtime;
    private int $sent = 0;
    private int $nativeWrites = 0;
    private ?\Throwable $remoteError = null;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, total TEXT, credit TEXT, status TEXT)');
        $this->pdo->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY AUTOINCREMENT, invoiceid INTEGER, userid INTEGER, transid TEXT UNIQUE, gateway TEXT, amountin DECIMAL(18,2), amountout DECIMAL(18,2))');
        $this->pdo->exec("INSERT INTO tblinvoices VALUES (19, 7, '12.00', '0.00', 'Paid')");
        $this->pdo->exec("INSERT INTO tblaccounts (invoiceid, userid, transid, gateway, amountin, amountout) VALUES (19, 7, 'original-receipt', 'pagou_pix', '12.00', '0.00')");
        $attempts = new PaymentAttemptStore($this->pdo);
        $this->attempt = $attempts->ensureCurrent(19, 7, 'pix', 1200)['id'];
        $attempts->complete($this->attempt, 'original-pix', 'paid', ['state' => 'paid', 'remoteId' => 'original-pix']);
        $this->pdo->prepare('INSERT INTO pagou_ledger_entries (id, idempotency_key, invoice_id, client_id, attempt_id, entry_type, amount_cents, currency, metadata_json, created_at, effective_at_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            'original-ledger', 'original-receipt', 19, 7, $this->attempt, 'received_payment', 1200, 'BRL',
            json_encode(['economic_key' => 'original-receipt', 'method' => 'pix', 'remote_charge_id' => 'original-pix']), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'),
        ]);
        $this->runtime = new PixRefundRuntime($this->pdo, function (RefundPixRequest $request): void {
            ++$this->sent;
            self::assertSame('original-pix', $request->pixId);
            if ($this->remoteError !== null) {
                throw $this->remoteError;
            }
        });
    }

    public function testAcceptedRequestDoesNotCreateOutflowAndFinalConfirmationIsAppliedOnce(): void
    {
        $this->runtime->request(19, 'original-receipt', 1200, 1);
        self::assertSame('requested', $this->row()['status']);
        self::assertSame('refund_pending', (new ClientPaymentStatus($this->pdo))->display(19, 'pix')['state']);
        self::assertSame('pending', $this->runtime->reconcile($this->attempt, $this->charge(1200, 2)));
        self::assertSame(0, $this->nativeWrites);
        self::assertSame('Paid', $this->invoiceState());
        self::assertSame('pending', $this->runtime->reconcile($this->attempt, $this->charge(1200)));
        $this->nativeCompletion(1200);
        for ($i = 0; $i < 5; ++$i) {
            $this->runtime->request(19, 'original-receipt', 1200, 1);
            self::assertSame('applied', $this->runtime->reconcile($this->attempt, $this->charge(1200)));
        }
        self::assertSame(1, $this->sent);
        self::assertSame(1, $this->nativeWrites);
        self::assertSame('Refunded', $this->invoiceState());
        self::assertSame('bank-refund', $this->pdo->query("SELECT transid FROM tblaccounts WHERE amountout = '12.00'")->fetchColumn());
        self::assertSame(-1200, (int) $this->pdo->query("SELECT amount_cents FROM pagou_ledger_entries WHERE entry_type = 'refunded_payment'")->fetchColumn());
        self::assertSame('refunded', (new ClientPaymentStatus($this->pdo))->display(19, 'pix')['state']);
    }

    public function testPartialRefundPreservesInvoiceStateAndCannotStartAnotherRefund(): void
    {
        $this->runtime->request(19, 'original-receipt', 400, 1);
        self::assertSame('pending', $this->runtime->reconcile($this->attempt, $this->charge(400)));
        $this->nativeCompletion(400);
        self::assertSame('applied', $this->runtime->reconcile($this->attempt, $this->charge(400)));
        self::assertSame('Paid', $this->invoiceState());
        self::assertSame('partially_refunded', (new ClientPaymentStatus($this->pdo))->display(19, 'pix')['state']);
        self::assertEquals(4, $this->pdo->query("SELECT amountout FROM tblaccounts WHERE transid = 'bank-refund'")->fetchColumn());
        try {
            $this->runtime->request(19, 'original-receipt', 800, 1);
            self::fail('A second partial refund must not be sent.');
        } catch (\DomainException) {
            self::assertSame(1, $this->sent);
        }
    }

    public function testOnlyATotalRefundLetsWhmcsReverseTheReceiptFee(): void
    {
        // Pagou gives the Pix fee back only when the whole receipt is refunded.
        $this->runtime->request(19, 'original-receipt', 400, 1);
        $this->runtime->reconcile($this->attempt, $this->charge(400));
        $partial = $this->runtime->authorizeNative($this->attempt);
        self::assertSame('success', $partial['status']);
        self::assertSame('0.00', $partial['fees']);

        $this->pdo->exec('DELETE FROM pagou_pix_refunds');
        $this->runtime->request(19, 'original-receipt', 1200, 1);
        $this->runtime->reconcile($this->attempt, $this->charge(1200));
        $total = $this->runtime->authorizeNative($this->attempt);
        self::assertSame('success', $total['status']);
        self::assertArrayNotHasKey('fees', $total);
    }

    public function testInvalidAmountIdentityOrPreviousManualRefundNeverSends(): void
    {
        foreach ([[19, 'original-receipt', 0], [19, 'original-receipt', -1], [19, 'original-receipt', 1201], [20, 'original-receipt', 400], [19, 'another-receipt', 400]] as [$invoice, $transaction, $amount]) {
            try {
                $this->runtime->request($invoice, $transaction, $amount, 1);
                self::fail('Invalid request was accepted.');
            } catch (\LogicException) {
                self::assertSame(0, $this->sent);
            }
        }
        $this->pdo->exec("INSERT INTO tblaccounts (invoiceid, userid, transid, gateway, amountin, amountout) VALUES (19, 7, 'manual', 'pagou_pix', '0.00', '1.00')");
        $this->expectException(\DomainException::class);
        $this->runtime->request(19, 'original-receipt', 400, 1);
    }

    public function testLostRemoteResponseAndServerErrorsNeverResend(): void
    {
        $this->remoteError = new PixApiException('Synthetic unavailable response', 503);
        $this->runtime->request(19, 'original-receipt', 1200, 1);
        self::assertSame('uncertain', $this->row()['status']);
        $this->runtime->request(19, 'original-receipt', 1200, 1);
        self::assertSame(1, $this->sent);
        self::assertSame('pending', $this->runtime->reconcile($this->attempt, $this->charge(1200)));
        $this->nativeCompletion(1200);
        self::assertSame('applied', $this->runtime->reconcile($this->attempt, $this->charge(1200)));
        self::assertSame(1, $this->nativeWrites);
    }

    public function testDefinitiveRejectionDoesNotRecordRefundOrAllowResubmission(): void
    {
        $this->remoteError = new PixApiException('Synthetic invalid request', 422);
        $this->runtime->request(19, 'original-receipt', 1200, 1);
        $this->runtime->request(19, 'original-receipt', 1200, 1);
        self::assertSame('rejected', $this->row()['status']);
        self::assertSame(1, $this->sent);
        self::assertSame(0, $this->nativeWrites);
    }

    public function testMismatchedRemoteAmountOrIdentityIsSentForReview(): void
    {
        $this->runtime->request(19, 'original-receipt', 400, 1);
        foreach ([['amount' => 3], ['external_id' => 'original-receipt'], ['external_id' => '']] as $change) {
            self::assertSame('review', $this->runtime->reconcile($this->attempt, $this->charge(400, 3, $change)));
            self::assertSame(0, $this->nativeWrites);
        }
        $charge = (new PixResponseMapper())->charge(['id' => 'other-pix', 'amount' => 12, 'status' => 5, 'refund' => ['external_id' => 'bank-refund', 'amount' => 4, 'status' => 3]]);
        self::assertSame('review', $this->runtime->reconcile($this->attempt, $charge));
        self::assertSame(0, $this->nativeWrites);
    }

    public function testNativeCallbackSuccessIsIssuedOnlyOnceAfterRemoteConfirmation(): void
    {
        $this->runtime->request(19, 'original-receipt', 1200, 1);
        self::assertSame('declined', $this->runtime->authorizeNative($this->attempt)['status']);
        self::assertSame('pending', $this->runtime->reconcile($this->attempt, $this->charge(1200)));
        self::assertSame('confirmed', $this->row()['status']);
        self::assertSame(0, $this->nativeWrites);
        $result = $this->runtime->authorizeNative($this->attempt);
        self::assertSame('success', $result['status']);
        self::assertSame('bank-refund', $result['transid']);
        self::assertSame('declined', $this->runtime->authorizeNative($this->attempt)['status']);
        // A lost native response is recovered by observing WHMCS's transaction.
        $this->insertNative(1200, $result['transid']);
        self::assertSame('applied', $this->runtime->reconcile($this->attempt, $this->charge(1200)));
        self::assertSame('declined', $this->runtime->authorizeNative($this->attempt)['status']);
        self::assertSame(1, $this->nativeWrites);
    }

    public function testNativeDispatchWithoutConfirmedOutflowIsNeverAutomaticallyRepeated(): void
    {
        $this->runtime->request(19, 'original-receipt', 1200, 1);
        $this->runtime->reconcile($this->attempt, $this->charge(1200));
        self::assertSame('success', $this->runtime->authorizeNative($this->attempt)['status']);
        $this->pdo->exec("UPDATE pagou_pix_refunds SET native_dispatch_at = '2000-01-01'");
        self::assertSame('review', $this->runtime->reconcile($this->attempt, $this->charge(1200)));
        self::assertSame('declined', $this->runtime->authorizeNative($this->attempt)['status']);
        self::assertSame(0, $this->nativeWrites);
        self::assertSame(1, $this->sent);
    }

    public function testChangedOriginalReceiptOrConflictingNativeIdentityCannotBeApplied(): void
    {
        $this->runtime->request(19, 'original-receipt', 400, 1);
        $this->pdo->exec("UPDATE tblaccounts SET amountin = '11.00'");
        self::assertSame('review', $this->runtime->reconcile($this->attempt, $this->charge(400)));
        $this->pdo->exec("UPDATE tblaccounts SET amountin = '12.00'");
        $this->pdo->exec("INSERT INTO tblaccounts (invoiceid, userid, transid, gateway, amountin, amountout) VALUES (20, 8, 'bank-refund', 'pagou_pix', '0.00', '4.00')");
        self::assertSame('review', $this->runtime->reconcile($this->attempt, $this->charge(400)));
        self::assertSame(0, $this->nativeWrites);
    }

    public function testLeaseExcludesAnotherWorkerAndRetentionPreservesRefundIdentity(): void
    {
        $this->runtime->request(19, 'original-receipt', 400, 1);
        $lease = new LeaseRepository($this->pdo);
        self::assertTrue($lease->acquire('pix-refund:' . $this->attempt, 'other-worker', 60));
        self::assertSame('pending', $this->runtime->reconcile($this->attempt, $this->charge(400)));
        self::assertSame(0, $this->nativeWrites);
        $lease->release('pix-refund:' . $this->attempt, 'other-worker');
        self::assertSame('pending', $this->runtime->reconcile($this->attempt, $this->charge(400)));
        $this->nativeCompletion(400);
        self::assertSame('applied', $this->runtime->reconcile($this->attempt, $this->charge(400)));
        $this->pdo->exec("UPDATE pagou_pix_refunds SET requested_at = '2000-01-01', updated_at = '2000-01-01'");
        (new RetentionService($this->pdo))->run();
        self::assertSame('bank-refund', $this->row()['provider_refund_id']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM pagou_ledger_entries WHERE entry_type = 'refunded_payment'")->fetchColumn());
    }

    public function testWorkerConfirmsTheBankButOnlyNativeCallbackAuthorizesWhmcsBooking(): void
    {
        $this->runtime->request(19, 'original-receipt', 400, 1);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pagou_payment_attempts a WHERE ' . PeriodicReconciliation::eligibleSql())->fetchColumn());
        $worker = new WhmcsRuntime($this->pdo, new AddonSettings(['worker_max_jobs' => '1']), new PdoOperationOutbox($this->pdo), static function (): array {
            throw new \LogicException('The module must never replace the native refund through AddTransaction or UpdateInvoice.');
        }, fn () => $this->charge(400));
        $worker->refreshPixRefund(19);
        self::assertSame('confirmed', $this->row()['status']);
        self::assertSame(0, $this->nativeWrites);
        self::assertSame(['supported' => true, 'refundStatus' => 'confirmed', 'ready' => true, 'amount' => '4.00', 'amountLabel' => 'R$ 4,00'], $worker->nativePixRefund(19, 1, 'read'));
        // A blank Refund amount means the full receipt in WHMCS; the pending partial refund is declined with its amount.
        $declined = $worker->refundPix(19, 'original-receipt', 1000, 1);
        self::assertSame('declined', $declined['status']);
        self::assertSame('Este Pix já possui uma devolução de R$ 4,00. Informe esse valor para concluir o registro.', $declined['declinereason']);
        self::assertSame('confirmed', $this->row()['status']);
        self::assertSame(['supported' => false], $worker->nativePixRefund(20, 1, 'read'));
        $result = $worker->refundPix(19, 'original-receipt', 400, 1);
        self::assertSame('success', $result['status']);
        $this->insertNative(400, $result['transid']);
        $worker->confirmNativePixRefunds(19);
        $worker->confirmNativePixRefunds(19);
        self::assertSame('applied', $this->row()['status']);
        self::assertSame('paid', (new PaymentAttemptStore($this->pdo))->find($this->attempt)['status']);
        self::assertSame('Paid', $this->invoiceState());
        self::assertSame(1, $this->nativeWrites);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pagou_payment_attempts a WHERE ' . PeriodicReconciliation::eligibleSql())->fetchColumn());
    }

    public function testRefundViewOnlyExposesTheRecordedRefundStatus(): void
    {
        $attempt = (new PaymentAttemptStore($this->pdo))->find($this->attempt);
        $view = new PixRefundView($this->pdo);
        self::assertSame([], $view->forAttempt($attempt));
        $this->runtime->request(19, 'original-receipt', 400, 1);
        self::assertSame('requested', $view->forAttempt($attempt)['status']);
        self::assertArrayNotHasKey('transactionId', $view->forAttempt($attempt));
    }

    public function testChangedClientCannotRefundAPaymentOwnedByThePreviousClient(): void
    {
        $this->pdo->exec('UPDATE tblinvoices SET userid = 8');
        try {
            $this->runtime->request(19, 'original-receipt', 400, 1);
            self::fail('The changed invoice owner must be checked before sending.');
        } catch (\DomainException) {
            self::assertSame(0, $this->sent);
        }
    }

    /** @param array<string,mixed> $change */
    private function charge(int $amount, int $status = 3, array $change = []): PixCharge
    {
        return (new PixResponseMapper())->charge(['id' => 'original-pix', 'amount' => 12, 'status' => $status === 3 ? 5 : 4,
            'refund' => array_replace(['external_id' => 'bank-refund', 'amount' => $amount / 100, 'status' => $status], $change)]);
    }

    private function nativeCompletion(int $amount): void
    {
        $result = $this->runtime->authorizeNative($this->attempt);
        self::assertSame('success', $result['status']);
        $this->insertNative($amount, $result['transid']);
        $this->runtime->confirmNative(19);
    }

    /** Simulates the documented WHMCS caller, outside the module's implementation. */
    private function insertNative(int $amount, string $id): void
    {
        ++$this->nativeWrites;
        $this->pdo->prepare('INSERT INTO tblaccounts (invoiceid, userid, transid, gateway, amountin, amountout) VALUES (19, 7, ?, ?, ?, ?)')->execute([$id, 'pagou_pix', '0.00', number_format($amount / 100, 2, '.', '')]);
        if ($amount === 1200) {
            $this->pdo->exec("UPDATE tblinvoices SET status = 'Refunded' WHERE id = 19");
        }
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return (new PdoPixRefundStore($this->pdo))->find($this->attempt);
    }

    private function invoiceState(): string
    {
        return $this->pdo->query('SELECT status FROM tblinvoices WHERE id = 19')->fetchColumn();
    }
}
