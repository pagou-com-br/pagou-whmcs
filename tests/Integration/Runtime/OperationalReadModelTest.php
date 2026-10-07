<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\OperationalReadModel;
use Pagou\Whmcs\Application\Runtime\PaymentAttemptStore;
use Pagou\Whmcs\Configuration\CentralSettingsStore;
use Pagou\Whmcs\Configuration\EncryptedCredentialStore;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardAttemptStore;
use PHPUnit\Framework\TestCase;

final class OperationalReadModelTest extends TestCase
{
    public function testBuildsGuidedReadinessFromTheWhmcsInstallation(): void
    {
        $pdo = $this->pdo();
        (new CentralSettingsStore($pdo))->save(['cpf_field_id' => '2']);
        (new EncryptedCredentialStore($pdo))->save('fake-secret-that-must-not-leak');
        $now = gmdate('Y-m-d H:i:s.u');
        $this->setting($pdo, 'diagnostics_api_state', 'ready', $now);
        $this->setting($pdo, 'diagnostics_api_checked_utc', $now, $now);
        $this->setting($pdo, 'worker_last_run_utc', $now, $now);
        $pdo->exec(
            "INSERT INTO tblpaymentgateways (gateway, setting, value) VALUES "
            . "('pagou_pix', 'name', 'Pagou - Pix'), ('pagou_pix', 'visible', '')"
        );

        $model = new OperationalReadModel($pdo);
        $dashboard = $model->admin('dashboard');
        $settings = $model->admin('settings');
        $diagnostics = $model->admin('diagnostics');
        $about = $model->admin('about');

        self::assertSame(6, $dashboard['onboarding']['ready']);
        self::assertSame(6, $dashboard['onboarding']['total']);
        self::assertSame('ready', $dashboard['onboarding']['state']);
        self::assertSame(['addon', 'credential', 'documents', 'worker', 'callback', 'gateways'], array_column($dashboard['onboarding']['steps'], 'id'));
        self::assertNull($dashboard['onboarding']['next']);
        self::assertSame('Ativo', $dashboard['readinessCards'][2]['value']);
        self::assertSame('Documento CPF/CNPJ', $settings['customFields'][0]['label']);
        self::assertTrue($settings['gatewayStatus'][0]['active']);
        self::assertFalse($settings['gatewayStatus'][0]['visible']);
        self::assertSame('https://whmcs.example/modules/gateways/callback/pagou.php', $settings['callback']['url']);
        self::assertSame(trim((string) file_get_contents(dirname(__DIR__, 3) . '/package/VERSION')), $about['version']);
        self::assertSame('8.13.1', $about['currentWhmcs']);
        self::assertStringContainsString('relatório seguro de diagnóstico', $diagnostics['supportReport']);
        self::assertNotContains('split_projection', array_column($diagnostics['checks'], 'id'));
        self::assertNotContains('card_release', array_column($diagnostics['checks'], 'id'));
        self::assertNotContains('card', array_column($diagnostics['checks'], 'id'));
        self::assertStringNotContainsString('Split em Pix e boleto', $diagnostics['supportReport']);
        self::assertStringNotContainsString('Core', $diagnostics['supportReport']);
        self::assertContains('Widget do painel WHMCS', $about['components']);
        self::assertStringNotContainsString('fake-secret-that-must-not-leak', $diagnostics['supportReport']);
        self::assertStringContainsString('Cartão de crédito, detalhes para suporte:', $diagnostics['supportReport']);
        self::assertStringContainsString('Fluxo 3DS no banco emissor: não avaliado por este relatório.', $diagnostics['supportReport']);
        self::assertStringContainsString('Credenciamento da conta Pagou: não consultado neste relatório.', $diagnostics['supportReport']);
    }

    public function testReceiptsDoNotCompleteMissingInstallationConfiguration(): void
    {
        $pdo = $this->pdo();
        $this->receipt($pdo, 'received', 'applied', 1000, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $onboarding = (new OperationalReadModel($pdo))->admin('dashboard')['onboarding'];
        $steps = array_column($onboarding['steps'], null, 'id');

        self::assertSame('attention', $onboarding['state']);
        self::assertSame('credential', $onboarding['next']['id']);
        foreach (['credential', 'documents', 'worker', 'gateways'] as $id) {
            self::assertSame('attention', $steps[$id]['state']);
        }
    }

    public function testDiagnosticsKeepsCardChecksForHiddenActiveGatewayOrExistingCharges(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO tblpaymentgateways (gateway, setting, value) VALUES ('pagou_pix', 'visible', 'on')");
        $model = new OperationalReadModel($pdo);
        $initial = $model->admin('diagnostics');
        self::assertFalse($initial['cardInUse']);
        $pdo->exec("INSERT INTO tblpaymentgateways (gateway, setting, value) VALUES ('pagou_creditcard', 'visible', '')");
        $active = $model->admin('diagnostics');
        self::assertTrue($active['cardInUse']);
        $checks = array_column($active['checks'], null, 'id');
        self::assertSame('attention', $checks['card']['state']);
        self::assertSame($initial['attentionCount'] + 1, $active['attentionCount']);
        self::assertSame(count($active['checks']), $active['readyCount'] + $active['attentionCount'] + $active['unavailableCount']);

        $pdo->exec("DELETE FROM tblpaymentgateways WHERE gateway = 'pagou_creditcard'");
        (new PdoCardAttemptStore($pdo))->begin(40, 20, 1000, 'opaque-reference', 1);
        self::assertTrue($model->admin('diagnostics')['cardInUse']);
        self::assertContains('card', array_column($model->admin('diagnostics')['checks'], 'id'));
    }

    public function testDiagnosticsCompareAppliedMigrationsWithThePackagedList(): void
    {
        $pdo = $this->pdo();
        $expected = count(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $check = static fn (array $diagnostics): array => array_values(array_filter($diagnostics['checks'], static fn (array $item): bool => $item['id'] === 'database'))[0];

        $ready = $check((new OperationalReadModel($pdo))->admin('diagnostics'));
        self::assertSame('ready', $ready['state']);
        self::assertSame($expected . ' de ' . $expected . ' migrations registradas.', $ready['detail']);

        $pdo->exec("DELETE FROM pagou_schema_migrations WHERE version = (SELECT MAX(version) FROM pagou_schema_migrations)");
        $pending = $check((new OperationalReadModel($pdo))->admin('diagnostics'));
        self::assertSame('attention', $pending['state']);
        self::assertSame(($expected - 1) . ' de ' . $expected . ' migrations registradas.', $pending['detail']);
    }

    public function testTranslatesCancellationConfirmationAndWorkerStatesForTheOperator(): void
    {
        $pdo = $this->pdo();
        $now = '2026-08-23 19:15:00.000000';
        $statement = $pdo->prepare(
            'INSERT INTO pagou_payment_operations '
            . '(id, attempt_id, operation_type, status, deduplication_key, priority, payload_json, available_at, created_at, updated_at) '
            . 'VALUES (:id, :attempt_id, :operation_type, :status, :deduplication_key, :priority, :payload_json, :available_at, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => '11111111-1111-4111-8111-111111111111',
            'attempt_id' => '22222222-2222-4222-8222-222222222222',
            'operation_type' => 'replace_boleto',
            'status' => 'retrying',
            'deduplication_key' => str_repeat('a', 64),
            'priority' => 100,
            'payload_json' => json_encode(['replace' => false], JSON_THROW_ON_ERROR),
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $operations = (new OperationalReadModel($pdo))->admin('operations')['operations'];

        self::assertSame('Confirmar cancelamento do boleto', $operations[0][1]);
        self::assertSame('Não informado', $operations[0][2]);
        self::assertSame('Sem fatura', $operations[0][3]);
        self::assertSame('Nova tentativa', $operations[0][4]);
    }

    public function testSupersededOperationsAreNotPresentedAsFailuresAndCanBeFiltered(): void
    {
        $pdo = $this->pdo();
        $store = new PaymentAttemptStore($pdo);
        $attempt = $store->ensureCurrent(10, 20, 'boleto', 1000, '2026-09-10');
        $store->markStatus($attempt['id'], 'superseded');
        $now = gmdate('Y-m-d H:i:s.u');
        $statement = $pdo->prepare(
            'INSERT INTO pagou_payment_operations '
            . '(id, attempt_id, operation_type, status, deduplication_key, priority, payload_json, error_message, available_at, created_at, updated_at) '
            . 'VALUES (:id, :attempt_id, :operation_type, :status, :deduplication_key, :priority, :payload_json, :error_message, :available_at, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => '33333333-3333-4333-8333-333333333333',
            'attempt_id' => $attempt['id'],
            'operation_type' => 'issue_boleto',
            'status' => 'failed',
            'deduplication_key' => str_repeat('b', 64),
            'priority' => 100,
            'payload_json' => json_encode(['attempt_id' => $attempt['id'], 'invoice_id' => 10, 'method' => 'boleto'], JSON_THROW_ON_ERROR),
            'error_message' => 'boleto_attempt_superseded',
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $model = new OperationalReadModel($pdo);
        $operations = $model->admin('operations', [
            'invoice' => '10', 'method' => 'boleto', 'status' => 'superseded', 'period' => 'today',
        ])['operations'];

        self::assertCount(1, $operations);
        self::assertSame('Boleto', $operations[0][2]);
        self::assertSame('#10', $operations[0][3]);
        self::assertSame('Substituída', $operations[0][4]);
        self::assertSame([], $model->admin('operations', ['status' => 'failed'])['operations']);
    }

    public function testDashboardUsesAppliedReceiptAmountAndPaymentDayInsteadOfAttemptUpdates(): void
    {
        $pdo = $this->pdo();
        $store = new PaymentAttemptStore($pdo);
        $attempt = $store->ensureCurrent(101, 20, 'boleto', 1000, '2026-09-17');
        $store->markStatus($attempt['id'], 'paid');
        $model = new OperationalReadModel($pdo);
        self::assertSame(0, $model->admin('dashboard')['paymentsToday']);

        $today = new \DateTimeImmutable('today', new \DateTimeZone('America/Sao_Paulo'));
        $start = $today->setTimezone(new \DateTimeZone('UTC'));
        $this->receipt($pdo, 'current', 'applying', 1234, $start);
        $this->receipt($pdo, 'current', 'applied', 1234, $start);
        $this->receipt($pdo, 'pending', 'applying', 6000, $start);
        $this->receipt($pdo, 'quarantined', 'quarantined', 7000, $start);
        $this->receipt($pdo, 'previous', 'applied', 8000, $start->modify('-1 second'));
        $this->receipt($pdo, 'future', 'applied', 9000, $start->modify('+1 day'));

        $dashboard = $model->admin('dashboard');
        self::assertSame(1, $dashboard['paymentsToday']);
        self::assertSame('R$ 12,34', $dashboard['paymentsTodayValue']);
    }

    public function testPaymentsShowTheWhmcsCustomerAndTabsCountOpenFindings(): void
    {
        $pdo = $this->pdo();
        $store = new PaymentAttemptStore($pdo);
        $store->ensureCurrent(101, 20, 'boleto', 1200, '2026-09-17');
        $store->ensureCurrent(102, 21, 'pix', 500, '2026-09-17');
        $model = new OperationalReadModel($pdo);
        // Without the WHMCS client table the row stays visible with a neutral identity.
        self::assertSame('Cliente #20', $model->admin('payments', ['invoice' => '101', 'group' => 'all'])['payments'][0][1]);

        $pdo->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT)');
        $pdo->exec("INSERT INTO tblclients VALUES (20, 'Ana', 'Silva', 'Loja Exemplo'), (21, 'Bruno', 'Costa', '')");
        $rows = $model->admin('payments', ['group' => 'all'])['payments'];
        self::assertSame(['Loja Exemplo', 'Bruno Costa'], [$rows[1][1], $rows[0][1]]);
        self::assertSame('#101', $rows[1][0]);

        self::assertSame(['findings' => 0], $model->navigationBadges());
        $pdo->exec("INSERT INTO pagou_reconciliation_findings (id, finding_key, severity, finding_type, status, details_json, detected_at_utc, created_at, updated_at) VALUES ('f1', 'k1', 'high', 'amount_mismatch', 'open', '{}', '2026-09-17', '2026-09-17', '2026-09-17'), ('f2', 'k2', 'high', 'amount_mismatch', 'resolved', '{}', '2026-09-17', '2026-09-17', '2026-09-17')");
        self::assertSame(['findings' => 1], $model->navigationBadges());
    }

    public function testPaymentsGroupOneRowPerInvoiceWithTheNewestAttemptAndItsHistory(): void
    {
        $pdo = $this->pdo();
        $store = new PaymentAttemptStore($pdo);
        $first = $store->ensureCurrent(101, 20, 'boleto', 1200, '2026-09-17');
        $pdo->prepare("UPDATE pagou_payment_attempts SET created_at = '2026-09-17 10:00:00' WHERE id = ?")->execute([$first['id']]);
        $second = $store->ensureQueued(101, 20, 'pix', 1200, 2, '2026-09-17');
        $pdo->prepare("UPDATE pagou_payment_attempts SET created_at = '2026-09-17 11:00:00', status = 'ready' WHERE id = ?")->execute([$second['id']]);
        // The older attempt is touched later, as when a cancellation is confirmed afterwards.
        $pdo->prepare("UPDATE pagou_payment_attempts SET status = 'cancelled', updated_at = '2026-09-18 09:00:00' WHERE id = ?")->execute([$first['id']]);
        $store->ensureCurrent(102, 21, 'pix', 500, '2026-09-17');
        $pdo->exec("UPDATE pagou_payment_attempts SET created_at = '2026-09-01 10:00:00', updated_at = '2026-09-01 10:00:00' WHERE invoice_id = 102");
        $model = new OperationalReadModel($pdo);

        // Invoices are ordered by their latest activity.
        $page = $model->admin('payments');
        self::assertTrue($page['grouped']);
        self::assertSame([101, 102], array_column($page['payments'], 'invoice'));
        $group = $page['payments'][0];
        self::assertSame($second['id'], $group['attemptId']);
        self::assertSame('Pix', $group['method']);
        self::assertSame('Aguardando pagamento', $group['status']);
        self::assertCount(1, $group['history']);
        self::assertSame($first['id'], $group['history'][0]['attemptId']);
        self::assertSame('Cancelado', $group['history'][0]['status']);
        // A filter selects invoices with a matching attempt and keeps their whole history.
        $cancelled = $model->admin('payments', ['status' => 'cancelled'])['payments'];
        self::assertSame([101], array_column($cancelled, 'invoice'));
        self::assertCount(1, $cancelled[0]['history']);
        self::assertCount(3, $model->admin('payments', ['group' => 'all'])['payments']);
        self::assertSame(['Agrupamento: selecione uma opção válida.'], $model->admin('payments', ['group' => 'x'])['filterErrors']);
        // The status shortcuts count invoices under the other filters, as the grouped filter selects them.
        self::assertSame(['' => 2, 'cancelled' => 1, 'queued' => 1, 'ready' => 1], $this->sorted($page['statusCounts']));
        self::assertSame(['' => 1, 'cancelled' => 1, 'ready' => 1], $this->sorted($model->admin('payments', ['invoice' => '101', 'status' => 'paid'])['statusCounts']));
        self::assertSame(['' => 3, 'cancelled' => 1, 'queued' => 1, 'ready' => 1], $this->sorted($model->admin('payments', ['group' => 'all'])['statusCounts']));
        self::assertSame('pix', $group['methodKey']);
        self::assertSame('', $group['remoteId']);
        $store->complete($second['id'], 'pix-remote-101', 'ready', ['state' => 'ready']);
        self::assertSame('pix-remote-101', $model->admin('payments')['payments'][0]['remoteId']);
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }

    public function testNotificationsFilterByProvenInvoiceAndPeriodAndSummariseToday(): void
    {
        $pdo = $this->pdo();
        $store = new PaymentAttemptStore($pdo);
        $attempt = $store->ensureCurrent(301, 20, 'pix', 1000, '2026-09-17');
        $store->complete($attempt['id'], 'pix-301', 'ready', ['state' => 'ready']);
        $inbox = new \Pagou\Whmcs\Infrastructure\Persistence\Webhook\PdoWebhookInbox($pdo);
        foreach (['today-linked' => 'now', 'today-other' => '-1 minute', 'old-linked' => '-40 days'] as $key => $at) {
            $inbox->reserve($key, new \DateTimeImmutable($at));
        }
        $pdo->exec("UPDATE pagou_webhook_deliveries SET event_type = 'qrcode.completed', signature_valid = 1");
        $pdo->exec("UPDATE pagou_webhook_deliveries SET provider_event_id = 'pix-301' WHERE event_key IN ('today-linked', 'old-linked')");
        $pdo->exec("UPDATE pagou_webhook_deliveries SET signature_valid = 0 WHERE event_key = 'today-other'");
        $model = new OperationalReadModel($pdo);

        self::assertCount(3, $model->admin('webhooks')['webhooks']);
        self::assertCount(2, $model->admin('webhooks', ['invoice' => '301'])['webhooks']);
        self::assertCount(1, $model->admin('webhooks', ['invoice' => '301', 'period' => '7d'])['webhooks']);
        self::assertSame([], $model->admin('webhooks', ['invoice' => '999'])['webhooks']);
        $summary = $model->admin('webhooks')['summary'];
        // Midnight in São Paulo may fall between the two recent deliveries only right after 00:00.
        self::assertContains($summary['today'], [1, 2]);
        self::assertSame(1, $summary['valid']);
        self::assertNotSame('', $summary['last']);
    }

    public function testOperationsReportTheQueueBesideTheirActions(): void
    {
        $pdo = $this->pdo();
        $model = new OperationalReadModel($pdo);
        self::assertSame(['pending' => 0, 'oldest' => ''], $model->admin('operations')['queue']);
        $this->operation($pdo, 'waiting', 'reconcile_payment', 'pending');
        $this->operation($pdo, 'done', 'issue_pix', 'succeeded');
        $queue = $model->admin('operations')['queue'];
        self::assertSame(1, $queue['pending']);
        self::assertNotSame('', $queue['oldest']);
    }

    public function testPaginationHasNoPhantomPageOrSkippedRowsForAllHistories(): void
    {
        $pdo = $this->pdo();
        $store = new PaymentAttemptStore($pdo);
        $inbox = new \Pagou\Whmcs\Infrastructure\Persistence\Webhook\PdoWebhookInbox($pdo);
        for ($i = 1; $i <= 50; $i++) {
            $store->ensureCurrent($i, 20, 'pix', 1000 + $i, '2026-09-17');
            $this->operation($pdo, 'operation-' . $i, 'issue_pix', 'succeeded', null, ['invoice_id' => $i]);
            $inbox->reserve('delivery-' . $i, new \DateTimeImmutable('2026-09-17T12:00:00Z'));
        }
        $model = new OperationalReadModel($pdo);
        foreach (['payments', 'operations', 'webhooks'] as $page) {
            self::assertFalse($model->admin($page)['hasNext']);
            self::assertCount(50, $model->admin($page)[$page]);
        }
        $store->ensureCurrent(51, 20, 'pix', 1051, '2026-09-17');
        $this->operation($pdo, 'operation-51', 'issue_pix', 'succeeded', null, ['invoice_id' => 51]);
        $inbox->reserve('delivery-51', new \DateTimeImmutable('2026-09-17T12:00:00Z'));
        foreach (['payments', 'operations', 'webhooks'] as $page) {
            $first = $model->admin($page);
            $second = $model->admin($page, ['page' => '2']);
            self::assertTrue($first['hasNext']);
            self::assertCount(50, $first[$page]);
            self::assertFalse($second['hasNext']);
            self::assertCount(1, $second[$page]);
            if ($page !== 'webhooks') {
                self::assertNotContains($second[$page][0], $first[$page]);
            }
        }
    }

    public function testNotificationOptionsIncludeReceivedTypesAcrossFiltersWithoutChangingDeliveries(): void
    {
        $pdo = $this->pdo();
        $model = new OperationalReadModel($pdo);
        self::assertSame(['' => 'Todos os eventos'], $model->admin('webhooks')['eventTypes']);
        $inbox = new \Pagou\Whmcs\Infrastructure\Persistence\Webhook\PdoWebhookInbox($pdo);
        foreach (['qrcode.completed', 'charge.paid', 'future.event', 'unknown'] as $type) {
            $inbox->reserve($type, new \DateTimeImmutable('2026-09-17T12:00:00Z'));
            $statement = $pdo->prepare('UPDATE pagou_webhook_deliveries SET event_type = :type WHERE event_key = :key');
            $statement->execute(['type' => $type, 'key' => $type]);
        }
        $before = $pdo->query('SELECT * FROM pagou_webhook_deliveries')->fetchAll(PDO::FETCH_ASSOC);
        $filtered = $model->admin('webhooks', ['type' => 'charge.paid']);
        self::assertCount(1, $filtered['webhooks']);
        self::assertSame('Boleto pago', $filtered['webhooks'][0][1]);
        self::assertSame('Pix recebido', $filtered['eventTypes']['qrcode.completed']);
        self::assertSame('Tipo não reconhecido', $filtered['eventTypes']['unknown']);
        self::assertArrayHasKey('future.event', $filtered['eventTypes']);
        self::assertSame($before, $pdo->query('SELECT * FROM pagou_webhook_deliveries')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testNotificationAndReconciliationHistoryReflectJobsWithoutReprocessingDeliveries(): void
    {
        $pdo = $this->pdo();
        $inbox = new \Pagou\Whmcs\Infrastructure\Persistence\Webhook\PdoWebhookInbox($pdo);
        $inbox->reserve('old-event', new \DateTimeImmutable('2026-08-23T12:00:00Z'));
        $inbox->markProcessed('old-event');
        $pdo->exec("UPDATE pagou_webhook_deliveries SET event_type = 'unknown'");
        $before = $pdo->query('SELECT * FROM pagou_webhook_deliveries')->fetchAll(PDO::FETCH_ASSOC);
        $this->operation($pdo, 'reconciliation', 'reconcile_payment', 'succeeded', null, ['invoice_id' => 15, 'method' => 'boleto'], 'webhook:' . hash('sha256', 'old-event'));
        $this->operation($pdo, 'pending-reconciliation', 'reconcile_uncertain_operation', 'retrying');
        $this->operation($pdo, 'issuance', 'issue_boleto', 'succeeded');
        $model = new OperationalReadModel($pdo);
        // Unrecognised deliveries leave the default list but stay counted and reachable.
        $default = $model->admin('webhooks');
        self::assertSame([], $default['webhooks']);
        self::assertSame(1, $default['hiddenUnknown']);
        $webhooks = $model->admin('webhooks', ['type' => 'unknown'])['webhooks'];
        self::assertSame('Tipo não reconhecido', $webhooks[0][1]);
        self::assertSame('Concluída', $webhooks[0][4]);
        $reconciliation = $model->admin('reconciliation');
        self::assertCount(2, $reconciliation['runs']);
        self::assertSame(1, $reconciliation['pending']);
        self::assertSame($before, $pdo->query('SELECT * FROM pagou_webhook_deliveries')->fetchAll(PDO::FETCH_ASSOC));
        $pdo->exec("DELETE FROM pagou_payment_operations WHERE id = 'reconciliation'");
        self::assertSame('Encaminhada; histórico indisponível', $model->admin('webhooks', ['type' => 'unknown'])['webhooks'][0][4]);
    }

    public function testCompletedOperationKeepsItsResultAfterAttemptIsSuperseded(): void
    {
        $pdo = $this->pdo();
        $store = new PaymentAttemptStore($pdo);
        $attempt = $store->ensureCurrent(40, 20, 'boleto', 1000, '2026-09-17');
        $store->markStatus($attempt['id'], 'superseded');
        $this->operation($pdo, 'completed', 'issue_boleto', 'succeeded', $attempt['id']);
        $model = new OperationalReadModel($pdo);
        self::assertSame('Concluída', $model->admin('operations')['operations'][0][4]);
        self::assertSame([], $model->admin('operations', ['status' => 'superseded'])['operations']);
        self::assertCount(1, $model->admin('operations', ['status' => 'succeeded'])['operations']);
    }

    public function testCardDistinguishesInstallationFromDiagnosticsAndUsesConfiguredInstallments(): void
    {
        $pdo = $this->pdo();
        $model = new OperationalReadModel($pdo);
        $card = $model->admin('card');
        self::assertFalse($card['cardReady']);
        self::assertSame('inactive', $card['availability']);
        self::assertSame('À vista (1 parcela)', $card['installments']);
        self::assertArrayNotHasKey('checks', $card);
        self::assertArrayNotHasKey('threeDs', $card);
        $this->setting($pdo, 'diagnostics_card_state', 'ready', gmdate('Y-m-d H:i:s'));
        self::assertTrue($model->admin('card')['cardReady']);
        self::assertSame('inactive', $model->admin('card')['availability']);
        $this->setting($pdo, 'card_max_installments', '6', gmdate('Y-m-d H:i:s'));
        self::assertFalse($model->admin('card')['cardReady']);
        self::assertStringContainsString('revise para pagamento à vista', $model->admin('card')['installments']);
        $pdo->exec("DELETE FROM pagou_schema_migrations WHERE version = '013'");
        self::assertFalse($model->admin('card')['cardReady']);
        self::assertStringContainsString('Componentes do cartão: instalação incompleta', $model->admin('diagnostics')['supportReport']);
    }

    public function testCardAvailabilityReadsNativeVisibilityWithoutChangingItOrClaimingRemoteApproval(): void
    {
        $pdo = $this->pdo();
        $model = new OperationalReadModel($pdo);
        $this->setting($pdo, 'diagnostics_card_state', 'ready', gmdate('Y-m-d H:i:s'));
        $pdo->exec("INSERT INTO tblpaymentgateways (gateway, setting, value) VALUES ('pagou_pix', 'visible', 'on')");
        self::assertSame('inactive', $model->admin('card')['availability']);
        $pdo->exec("INSERT INTO tblpaymentgateways (gateway, setting, value) VALUES ('pagou_creditcard', 'name', 'Pagou - Cartão')");
        self::assertSame('hidden', $model->admin('card')['availability']);
        $pdo->exec("INSERT INTO tblpaymentgateways (gateway, setting, value) VALUES ('pagou_creditcard', 'visible', '')");
        self::assertSame('hidden', $model->admin('card')['availability']);
        $pdo->exec("UPDATE tblpaymentgateways SET value = 'on' WHERE gateway = 'pagou_creditcard' AND setting = 'visible'");
        $before = $pdo->query('SELECT * FROM tblpaymentgateways')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame('visible', $model->admin('card')['availability']);
        $this->setting($pdo, 'card_max_installments', '6', gmdate('Y-m-d H:i:s'));
        $card = $model->admin('card');
        self::assertSame('visible', $card['availability']);
        self::assertFalse($card['cardReady']);
        $support = $model->admin('diagnostics')['supportReport'];
        self::assertStringContainsString('ativo e visível', $support);
        self::assertStringNotContainsString('homologação pendente', mb_strtolower($support));
        self::assertSame($before, $pdo->query('SELECT * FROM tblpaymentgateways')->fetchAll(PDO::FETCH_ASSOC));
        $pdo->exec('DROP TABLE tblpaymentgateways');
        self::assertSame('unknown', $model->admin('card')['availability']);
    }

    public function testSupportReportSurvivesUnavailableCardInstallationMetadata(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('DROP TABLE pagou_schema_migrations');
        $diagnostics = (new OperationalReadModel($pdo))->admin('diagnostics');

        self::assertStringContainsString('Componentes e diagnóstico local do cartão: não foi possível consultar.', $diagnostics['supportReport']);
        self::assertStringNotContainsString('SQLSTATE', $diagnostics['supportReport']);
        self::assertStringContainsString('não contém credenciais', $diagnostics['supportReport']);
    }

    private function receipt(PDO $pdo, string $key, string $status, int $amount, \DateTimeImmutable $paidAt): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO pagou_ledger_entries (id, idempotency_key, invoice_id, client_id, entry_type, amount_cents, currency, payment_at_utc, effective_at_utc, metadata_json, created_at) '
            . 'VALUES (:id, :key, 101, 20, :type, :amount, :currency, :paid_at, :now, :metadata, :created_at)'
        );
        $statement->execute([
            'id' => $key . '-' . $status, 'key' => hash('sha256', $key . $status),
            'type' => 'received_payment_transition', 'amount' => $amount, 'currency' => 'BRL',
            'paid_at' => $paidAt->format('Y-m-d H:i:s.u'), 'now' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'metadata' => json_encode(['economic_key' => $key, 'status' => $status], JSON_THROW_ON_ERROR),
        ]);
    }

    /** @param array<string, scalar> $payload */
    private function operation(PDO $pdo, string $id, string $type, string $status, ?string $attemptId = null, array $payload = [], ?string $deduplicationKey = null): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO pagou_payment_operations (id, attempt_id, operation_type, status, deduplication_key, priority, payload_json, available_at, created_at, updated_at) '
            . 'VALUES (:id, :attempt, :type, :status, :key, 100, :payload, :available_at, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id, 'attempt' => $attemptId, 'type' => $type, 'status' => $status,
            'key' => hash('sha256', $deduplicationKey ?? $id), 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'available_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($pdo))->migrate($migrations);
        $pdo->exec('CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE tblcustomfields (id INTEGER PRIMARY KEY, type TEXT NOT NULL, fieldname TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE tblpaymentgateways (gateway TEXT, setting TEXT, value TEXT)');
        $pdo->exec(
            "INSERT INTO tblconfiguration (setting, value) VALUES "
            . "('SystemURL', 'https://whmcs.example'), ('Version', '8.13.1')"
        );
        $pdo->exec(
            "INSERT INTO tblcustomfields (id, type, fieldname) VALUES (2, 'client', 'Documento CPF/CNPJ')"
        );

        return $pdo;
    }

    private function setting(PDO $pdo, string $key, string $value, string $updatedAt): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO pagou_settings (setting_key, setting_value, is_secret, updated_at) '
            . 'VALUES (:key, :value, 0, :updated_at)'
        );
        $statement->execute(['key' => $key, 'value' => $value, 'updated_at' => $updatedAt]);
    }
}
