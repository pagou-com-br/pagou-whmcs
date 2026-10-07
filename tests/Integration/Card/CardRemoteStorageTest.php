<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Card;

use PDO;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Payment\Card\CardStatus;
use Pagou\Whmcs\Payment\Card\Dto\CardCharge;
use Pagou\Whmcs\Payment\Card\IdempotencyKey;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardAttemptStore;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardCustomerStore;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardRefundStore;
use Pagou\Whmcs\Payment\Card\RemoteCardReference;
use Pagou\Whmcs\Payment\Card\RemoteInput\PdoRemoteInputSessionStore;
use PHPUnit\Framework\TestCase;

final class CardRemoteStorageTest extends TestCase
{
    public function testUncertainAttemptBlocksChangedAmountAndCard(): void
    {
        $pdo = $this->pdo();
        $store = new PdoCardAttemptStore($pdo);
        $first = $store->begin(19, 7, 1590, 'original', 1);
        $store->uncertain($first['id']);
        $second = $store->begin(19, 7, 2500, 'replacement', 1);
        self::assertFalse($second['created']);
        self::assertSame($first['id'], $second['id']);
    }

    public function testRemoteReferenceIsVersionedAndRejectsInvalidValues(): void
    {
        $reference = new RemoteCardReference(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
        );

        self::assertSame($reference->customerId, RemoteCardReference::decode($reference->encode())->customerId);
        self::assertSame($reference->cardId, RemoteCardReference::decode($reference->encode())->cardId);
        self::assertStringNotContainsString('|', $reference->encode());
        self::assertSame(
            $reference->cardId,
            RemoteCardReference::decode($reference->customerId . '|' . $reference->cardId)->cardId,
        );

        $this->expectException(\InvalidArgumentException::class);
        RemoteCardReference::decode('4111111111111111');
    }

    public function testRemoteInputSessionIsSingleUseAndStoresOnlyASecretHash(): void
    {
        $pdo = $this->pdo();
        $store = new PdoRemoteInputSessionStore($pdo);
        $issued = $store->issue('payment', 7, 19, null, 1590, 'brl', null, 3);
        $row = $pdo->query('SELECT secret_hash, status, currency, installments FROM pagou_card_remote_input_sessions')
            ->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertNotSame($issued['secret'], $row['secret_hash']);
        self::assertSame(hash('sha256', $issued['secret']), $row['secret_hash']);
        self::assertSame('BRL', $row['currency']);
        self::assertSame(3, (int) $row['installments']);
        self::assertSame('processing', $store->claim($issued['id'], $issued['secret'])['status']);

        try {
            $store->claim($issued['id'], $issued['secret']);
            self::fail('A sessão em processamento não pode ser consumida novamente.');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }

        $store->complete($issued['id'], ['paid' => true]);
        $completed = $store->claim($issued['id'], $issued['secret']);
        self::assertSame('completed', $completed['status']);
        self::assertSame(['paid' => true], json_decode((string) $completed['result_json'], true, 16, JSON_THROW_ON_ERROR));
    }

    public function testExpiredRemoteInputSessionFailsClosed(): void
    {
        $pdo = $this->pdo();
        $store = new PdoRemoteInputSessionStore($pdo);
        $issued = $store->issue('create', 7, null, null, 0, 'BRL', null);
        $pdo->exec("UPDATE pagou_card_remote_input_sessions SET expires_at_utc = '2000-01-01 00:00:00.000000'");

        $this->expectException(\RuntimeException::class);
        $store->inspect($issued['id'], $issued['secret']);
    }

    public function testCustomerMappingChangesWhenTheDocumentChanges(): void
    {
        $pdo = $this->pdo();
        $store = new PdoCardCustomerStore($pdo);
        $store->save(7, '11111111-1111-4111-8111-111111111111', '12345678901');

        self::assertSame('11111111-1111-4111-8111-111111111111', $store->find(7, '12345678901'));
        self::assertNull($store->find(7, '10987654321'));

        $store->save(7, '22222222-2222-4222-8222-222222222222', '10987654321');
        self::assertSame('22222222-2222-4222-8222-222222222222', $store->find(7, '10987654321'));
        self::assertNull($store->find(7, '12345678901'));
    }

    public function testCardAttemptIsIdempotentAndAConclusiveFailureAllowsANewRevision(): void
    {
        $pdo = $this->pdo();
        $store = new PdoCardAttemptStore($pdo);
        $first = $store->begin(19, 7, 1590, 'opaque-reference', 1);
        $replay = $store->begin(19, 7, 1590, 'opaque-reference', 1);

        self::assertTrue($first['created']);
        self::assertFalse($replay['created']);
        self::assertSame($first['id'], $replay['id']);
        self::assertSame($first['key']->value(), $replay['key']->value());
        $request = (string) $pdo->query('SELECT request_json FROM pagou_payment_attempts')->fetchColumn();
        self::assertStringNotContainsString('card_token', $request);
        self::assertStringNotContainsString('cvv', $request);
        self::assertStringNotContainsString('411111', $request);

        $store->failed($first['id']);
        $second = $store->begin(19, 7, 1590, 'opaque-reference', 1);
        self::assertTrue($second['created']);
        self::assertNotSame($first['id'], $second['id']);
        self::assertNotSame($first['key']->value(), $second['key']->value());
    }

    public function testPaidAttemptAndFullRefundArePersistedIdempotently(): void
    {
        $pdo = $this->pdo();
        $attempts = new PdoCardAttemptStore($pdo);
        $attempt = $attempts->begin(19, 7, 1590, 'opaque-reference', 1);
        $charge = new CardCharge('charge-19', CardStatus::Paid, 1590, providerStatus: 'captured');
        $attempts->complete($attempt['id'], $charge, 'opaque-reference', 'Visa', '4242', 1);

        $replay = $attempts->begin(19, 7, 1590, 'opaque-reference', 1);
        self::assertFalse($replay['created']);
        self::assertSame('paid', $replay['status']);
        self::assertSame('charge-19', $replay['remote_id']);

        $refunds = new PdoCardRefundStore($pdo);
        $key = IdempotencyKey::create('refund', 'charge-19:1590');
        $refund = $refunds->begin('charge-19', 1590, $key);
        self::assertTrue($refund['created']);
        self::assertFalse($refunds->begin('charge-19', 1590, $key)['created']);
        $refunds->complete($refund['id'], new CardCharge('charge-19', CardStatus::Reversed, 1590));

        self::assertSame('reversed', $pdo->query('SELECT status FROM pagou_card_refunds')->fetchColumn());
    }

    public function testNativeRefundResponseOnlySucceedsAfterConfirmationAndHasItsOwnIdentity(): void
    {
        if (!defined('WHMCS')) {
            define('WHMCS', true);
        }
        require_once dirname(__DIR__, 3) . '/package/modules/gateways/pagou_creditcard.php';
        foreach (['dispatching', 'uncertain', 'paid', 'pending', 'failed'] as $state) {
            $result = \pagou_creditcard_refund_result($state, 'refund-1');
            self::assertSame('error', $result['status']);
            self::assertArrayNotHasKey('transid', $result);
        }
        $success = \pagou_creditcard_refund_result('reversed', 'refund-1');
        self::assertSame('success', $success['status']);
        self::assertSame('pagou-card-refund:refund-1', $success['transid']);
        self::assertSame($success['transid'], \pagou_creditcard_refund_result('refunded', 'refund-1')['transid']);
    }

    public function testLateResponsesCannotReopenPaidOrRefundedAttempts(): void
    {
        $pdo = $this->pdo();
        $store = new PdoCardAttemptStore($pdo);
        $attempt = $store->begin(19, 7, 1590, 'reference', 1);
        $store->complete($attempt['id'], new CardCharge('charge-19', CardStatus::Paid, 1590), 'reference', null, null, 1);
        foreach ([CardStatus::Pending, CardStatus::Authorized, CardStatus::Failed, CardStatus::Cancelled, CardStatus::Unknown] as $state) {
            self::assertFalse($store->complete($attempt['id'], new CardCharge('charge-19', $state, 1590), 'reference', null, null, 1));
        }
        $store->uncertain($attempt['id']);
        $store->failed($attempt['id']);
        self::assertSame('paid', $store->latestForInvoice(19)['status']);
        self::assertFalse($store->begin(19, 7, 1590, 'other', 1)['created']);
        $store->synchronize(new CardCharge('charge-19', CardStatus::Refunded, 1590));
        self::assertFalse($store->complete($attempt['id'], new CardCharge('charge-19', CardStatus::Paid, 1590), 'reference', null, null, 1));
        $store->uncertain($attempt['id']);
        self::assertSame('refunded', $store->latestForInvoice(19)['status']);
        self::assertSame('refunded', $pdo->query('SELECT capture_status FROM pagou_card_transactions')->fetchColumn());
    }

    public function testAttemptRejectsDifferentIdentityAndAmountWithoutWriting(): void
    {
        $pdo = $this->pdo();
        $store = new PdoCardAttemptStore($pdo);
        $attempt = $store->begin(19, 7, 1590, 'reference', 1);
        $store->rememberRemoteId($attempt['id'], 'charge-19');
        foreach ([new CardCharge('wrong-charge', CardStatus::Paid, 1590), new CardCharge('charge-19', CardStatus::Paid, 1)] as $response) {
            try {
                $store->complete($attempt['id'], $response, 'reference', null, null, 1);
                self::fail('Divergent response accepted.');
            } catch (\DomainException) {
                self::assertSame('dispatching', $store->latestForInvoice(19)['status']);
                self::assertSame('charge-19', $store->latestForInvoice(19)['remote_id']);
                self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pagou_card_transactions')->fetchColumn());
            }
        }
    }

    public function testNativePayMethodAndSessionResultCommitTogetherOnlyOnce(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE native_pay_methods (id INTEGER PRIMARY KEY, reference TEXT)');
        $store = new PdoRemoteInputSessionStore($pdo);
        $session = $store->issue('create', 7, null, null, 0, 'BRL', null);
        $store->claim($session['id'], $session['secret']);
        try {
            $store->persistResult($session['id'], ['workflow' => 'create'], static function () use ($pdo): void {
                $pdo->exec("INSERT INTO native_pay_methods VALUES (1, 'new-card')");
                throw new \RuntimeException('Native persistence failed');
            });
            self::fail('Persistence failure was hidden.');
        } catch (\RuntimeException) {
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM native_pay_methods')->fetchColumn());
            self::assertSame('processing', $store->inspect($session['id'], $session['secret'])['status']);
        }
        $store->persistResult($session['id'], ['workflow' => 'create', 'card_id' => 'new-card'], static function () use ($pdo): void {
            $pdo->exec("INSERT INTO native_pay_methods VALUES (1, 'new-card')");
        });
        $store->complete($session['id'], ['paid' => false, 'pending' => true]);
        $store->fail($session['id']);
        self::assertSame(['workflow' => 'create', 'card_id' => 'new-card'], json_decode($store->inspect($session['id'], $session['secret'])['result_json'], true));
        try {
            $store->persistResult($session['id'], [], static function () use ($pdo): void {
                $pdo->exec("INSERT INTO native_pay_methods VALUES (2, 'duplicate')");
            });
            self::fail('Completed session ran the native write again.');
        } catch (\RuntimeException) {
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM native_pay_methods')->fetchColumn());
        }
    }

    public function testReplacementFailurePreservesThePreviousNativeCard(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("CREATE TABLE native_pay_methods (id INTEGER PRIMARY KEY, reference TEXT)");
        $pdo->exec("INSERT INTO native_pay_methods VALUES (1, 'old-card')");
        $store = new PdoRemoteInputSessionStore($pdo);
        $session = $store->issue('update', 7, null, 1, 0, 'BRL', 'old-card');
        $store->claim($session['id'], $session['secret']);
        try {
            $store->persistResult($session['id'], ['workflow' => 'update'], static function () use ($pdo): void {
                $pdo->exec("UPDATE native_pay_methods SET reference = 'new-card' WHERE id = 1");
                throw new \RuntimeException('Masked card save failed');
            });
        } catch (\RuntimeException) {
            self::assertSame('old-card', $pdo->query('SELECT reference FROM native_pay_methods')->fetchColumn());
            self::assertSame('processing', $store->inspect($session['id'], $session['secret'])['status']);
        }
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($pdo))->migrate(
            require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php',
        );

        return $pdo;
    }
}
