<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Pagou\Whmcs\Application\Async\{JobPriority, OperationScheduler, OperationType, SystemAsyncClock};
use Pagou\Whmcs\Application\Runtime\{AddonSettings, OperationalReadModel, PaymentAttemptStore, PeriodicReconciliation, WhmcsRuntime};
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
$pdo->exec("INSERT INTO tblinvoices VALUES (11, 21, '2026-10-28', 'Unpaid')");
$review = (new PaymentAttemptStore($pdo))->ensureCurrent(11, 21, 'boleto', 100, '2026-10-28');
$pdo->prepare("UPDATE pagou_payment_attempts SET remote_id = 'native-boleto', status = 'paid', updated_at = '2026-11-06 09:53:00' WHERE id = ?")->execute([$review['id']]);
$pdo->prepare("UPDATE pagou_payment_attempts SET updated_at = '2026-09-21 00:00:00' WHERE id = ?")->execute([$attempt['id']]);
$pdo->prepare("INSERT INTO pagou_reconciliation_findings (id, finding_key, attempt_id, severity, finding_type, status, details_json, detected_at_utc, created_at, updated_at, version) "
    . "VALUES ('native-finding', 'native-finding', ?, 'high', 'payment_evidence_missing', 'open', '{}', '2026-11-04 12:37:00', '2026-11-04 12:37:00', '2026-11-04 12:37:00', 1)")->execute([$review['id']]);
$button = new WhmcsRuntime($pdo, new AddonSettings([]), new PdoOperationOutbox($pdo), static fn (): array => ['result' => 'success']);
check($button->schedulePendingReconciliation(1) === 1, 'Native reconciliation button schedules one check');
$queued = $pdo->prepare('SELECT COUNT(*) FROM pagou_payment_operations WHERE payload_json LIKE ?');
$queued->execute(['%' . $review['id'] . '%']);
check((int) $queued->fetchColumn() === 1, 'Native reconciliation button reaches the payment in review first');
$pdo->exec("INSERT INTO tblinvoices VALUES (12, 22, '2026-10-14', 'Paid')");
(new OperationScheduler(new PdoOperationOutbox($pdo), new SystemAsyncClock()))->schedule('native-email-note', OperationType::DeliverInvoiceEmail, 'native:email-note', JobPriority::Delivery, ['invoice_id' => 13]);
$noted = $outbox->claim('email-note', new DateTimeImmutable('now'), new DateInterval('PT60S'));
check($noted !== null && $outbox->complete($noted->job->id, $noted->token, 'invoice_emails_disabled'), 'Native outbox completes a job with its note');
$note = $pdo->prepare('SELECT error_message FROM pagou_payment_operations WHERE id = ?');
$note->execute([$noted->job->id]);
check($note->fetchColumn() === 'invoice_emails_disabled', 'Native outbox keeps the note of a completed job');
$pdo->exec("INSERT INTO pagou_payment_operations (id, operation_type, status, deduplication_key, priority, payload_json, available_at, created_at, updated_at, error_message, version) "
    . "VALUES ('native-email-uncertain', 'deliver_invoice_email', 'uncertain', 'native:email-uncertain', 50, '{\"invoice_id\":12,\"deadline\":true}', '2026-10-14 10:00:00', '2026-10-14 10:00:00', '2026-10-14 10:00:00', 'invoice_email_delivery_uncertain', 1)");
$emailReview = new Pagou\Whmcs\Application\Runtime\InvoiceEmailReview($pdo, static fn (int $client): bool => false);
check($emailReview->close(new DateTimeImmutable('now', new DateTimeZone('UTC'))) === 1, 'Native review closes the uncertain email of a paid invoice');
check($pdo->query("SELECT CONCAT(status, ':', error_message) FROM pagou_payment_operations WHERE id = 'native-email-uncertain'")->fetchColumn() === 'succeeded:settled_by_invoice_state', 'Native review records why the email is no longer needed');
$cancelling = (new PaymentAttemptStore($pdo))->ensureCurrent(12, 22, 'boleto', 100, '2026-10-14');
$pdo->prepare("UPDATE pagou_payment_attempts SET remote_id = 'native-cancelling', status = 'cancel_requested' WHERE id = ?")->execute([$cancelling['id']]);
$pdo->prepare("INSERT INTO pagou_payment_operations (id, operation_type, status, deduplication_key, priority, payload_json, available_at, created_at, updated_at, attempt_id, error_message, version) "
    . "VALUES ('native-cancel', 'cancel_boleto', 'uncertain', 'native:cancel', 400, '{}', '2026-10-22 11:07:00', '2026-10-22 11:07:00', '2026-10-22 11:07:00', ?, 'boleto_cancel_unknown', 1)")->execute([$cancelling['id']]);
$housekeeping = new Pagou\Whmcs\Application\Runtime\OperationHousekeeping($pdo, new OperationScheduler(new PdoOperationOutbox($pdo), new SystemAsyncClock()));
check($housekeeping->flagUnconfirmedCancellations(new DateTimeImmutable('now', new DateTimeZone('UTC'))) === 1, 'Native housekeeping lists a boleto whose cancellation is never confirmed');
$pdo->prepare("UPDATE pagou_payment_attempts SET status = 'cancelled' WHERE id = ?")->execute([$cancelling['id']]);
$housekeeping->flagUnconfirmedCancellations(new DateTimeImmutable('now', new DateTimeZone('UTC')));
check($pdo->query("SELECT status FROM pagou_reconciliation_findings WHERE finding_type = 'boleto_cancellation_unconfirmed'")->fetchColumn() === 'resolved', 'Native housekeeping closes the listing once the boleto is cancelled');
$failing = (new PaymentAttemptStore($pdo))->ensureCurrent(12, 22, 'pix', 100, '2026-10-14');
$pdo->prepare("UPDATE pagou_payment_attempts SET remote_id = 'native-failing', status = 'pending' WHERE id = ?")->execute([$failing['id']]);
foreach (['2026-11-01', '2026-11-02', '2026-11-03'] as $day) {
    $pdo->prepare("INSERT INTO pagou_payment_operations (id, operation_type, status, deduplication_key, priority, payload_json, available_at, created_at, updated_at, attempt_id, error_message, version) "
        . "VALUES (?, 'reconcile_payment', 'failed', ?, 10, '{}', ?, ?, ?, ?, 'retry_exhausted', 1)")->execute(['native-failed-' . $day, 'native:failed:' . $day, $day, $day, $day, $failing['id']]);
}
check($housekeeping->flagFailingChecks(new DateTimeImmutable('now', new DateTimeZone('UTC'))) === 1, 'Native housekeeping lists a check that keeps failing');
$pdo->prepare("INSERT INTO pagou_payment_operations (id, operation_type, status, deduplication_key, priority, payload_json, available_at, created_at, updated_at, finished_at, attempt_id, version) "
    . "VALUES ('native-check-ok', 'reconcile_payment', 'succeeded', 'native:check-ok', 10, '{}', '2999-01-01', '2999-01-01', '2999-01-01', '2999-01-01', ?, 1)")->execute([$failing['id']]);
$housekeeping->flagFailingChecks(new DateTimeImmutable('now', new DateTimeZone('UTC')));
check($pdo->query("SELECT status FROM pagou_reconciliation_findings WHERE finding_type = 'reconciliation_keeps_failing'")->fetchColumn() === 'resolved', 'Native housekeeping closes the listing once a check succeeds');
// The stepped periodic selection against its former single statement, on a database of its own
// whose tblinvoices is MyISAM, as in WHMCS installations.
$pdo->exec('CREATE DATABASE pagou_selection_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$selection = new PDO('mysql:unix_socket=' . getenv('PAGOU_NATIVE_RUN_DIR') . '/mysql.sock;dbname=pagou_selection_test', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
(new MigrationRunner($selection))->migrate(require dirname(__DIR__, 2) . '/package/modules/addons/pagou_payments/migrations.php');
$selection->exec('CREATE TABLE tblinvoices (id INT PRIMARY KEY, userid INT, duedate DATE NULL, status VARCHAR(32)) ENGINE=MyISAM');
$selectionNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
Pagou\Whmcs\Tests\Support\Runtime\PeriodicSelectionFixture::seed($selection, $selectionNow, 97, 700);
$periodic = new PeriodicReconciliation($selection, new OperationScheduler(new PdoOperationOutbox($selection), new SystemAsyncClock()));
$differences = [];
foreach ([1, 3, 10, 25] as $selectionLimit) {
    $differences = [...$differences, ...Pagou\Whmcs\Tests\Support\Runtime\PeriodicSelectionFixture::differences($selection, $periodic, $selectionNow, $selectionLimit)];
}
check($differences === [], 'Native stepped periodic selection picks what the former statement picked' . ($differences === [] ? '' : ': ' . implode('; ', $differences)));
$selected = $periodic->dueCharges($selectionNow, 25);
check($selected['near'] !== [] && $selected['old'] !== [], 'Native selection data reaches the recent and the old groups');
$coverage = $periodic->coverage();
check($coverage === Pagou\Whmcs\Tests\Support\Runtime\PeriodicSelectionFixture::legacyCoverage($selection) && $coverage['checked'] > 0 && $coverage['stale'] > 0, 'Native coverage counts what the former statement counted');
$selectionLease = new LeaseRepository($selection);
check($selectionLease->acquire('native-run', 'first', 300) && $selectionLease->holdUntil('native-run', 'first', time() + 60), 'Native lease held for a run is shortened at its end');
check((string) $selection->query("SELECT lease_until FROM pagou_leases WHERE name = 'native-run'")->fetchColumn() <= gmdate('Y-m-d H:i:s', time() + 61), 'Native shortened lease keeps one run a minute');
check(!$selectionLease->holdUntil('native-run', 'another', time() + 600), 'Native lease of another owner is left alone');
// Boletos the bank refused (2026-11-22): the invoices to issue again, read by id from a MyISAM tblinvoices.
$pdo->exec('CREATE DATABASE pagou_reissue_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$reissueDb = new PDO('mysql:unix_socket=' . getenv('PAGOU_NATIVE_RUN_DIR') . '/mysql.sock;dbname=pagou_reissue_test', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
(new MigrationRunner($reissueDb))->migrate(require dirname(__DIR__, 2) . '/package/modules/addons/pagou_payments/migrations.php');
$reissueDb->exec('CREATE TABLE tblinvoices (id INT PRIMARY KEY, status VARCHAR(32), paymentmethod VARCHAR(64)) ENGINE=MyISAM');
$reissueNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$refusedAttempt = $reissueDb->prepare('INSERT INTO pagou_payment_attempts (id, invoice_id, client_id, gateway, method, status, amount_cents, currency, remote_id, idempotency_key, economic_key, created_at, updated_at) '
    . "VALUES (?, ?, 22, 'pagou', 'boleto', ?, 100, 'BRL', ?, ?, ?, ?, ?)");
$refusedRows = [
    // id, invoice, invoice status, attempt status, remote, created, updated
    ['due', 21, 'Unpaid', 'failed', 'remote-due', '-2 hours', '-31 minutes'],
    ['waiting', 22, 'Unpaid', 'failed', 'remote-waiting', '-2 hours', '-29 minutes'],
    ['paid', 23, 'Paid', 'failed', 'remote-paid', '-2 hours', '-1 hour'],
    ['local', 24, 'Unpaid', 'failed', null, '-2 hours', '-1 hour'],
];
foreach ($refusedRows as [$id, $invoice, $invoiceStatus, $status, $remote, $created, $updated]) {
    $reissueDb->prepare("INSERT INTO tblinvoices VALUES (?, ?, 'pagou_boleto')")->execute([$invoice, $invoiceStatus]);
    $refusedAttempt->execute([$id, $invoice, $status, $remote, hash('sha256', 'i' . $id), hash('sha256', 'e' . $id),
        $reissueNow->modify($created)->format('Y-m-d H:i:s.u'), $reissueNow->modify($updated)->format('Y-m-d H:i:s.u')]);
}
check((new Pagou\Whmcs\Application\Runtime\RefusedBoletoReissue($reissueDb))->due($reissueNow) === ['reissue' => [21], 'exhausted' => []], 'Native reissue picks only the open invoice whose refused boleto waited enough');
