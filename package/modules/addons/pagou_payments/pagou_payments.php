<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    exit('Acesso direto não permitido.');
}

require_once dirname(__DIR__, 2) . '/gateways/pagou/bootstrap.php';

use Pagou\Payments\Admin\Controller;
use Pagou\Payments\Admin\View\AccountPanel;
use Pagou\Payments\Admin\Widget\AccountSummary;
use Pagou\Whmcs\Application\Runtime\GatewayActivationService;
use Pagou\Whmcs\Application\Runtime\RuntimeFactory;
use Pagou\Whmcs\Application\Runtime\OperationalReadModel;
use Pagou\Whmcs\Configuration\CentralSettingsStore;
use Pagou\Whmcs\Configuration\EncryptedCredentialStore;
use Pagou\Whmcs\Infrastructure\Http\ApiClientConfig;
use Pagou\Whmcs\Infrastructure\Http\SafeCurlApiClient;
use Pagou\Whmcs\Payment\Card\Exception\CardUncertainOperation;
use Pagou\Whmcs\Payment\Card\IdempotencyKey;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardAttemptStore;

function pagou_payments_config(): array
{
    return [
        'name' => 'Pagou para WHMCS',
        'description' => 'Operação central dos meios de pagamento Pagou no WHMCS.',
        'version' => trim((string) file_get_contents(__DIR__ . '/VERSION')),
        'author' => 'Pagou',
        'language' => 'portuguese-br',
        // Keep the native addon screen limited to WHMCS Access Control.
        'fields' => [],
    ];
}

function pagou_payments_activate(): array
{
    try {
        RuntimeFactory::migrate();

        return ['status' => 'success', 'description' => 'Pagou para WHMCS ativado e banco atualizado.'];
    } catch (Throwable $exception) {
        if (function_exists('logActivity')) {
            $message = str_replace(["\r", "\n"], ' ', $exception->getMessage());
            logActivity(sprintf(
                'Pagou Payments: ativação falhou. %s: %s',
                $exception::class,
                substr($message, 0, 500),
            ));
        }

        return ['status' => 'error', 'description' => 'Não foi possível ativar o módulo. Consulte o log administrativo.'];
    }
}

function pagou_payments_deactivate(): array
{
    return [
        'status' => 'success',
        'description' => 'Módulo desativado. Os registros financeiros e de auditoria foram preservados.',
    ];
}

function pagou_payments_upgrade(array $vars): void
{
    RuntimeFactory::migrate();
}

function pagou_payments_output(array $vars): void
{
    $pdo = RuntimeFactory::pdo();
    $filters = pagou_payments_filters($_GET);
    $request = \Pagou\Payments\Admin\Http\AdminRequest::fromGlobals();
    if ($request->isPost() && $request->action() === 'export-report') {
        try {
            $export = new \Pagou\Payments\Admin\ReportExport($pdo, $filters, $request);
            $export->send();
            exit;
        } catch (Throwable $exception) {
            $error = \Pagou\Whmcs\Application\Reporting\AdminReportProvider::error($exception);
            echo (new Controller(null, static fn (): array => ['reportError' => $error, 'reportFilters' => $filters]))
                ->handle(new \Pagou\Payments\Admin\Http\AdminRequest(['view' => 'reports'], [], ['REQUEST_METHOD' => 'GET']));
            return;
        }
    }
    if ($request->page() === 'dashboard' && !$request->isPost() && $request->queryString('fragment') === 'account') {
        pagou_payments_account_fragment(false);
        exit;
    }
    if ($request->isPost() && $request->action() === 'account-summary') {
        // A forced refresh bypasses the shared cache, so it requires the admin form token.
        try {
            (new \Pagou\Payments\Admin\Security\Authorization())->assertCanOperate();
            (new \Pagou\Payments\Admin\Security\Csrf())->assertValid($request->postString('token'));
        } catch (Throwable) {
            pagou_payments_account_fragment(false, 403);
            exit;
        }
        pagou_payments_account_fragment(true);
        exit;
    }
    RuntimeFactory::migrate();
    echo (new Controller(static function (string $action, array $input) use ($pdo): array {
        return match ($action) {
            'install-pdf-template', 'remove-pdf-template' => pagou_payments_pdf_template($pdo, $input, $action === 'install-pdf-template'),
            'run-diagnostics' => pagou_payments_run_diagnostics($pdo),
            'run-reconciliation', 'refresh-pending' => pagou_payments_run_reconciliation($pdo),
            'resume-queue' => pagou_payments_resume_queue($pdo),
            'cancel-invoice', 'replace-boleto' => pagou_payments_schedule_invoice_action(
                $pdo,
                (int) ($input['invoice_id'] ?? 0),
                (string) ($input['reason'] ?? ''),
                $action === 'replace-boleto',
            ),
            'validate-credential' => pagou_payments_save_credential(
                $pdo,
                $input['credential'] ?? '',
                ($input['replace_credential'] ?? '0') === '1',
            ),
            'test-credential' => pagou_payments_test_credential($pdo),
            'save-settings' => pagou_payments_save_settings($pdo, $input),
            'reset-settings' => pagou_payments_reset_settings($pdo, $input),
            'activate-gateway' => pagou_payments_activate_gateways(
                $pdo,
                [(string) ($input['gateway'] ?? '')],
            ),
            'activate-recommended-gateways' => pagou_payments_activate_gateways(
                $pdo,
                ['pagou_pix', 'pagou_boleto'],
            ),
            'card-capture', 'card-reverse', 'card-cancel', 'card-retry', 'card-reconcile' =>
                pagou_payments_card_action($pdo, $action, (string) ($input['charge_id'] ?? '')),
            default => throw new RuntimeException('Ação administrativa não reconhecida.'),
        };
    }, static fn (string $page): array => (new OperationalReadModel($pdo))->admin($page, $filters)
        + (pagou_payments_is_dashboard($page) && AccountSummary::allowed()
            // Cached values only; an expired snapshot is refreshed by the page asynchronously.
            ? ['account' => AccountSummary::read(false, false)]
            : []), true, static fn (): array => (new OperationalReadModel($pdo))->navigationBadges()))->handle();
}

function pagou_payments_is_dashboard(string $page): bool
{
    return !in_array($page, [
        'payments', 'operations', 'webhooks', 'reconciliation', 'findings', 'reports', 'charge', 'search',
        'card', 'settings', 'diagnostics', 'about',
    ], true);
}

/**
 * Account card for the overview, rendered alone for the asynchronous refresh.
 * Requires the same finance permission as the WHMCS home widget.
 */
function pagou_payments_account_fragment(bool $force, int $status = 200): void
{
    $allowed = $status === 200 && AccountSummary::allowed();
    $html = $allowed ? (new AccountPanel())->render(AccountSummary::read($force, true)) : '';
    while (ob_get_level() > 0) {
        if (!ob_end_clean()) {
            break;
        }
    }
    if (!headers_sent()) {
        http_response_code($allowed ? 200 : 403);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
    }
    echo $html;
}

/**
 * @param array<string, mixed> $query
 * @return array<string, string>
 */
function pagou_payments_filters(array $query): array
{
    $filters = [];
    foreach (['invoice', 'method', 'status', 'type', 'period', 'page', 'from', 'to', 'client', 'report', 'q', 'document', 'party', 'group'] as $key) {
        $value = $query[$key] ?? null;
        if ($value === null) {
            continue;
        }
        if (!is_scalar($value) || strlen((string) $value) > 64) {
            // Keep invalid input invalid. Dropping a malformed customer/date filter
            // could otherwise broaden a requested financial export silently.
            $filters[$key] = '!invalid';
            continue;
        }
        $value = trim((string) $value);
        if ($value !== '') {
            $filters[$key] = $value;
        }
    }

    return $filters;
}

/** @return array{notice:string, noticeTone:string} */
function pagou_payments_resume_queue(PDO $pdo): array
{
    $runtime = RuntimeFactory::runtime($pdo);
    $operations = $runtime->resumeRecoverableOperations();
    $reconciliation = $runtime->schedulePendingReconciliation();
    $cards = RuntimeFactory::cardReconciliation($pdo)->run();

    return [
        'notice' => sprintf(
            '%d operação(ões) segura(s) retomada(s), %d cobrança(s) registrada(s) e %d cartão(ões) consultado(s).',
            $operations,
            $reconciliation,
            $cards['inspected'],
        ),
        'noticeTone' => 'success',
    ];
}

/** @return array{notice:string, noticeTone:string, data:array<string, mixed>} */
function pagou_payments_run_reconciliation(PDO $pdo): array
{
    $scheduled = RuntimeFactory::runtime($pdo)->schedulePendingReconciliation();
    $cards = RuntimeFactory::cardReconciliation($pdo)->run();

    return [
        'notice' => sprintf(
            '%d cobrança(s) registrada(s) para consulta, %d cartão(ões) consultado(s), %d pagamento(s) aplicado(s) e %d pendência(s) identificada(s).',
            $scheduled,
            $cards['inspected'],
            $cards['applied'],
            $cards['findings'],
        ),
        'noticeTone' => $cards['failed'] > 0 || $cards['findings'] > 0 ? 'warning' : 'success',
        'data' => (new OperationalReadModel($pdo))->admin('reconciliation'),
    ];
}

/** @return array{notice:string, noticeTone:string, data:array<string, mixed>} */
function pagou_payments_card_action(PDO $pdo, string $action, string $chargeId): array
{
    $chargeId = trim($chargeId);
    if (preg_match('/^[A-Za-z0-9_-]{8,128}$/', $chargeId) !== 1) {
        throw new InvalidArgumentException('A cobrança de cartão informada é inválida.');
    }
    $attempts = new PdoCardAttemptStore($pdo);
    $local = $attempts->byCharge($chargeId);
    if ($local === null) {
        throw new OutOfBoundsException('A cobrança não pertence a esta instalação do WHMCS.');
    }
    $state = strtolower((string) ($local['capture_status'] ?? $local['status'] ?? 'unknown'));
    $allowed = match ($action) {
        'card-capture' => [],
        'card-reverse' => ['authorized'],
        'card-cancel' => ['pending', 'action_required'],
        'card-retry' => ['failed'],
        'card-reconcile' => [$state],
        default => [],
    };
    if (!in_array($state, $allowed, true)) {
        throw new DomainException('A operação não é permitida no estado atual da cobrança.');
    }

    $verb = substr($action, strlen('card-'));
    $key = IdempotencyKey::create(
        'admin_' . $verb,
        'charge:' . $chargeId . ':state:' . $state . ':version:' . (int) ($local['version'] ?? 1),
    );
    try {
        if ($action === 'card-reconcile') {
            $outcome = RuntimeFactory::cardReconciliation($pdo)->refresh($chargeId);
        } else {
            $service = RuntimeFactory::card($pdo);
            $charge = match ($action) {
                'card-capture' => $service->capture($chargeId, $key),
                'card-reverse' => $service->reverse($chargeId, $key),
                'card-cancel' => $service->cancelPending($chargeId, $key),
                'card-retry' => $service->retry($chargeId, $key),
                default => throw new LogicException('Operação de cartão não implementada.'),
            };
            $outcome = RuntimeFactory::cardReconciliation($pdo)->process($charge);
        }
    } catch (CardUncertainOperation $exception) {
        $attempts->uncertain((string) $local['id']);
        pagou_payments_audit_card_action($pdo, $action, $chargeId, 'uncertain');
        throw $exception;
    }

    pagou_payments_audit_card_action($pdo, $action, $chargeId, $outcome);

    return [
        'notice' => match ($action) {
            'card-capture' => 'Captura solicitada e estado financeiro atualizado com segurança.',
            'card-reverse' => 'Reversão da autorização solicitada e estado atualizado.',
            'card-cancel' => 'Cancelamento da cobrança pendente solicitado e estado atualizado.',
            'card-retry' => 'Nova tentativa solicitada sem criar outra cobrança.',
            default => 'Estado da cobrança consultado sem criar uma nova cobrança.',
        },
        'noticeTone' => $outcome === 'findings' ? 'warning' : 'success',
        'data' => (new OperationalReadModel($pdo))->admin('card'),
    ];
}

function pagou_payments_audit_card_action(
    PDO $pdo,
    string $action,
    string $chargeId,
    string $outcome,
): void {
    $statement = $pdo->prepare(
        'INSERT INTO pagou_audit_log '
        . '(actor_type, actor_id, action, subject_type, subject_id, correlation_id, ip_address, metadata_json, occurred_at_utc) '
        . 'VALUES (:actor_type, :actor_id, :action, :subject_type, :subject_id, NULL, NULL, :metadata_json, :occurred_at_utc)'
    );
    $statement->execute([
        'actor_type' => 'admin',
        'actor_id' => (string) ((int) ($_SESSION['adminid'] ?? 0)),
        'action' => $action,
        'subject_type' => 'card_charge',
        'subject_id' => $chargeId,
        'metadata_json' => json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR),
        'occurred_at_utc' => gmdate('Y-m-d H:i:s.u'),
    ]);
}

/** @return array{notice:string, noticeTone:string} */
function pagou_payments_schedule_invoice_action(PDO $pdo, int $invoiceId, string $reason, bool $replace): array
{
    $actorId = (int) ($_SESSION['adminid'] ?? 0);
    $runtime = RuntimeFactory::runtime($pdo);
    $runtime->requestInvoiceCancellation($invoiceId, $reason, $actorId, $replace);
    $runtime->advanceInvoice($invoiceId);

    return [
        'notice' => $replace
            ? 'Substituição iniciada. Acompanhe o novo boleto no painel da fatura.'
            : 'Cancelamento iniciado. Acompanhe a confirmação no painel da fatura.',
        'noticeTone' => 'success',
    ];
}

/** @return array{notice:string, noticeTone:string} */
function pagou_payments_save_credential(PDO $pdo, string $credential, bool $replace = false): array
{
    $store = new EncryptedCredentialStore($pdo);
    if ($store->configured() && !$replace) {
        throw new InvalidArgumentException('Confirme explicitamente a substituição da credencial atual.');
    }
    $client = pagou_payments_api_client(trim($credential));
    $client->request('GET', '/v1/customers/balance');
    $fingerprint = $store->save($credential);
    $now = gmdate('Y-m-d H:i:s.u');
    pagou_payments_write_operational_setting($pdo, 'credential_last_validated_utc', $now);
    pagou_payments_write_operational_setting($pdo, 'credential_fingerprint', $fingerprint);
    pagou_payments_write_operational_setting($pdo, 'diagnostics_api_state', 'ready');
    pagou_payments_write_operational_setting($pdo, 'diagnostics_api_checked_utc', $now);

    return [
        'notice' => 'Credencial validada e protegida pelo cofre nativo do WHMCS (' . $fingerprint . ').',
        'noticeTone' => 'success',
        'data' => (new OperationalReadModel($pdo))->admin('settings'),
    ];
}

/** @return array{notice:string, noticeTone:string, data:array<string, mixed>} */
function pagou_payments_test_credential(PDO $pdo): array
{
    $credential = pagou_payments_current_credential($pdo);
    pagou_payments_api_client($credential)->request('GET', '/v1/customers/balance');
    $now = gmdate('Y-m-d H:i:s.u');
    $fingerprint = Pagou\Whmcs\Configuration\CredentialRedactor::fingerprint($credential);
    pagou_payments_write_operational_setting($pdo, 'credential_last_validated_utc', $now);
    pagou_payments_write_operational_setting($pdo, 'credential_fingerprint', $fingerprint);
    pagou_payments_write_operational_setting($pdo, 'diagnostics_api_state', 'ready');
    pagou_payments_write_operational_setting($pdo, 'diagnostics_api_checked_utc', $now);

    return [
        'notice' => 'Conexão com a Pagou validada sem criar cobranças.',
        'noticeTone' => 'success',
        'data' => (new OperationalReadModel($pdo))->admin('settings'),
    ];
}

/**
 * @param list<string> $gateways
 * @return array{notice:string, noticeTone:string, data:array<string, mixed>}
 */
function pagou_payments_activate_gateways(PDO $pdo, array $gateways): array
{
    $service = new GatewayActivationService($pdo, RuntimeFactory::localApi());
    $activated = [];
    $alreadyActive = [];
    $visibilityCorrected = [];
    $failed = [];
    $manualReview = false;

    foreach (array_values(array_unique($gateways)) as $gateway) {
        try {
            pagou_payments_assert_gateway_activation_ready($pdo, $gateway);
            $result = $service->activate($gateway);
            if ($result['outcome'] === 'activated_hidden') {
                $activated[] = $result['label'];
                try {
                    pagou_payments_audit_gateway_activation($pdo, $result['gateway']);
                } catch (Throwable $auditException) {
                    if (function_exists('logActivity')) {
                        logActivity('Pagou Payments: auditoria da ativação não foi gravada. ' . $auditException::class);
                    }
                }
            } elseif ($result['outcome'] === 'visibility_corrected') {
                $visibilityCorrected[] = $result['label'];
            } else {
                $alreadyActive[] = $result['label'];
            }
        } catch (Throwable $exception) {
            $failed[] = pagou_payments_gateway_label($gateway);
            $manualReview = $manualReview || pagou_payments_gateway_is_visible($pdo, $gateway);
            if (function_exists('logActivity')) {
                logActivity('Pagou Payments: ativação assistida recusada. ' . $exception::class);
            }
        }
    }

    $parts = [];
    if ($activated !== []) {
        $parts[] = implode(' e ', $activated) . ' ativado(s) e mantido(s) oculto(s) para teste controlado.';
    }
    if ($alreadyActive !== []) {
        $parts[] = implode(' e ', $alreadyActive) . ' já estava(m) ativo(s); nenhuma configuração foi alterada.';
    }
    if ($visibilityCorrected !== []) {
        $parts[] = implode(' e ', $visibilityCorrected) . ' já estava(m) ativo(s) e agora permanece(m) oculto(s).';
    }
    if ($failed !== []) {
        $parts[] = 'Não foi possível ativar ' . implode(' e ', $failed) . '. Confira o diagnóstico antes de tentar novamente.';
    }
    if ($manualReview) {
        $parts[] = 'Atenção: confira e oculte imediatamente o gateway na configuração de pagamentos do WHMCS.';
    }

    return [
        'notice' => implode(' ', $parts),
        'noticeTone' => $failed === [] ? 'success' : 'danger',
        'data' => (new OperationalReadModel($pdo))->admin('settings'),
    ];
}

function pagou_payments_gateway_is_visible(PDO $pdo, string $gateway): bool
{
    $statement = $pdo->prepare(
        "SELECT value FROM tblpaymentgateways WHERE gateway = :gateway AND setting = 'visible' LIMIT 1"
    );
    $statement->execute(['gateway' => $gateway]);
    $value = $statement->fetchColumn();

    return is_scalar($value)
        && in_array(strtolower(trim((string) $value)), ['on', '1', 'yes', 'true'], true);
}

function pagou_payments_assert_gateway_activation_ready(PDO $pdo, string $gateway = ''): void
{
    $settings = (new CentralSettingsStore($pdo))->values();
    if (trim((string) ($settings['cpf_field_id'] ?? '')) === '') {
        throw new InvalidArgumentException('Selecione o campo principal de CPF ou CNPJ antes de ativar os gateways.');
    }

    $credential = pagou_payments_current_credential($pdo);
    pagou_payments_api_client($credential)->request('GET', '/v1/customers/balance');

    if ($gateway === 'pagou_creditcard') {
        pagou_payments_assert_card_ready($pdo, $credential, true);
    }
}

function pagou_payments_assert_card_ready(PDO $pdo, ?string $credential = null, bool $probeApi = false): void
{
    $settings = \Pagou\Whmcs\Application\Runtime\AddonSettings::fromPdo($pdo);
    \Pagou\Whmcs\Payment\Card\CardCheckoutPolicy::assertSupported($settings->integer('card_max_installments', 1), $settings->boolean('card_auto_capture', true));
    foreach (['curl', 'json', 'openssl', 'pdo'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('A extensão PHP ' . $extension . ' é obrigatória para o cartão.');
        }
    }
    $migration = $pdo->prepare('SELECT 1 FROM pagou_schema_migrations WHERE version = :version LIMIT 1');
    $migration->execute(['version' => '013']);
    if ($migration->fetchColumn() === false) {
        throw new RuntimeException('Atualize o addon Pagou antes de ativar o cartão.');
    }
    foreach (
        [
        dirname(__DIR__, 2) . '/gateways/pagou/card-input.php',
        dirname(__DIR__, 2) . '/gateways/callback/pagou_creditcard.php',
        ] as $file
    ) {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('A instalação do fluxo seguro de cartão está incompleta.');
        }
    }
    if ($probeApi) {
        pagou_payments_api_client($credential ?? pagou_payments_current_credential($pdo))
            ->request('GET', '/v1/creditcard/customers');
    }
}

function pagou_payments_gateway_label(string $gateway): string
{
    return match ($gateway) {
        'pagou_pix' => 'Pix',
        'pagou_boleto' => 'Boleto',
        'pagou_creditcard' => 'Cartão de crédito',
        default => 'o gateway solicitado',
    };
}

function pagou_payments_audit_gateway_activation(PDO $pdo, string $gateway): void
{
    $statement = $pdo->prepare(
        'INSERT INTO pagou_audit_log '
        . '(actor_type, actor_id, action, subject_type, subject_id, correlation_id, ip_address, metadata_json, occurred_at_utc) '
        . 'VALUES (:actor_type, :actor_id, :action, :subject_type, :subject_id, NULL, NULL, :metadata_json, :occurred_at_utc)'
    );
    $statement->execute([
        'actor_type' => 'admin',
        'actor_id' => (string) ((int) ($_SESSION['adminid'] ?? 0)),
        'action' => 'gateway.activated',
        'subject_type' => 'payment_gateway',
        'subject_id' => $gateway,
        'metadata_json' => json_encode(['visibility' => 'hidden', 'source' => 'whmcs_local_api'], JSON_THROW_ON_ERROR),
        'occurred_at_utc' => gmdate('Y-m-d H:i:s.u'),
    ]);
}

/** @return array{notice:string, noticeTone:string, data:array<string, mixed>} */
function pagou_payments_run_diagnostics(PDO $pdo): array
{
    $notice = 'Conexão técnica verificada. O diagnóstico não confirma credenciamento nem homologação de cartão. Nenhuma cobrança foi criada.';
    $tone = 'success';
    $cardReady = false;
    try {
        $credential = pagou_payments_current_credential($pdo);
        pagou_payments_api_client($credential)->request('GET', '/v1/customers/balance');
        pagou_payments_write_operational_setting($pdo, 'diagnostics_api_state', 'ready');
        try {
            pagou_payments_assert_card_ready($pdo, $credential, true);
            pagou_payments_write_operational_setting($pdo, 'diagnostics_card_state', 'ready');
            $cardReady = true;
        } catch (Throwable) {
            pagou_payments_write_operational_setting($pdo, 'diagnostics_card_state', 'attention');
        }
    } catch (Throwable) {
        pagou_payments_write_operational_setting($pdo, 'diagnostics_api_state', 'attention');
        pagou_payments_write_operational_setting($pdo, 'diagnostics_card_state', 'attention');
        $notice = 'Diagnóstico concluído com atenção na conexão Pagou. Nenhuma cobrança foi criada.';
        $tone = 'danger';
    }
    if ($tone === 'success' && !$cardReady) {
        $notice = 'Diagnóstico geral concluído. O cartão ainda requer atenção antes da ativação.';
        $tone = 'warning';
    }
    pagou_payments_write_operational_setting($pdo, 'diagnostics_api_checked_utc', gmdate('Y-m-d H:i:s.u'));

    return [
        'notice' => $notice,
        'noticeTone' => $tone,
        'data' => (new OperationalReadModel($pdo))->admin('diagnostics'),
    ];
}

function pagou_payments_current_credential(PDO $pdo): string
{
    $environment = getenv('PAGOU_API_KEY');
    if (is_string($environment) && trim($environment) !== '') {
        return trim($environment);
    }
    $credential = (new EncryptedCredentialStore($pdo))->load();
    if ($credential === null) {
        throw new RuntimeException('A credencial Pagou ainda não foi configurada.');
    }

    return $credential;
}

function pagou_payments_api_client(string $credential): SafeCurlApiClient
{
    $configured = getenv('PAGOU_INTERNAL_API_BASE_URL');
    $baseUrl = is_string($configured) && trim($configured) !== '' ? trim($configured) : null;

    return new SafeCurlApiClient(ApiClientConfig::fromInternalOverride(trim($credential), $baseUrl));
}

function pagou_payments_write_operational_setting(PDO $pdo, string $key, string $value): void
{
    if (preg_match('/^[a-z0-9_]{3,64}$/', $key) !== 1 || strlen($value) > 255) {
        throw new InvalidArgumentException('A configuração operacional informada é inválida.');
    }
    $parameters = ['key' => $key, 'value' => $value, 'updated_at' => gmdate('Y-m-d H:i:s.u')];
    $update = $pdo->prepare(
        'UPDATE pagou_settings SET setting_value = :value, is_secret = 0, updated_at = :updated_at '
        . 'WHERE setting_key = :key AND is_secret = 0'
    );
    $update->execute($parameters);
    if ($update->rowCount() > 0) {
        return;
    }
    try {
        $insert = $pdo->prepare(
            'INSERT INTO pagou_settings (setting_key, setting_value, is_secret, updated_at) '
            . 'VALUES (:key, :value, 0, :updated_at)'
        );
        $insert->execute($parameters);
    } catch (PDOException $exception) {
        if (!in_array((string) $exception->getCode(), ['19', '23000', '23505'], true)) {
            throw $exception;
        }
        $update->execute($parameters);
    }
}

/**
 * @param array<string, string> $input
 * @return array{notice:string, noticeTone:string, data:array<string, mixed>}
 */
function pagou_payments_save_settings(PDO $pdo, array $input): array
{
    $section = $input['settings_section'] ?? 'general';
    unset($input['settings_section']);
    $changedKeys = array_keys($input);
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        $settings = (new CentralSettingsStore($pdo))->save($input);
        pagou_payments_audit_settings($pdo, $changedKeys);
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'notice' => match ($section) {
            'pix' => 'Configurações do Pix salvas.',
            'boleto' => 'Configurações do boleto salvas.',
            'card' => 'Configurações do cartão de crédito salvas.',
            default => 'Configurações gerais salvas.',
        },
        'noticeTone' => 'success',
        'data' => ['settings' => $settings, 'settingsSection' => $section],
    ];
}

/**
 * @param array<string, string> $input
 * @return array{notice:string, noticeTone:string, data:array<string, mixed>}
 */
function pagou_payments_reset_settings(PDO $pdo, array $input): array
{
    $section = $input['settings_section'] ?? '';
    if (!in_array($section, CentralSettingsStore::sections(), true)) {
        throw new InvalidArgumentException('A seção de configurações informada é inválida.');
    }

    $keys = CentralSettingsStore::keysForSection($section);
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        $settings = (new CentralSettingsStore($pdo))->resetSection($section);
        pagou_payments_audit_settings($pdo, $keys, 'settings.reset', $section);
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    if (function_exists('logActivity')) {
        logActivity(sprintf(
            'Pagou Payments: padrões da seção %s restaurados pelo administrador #%d.',
            $section,
            (int) ($_SESSION['adminid'] ?? 0),
        ));
    }

    return [
        'notice' => match ($section) {
            'pix' => 'Padrões do Pix restaurados. A credencial e o estado do gateway foram preservados.',
            'boleto' => 'Padrões do boleto restaurados. A credencial e o estado do gateway foram preservados.',
            'card' => 'Padrões do cartão restaurados. A credencial e o estado do gateway foram preservados.',
            default => 'Padrões gerais restaurados. A credencial e o estado dos gateways foram preservados.',
        },
        'noticeTone' => 'success',
        'data' => ['settings' => $settings, 'settingsSection' => $section],
    ];
}

/** @param list<string> $keys */
function pagou_payments_audit_settings(
    PDO $pdo,
    array $keys,
    string $action = 'settings.updated',
    string $section = '',
): void {
    $statement = $pdo->prepare(
        'INSERT INTO pagou_audit_log '
        . '(actor_type, actor_id, action, subject_type, subject_id, correlation_id, ip_address, metadata_json, occurred_at_utc) '
        . 'VALUES (:actor_type, :actor_id, :action, :subject_type, :subject_id, NULL, NULL, :metadata_json, :occurred_at_utc)'
    );
    $statement->execute([
        'actor_type' => 'admin',
        'actor_id' => (string) ((int) ($_SESSION['adminid'] ?? 0)),
        'action' => $action,
        'subject_type' => 'module_configuration',
        'subject_id' => 'pagou_payments',
        'metadata_json' => json_encode(
            array_filter(['keys' => $keys, 'section' => $section], static fn (mixed $value): bool => $value !== ''),
            JSON_THROW_ON_ERROR,
        ),
        'occurred_at_utc' => gmdate('Y-m-d H:i:s.u'),
    ]);
}

function pagou_payments_clientarea(array $vars): array
{
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9999]]) ?: 1;
    $payments = $clientId > 0
        ? (new OperationalReadModel(RuntimeFactory::pdo()))->clientPayments($clientId, $page)
        : [];

    return [
        'pagetitle' => 'Meus pagamentos Pagou',
        'breadcrumb' => ['index.php?m=pagou_payments' => 'Pagamentos Pagou'],
        'templatefile' => 'client-payments',
        'requirelogin' => true,
        'forcessl' => true,
        'vars' => ['payments' => array_slice($payments, 0, 50), 'paymentPage' => $page, 'paymentHasNext' => count($payments) > 50, 'moduleVersion' => $vars['version'] ?? ''],
    ];
}

/**
 * @param array<string,string> $input
 * @return array<string,mixed>
 */
function pagou_payments_pdf_template(PDO $pdo, array $input, bool $install): array
{
    $service = new \Pagou\Whmcs\InvoicePdf\IntegrationService($pdo, (string) ROOTDIR);
    try {
        $service->change($input['theme'] ?? '', $input['template_hash'] ?? '', $install);
        $notice = $install ? 'Integração adicionada. O conteúdo anterior do tema foi preservado e uma cópia privada foi salva.'
            : 'Somente o bloco da Pagou foi removido. As demais personalizações do tema foram preservadas.';
        $tone = 'success';
    } catch (RuntimeException $exception) {
        $notice = $exception->getMessage();
        $tone = 'warning';
    }
    return ['notice' => $notice, 'noticeTone' => $tone, 'data' => ['pdfIntegration' => $service->inspect()]];
}
