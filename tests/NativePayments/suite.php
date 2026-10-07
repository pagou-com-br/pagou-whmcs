<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Pagou\Whmcs\Application\Async\{OperationScheduler, SystemAsyncClock};
use Pagou\Whmcs\Application\Runtime\{OperationalReadModel, PaymentAttemptStore, PeriodicReconciliation};
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\{LeaseRepository, MigrationRunner};
use Pagou\Whmcs\Payment\Pix\Infrastructure\PdoOperationJournal;

function connection(): PDO
{
    return new PDO('mysql:unix_socket=' . getenv('PAGOU_NATIVE_RUN_DIR') . '/mysql.sock;dbname=pagou_payments_test', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}
function check(bool $passed, string $message): void
{
    if (!$passed) {
        throw new RuntimeException($message);
    }
    echo 'OK: ' . $message . "\n";
}
$pdo = connection();
(new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 2) . '/package/modules/addons/pagou_payments/migrations.php');
$pdo->exec('CREATE TABLE tblinvoices (id INT PRIMARY KEY, userid INT, duedate DATE, status VARCHAR(32))');
$pdo->exec("INSERT INTO tblinvoices VALUES (10, 20, '2026-10-06', 'Unpaid')");
$attempt = (new PaymentAttemptStore($pdo))->ensureCurrent(10, 20, 'pix', 100, '2026-10-06');
(new PaymentAttemptStore($pdo))->complete($attempt['id'], 'synthetic', 'ready', ['state' => 'ready']);
$portal = new OperationalReadModel($pdo);
check(count($portal->clientPayments(20)) === 1, 'Portal works with native MySQL placeholders');
check($portal->clientPayments(21) === [], 'Portal isolates another customer');
$pdo->exec('UPDATE tblinvoices SET userid = 21');
check($portal->clientPayments(20) === [] && $portal->clientPayments(21) === [], 'Mismatched projection never leaks invoices');
$lease = new LeaseRepository($pdo);
check($lease->acquire('native', 'one', 60), 'First lease acquired');
check(!(new LeaseRepository(connection()))->acquire('native', 'two', 60), 'Concurrent owner cannot take active lease');
$pdo->exec("UPDATE pagou_leases SET lease_until = '2000-01-01'");
check((new LeaseRepository(connection()))->acquire('native', 'two', 60), 'Expired MySQL lease is acquired correctly');
$journal = new PdoOperationJournal($pdo);
$key = hash('sha256', 'native-claim');
check($journal->started('pix.refund', $key, ['attempt_id' => $attempt['id']])['status'] === 'claimed', 'Only first mutation is claimed');
check((new PdoOperationJournal(connection()))->started('pix.refund', $key, ['attempt_id' => $attempt['id']])['status'] === 'started', 'Second connection sees existing mutation');
$journal->succeeded('pix.refund', $key, []);
check((new PdoOperationJournal(connection()))->started('pix.refund', $key, ['attempt_id' => $attempt['id']])['status'] === 'succeeded', 'Replay preserves success');
$pdo->exec("UPDATE pagou_payment_operations SET updated_at = '2000-01-01'");
(new Pagou\Whmcs\Application\Runtime\RetentionService($pdo))->run();
check($journal->started('pix.refund', $key, ['attempt_id' => $attempt['id']])['status'] === 'succeeded', 'MySQL retention preserves terminal replay');
$scheduler = new PeriodicReconciliation($pdo, new OperationScheduler(new PdoOperationOutbox($pdo), new SystemAsyncClock()));
check($scheduler->schedule() === 1, 'Native MySQL periodic scheduler creates one check');
check($scheduler->schedule() === 0, 'Native MySQL periodic scheduler deduplicates overlapping executions');
check($scheduler->coverage()['stale'] === 1, 'Coverage reports outstanding check');
$outbox = new PdoOperationOutbox($pdo);
$claimed = $outbox->claim('coverage', new DateTimeImmutable('now'), new DateInterval('PT60S'));
check($claimed !== null && $outbox->complete($claimed->job->id, $claimed->token), 'Native outbox completes the periodic check');
check($scheduler->coverage()['checked'] === 1, 'Coverage recognizes actual native outbox completion');
$pdo->exec("UPDATE pagou_payment_operations SET finished_at = NULL WHERE operation_type = 'reconcile_payment'");
check($scheduler->coverage()['checked'] === 1, 'Coverage recognizes legacy success without finished_at');
$identities = new Pagou\Whmcs\Application\Runtime\PaymentIdentityStore($pdo);
$identities->snapshot($attempt['id'], ['name' => 'Emissão nativa', 'document' => '52998224725']);
check($identities->snapshot($attempt['id'], ['name' => 'Cadastro alterado', 'document' => '11144477735'])['name'] === 'Emissão nativa', 'Native identity snapshot survives retries and profile changes');
$body = json_encode(['name' => 'qrcode.completed', 'data' => ['id' => 'synthetic', 'transaction_id' => 'native-receipt', 'amount' => 1, 'e2e_id' => 'E-native', 'payer' => ['name' => 'Pagador nativo', 'document' => '11144477735']]], JSON_THROW_ON_ERROR);
$endpoint = new Pagou\Whmcs\Application\Webhook\WebhookEndpointService(
    new Pagou\Whmcs\Application\Webhook\HmacWebhookVerifier('synthetic-native'),
    new Pagou\Whmcs\Application\Webhook\WebhookEventParser(),
    new Pagou\Whmcs\Infrastructure\Persistence\Webhook\PdoWebhookInbox($pdo),
    new Pagou\Whmcs\Infrastructure\Persistence\Webhook\OutboxWebhookEventHandler($pdo, $outbox),
);
foreach ([0, 1] as $offset) {
    $time = (string) (time() + $offset);
    $endpoint->receive(['X-Pagou-Timestamp' => $time, 'X-Pagou-Signature' => hash_hmac('sha256', $time . $body, 'synthetic-native')], $body);
}
$row = ['invoice' => 10, 'client' => 20, 'method' => 'pix', 'amount' => 100, 'reference' => 'native-receipt', 'pagouId' => 'synthetic'];
check($identities->enrich([$row])[0]['payerDocument'] === '11144477735', 'Native prepared lookup links the verified Pix payer');
check((int) $pdo->query('SELECT COUNT(*) FROM pagou_pix_payers')->fetchColumn() === 1, 'Native Pix projection deduplicates signed retries');
$pdo->exec("UPDATE pagou_webhook_deliveries SET received_at_utc = '2000-01-01', processing_status = 'succeeded'");
(new Pagou\Whmcs\Application\Runtime\RetentionService($pdo))->run();
check($identities->enrich([$row])[0]['payerName'] === 'Pagador nativo', 'Native retention preserves the minimal payer history');
(new MigrationRunner($pdo))->migrate(require dirname(__DIR__, 2) . '/package/modules/addons/pagou_payments/migrations.php');
check($identities->enrich([$row])[0]['thirdParty'] === true, 'Repeated native migrations preserve payer identity');
$attemptRow = (new PaymentAttemptStore($pdo))->find($attempt['id']);
$refunds = new Pagou\Whmcs\Payment\Pix\Infrastructure\PdoPixRefundStore($pdo);
$otherRefunds = new Pagou\Whmcs\Payment\Pix\Infrastructure\PdoPixRefundStore(connection());
check($refunds->reserve($attemptRow, 'native-receipt', 40, 100, 1)['created'], 'First MySQL refund intent reserves the Pix');
check(!$otherRefunds->reserve($attemptRow, 'native-receipt', 40, 100, 1)['created'], 'Another connection cannot reserve another refund');
try {
    $otherRefunds->reserve($attemptRow, 'native-receipt', 60, 100, 1);
    throw new RuntimeException('A second partial refund was incorrectly allowed');
} catch (DomainException) {
    check(true, 'Native reservation rejects a different partial refund on the same Pix');
}
$pdo->exec("UPDATE pagou_payment_attempts SET status = 'paid'");
check((int) $pdo->query('SELECT COUNT(*) FROM pagou_payment_attempts a WHERE ' . PeriodicReconciliation::eligibleSql())->fetchColumn() === 1, 'Paid Pix with a pending refund remains eligible for reconciliation');
$refunds->confirm($attempt['id'], 'native-refund');
check($refunds->claimNative($attempt['id']), 'First worker claims the native refund write');
check(!$otherRefunds->claimNative($attempt['id']), 'Another worker cannot repeat the native refund write');
$refunds->applied($refunds->find($attempt['id']));
$otherRefunds->applied($otherRefunds->find($attempt['id']));
check((int) $pdo->query("SELECT COUNT(*) FROM pagou_ledger_entries WHERE entry_type = 'refunded_payment' AND amount_cents = -40")->fetchColumn() === 1, 'MySQL refund ledger records one negative entry');
(new Pagou\Whmcs\Application\Runtime\RetentionService($pdo))->run();
check($refunds->find($attempt['id'])['status'] === 'applied', 'Native retention preserves refund financial history');
