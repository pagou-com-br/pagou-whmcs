<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Card;

use PDO;
use Pagou\Whmcs\Application\Async\InMemoryOperationOutbox;
use Pagou\Whmcs\Application\Runtime\CardReconciliationRuntime;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Payment\Card\CardApiClient;
use Pagou\Whmcs\Payment\Card\CardPaymentService;
use Pagou\Whmcs\Payment\Card\CardReconciler;
use Pagou\Whmcs\Payment\Card\CardStatus;
use Pagou\Whmcs\Payment\Card\CardTransport;
use Pagou\Whmcs\Payment\Card\Contracts\CardOperationJournal;
use Pagou\Whmcs\Payment\Card\Contracts\CardReconciliationScheduler;
use Pagou\Whmcs\Payment\Card\Dto\CardCharge;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardAttemptStore;
use PHPUnit\Framework\TestCase;

final class CardReconciliationRuntimeTest extends TestCase
{
    public function testLegacyNativeCaptureIsNotAppliedAgainByReconciliation(): void
    {
        $pdo = $this->pdo();
        $calls = 0;
        $runtime = $this->runtime($pdo, new RecordingCardTransport(), $calls);
        $this->attempt($pdo, 19, 1590, CardStatus::Paid);
        $pdo->exec("INSERT INTO tblaccounts (invoiceid, transid, gateway, amountin, amountout) VALUES (19, 'charge-19', 'pagou_creditcard', '15.90', '0.00')");
        $pdo->exec("UPDATE tblinvoices SET status = 'Paid' WHERE id = 19");

        self::assertSame('applied', $runtime->process(new CardCharge('charge-19', CardStatus::Paid, 1590)));
        self::assertSame(0, $calls);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn());
    }

    public function testPaidChargeIsAppliedExactlyOnce(): void
    {
        $pdo = $this->pdo();
        $calls = 0;
        $runtime = $this->runtime($pdo, new RecordingCardTransport(), $calls);
        $attempt = $this->attempt($pdo, 19, 1590, CardStatus::Pending);
        $paid = new CardCharge(
            'charge-19',
            CardStatus::Paid,
            1590,
            providerStatus: 'captured',
            raw: ['captured_at' => '2026-08-23T12:00:00Z'],
        );

        self::assertSame('applied', $runtime->process($paid));
        self::assertSame('applied', $runtime->process($paid));
        self::assertSame(1, $calls);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn());
        self::assertSame('Paid', $pdo->query('SELECT status FROM tblinvoices WHERE id = 19')->fetchColumn());
        self::assertSame('paid', $pdo->query("SELECT status FROM pagou_payment_attempts WHERE id = '{$attempt}'")->fetchColumn());
    }

    public function testAmountMismatchAndChargebackCreateCriticalFindings(): void
    {
        $pdo = $this->pdo();
        $calls = 0;
        $runtime = $this->runtime($pdo, new RecordingCardTransport(), $calls);
        $this->attempt($pdo, 19, 1590, CardStatus::Pending);

        self::assertSame(
            'findings',
            $runtime->process(new CardCharge('charge-19', CardStatus::Paid, 1600, providerStatus: 'captured')),
        );
        self::assertSame(0, $calls);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM tblaccounts')->fetchColumn());
        self::assertSame('card_amount_mismatch', $pdo->query('SELECT finding_type FROM pagou_reconciliation_findings')->fetchColumn());

        self::assertSame(
            'findings',
            $runtime->process(new CardCharge('charge-19', CardStatus::ChargedBack, 1590, providerStatus: 'charged_back')),
        );
        self::assertSame(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM pagou_reconciliation_findings WHERE finding_type = 'card_chargeback_requires_review'")->fetchColumn(),
        );
    }

    public function testUncertainAttemptWithoutRemoteIdentityIsNeverRecreated(): void
    {
        $pdo = $this->pdo();
        $calls = 0;
        $runtime = $this->runtime($pdo, new RecordingCardTransport(), $calls);
        $attempts = new PdoCardAttemptStore($pdo);
        $attempt = $attempts->begin(19, 7, 1590, 'opaque-reference', 1);
        $attempts->uncertain($attempt['id']);

        $report = $runtime->run();

        self::assertSame(0, $calls);
        self::assertSame(0, $report['inspected']);
        self::assertSame(1, $report['findings']);
        self::assertSame(
            'card_remote_identity_unavailable',
            $pdo->query('SELECT finding_type FROM pagou_reconciliation_findings')->fetchColumn(),
        );
    }

    public function testPaidChargeIsMonitoredLaterForChargebacks(): void
    {
        $pdo = $this->pdo();
        $calls = 0;
        $transport = new RecordingCardTransport([
            'id' => 'charge-19',
            'status' => 'charged_back',
            'value' => 1590,
        ]);
        $runtime = $this->runtime($pdo, $transport, $calls);
        $this->attempt($pdo, 19, 1590, CardStatus::Paid);
        $pdo->exec("UPDATE pagou_card_transactions SET updated_at = '2026-08-22 00:00:00.000000'");

        $report = $runtime->run();

        self::assertSame(1, $transport->calls);
        self::assertSame(1, $report['inspected']);
        self::assertSame(1, $report['findings']);
        self::assertSame('charged_back', $pdo->query('SELECT capture_status FROM pagou_card_transactions')->fetchColumn());
    }

    public function testJournalRecoversRemoteSuccessAfterLocalWriteFailure(): void
    {
        $pdo = $this->pdo();
        $calls = 0;
        $transport = new RecordingCardTransport(['id' => 'charge-19', 'status' => 'captured', 'value' => 1590, 'captured_at' => '2026-09-17T12:00:00Z']);
        $store = new PdoCardAttemptStore($pdo);
        $attempt = $store->begin(19, 7, 1590, 'opaque-reference', 1);
        $store->uncertain($attempt['id']);
        $journal = new \Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardOperationJournal($pdo);
        $journal->started('card.charge.create', $attempt['key']->value(), ['attempt_id' => $attempt['id']]);
        $journal->succeeded('card.charge.create', $attempt['key']->value(), ['remote_id' => 'charge-19', 'status' => 'paid']);
        $report = $this->runtime($pdo, $transport, $calls)->run();
        self::assertSame(0, $report['failed']);
        self::assertSame(1, $transport->calls);
        self::assertSame(1, $calls);
        self::assertSame('paid', $pdo->query('SELECT status FROM pagou_payment_attempts')->fetchColumn());
        $transid = (string) $pdo->query('SELECT transid FROM tblaccounts')->fetchColumn();
        self::assertSame('charge-19', $transid);
        $original = (new \Pagou\Whmcs\Payment\Ledger\Infrastructure\PaymentTransactionResolver($pdo))->resolve(19, $transid, 'card');
        self::assertSame('charge-19', $original['remote_id']);
    }

    public function testUncertainRefundClosesByReadOnlyReconciliationAndReplayIsStable(): void
    {
        $pdo = $this->pdo();
        $calls = 0;
        $this->attempt($pdo, 19, 1590, CardStatus::Paid);
        $store = new \Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardRefundStore($pdo);
        $key = \Pagou\Whmcs\Payment\Card\IdempotencyKey::create('refund', 'charge-19');
        $refund = $store->begin('charge-19', 1590, $key);
        $store->mark($refund['id'], 'uncertain');
        $transport = new RecordingCardTransport(['id' => 'charge-19', 'status' => 'reversed', 'value' => 1590]);
        $runtime = $this->runtime($pdo, $transport, $calls);

        self::assertSame(1, $runtime->run()['inspected']);
        self::assertSame(0, $runtime->run()['inspected']);
        $saved = $pdo->query('SELECT * FROM pagou_card_refunds')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('reversed', $saved['status']);
        self::assertNotNull($saved['completed_at_utc']);
        $runtime->process(new CardCharge('charge-19', CardStatus::Reversed, 1590));
        $store->mark($refund['id'], 'uncertain');
        self::assertSame($saved, $pdo->query('SELECT * FROM pagou_card_refunds')->fetch(PDO::FETCH_ASSOC));
        self::assertSame('reversed', $store->begin('charge-19', 1590, $key)['status']);
        self::assertSame(1, $transport->calls);
        self::assertSame(0, $calls);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM pagou_reconciliation_findings WHERE finding_type = 'card_refund_confirmed_requires_review'")->fetchColumn());
    }

    public function testPendingRefundHasNoCompletionAndWrongAmountCannotCloseIt(): void
    {
        $pdo = $this->pdo();
        $calls = 0;
        $this->attempt($pdo, 19, 1590, CardStatus::Paid);
        $store = new \Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardRefundStore($pdo);
        $refund = $store->begin('charge-19', 1590, \Pagou\Whmcs\Payment\Card\IdempotencyKey::create('refund', 'charge-19'));
        $store->complete($refund['id'], new CardCharge('charge-19', CardStatus::Paid, 1590));
        self::assertNull($pdo->query('SELECT completed_at_utc FROM pagou_card_refunds')->fetchColumn());
        self::assertNull($pdo->query('SELECT provider_refund_id FROM pagou_card_refunds')->fetchColumn());
        self::assertSame('uncertain', $pdo->query('SELECT status FROM pagou_card_refunds')->fetchColumn());
        $runtime = $this->runtime($pdo, new RecordingCardTransport(), $calls);
        self::assertSame('findings', $runtime->process(new CardCharge('charge-19', CardStatus::Reversed, 1)));
        self::assertSame('uncertain', $pdo->query('SELECT status FROM pagou_card_refunds')->fetchColumn());
        self::assertSame('paid', $pdo->query('SELECT capture_status FROM pagou_card_transactions')->fetchColumn());
        self::assertSame(0, $calls);
        $this->expectException(\DomainException::class);
        $store->complete($refund['id'], new CardCharge('unrelated-charge', CardStatus::Reversed, 1590));
    }

    public function testOldPaidChargeIsNotStarvedByPendingOrFailedConsultations(): void
    {
        $pdo = $this->pdo();
        $calls = 0;
        $this->attempt($pdo, 19, 1590, CardStatus::Pending);
        $this->attempt($pdo, 20, 1590, CardStatus::Paid);
        $pdo->exec("UPDATE pagou_card_transactions SET updated_at = '2026-01-01 00:00:00' WHERE provider_transaction_id = 'charge-19'");
        $pdo->exec("UPDATE pagou_card_transactions SET updated_at = '2026-01-02 00:00:00' WHERE provider_transaction_id = 'charge-20'");
        $transport = new class implements CardTransport {
            public array $paths = [];
            public function request(string $method, string $path, array $headers = [], ?array $body = null): array
            {
                self::assertReadOnly($method);
                $this->paths[] = $path;
                if (str_ends_with($path, 'charge-19')) {
                    throw new \RuntimeException('read timeout');
                }
                return ['id' => 'charge-20', 'status' => 'charged_back', 'value' => 1590];
            }
            private static function assertReadOnly(string $method): void
            {
                if ($method !== 'GET') {
                    throw new \LogicException('Reconciliation must never mutate the provider.');
                }
            }
        };
        $runtime = $this->runtime($pdo, $transport, $calls);
        self::assertSame(1, $runtime->run(1)['failed']);
        self::assertSame(1, $runtime->run(1)['findings']);
        self::assertSame(['/v1/creditcard/charges/charge-19', '/v1/creditcard/charges/charge-20'], $transport->paths);
        self::assertSame(0, $calls);
    }

    public function testReversingAnUncapturedAuthorizationDoesNotInventARefund(): void
    {
        $pdo = $this->pdo();
        $calls = 0;
        $this->attempt($pdo, 19, 1590, CardStatus::Authorized);
        $runtime = $this->runtime($pdo, new RecordingCardTransport(), $calls);
        self::assertSame('updated', $runtime->process(new CardCharge('charge-19', CardStatus::Reversed, 1590)));
        self::assertSame(0, $calls);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_reconciliation_findings')->fetchColumn());
    }

    public function testReadCannotApplyAnotherChargeReturnedByMistake(): void
    {
        $pdo = $this->pdo();
        $calls = 0;
        $this->attempt($pdo, 19, 1590, CardStatus::Pending);
        $runtime = $this->runtime($pdo, new RecordingCardTransport(['id' => 'charge-19', 'status' => 'captured', 'value' => 1590]), $calls);
        try {
            $runtime->refresh('charge-unrelated');
            self::fail('A different remote identity must be rejected.');
        } catch (\DomainException) {
            self::assertSame(0, $calls);
            self::assertSame('pending', $pdo->query('SELECT capture_status FROM pagou_card_transactions')->fetchColumn());
        }
    }

    private function attempt(PDO $pdo, int $invoiceId, int $amountCents, CardStatus $status): string
    {
        $attempts = new PdoCardAttemptStore($pdo);
        $attempt = $attempts->begin($invoiceId, 7, $amountCents, 'opaque-reference', 1);
        $attempts->complete(
            $attempt['id'],
            new CardCharge('charge-' . $invoiceId, $status, $amountCents, providerStatus: $status->value),
            'opaque-reference',
            'Visa',
            '4242',
            1,
        );

        return $attempt['id'];
    }

    private function runtime(PDO $pdo, CardTransport $transport, int &$calls): CardReconciliationRuntime
    {
        $api = new CardApiClient($transport);
        $service = new CardPaymentService(
            $api,
            new CardReconciler($api),
            new NullCardJournal(),
            new NullCardScheduler(),
        );
        $localApi = static function (string $command, array $parameters) use ($pdo, &$calls): array {
            if ($command !== 'AddInvoicePayment') {
                return ['result' => 'error'];
            }
            $calls++;
            $pdo->prepare(
                'INSERT INTO tblaccounts (invoiceid, transid, gateway, amountin, amountout) '
                . 'VALUES (:invoiceid, :transid, :gateway, :amountin, 0)',
            )->execute([
                'invoiceid' => (int) $parameters['invoiceid'],
                'transid' => (string) $parameters['transid'],
                'gateway' => (string) $parameters['gateway'],
                'amountin' => (string) $parameters['amount'],
            ]);
            $pdo->prepare("UPDATE tblinvoices SET status = 'Paid' WHERE id = :id")
                ->execute(['id' => (int) $parameters['invoiceid']]);

            return ['result' => 'success'];
        };

        return new CardReconciliationRuntime(
            $pdo,
            $service,
            $localApi,
            new InMemoryOperationOutbox(),
        );
    }

    public function testLateCaptureAfterRefundNeverCreatesANativePayment(): void
    {
        $pdo = $this->pdo();
        $calls = 0;
        $runtime = $this->runtime($pdo, new RecordingCardTransport(), $calls);
        $this->attempt($pdo, 19, 1590, CardStatus::Refunded);
        self::assertSame('updated', $runtime->process(new CardCharge('charge-19', CardStatus::Paid, 1590)));
        self::assertSame(0, $calls);
        self::assertSame('refunded', $pdo->query('SELECT capture_status FROM pagou_card_transactions')->fetchColumn());
    }

    public function testRecoveryDoesNotAttachAResponseForAnotherCharge(): void
    {
        $pdo = $this->pdo();
        $calls = 0;
        $runtime = $this->runtime($pdo, new RecordingCardTransport(['id' => 'other-charge', 'status' => 'captured', 'value' => 1590]), $calls);
        $store = new PdoCardAttemptStore($pdo);
        $attempt = $store->begin(19, 7, 1590, 'reference', 1);
        $store->rememberRemoteId($attempt['id'], 'charge-19');
        $store->uncertain($attempt['id']);
        $runtime->run();
        self::assertSame(0, $calls);
        self::assertSame('charge-19', $store->latestForInvoice(19)['remote_id']);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_card_transactions')->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM pagou_reconciliation_findings WHERE finding_type = 'card_recovery_response_mismatch'")->fetchColumn());
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($pdo))->migrate(
            require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php',
        );
        $pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER NOT NULL, total TEXT NOT NULL, credit TEXT NOT NULL, status TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY AUTOINCREMENT, invoiceid INTEGER NOT NULL, transid TEXT NOT NULL UNIQUE, gateway TEXT NOT NULL, amountin TEXT NOT NULL, amountout TEXT NOT NULL)');
        $pdo->exec("INSERT INTO tblinvoices (id, userid, total, credit, status) VALUES (19, 7, '15.90', '0.00', 'Unpaid')");

        return $pdo;
    }
}

final class RecordingCardTransport implements CardTransport
{
    public int $calls = 0;

    /** @param array<string, mixed> $response */
    public function __construct(private readonly array $response = [])
    {
    }

    public function request(string $method, string $path, array $headers = [], ?array $body = null): array
    {
        $this->calls++;

        return $this->response;
    }
}

final class NullCardJournal implements CardOperationJournal
{
    public function started(string $operation, string $idempotencyKey, array $context): void
    {
    }

    public function succeeded(string $operation, string $idempotencyKey, array $result): void
    {
    }

    public function uncertain(string $operation, string $idempotencyKey, array $context): void
    {
    }

    public function failed(string $operation, string $idempotencyKey, array $context): void
    {
    }
}

final class NullCardScheduler implements CardReconciliationScheduler
{
    public function schedule(string $operation, string $idempotencyKey, array $context): void
    {
    }
}
