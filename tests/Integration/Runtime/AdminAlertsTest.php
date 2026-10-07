<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pagou\Whmcs\Application\Async\AsyncClock;
use Pagou\Whmcs\Application\Runtime\AdminAlerts;
use Pagou\Whmcs\Application\Runtime\OperationalSettings;
use Pagou\Whmcs\Configuration\CentralSettingsStore;
use Pagou\Whmcs\Infrastructure\Persistence\LeaseRepository;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class AdminAlertsTest extends TestCase
{
    private PDO $pdo;

    /** @var list<array{0:string,1:array<string, mixed>}> */
    private array $calls = [];

    /** @var list<string> */
    private array $logs = [];

    /** @var array<string, mixed>|\Throwable */
    private array|\Throwable $response = ['result' => 'success'];

    private AsyncClock $clock;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $migrations = require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php';
        (new MigrationRunner($this->pdo))->migrate($migrations);
        $this->pdo->exec('CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE tblpaymentgateways (gateway TEXT, setting TEXT, value TEXT)');
        $this->pdo->exec("INSERT INTO tblconfiguration (setting, value) VALUES ('SystemURL', 'https://whmcs.example/')");
        $this->now = new DateTimeImmutable('2026-10-06 12:00:00', new DateTimeZone('UTC'));
        $this->clock = new class ($this) implements AsyncClock {
            public function __construct(private readonly AdminAlertsTest $test)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->test->currentTime();
            }
        };
    }

    public function currentTime(): DateTimeImmutable
    {
        return $this->now;
    }

    public function testWorkerIsNotAlertedWithoutAnActivePagouGateway(): void
    {
        $this->pdo->exec("INSERT INTO tblpaymentgateways (gateway, setting, value) VALUES ('paypal', 'name', 'PayPal')");

        $report = $this->alerts()->run();

        self::assertSame(['worker' => 'idle', 'findings' => 'idle', 'refunds' => 'idle'], $report);
        self::assertSame([], $this->calls);
        self::assertNull((new OperationalSettings($this->pdo))->get('admin_alert_worker'));
    }

    public function testStoppedWorkerIsAlertedOnceAndRemindedEveryTwentyFourHours(): void
    {
        $this->activateGateway();
        $this->heartbeat($this->now->modify('-20 minutes'));

        self::assertSame('alerted', $this->alerts()->run()['worker']);
        self::assertCount(1, $this->calls);
        [$command, $parameters] = $this->calls[0];
        self::assertSame('SendAdminEmail', $command);
        self::assertSame(['customsubject', 'custommessage', 'type'], array_keys($parameters));
        self::assertSame('system', $parameters['type']);
        self::assertSame('Pagou para WHMCS: worker sem execução recente', $parameters['customsubject']);
        self::assertStringContainsString('A última execução do worker do Pagou para WHMCS foi em 06/10/2026 08:40 (horário de Brasília).', $parameters['custommessage']);
        self::assertStringContainsString('Addons &gt; Pagou para WHMCS &gt; Diagnóstico', $parameters['custommessage']);
        self::assertStringContainsString(
            'href="https://whmcs.example/painel/addonmodules.php?module=pagou_payments&amp;view=diagnostics"',
            $parameters['custommessage'],
        );
        self::assertContains('Pagou Payments: alerta de worker enviado aos administradores.', $this->logs);

        self::assertSame('waiting', $this->alerts()->run()['worker']);
        $this->now = $this->now->modify('+23 hours 59 minutes');
        self::assertSame('waiting', $this->alerts()->run()['worker']);
        self::assertCount(1, $this->calls);

        $this->now = $this->now->modify('+1 minute');
        self::assertSame('reminded', $this->alerts()->run()['worker']);
        self::assertCount(2, $this->calls);
        self::assertSame('Lembrete Pagou para WHMCS: worker sem execução recente', $this->calls[1][1]['customsubject']);
        self::assertSame('waiting', $this->alerts()->run()['worker']);

        $this->heartbeat($this->now);
        self::assertSame('idle', $this->alerts()->run()['worker']);
        self::assertNull((new OperationalSettings($this->pdo))->get('admin_alert_worker'));

        $this->now = $this->now->modify('+16 minutes');
        self::assertSame('alerted', $this->alerts()->run()['worker']);
        self::assertCount(3, $this->calls);
        self::assertSame('Pagou para WHMCS: worker sem execução recente', $this->calls[2][1]['customsubject']);
    }

    public function testWorkerThatNeverRanIsReportedAfterGatewayActivation(): void
    {
        $this->activateGateway('pagou_boleto');

        self::assertSame('alerted', $this->alerts()->run()['worker']);
        self::assertStringContainsString(
            'O worker do Pagou para WHMCS ainda não registrou nenhuma execução.',
            $this->calls[0][1]['custommessage'],
        );
    }

    public function testRecentWorkerHeartbeatIsNotAlerted(): void
    {
        $this->activateGateway();
        $this->heartbeat($this->now->modify('-14 minutes'));

        self::assertSame('idle', $this->alerts()->run()['worker']);
        self::assertSame([], $this->calls);
    }

    public function testNewFindingsAreAlertedOnceWithTranslatedTypesAndInvoiceNumbers(): void
    {
        $this->attempt('attempt-101', 101);
        $this->attempt('attempt-102', 102);
        $this->attempt('attempt-104', 104);
        $this->attempt('attempt-105', 105);
        $this->finding('finding-1', 'attempt-101', 'amount_mismatch', 'open', '-3 hours');
        $this->finding('finding-2', 'attempt-102', 'payment_evidence_missing', 'pending', '-2 hours');
        $this->finding('finding-3', null, 'payment_application_failed', 'open', '-1 hour', ['economic_key' => 'economic-103']);
        $this->finding('finding-4', 'attempt-104', 'amount_mismatch', 'resolved', '-4 hours');
        $this->ledger('economic-103', 103);

        self::assertSame('alerted', $this->alerts()->run()['findings']);
        $first = $this->calls[0][1];
        self::assertSame('Pagou para WHMCS: 3 novas pendências financeiras', $first['customsubject']);
        self::assertStringContainsString('Foram registradas 3 novas pendências financeiras que precisam de conferência.', $first['custommessage']);
        self::assertStringContainsString('Valor divergente (1)', $first['custommessage']);
        self::assertStringContainsString('Dados do recebimento pendentes (1)', $first['custommessage']);
        self::assertStringContainsString('Baixa requer conferência (1)', $first['custommessage']);
        self::assertStringContainsString('Faturas: #101, #102, #103.', $first['custommessage']);
        self::assertStringContainsString('Total de pendências em aberto: 3.', $first['custommessage']);
        self::assertStringContainsString('Addons &gt; Pagou para WHMCS &gt; Pendências', $first['custommessage']);
        self::assertStringContainsString('view=findings', $first['custommessage']);
        self::assertStringNotContainsString('#104', $first['custommessage']);

        self::assertSame('waiting', $this->alerts()->run()['findings']);
        self::assertCount(1, $this->calls);

        $this->now = $this->now->modify('+2 hours');
        $this->finding('finding-5', 'attempt-105', 'pix_refund_requires_review', 'open', '-5 minutes');
        self::assertSame('alerted', $this->alerts()->run()['findings']);
        $second = $this->calls[1][1];
        self::assertSame('Pagou para WHMCS: 1 nova pendência financeira', $second['customsubject']);
        self::assertStringContainsString('Foi registrada 1 nova pendência financeira que precisa de conferência.', $second['custommessage']);
        self::assertStringContainsString('Reembolso Pix requer conferência (1)', $second['custommessage']);
        self::assertStringContainsString('Faturas: #105.', $second['custommessage']);
        self::assertStringContainsString('Total de pendências em aberto: 4.', $second['custommessage']);
        self::assertStringNotContainsString('#101', $second['custommessage']);

        $this->now = $this->now->modify('+23 hours');
        self::assertSame('waiting', $this->alerts()->run()['findings']);
        $this->now = $this->now->modify('+1 hour');
        self::assertSame('reminded', $this->alerts()->run()['findings']);
        $reminder = $this->calls[2][1];
        self::assertSame('Lembrete Pagou para WHMCS: 4 pendências financeiras em aberto', $reminder['customsubject']);
        self::assertStringContainsString('Ainda há 4 pendências financeiras em aberto.', $reminder['custommessage']);
        self::assertStringContainsString('Faturas: #101, #102, #103, #105.', $reminder['custommessage']);

        $this->pdo->exec("UPDATE pagou_reconciliation_findings SET status = 'resolved'");
        self::assertSame('idle', $this->alerts()->run()['findings']);
        self::assertNull((new OperationalSettings($this->pdo))->get('admin_alert_findings'));
    }

    public function testUnknownFindingTypesUseAGenericPortugueseLabel(): void
    {
        $this->finding('finding-x', null, 'internal_code_without_label', 'open', '-1 minute');

        self::assertSame('alerted', $this->alerts()->run()['findings']);
        self::assertStringContainsString('Tipos: Outra pendência financeira (1).', $this->calls[0][1]['custommessage']);
        self::assertStringContainsString('Faturas: nenhuma fatura identificada.', $this->calls[0][1]['custommessage']);
        self::assertStringNotContainsString('internal', $this->calls[0][1]['custommessage']);
    }

    public function testPixRefundsInReviewAreAlertedWithInvoiceAndAmountOnly(): void
    {
        $this->refund('refund-1', 77, 1234, 'review');
        $this->refund('refund-2', 78, 999, 'confirmed');

        self::assertSame('alerted', $this->alerts()->run()['refunds']);
        $message = $this->calls[0][1];
        self::assertSame('Pagou para WHMCS: reembolso Pix requer conferência', $message['customsubject']);
        self::assertStringContainsString(
            'O Pagou para WHMCS marcou 1 reembolso Pix para conferência. Não solicite outra devolução para esta fatura.',
            $message['custommessage'],
        );
        self::assertStringContainsString('Fatura #77: R$ 12,34', $message['custommessage']);
        self::assertStringNotContainsString('#78', $message['custommessage']);
        self::assertStringContainsString('aba Pendências em Addons &gt; Pagou para WHMCS.', $message['custommessage']);

        self::assertSame('waiting', $this->alerts()->run()['refunds']);
        $this->refund('refund-3', 79, 50000, 'review');
        self::assertSame('alerted', $this->alerts()->run()['refunds']);
        self::assertStringContainsString('Fatura #79: R$ 500,00', $this->calls[1][1]['custommessage']);
        self::assertStringNotContainsString('#77', $this->calls[1][1]['custommessage']);

        $this->now = $this->now->modify('+24 hours');
        self::assertSame('reminded', $this->alerts()->run()['refunds']);
        self::assertSame('Lembrete Pagou para WHMCS: 2 reembolsos Pix aguardam conferência', $this->calls[2][1]['customsubject']);
        self::assertStringContainsString(
            'Ainda há 2 reembolsos Pix aguardando conferência. Não solicite outra devolução para estas faturas.',
            $this->calls[2][1]['custommessage'],
        );
        self::assertStringContainsString('Fatura #77: R$ 12,34<br>Fatura #79: R$ 500,00', $this->calls[2][1]['custommessage']);
    }

    public function testDisabledSettingSendsNothing(): void
    {
        (new CentralSettingsStore($this->pdo))->save(['admin_alerts_enabled' => '0']);
        $this->activateGateway();
        $this->finding('finding-1', null, 'amount_mismatch', 'open', '-1 minute');
        $this->refund('refund-1', 77, 1234, 'review');

        self::assertSame(['worker' => 'disabled', 'findings' => 'disabled', 'refunds' => 'disabled'], $this->alerts()->run());
        self::assertSame([], $this->calls);
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM pagou_settings WHERE setting_key IN ('admin_alert_worker', 'admin_alert_findings', 'admin_alert_refunds')"
        )->fetchColumn());
    }

    public function testApiFailureIsLoggedSafelyAndRetriedLater(): void
    {
        $this->activateGateway();
        $this->response = ['result' => 'error', 'message' => 'smtp-password-in-error'];

        self::assertSame('failed', $this->alerts()->run()['worker']);
        self::assertCount(1, $this->calls);
        self::assertContains(
            'Pagou Payments: não foi possível enviar o alerta de worker aos administradores. Nova tentativa em até 30 minutos. RuntimeException',
            $this->logs,
        );
        self::assertStringNotContainsString('smtp-password-in-error', implode("\n", $this->logs));

        $this->response = ['result' => 'success'];
        self::assertSame('deferred', $this->alerts()->run()['worker']);
        self::assertCount(1, $this->calls);

        $this->now = $this->now->modify('+31 minutes');
        self::assertSame('alerted', $this->alerts()->run()['worker']);
        self::assertCount(2, $this->calls);
        self::assertSame('waiting', $this->alerts()->run()['worker']);
    }

    public function testThrowingApiAndBrokenQueriesNeverEscapeTheRun(): void
    {
        $this->activateGateway();
        $this->response = new \RuntimeException('api-token-in-exception');
        $this->pdo->exec('DROP TABLE pagou_pix_refunds');

        $report = $this->alerts()->run();

        self::assertSame(['worker' => 'failed', 'findings' => 'idle', 'refunds' => 'failed'], $report);
        self::assertContains('Pagou Payments: verificação do alerta de reembolsos Pix indisponível. PDOException', $this->logs);
        self::assertStringNotContainsString('api-token-in-exception', implode("\n", $this->logs));
        self::assertTrue((new LeaseRepository($this->pdo))->acquire('admin-alerts', 'next-run', 60));
    }

    public function testConcurrentRunDoesNotSendDuplicates(): void
    {
        $this->activateGateway();
        $lease = new LeaseRepository($this->pdo);
        self::assertTrue($lease->acquire('admin-alerts', 'other-cron', 300));

        self::assertSame(['worker' => 'busy', 'findings' => 'busy', 'refunds' => 'busy'], $this->alerts()->run());
        self::assertSame([], $this->calls);

        $lease->release('admin-alerts', 'other-cron');
        self::assertSame('alerted', $this->alerts()->run()['worker']);
        self::assertSame('waiting', $this->alerts()->run()['worker']);
        self::assertCount(1, $this->calls);
    }

    public function testMessagesNeverExposeSensitiveData(): void
    {
        $secret = $this->pdo->prepare(
            'INSERT INTO pagou_settings (setting_key, setting_value, is_secret, updated_at) VALUES (:key, :value, 1, :updated_at)'
        );
        $secret->execute(['key' => 'api_key_ciphertext', 'value' => 'cipher-secret-value', 'updated_at' => gmdate('Y-m-d H:i:s.u')]);
        $this->activateGateway();
        $this->attempt('attempt-101', 101, 4242);
        $this->finding('finding-1', 'attempt-101', 'amount_mismatch', 'open', '-1 minute', [
            'message' => 'payload-secret-text',
            'document' => '123.456.789-09',
            'customer' => 'Cliente Sigiloso',
        ]);
        $this->refund('refund-1', 77, 1234, 'review', 4242);

        self::assertSame(['worker' => 'alerted', 'findings' => 'alerted', 'refunds' => 'alerted'], $this->alerts()->run());
        self::assertCount(3, $this->calls);
        $sent = implode("\n", array_map(
            static fn (array $call): string => $call[1]['customsubject'] . "\n" . $call[1]['custommessage'],
            $this->calls,
        )) . "\n" . implode("\n", $this->logs);
        foreach (
            [
                'cipher-secret-value', 'payload-secret-text', '123.456.789-09', 'Cliente Sigiloso', '4242',
                'remote-secret-id', 'txn-secret-id', 'provider-secret-id', 'attempt-101', 'finding-1', 'refund-1',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $sent);
        }
        self::assertStringNotContainsString("\u{2014}", $sent);
        self::assertStringNotContainsString("\u{2013}", $sent);
    }

    public function testAdminLinkIsOmittedWithoutTrustedConfiguration(): void
    {
        $this->activateGateway();

        $this->alerts(null)->run();
        self::assertStringNotContainsString('addonmodules.php', $this->calls[0][1]['custommessage']);
        self::assertStringContainsString('Addons &gt; Pagou para WHMCS &gt; Diagnóstico', $this->calls[0][1]['custommessage']);

        $this->pdo->exec("UPDATE tblconfiguration SET value = 'javascript:alert(1)' WHERE setting = 'SystemURL'");
        $this->pdo->exec("DELETE FROM pagou_settings WHERE setting_key = 'admin_alert_worker'");
        $this->alerts('painel')->run();
        self::assertCount(2, $this->calls);
        self::assertStringNotContainsString('href=', $this->calls[1][1]['custommessage']);

        $this->pdo->exec("UPDATE tblconfiguration SET value = 'https://whmcs.example/billing' WHERE setting = 'SystemURL'");
        $this->pdo->exec("DELETE FROM pagou_settings WHERE setting_key = 'admin_alert_worker'");
        $this->alerts('../painel')->run();
        self::assertStringNotContainsString('href=', $this->calls[2][1]['custommessage']);
    }

    public function testAdminFolderComesOnlyFromAValidCustomAdminPath(): void
    {
        $previous = $GLOBALS['customadminpath'] ?? null;
        try {
            unset($GLOBALS['customadminpath']);
            self::assertNull(AdminAlerts::detectAdminFolder());
            $GLOBALS['customadminpath'] = 'gestao_2';
            self::assertSame('gestao_2', AdminAlerts::detectAdminFolder());
            $GLOBALS['customadminpath'] = '../admin';
            self::assertNull(AdminAlerts::detectAdminFolder());
        } finally {
            if ($previous === null) {
                unset($GLOBALS['customadminpath']);
            } else {
                $GLOBALS['customadminpath'] = $previous;
            }
        }
    }

    private function alerts(?string $folder = 'painel'): AdminAlerts
    {
        return new AdminAlerts(
            $this->pdo,
            function (string $command, array $parameters): array {
                $this->calls[] = [$command, $parameters];
                if ($this->response instanceof \Throwable) {
                    throw $this->response;
                }

                return $this->response;
            },
            $this->clock,
            $folder,
            function (string $message): void {
                $this->logs[] = $message;
            },
        );
    }

    private function activateGateway(string $gateway = 'pagou_pix'): void
    {
        $statement = $this->pdo->prepare("INSERT INTO tblpaymentgateways (gateway, setting, value) VALUES (:gateway, 'visible', '')");
        $statement->execute(['gateway' => $gateway]);
    }

    private function heartbeat(DateTimeImmutable $at): void
    {
        (new OperationalSettings($this->pdo))->set('worker_last_run_utc', $at->format('Y-m-d H:i:s.u'));
    }

    private function attempt(string $id, int $invoiceId, int $clientId = 9): void
    {
        $now = $this->now->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare(
            'INSERT INTO pagou_payment_attempts (id, invoice_id, client_id, gateway, method, status, amount_cents, currency, remote_id, '
            . 'idempotency_key, economic_key, created_at, updated_at) VALUES (:id, :invoice, :client, :gateway, :method, :status, 1000, '
            . "'BRL', :remote, :idempotency, :economic, :now, :now)"
        );
        $statement->execute([
            'id' => $id, 'invoice' => $invoiceId, 'client' => $clientId, 'gateway' => 'pagou_pix', 'method' => 'pix',
            'status' => 'paid', 'remote' => 'remote-' . $id, 'idempotency' => 'idem-' . $id, 'economic' => 'eco-' . $id, 'now' => $now,
        ]);
    }

    /** @param array<string, string> $details */
    private function finding(string $id, ?string $attemptId, string $type, string $status, string $age, array $details = []): void
    {
        $at = $this->now->modify($age)->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare(
            'INSERT INTO pagou_reconciliation_findings (id, finding_key, attempt_id, severity, finding_type, status, details_json, '
            . 'detected_at_utc, created_at, updated_at) VALUES (:id, :key, :attempt, :severity, :type, :status, :details, :at, :at, :at)'
        );
        $statement->execute([
            'id' => $id, 'key' => hash('sha256', $id), 'attempt' => $attemptId, 'severity' => 'high', 'type' => $type,
            'status' => $status, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'at' => $at,
        ]);
    }

    private function ledger(string $economicKey, int $invoiceId): void
    {
        $now = $this->now->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare(
            'INSERT INTO pagou_ledger_entries (id, idempotency_key, invoice_id, client_id, entry_type, amount_cents, currency, '
            . "effective_at_utc, created_at) VALUES (:id, :key, :invoice, 9, 'received_payment', 1000, 'BRL', :now, :now)"
        );
        $statement->execute(['id' => 'ledger-' . $economicKey, 'key' => $economicKey, 'invoice' => $invoiceId, 'now' => $now]);
    }

    private function refund(string $id, int $invoiceId, int $amount, string $status, int $clientId = 9): void
    {
        $now = $this->now->format('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO pagou_pix_refunds (id, attempt_id, invoice_id, client_id, remote_id, original_transaction_id, amount_cents, '
            . 'receipt_cents, status, provider_refund_id, actor_id, requested_at, updated_at, error_code) VALUES (:id, :attempt, :invoice, '
            . ':client, :remote, :transaction, :amount, :amount, :status, :provider, 1, :now, :now, :error)'
        );
        $statement->execute([
            'id' => $id, 'attempt' => 'refund-attempt-' . $id, 'invoice' => $invoiceId, 'client' => $clientId,
            'remote' => 'remote-secret-id-' . $id, 'transaction' => 'txn-secret-id-' . $id, 'amount' => $amount, 'status' => $status,
            'provider' => 'provider-secret-id-' . $id, 'now' => $now, 'error' => 'refund_confirmation_requires_review',
        ]);
    }
}
