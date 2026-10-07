<?php

declare(strict_types=1);

use Pagou\Whmcs\Payment\Card\CardStatus;
use Pagou\Whmcs\Payment\Card\Dto\CardCharge;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardAttemptStore;
use Pagou\Whmcs\Payment\Card\RemoteInput\PdoRemoteInputSessionStore;
use WHMCS\Database\Capsule;
use WHMCS\Payment\PayMethod\Adapter\RemoteCreditCard;
use WHMCS\Payment\PayMethod\Model;

require __DIR__ . '/bootstrap.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}

function rejected(callable $operation): void
{
    try {
        $operation();
    } catch (RuntimeException $error) {
        // Database errors are not expected business rejections.
        if ($error instanceof PDOException) {
            throw $error;
        }
        return;
    }
    throw new RuntimeException('Expected rejection did not occur');
}

function sessions(): PdoRemoteInputSessionStore
{
    return new PdoRemoteInputSessionStore(
        Capsule::connection()->getPdo(),
        static fn (callable $write) => Capsule::connection()->transaction($write),
    );
}

function createNativeCard(string $reference): Model
{
    $payment = new RemoteCreditCard();
    $payment->saveOrFail();
    $method = $payment->newPayMethod(WHMCS\User\Client::findOrFail(7));
    $method->gateway_name = 'pagou_creditcard';
    $method->saveOrFail();
    $payment->setCardNumber('4242');
    $payment->setCardType('Visa');
    $payment->setExpiryDate(WHMCS\Carbon::createFromFormat('my', '1230'));
    $payment->setRemoteToken($reference);
    $payment->saveOrFail();
    return $method;
}

$pdo = Capsule::connection()->getPdo();
$pdo->exec("CREATE TABLE tblclients (id INT PRIMARY KEY, firstname VARCHAR(64), lastname VARCHAR(64), email VARCHAR(255), country CHAR(2), currency INT, defaultgateway VARCHAR(64), uuid CHAR(36), created_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO tblclients VALUES (7, 'Teste', 'Pagou', 'test@example.invalid', 'BR', 1, 'pagou_creditcard', '11111111-1111-4111-8111-111111111111', NULL, NULL)");
$pdo->exec('CREATE TABLE tblinvoices (id BIGINT PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('INSERT INTO tblinvoices VALUES (19), (20), (21)');
foreach (['002_create_payment_attempts', '011_create_card_transactions', '013_create_remote_card_storage'] as $name) {
    $migration = require dirname(__DIR__, 2) . '/package/modules/addons/pagou_payments/migrations/' . $name . '.php';
    foreach ($migration->statements('mysql') as $sql) {
        $pdo->exec($sql);
    }
}
(new Model())->createTable();
(new RemoteCreditCard())->createTable();
check(!(bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'Native MySQL prepared statements enabled');
$engines = $pdo->query("SELECT DISTINCT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
check($engines === ['InnoDB'], 'All test tables use transactional InnoDB');

$store = sessions();
$issued = $store->issue('create', 7, null, null, 0, 'BRL', null);
$store->claim($issued['id'], $issued['secret']);
$method = null;
$store->persistResult($issued['id'], ['saved' => true], static function () use (&$method): void {
    // Exercise nested native Capsule transactions on the module connection.
    Capsule::connection()->transaction(static function () use (&$method): void {
        $method = createNativeCard('synthetic-original');
    });
});
check($method->fresh()->payment->getRemoteToken() === 'synthetic-original', 'Native pay method committed');
check($store->claim($issued['id'], $issued['secret'])['status'] === 'completed', 'Completed session can be read on replay');
rejected(static fn () => $store->persistResult($issued['id'], [], static fn () => createNativeCard('duplicate')));
check(Model::count() === 1, 'Replay cannot save a second native card');

$failed = $store->issue('create', 7, null, null, 0, 'BRL', null);
$store->claim($failed['id'], $failed['secret']);
rejected(static fn () => $store->persistResult($failed['id'], [], static function (): void {
    Capsule::connection()->transaction(static fn () => createNativeCard('rolled-back'));
    throw new RuntimeException('Injected failure after native save');
}));
check(Model::count() === 1 && RemoteCreditCard::count() === 1, 'Failure rolls back both native tables');
check($store->inspect($failed['id'], $failed['secret'])['status'] === 'processing', 'Rollback never records a successful session');
$store->fail($failed['id']);
rejected(static fn () => $store->claim($failed['id'], $failed['secret']));
check(!$pdo->inTransaction() && Capsule::connection()->transactionLevel() === 0, 'Connection recovered after nested rollback');

$failedResult = $store->issue('create', 7, null, null, 0, 'BRL', null);
$store->claim($failedResult['id'], $failedResult['secret']);
$pdo->exec("CREATE TRIGGER fail_session_completion BEFORE UPDATE ON pagou_card_remote_input_sessions "
    . "FOR EACH ROW BEGIN IF NEW.status = 'completed' THEN SIGNAL SQLSTATE '45000' "
    . "SET MESSAGE_TEXT = 'injected completion failure'; END IF; END");
$wroteNative = false;
try {
    $store->persistResult($failedResult['id'], ['saved' => true], static function () use (&$wroteNative): void {
        createNativeCard('rolled-back-on-completion');
        $wroteNative = true;
    });
    throw new RuntimeException('Expected database failure');
} catch (PDOException $error) {
    check($error->getCode() === '45000' && $wroteNative, 'Database rejected completion after native write');
} finally {
    $pdo->exec('DROP TRIGGER fail_session_completion');
}
check(Model::count() === 1 && RemoteCreditCard::count() === 1
    && $store->inspect($failedResult['id'], $failedResult['secret'])['status'] === 'processing', 'Session completion failure rolls back the native card');
$store->fail($failedResult['id']);

$update = $store->issue('update', 7, null, (int) $method->id, 0, 'BRL', 'synthetic-original');
$store->claim($update['id'], $update['secret']);
$replace = static function () use ($method): void {
    $payment = $method->fresh()->payment;
    $payment->setRemoteToken('synthetic-replacement');
    $payment->setCardNumber('5556');
    $payment->setCardType('MasterCard');
    $payment->setExpiryDate(WHMCS\Carbon::createFromFormat('my', '1131'));
    $payment->saveOrFail();
};
rejected(static fn () => $store->persistResult($update['id'], [], static function () use ($replace): void {
    $replace();
    throw new RuntimeException('Injected replacement failure');
}));
$original = $method->fresh()->payment;
check($original->getRemoteToken() === 'synthetic-original' && $original->getLastFour() === '4242', 'Replacement rollback preserves original reference and card');
$store->persistResult($update['id'], ['saved' => true], $replace);
$replaced = $method->fresh()->payment;
check($replaced->getRemoteToken() === 'synthetic-replacement' && $replaced->getLastFour() === '5556'
    && $replaced->getCardType() === 'MasterCard' && $replaced->getExpiryDate()->format('my') === '1131', 'Replacement commits native metadata together');
check(Model::count() === 1 && RemoteCreditCard::count() === 1, 'Replacement preserves a single native pay method');

$attempts = new PdoCardAttemptStore($pdo);
$attempt = $attempts->begin(19, 7, 1590, 'synthetic-card', 1);
$attempts->uncertain($attempt['id']);
$retry = $attempts->begin(19, 7, 2500, 'different-card', 1);
check(!$retry['created'] && $retry['id'] === $attempt['id'], 'Uncertain charge blocks another card or amount');
$attempts->complete($attempt['id'], new CardCharge('charge-19', CardStatus::Paid, 1590), 'synthetic-card', 'Visa', '4242', 1);
check(!$attempts->complete($attempt['id'], new CardCharge('charge-19', CardStatus::Pending, 1590), 'synthetic-card', 'Visa', '4242', 1), 'Stale pending response cannot downgrade paid');
$attempts->complete($attempt['id'], new CardCharge('charge-19', CardStatus::Refunded, 1590), 'synthetic-card', 'Visa', '4242', 1);
check(!$attempts->complete($attempt['id'], new CardCharge('charge-19', CardStatus::Paid, 1590), 'synthetic-card', 'Visa', '4242', 1), 'Stale paid response cannot undo refund');
check((int) $pdo->query('SELECT COUNT(*) FROM pagou_card_transactions')->fetchColumn() === 1, 'Repeated updates preserve one transaction');

require __DIR__ . '/concurrency.php';
echo 'Native storage suite completed on ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ". No API requests.\n";
