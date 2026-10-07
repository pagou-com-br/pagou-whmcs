<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use Closure;
use PDO;
use Pagou\Whmcs\Application\Async\ExponentialBackoff;
use Pagou\Whmcs\Application\Async\HandlerOutcome;
use Pagou\Whmcs\Application\Async\HandlerRegistry;
use Pagou\Whmcs\Application\Async\JobPriority;
use Pagou\Whmcs\Application\Async\OperationJob;
use Pagou\Whmcs\Application\Async\OperationOutbox;
use Pagou\Whmcs\Application\Async\OperationResult;
use Pagou\Whmcs\Application\Async\OperationScheduler;
use Pagou\Whmcs\Application\Async\OperationType;
use Pagou\Whmcs\Application\Async\OperationWorker;
use Pagou\Whmcs\Application\Async\SystemAsyncClock;
use Pagou\Whmcs\Application\Async\SystemRandomSource;
use Pagou\Whmcs\Application\Async\WorkerBudget;
use Pagou\Whmcs\Application\Async\WorkerReport;
use Pagou\Whmcs\Infrastructure\Http\ApiClientConfig;
use Pagou\Whmcs\Infrastructure\Http\BoletoHttpClientAdapter;
use Pagou\Whmcs\Infrastructure\Http\PdfCurlHttpClient;
use Pagou\Whmcs\Infrastructure\Http\PixHttpClientAdapter;
use Pagou\Whmcs\Infrastructure\Http\SafeCurlApiClient;
use Pagou\Whmcs\Infrastructure\Whmcs\NativeWhmcsFinancialPort;
use Pagou\Whmcs\Infrastructure\Whmcs\PrivateStorage;
use Pagou\Whmcs\Payment\Boleto\Api\BoletoClient;
use Pagou\Whmcs\Payment\Boleto\Api\BoletoPayloadMapper;
use Pagou\Whmcs\Payment\Boleto\Api\BoletoResponseMapper;
use Pagou\Whmcs\Payment\Boleto\Api\CreateBoletoRequest;
use Pagou\Whmcs\Payment\Boleto\Application\BoletoEmissionHandler;
use Pagou\Whmcs\Payment\Boleto\Application\BoletoPdfCacheHandler;
use Pagou\Whmcs\Payment\Boleto\Application\PdfFetchPolicy;
use Pagou\Whmcs\Payment\Boleto\Application\SecurePdfFetcher;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoArtifacts;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;
use Pagou\Whmcs\Payment\Boleto\Infrastructure\OperationOutboxJobQueue;
use Pagou\Whmcs\Payment\Boleto\Infrastructure\PdoBoletoAttemptRepository;
use Pagou\Whmcs\Payment\Boleto\Infrastructure\PrivatePdfStorage;
use Pagou\Whmcs\Payment\Ledger\EconomicPaymentKey;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\OutboxFollowUpQueue;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\PdoInvoiceClientResolver;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\PdoLedgerRepository;
use Pagou\Whmcs\Payment\Ledger\Infrastructure\PdoReconciliationFindingRepository;
use Pagou\Whmcs\Payment\Ledger\ReceivedPayment;
use Pagou\Whmcs\Payment\Ledger\ReceivedPaymentApplier;
use Pagou\Whmcs\Payment\Pix\Dto\CreateDuePixRequest;
use Pagou\Whmcs\Payment\Pix\Dto\CreatePixRequest;
use Pagou\Whmcs\Payment\Pix\Dto\RefundPixRequest;
use Pagou\Whmcs\Payment\Pix\Exception\PixApiException;
use Pagou\Whmcs\Payment\Pix\Exception\PixUncertainOperation;
use Pagou\Whmcs\Payment\Pix\Infrastructure\OutboxReconciliationScheduler;
use Pagou\Whmcs\Payment\Pix\Infrastructure\PdoOperationJournal;
use Pagou\Whmcs\Payment\Pix\Mapper\PixPayloadMapper;
use Pagou\Whmcs\Payment\Pix\Mapper\PixResponseMapper;
use Pagou\Whmcs\Payment\Pix\PagouPixClient;
use Pagou\Whmcs\Payment\Pix\PixPaymentService;
use Pagou\Whmcs\Payment\Pix\Value\CivilDate;
use Pagou\Whmcs\Payment\Pix\Value\Money;
use Pagou\Whmcs\Support\Uuid;

/** Composition root used by the thin WHMCS entrypoints. */
final class WhmcsRuntime
{
    /** How long a held Invoice Created waits for its boleto before going out without it. */
    private const INVOICE_EMAIL_HOLD_SECONDS = 1800;

    /** @var Closure(string, array<string, mixed>): array<string, mixed> */
    private readonly Closure $localApi;
    private readonly PaymentAttemptStore $attempts;
    private readonly OperationScheduler $scheduler;
    /** @var Closure(string, string): (\Pagou\Whmcs\Payment\Pix\Dto\PixCharge|\Pagou\Whmcs\Payment\Boleto\Domain\BoletoCharge)|null */
    private readonly ?Closure $paymentLookup;

    /**
     * @param callable(string, array<string, mixed>): array<string, mixed> $localApi
     * @param (callable(string, string): (\Pagou\Whmcs\Payment\Pix\Dto\PixCharge|\Pagou\Whmcs\Payment\Boleto\Domain\BoletoCharge))|null $paymentLookup
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly AddonSettings $settings,
        private readonly OperationOutbox $outbox,
        callable $localApi,
        ?callable $paymentLookup = null,
    ) {
        $this->localApi = Closure::fromCallable($localApi);
        $this->attempts = new PaymentAttemptStore($pdo);
        $this->scheduler = new OperationScheduler($outbox, new SystemAsyncClock());
        $this->paymentLookup = $paymentLookup === null ? null : Closure::fromCallable($paymentLookup);
    }

    public function scheduleInvoice(int $invoiceId): bool
    {
        $invoice = $this->api('GetInvoice', ['invoiceid' => $invoiceId]);
        $gateway = strtolower((string) ($invoice['paymentmethod'] ?? ''));
        $method = match ($gateway) {
            'pagou_pix' => 'pix',
            'pagou_boleto' => 'boleto',
            default => null,
        };
        $invoiceStatus = strtolower(trim((string) ($invoice['status'] ?? '')));
        if (!in_array($invoiceStatus, ['draft', 'unpaid', 'overdue'], true)) {
            $this->scheduleCancellations($this->attempts->supersedeInvoice($invoiceId), null, $invoiceId);

            return false;
        }
        if ($method === null) {
            // Another gateway was chosen: charges already sent to the customer stay
            // payable while they match the invoice, until it is paid or closed.
            $this->retireOutdatedCharges($invoiceId, '', $invoice, $this->civilDate((string) ($invoice['duedate'] ?? '')));

            return false;
        }
        $baseInvoice = $invoice;
        $invoice = $this->applyConfiguredFee($invoice, $method);
        if ($method === 'pix') {
            $this->retireExpiredPix($invoiceId);
        }

        $amount = \Pagou\Whmcs\Domain\Money::fromDecimal((string) ($invoice['balance'] ?? $invoice['total'] ?? '0'));
        if (!$amount->isPositive()) {
            return false;
        }
        if (!$this->amountAllowed($method, $amount->centavos())) {
            return false;
        }
        $clientId = (int) ($invoice['userid'] ?? 0);
        $dueDate = $this->civilDate((string) ($invoice['duedate'] ?? ''));
        $attempt = $this->attempts->ensureCurrent(
            $invoiceId,
            $clientId,
            $method,
            $amount->centavos(),
            $dueDate,
        );
        $this->retireOutdatedCharges($invoiceId, $method, $baseInvoice, $dueDate);
        if (!$attempt['created']) {
            return false;
        }

        if ($attempt['superseded'] !== []) {
            $this->scheduleCancellations($attempt['superseded'], $attempt['id'], $invoiceId);

            return true;
        }

        if ($method === 'pix') {
            return $this->issuePixSynchronously($attempt['id']);
        }

        return $this->scheduleAttemptIssuance($attempt['id'], $invoiceId, $method, $attempt['revision']);
    }

    /**
     * A charge of another method stays payable only while it still matches what
     * that method would charge now; a changed amount or due date retires it.
     * @param array<string, mixed> $invoice The invoice before any method fee.
     */
    private function retireOutdatedCharges(int $invoiceId, string $method, array $invoice, ?string $dueDate): void
    {
        $outdated = [];
        foreach ($this->attempts->activeOtherMethods($invoiceId, $method) as $other) {
            $request = json_decode((string) ($other['request_json'] ?? '{}'), true);
            $expected = $this->expectedChargeCents($invoice, (string) $other['method']);
            if ((int) $other['amount_cents'] !== $expected || (is_array($request) ? ($request['due_date'] ?? null) : null) !== $dueDate) {
                $outdated[] = (string) $other['id'];
            }
        }
        if ($outdated !== []) {
            $this->scheduleCancellations($this->attempts->supersedeAttempts($invoiceId, $outdated), null, $invoiceId);
        }
    }

    public function configuredAmountForInvoice(int $invoiceId, string $method): \Pagou\Whmcs\Domain\Money
    {
        if (!in_array($method, ['pix', 'boleto', 'card'], true)) {
            throw new \InvalidArgumentException('Meio de pagamento Pagou inválido.');
        }
        $invoice = $this->api('GetInvoice', ['invoiceid' => $invoiceId]);
        $invoice = $this->applyConfiguredFee($invoice, $method);
        $amount = \Pagou\Whmcs\Domain\Money::fromDecimal((string) ($invoice['balance'] ?? $invoice['total'] ?? '0'));
        if (!$amount->isPositive() || !$this->amountAllowed($method, $amount->centavos())) {
            throw new \RuntimeException($this->amountLimitMessage($method));
        }

        return $amount;
    }

    public function assertCardInvoice(int $invoiceId, int $clientId, ?int $expectedCents = null): \Pagou\Whmcs\Domain\Money
    {
        $invoice = $this->api('GetInvoice', ['invoiceid' => $invoiceId]);
        if (
            (int) ($invoice['userid'] ?? 0) !== $clientId || $clientId < 1
            || strtolower((string) ($invoice['status'] ?? '')) !== 'unpaid'
            || ($invoice['paymentmethod'] ?? '') !== 'pagou_creditcard'
        ) {
            throw new \LogicException('A fatura não está disponível para pagamento por este cartão.');
        }
        $amount = $this->configuredAmountForInvoice($invoiceId, 'card');
        if ($expectedCents !== null && $amount->centavos() !== $expectedCents) {
            throw new \LogicException('O valor da fatura mudou. Atualize a página antes de pagar.');
        }
        return $amount;
    }

    /** @param array<string, mixed> $params */
    public function renderInvoice(array $params, string $method): string
    {
        $invoiceId = (int) ($params['invoiceid'] ?? 0);
        if ($invoiceId < 1) {
            return $this->message('Não foi possível identificar a fatura.');
        }
        $invoiceAmount = \Pagou\Whmcs\Domain\Money::fromDecimal((string) ($params['amount'] ?? '0'));
        if ($invoiceAmount->isPositive() && !$this->amountAllowed($method, $invoiceAmount->centavos())) {
            return $this->message($this->amountLimitMessage($method));
        }
        $invoiceQuery = $this->pdo->prepare('SELECT status FROM tblinvoices WHERE id = :id LIMIT 1');
        $invoiceQuery->execute(['id' => $invoiceId]);
        $invoiceState = strtolower((string) ($invoiceQuery->fetchColumn() ?: 'unpaid'));
        $display = (new ClientPaymentStatus($this->pdo))->display($invoiceId, $method, $invoiceState);
        $state = $this->safe((string) ($display['state'] ?? 'pending'));
        $label = $this->stateLabel($state, $method);
        if ($method === 'pix' && $state === 'pending' && !empty($display['copyPaste'])) {
            $label = 'Aguardando pagamento';
        }
        $amount = $this->safe((string) ($display['amount'] ?? $params['amount'] ?? ''));
        $progress = $method === 'boleto' && $invoiceState === 'unpaid'
            && (in_array($state, ['queued', 'pending', 'awaiting_registration'], true)
                || (empty($display['remoteId']) && in_array($state, ['ready', 'active'], true)));
        $statusUrl = 'modules/addons/pagou_payments/status.php?invoice=' . $invoiceId
            . '&method=' . rawurlencode($method);
        $html = $this->clientAssets()
            . '<section class="pagou-payment pagou-payment--' . $this->safe($method) . '"'
            . ' data-pagou-status-url="' . $this->safe($statusUrl) . '"'
            . ($progress ? ' data-pagou-progress-token="' . InvoiceProgressToken::issue($invoiceId) . '"' : '')
            . ' data-pagou-invoice-state="' . $this->safe($invoiceState) . '"'
            . ' data-pagou-state="' . $state . '" data-pagou-revision="' . hash('sha256', json_encode($display, JSON_THROW_ON_ERROR)) . '">';
        if (in_array($state, PaymentAttemptStore::RESULT_STATES, true)) {
            return $html . \Pagou\Whmcs\Presentation\PaymentResultView::render($state, $method, $invoiceState) . '</section>';
        }
        $html .= '<header class="pagou-payment__header"><strong>' . ($method === 'pix' ? 'Pix' : 'Boleto') . '</strong>'
            . '<span class="pagou-status">' . $this->safe($label) . '</span></header>';
        if (in_array($state, PaymentAttemptStore::CLOSED_STATES, true)) {
            // A closed charge shows no amount, due date or payment data.
            $message = (string) ($display['message'] ?? 'Não efetue um novo pagamento com os dados desta cobrança.');
            $html .= '<p class="pagou-help" data-pagou-connection role="status" aria-live="polite" hidden></p>'
                . '<p role="status">' . $this->safe($message) . '</p>';
            if ($invoiceState === 'unpaid') {
                $html .= '<p class="pagou-help">Se uma nova cobrança for emitida, ela aparecerá automaticamente nesta página.</p>';
            }

            return $html . '</section>';
        }
        $nativeAmount = number_format($invoiceAmount->centavos() / 100, 2, ',', '.');
        if ($amount !== '' && $amount !== $nativeAmount && $amount !== $invoiceAmount->jsonSerialize()) {
            $html .= '<p class="pagou-amount"><span>Valor da cobrança</span><strong>R$ ' . $amount . '</strong></p>';
        }
        $civilDue = (string) ($display['dueDate'] ?? '');
        $due = $civilDue !== '' ? $civilDue : (string) ($display['expiresAt'] ?? '');
        if ($due !== '') {
            try {
                $date = new \DateTimeImmutable($due, new \DateTimeZone('UTC'));
                $formatted = $civilDue !== '' ? $date->format('d/m/Y') : $date->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i');
                $html .= '<p class="pagou-due">Vencimento: ' . $this->safe($formatted) . '</p>';
            } catch (\Throwable) {
                // An unreadable date is never fabricated.
            }
        }
        $html .= '<p class="pagou-help" data-pagou-connection role="status" aria-live="polite" hidden></p>';
        if ($method === 'pix') {
            $html .= $this->pixArtifacts($display);
            if ($this->settings->boolean('pix_show_notes') && $this->settings->string('pix_notes') !== '') {
                $html .= '<p class="pagou-notes">' . nl2br($this->safe($this->settings->string('pix_notes'))) . '</p>';
            }
        } else {
            $html .= $this->boletoArtifacts($display);
            if ($this->settings->boolean('boleto_show_notes') && $this->settings->string('boleto_notes') !== '') {
                $html .= '<p class="pagou-notes">' . nl2br($this->safe($this->settings->string('boleto_notes'))) . '</p>';
            }
        }

        $help = $method === 'pix'
            ? 'A confirmação do pagamento aparecerá automaticamente nesta página.'
            : 'O boleto e a confirmação do pagamento serão atualizados automaticamente nesta página.';

        return $html . '<p class="pagou-help">' . $help . '</p></section>';
    }

    public function runWorker(?string $workerId = null): WorkerReport
    {
        try {
            (new PeriodicReconciliation($this->pdo, $this->scheduler))->schedule();
        } catch (\Throwable $exception) {
            if (function_exists('logActivity')) {
                logActivity('Pagou Payments: agendamento periódico indisponível. ' . $exception::class);
            }
            // Existing issuance/confirmation jobs must still be processed.
        }
        $worker = new OperationWorker(
            $this->outbox,
            $this->operationHandlers(),
            new ExponentialBackoff(new SystemRandomSource(), 5, 900, 8, 20),
            new SystemAsyncClock(),
        );

        $report = $worker->run(
            $workerId ?? 'whmcs-' . getmypid(),
            new WorkerBudget(
                $this->settings->integer('worker_max_jobs', 25),
                $this->settings->integer('worker_max_seconds', 20),
                45,
            ),
        );
        $this->recordWorkerHeartbeat();
        try {
            $this->runRetentionIfDue();
        } catch (\Throwable) {
            // Maintenance never changes the result of payment processing.
        }
        try {
            $this->paymentApplier()->settleInterrupted(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\Throwable) {
            // Interrupted applications are settled by a later worker run.
        }
        try {
            (new NativeReceiptRepair(
                $this->pdo,
                $this->localApi,
                fn (string $remoteId): ?int => $this->providerFee($this->pixService()->get($remoteId)->raw['fee'] ?? null),
            ))->run();
        } catch (\Throwable) {
            // The one-time correction is retried by a later worker run.
        }
        try {
            (new PartialRefundFees($this->pdo, $this->localApi))->correct();
        } catch (\Throwable) {
            // A refund fee left by WHMCS is cleared by a later worker run.
        }

        return $report;
    }

    /**
     * Called after a verified notification was acknowledged: advances its invoice now,
     * instead of waiting for the next cron run. Unlinked deliveries stay with the worker.
     */
    public function advanceWebhook(string $deliveryKey): ?WorkerReport
    {
        $statement = $this->pdo->prepare(
            'SELECT a.invoice_id FROM pagou_webhook_deliveries w INNER JOIN pagou_payment_attempts a ON a.id = w.attempt_id '
            . 'WHERE w.event_key = :event_key AND w.signature_valid = 1 LIMIT 1'
        );
        $statement->execute(['event_key' => $deliveryKey]);
        $invoiceId = (int) $statement->fetchColumn();

        return $invoiceId > 0 ? $this->advanceInvoice($invoiceId) : null;
    }

    /** Advances only the selected invoice immediately, using the same fenced operations as the worker. */
    public function advanceInvoice(int $invoiceId): WorkerReport
    {
        $outbox = new \Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox($this->pdo, $invoiceId);
        $clock = new SystemAsyncClock();
        $outbox->expeditePending($clock->now());
        $worker = new OperationWorker(
            $outbox,
            $this->operationHandlers(),
            new ExponentialBackoff(new SystemRandomSource(), 5, 900, 8, 20),
            $clock,
            2,
        );

        return $worker->run('invoice-' . $invoiceId . '-' . getmypid(), new WorkerBudget(3, 8, 45));
    }

    private function operationHandlers(): HandlerRegistry
    {
        return new HandlerRegistry([
            new RuntimeOperationHandler(OperationType::IssuePix, fn (OperationJob $job): HandlerOutcome => $this->issuePix($job)),
            new RuntimeOperationHandler(OperationType::IssueBoleto, fn (OperationJob $job): HandlerOutcome => $this->issueBoleto($job)),
            new RuntimeOperationHandler(OperationType::ReconcilePayment, fn (OperationJob $job): HandlerOutcome => $this->reconcile($job)),
            new RuntimeOperationHandler(OperationType::ReconcileUncertainOperation, fn (OperationJob $job): HandlerOutcome => $this->reconcile($job)),
            new RuntimeOperationHandler(OperationType::FetchBoletoPdf, fn (OperationJob $job): HandlerOutcome => $this->fetchBoletoPdf($job)),
            new RuntimeOperationHandler(OperationType::DeliverInvoiceEmail, fn (OperationJob $job): HandlerOutcome => $this->deliverInvoiceEmail($job)),
            new RuntimeOperationHandler(OperationType::CancelPix, fn (OperationJob $job): HandlerOutcome => $this->cancelPix($job)),
            new RuntimeOperationHandler(OperationType::CancelBoleto, fn (OperationJob $job): HandlerOutcome => $this->cancelBoleto($job)),
            new RuntimeOperationHandler(OperationType::ReplaceBoleto, fn (OperationJob $job): HandlerOutcome => $this->confirmBoletoCancellation($job)),
        ]);
    }

    public function schedulePendingReconciliation(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $statement = $this->pdo->query(
            "SELECT id, method, remote_id FROM pagou_payment_attempts "
            . "WHERE remote_id IS NOT NULL AND remote_id <> '' AND status IN ('queued', 'pending', 'ready', 'paid', 'awaiting_registration', 'uncertain') "
            . 'ORDER BY updated_at ASC LIMIT ' . $limit
        );
        if ($statement === false) {
            return 0;
        }
        $scheduled = 0;
        while (($attempt = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $attemptId = (string) $attempt['id'];
            $remoteId = (string) ($attempt['remote_id'] ?? '');
            if ($remoteId === '') {
                continue;
            }
            $queued = $this->scheduler->schedule(
                Uuid::v4(),
                OperationType::ReconcilePayment,
                'manual:reconcile:' . $attemptId . ':' . intdiv(time(), 60),
                JobPriority::FinancialRecovery,
                ['attempt_id' => $attemptId, 'method' => (string) $attempt['method'], 'remote_id' => $remoteId],
            );
            $scheduled += $queued ? 1 : 0;
        }

        return $scheduled;
    }

    public function resumeRecoverableOperations(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $statement = $this->pdo->query(
            "SELECT id, payload_json FROM pagou_payment_operations "
            . "WHERE status = 'failed' AND operation_type IN ('issue_pix', 'issue_boleto') "
            . 'ORDER BY updated_at ASC LIMIT ' . $limit
        );
        if ($statement === false) {
            return 0;
        }
        $resumed = 0;
        while (($operation = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $payload = $this->decode((string) ($operation['payload_json'] ?? ''));
            $attemptId = is_string($payload['attempt_id'] ?? null) ? $payload['attempt_id'] : '';
            $attempt = $attemptId === '' ? null : $this->attempts->find($attemptId);
            if ($attempt === null || (string) ($attempt['status'] ?? '') !== 'queued' || (string) ($attempt['remote_id'] ?? '') !== '') {
                continue;
            }
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $update = $this->pdo->prepare(
                "UPDATE pagou_payment_operations SET status = 'retrying', attempt_id = :attempt_id, "
                . 'available_at = :available_at, lease_token = NULL, lease_expires_at = NULL, '
                . 'error_code = NULL, error_message = NULL, retry_after_utc = NULL, finished_at = NULL, '
                . "updated_at = :updated_at, version = version + 1 WHERE id = :id AND status = 'failed'"
            );
            $update->execute([
                'attempt_id' => $attemptId,
                'available_at' => $now,
                'updated_at' => $now,
                'id' => (string) $operation['id'],
            ]);
            $resumed += $update->rowCount();
        }

        $pdfOperations = $this->pdo->query(
            "SELECT id, payload_json FROM pagou_payment_operations "
            . "WHERE status IN ('failed', 'succeeded') AND operation_type = 'fetch_boleto_pdf' "
            . 'ORDER BY updated_at ASC LIMIT ' . $limit
        );
        if ($pdfOperations === false) {
            return $resumed;
        }
        while (($operation = $pdfOperations->fetch(PDO::FETCH_ASSOC)) !== false) {
            $payload = $this->decode((string) ($operation['payload_json'] ?? ''));
            $attemptId = is_string($payload['attempt_id'] ?? null) ? $payload['attempt_id'] : '';
            $attempt = $attemptId === '' ? null : $this->attempts->find($attemptId);
            if (
                $attempt === null
                || (string) ($attempt['method'] ?? '') !== 'boleto'
                || trim((string) ($attempt['remote_id'] ?? '')) === ''
            ) {
                continue;
            }
            $repository = new PdoBoletoAttemptRepository($this->pdo, (int) $attempt['client_id']);
            $boleto = $repository->get($attemptId);
            if ($boleto === null) {
                continue;
            }
            $boleto = $this->recoverLegacyBoletoArtifacts($boleto, $attempt);
            if ($boleto->artifacts === null || $boleto->artifacts->localPdfKey !== null) {
                continue;
            }
            $boleto = $this->withPdfUrl($boleto);
            if ($boleto->artifacts?->pdfUrl === null) {
                continue;
            }
            $repository->save($boleto);
            $this->projectBoleto($boleto);
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $update = $this->pdo->prepare(
                "UPDATE pagou_payment_operations SET status = 'retrying', attempt_id = :attempt_id, attempts = 0, "
                . 'available_at = :available_at, lease_token = NULL, lease_expires_at = NULL, '
                . 'error_code = NULL, error_message = NULL, retry_after_utc = NULL, finished_at = NULL, '
                . "updated_at = :updated_at, version = version + 1 WHERE id = :id AND status IN ('failed', 'succeeded')"
            );
            $update->execute([
                'attempt_id' => $attemptId,
                'available_at' => $now,
                'updated_at' => $now,
                'id' => (string) $operation['id'],
            ]);
            $resumed += $update->rowCount();
        }

        $cancellationConfirmations = $this->pdo->query(
            "SELECT id, attempt_id, payload_json FROM pagou_payment_operations "
            . "WHERE status = 'failed' AND operation_type = 'replace_boleto' "
            . "AND (error_code = 'boleto_cancel_confirmation_invalid' "
            . "OR error_message = 'boleto_cancel_confirmation_invalid') "
            . 'ORDER BY updated_at ASC LIMIT ' . $limit
        );
        if ($cancellationConfirmations === false) {
            return $resumed;
        }
        while (($operation = $cancellationConfirmations->fetch(PDO::FETCH_ASSOC)) !== false) {
            $payload = $this->decode((string) ($operation['payload_json'] ?? ''));
            $attemptId = is_string($payload['attempt_id'] ?? null)
                ? $payload['attempt_id']
                : (string) ($operation['attempt_id'] ?? '');
            $attempt = $attemptId === '' ? null : $this->attempts->find($attemptId);
            $invoiceId = (int) ($attempt['invoice_id'] ?? 0);
            if ($attempt === null || $invoiceId < 1 || trim((string) ($attempt['remote_id'] ?? '')) === '') {
                continue;
            }
            $payload['attempt_id'] = $attemptId;
            $payload['invoice_id'] = $invoiceId;
            $payload['remote_id'] = (string) $attempt['remote_id'];
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $update = $this->pdo->prepare(
                "UPDATE pagou_payment_operations SET status = 'retrying', payload_json = :payload_json, "
                . 'available_at = :available_at, lease_token = NULL, lease_expires_at = NULL, attempts = 0, '
                . 'error_code = NULL, error_message = NULL, retry_after_utc = NULL, finished_at = NULL, '
                . "updated_at = :updated_at, version = version + 1 WHERE id = :id AND status = 'failed'"
            );
            $update->execute([
                'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'available_at' => $now,
                'updated_at' => $now,
                'id' => (string) $operation['id'],
            ]);
            $resumed += $update->rowCount();
        }

        return $resumed;
    }

    public function closePaidInvoice(int $invoiceId): int
    {
        if ($invoiceId < 1) {
            return 0;
        }
        $attempts = $this->attempts->supersedeInvoice($invoiceId);
        $this->scheduleCancellations($attempts, null, $invoiceId);

        return count($attempts);
    }

    public function requestInvoiceCancellation(int $invoiceId, string $reason, int $actorId, bool $replace = false): bool
    {
        $reason = trim($reason);
        if ($invoiceId < 1 || $actorId < 1 || strlen($reason) < 5 || strlen($reason) > 500) {
            throw new \InvalidArgumentException('Informe a fatura e um motivo com pelo menos cinco caracteres.');
        }
        // The charge shown on the invoice card, the one of the invoice's method.
        $current = match ((string) ($this->invoiceRow($invoiceId)['paymentmethod'] ?? '')) {
            'pagou_pix' => 'pix',
            'pagou_boleto' => 'boleto',
            default => null,
        };
        $attempt = $current === null ? $this->attempts->latestForInvoiceAnyMethod($invoiceId) : $this->attempts->latestForInvoice($invoiceId, $current);
        if ($attempt === null || in_array((string) $attempt['status'], ['paid', 'cancelled', 'canceled', 'refunded'], true)) {
            throw new \LogicException('A fatura não possui uma cobrança ativa que possa ser cancelada.');
        }
        if (!$replace) {
            // Cancelling the invoice charge leaves no other Pagou charge payable.
            $others = array_map(static fn (array $row): string => (string) $row['id'], $this->attempts->activeOtherMethods($invoiceId, (string) $attempt['method']));
            if ($others !== []) {
                $this->scheduleCancellations($this->attempts->supersedeAttempts($invoiceId, $others), null, $invoiceId);
            }
        }
        if ($replace && (string) $attempt['method'] !== 'boleto') {
            throw new \LogicException('Somente um boleto pode ser substituído por esta operação.');
        }

        $attemptId = (string) $attempt['id'];
        $remoteId = is_string($attempt['remote_id'] ?? null) ? trim($attempt['remote_id']) : '';
        if ($remoteId === '') {
            $this->attempts->markStatus($attemptId, 'superseded');
            $this->audit($actorId, $replace ? 'boleto.replace_requested' : 'payment.cancel_requested', $attemptId, $reason);

            return $replace ? $this->scheduleInvoice($invoiceId) : true;
        }

        $this->attempts->markStatus($attemptId, 'cancel_requested');
        $type = (string) $attempt['method'] === 'pix' ? OperationType::CancelPix : OperationType::CancelBoleto;
        $scheduled = $this->scheduler->schedule(
            Uuid::v4(),
            $type,
            sprintf('%s:cancel:%s:%s', (string) $attempt['method'], $attemptId, hash('sha256', $remoteId)),
            JobPriority::FinancialRecovery,
            [
                'attempt_id' => $attemptId,
                'invoice_id' => $invoiceId,
                'remote_id' => $remoteId,
                'replace' => $replace,
            ],
        );
        $this->audit($actorId, $replace ? 'boleto.replace_requested' : 'payment.cancel_requested', $attemptId, $reason);

        return $scheduled;
    }

    /** @return array<string,mixed> */
    public function refundPix(int $invoiceId, string $transactionId, int $amountCents, int $actorId): array
    {
        try {
            $this->pixRefunds()->request($invoiceId, $transactionId, $amountCents, $actorId);
        } catch (\DomainException $error) {
            // Business rules explain themselves on the invoice and in the gateway log.
            return ['status' => 'declined', 'declinereason' => $error->getMessage(), 'rawdata' => ['result' => 'refund_requires_review', 'reason' => $error->getMessage()]];
        }
        $attempt = (new \Pagou\Whmcs\Payment\Ledger\Infrastructure\PaymentTransactionResolver($this->pdo))->resolve($invoiceId, $transactionId, 'pix');
        $this->refreshPixRefund($invoiceId, (string) $attempt['id']);
        return $this->pixRefunds()->authorizeNative((string) $attempt['id']);
    }

    /** Preparation for the existing WHMCS Refund form. Native options remain in that form.
     * @return array<string,mixed> */
    public function nativePixRefund(int $invoiceId, int $accountId, string $action): array
    {
        $native = new \Pagou\Whmcs\Infrastructure\Whmcs\NativePixRefundPort($this->pdo);
        $receipt = $native->receipt($invoiceId, $accountId);
        if ($receipt === null) {
            return ['supported' => false];
        }
        $transactionId = (string) $receipt['transid'];
        $attempt = (new \Pagou\Whmcs\Payment\Ledger\Infrastructure\PaymentTransactionResolver($this->pdo))->resolve($invoiceId, $transactionId, 'pix');
        $native->originalAmount($invoiceId, $transactionId, (int) $attempt['client_id']);
        if ($action === 'refresh') {
            $this->refreshPixRefund($invoiceId, (string) $attempt['id']);
        } elseif ($action !== 'read') {
            throw new \InvalidArgumentException('Ação inválida.');
        }
        $row = (new \Pagou\Whmcs\Payment\Pix\Infrastructure\PdoPixRefundStore($this->pdo))->find((string) $attempt['id']);
        $result = ['supported' => true, 'refundStatus' => $row['status'] ?? 'available',
            'ready' => ($row['status'] ?? '') === 'confirmed' && empty($row['native_dispatch_at'])];
        if ($row !== null) {
            // The Refund form must carry the requested amount; WHMCS would otherwise default to the full receipt.
            $cents = (int) $row['amount_cents'];
            $result['amount'] = intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
            $result['amountLabel'] = 'R$ ' . number_format($cents / 100, 2, ',', '.');
        }

        return $result;
    }

    public function confirmNativePixRefunds(int $invoiceId): void
    {
        foreach ($this->pixRefunds()->confirmNative($invoiceId) as $refund) {
            $state = (int) $refund['amount_cents'] < (int) $refund['receipt_cents'] ? 'paid' : 'refunded';
            $this->attempts->complete((string) $refund['attempt_id'], (string) $refund['remote_id'], $state, ['state' => $state, 'remoteId' => $refund['remote_id']]);
        }
        try {
            (new PartialRefundFees($this->pdo, $this->localApi))->correct($invoiceId);
        } catch (\Throwable) {
            // The worker clears the fee later; the refund itself is already recorded.
        }
    }

    /** Schedule a read-only lookup of the existing refund, never another debit. */
    public function refreshPixRefund(int $invoiceId, ?string $attemptId = null): void
    {
        $attempt = $attemptId === null ? $this->attempts->latestForInvoice($invoiceId, 'pix') : $this->attempts->find($attemptId);
        if ($attempt === null || (int) $attempt['invoice_id'] !== $invoiceId || $attempt['method'] !== 'pix') {
            return;
        }
        $refund = (new \Pagou\Whmcs\Payment\Pix\Infrastructure\PdoPixRefundStore($this->pdo))->find((string) $attempt['id']);
        if ($refund === null || in_array($refund['status'], ['applied', 'rejected', 'review'], true)) {
            return;
        }
        // Share the durable outbox and its leases with cron and webhook processing.
        $busy = $this->pdo->prepare("SELECT 1 FROM pagou_payment_operations WHERE attempt_id = ? AND operation_type LIKE 'reconcile%' AND status IN ('queued','pending','leased','retrying') LIMIT 1");
        $busy->execute([$attempt['id']]);
        if ($busy->fetchColumn() === false) {
            $this->scheduler->schedule(
                Uuid::v4(),
                OperationType::ReconcilePayment,
                'pix:refund:check:' . $refund['id'] . ':' . intdiv(time(), 15),
                JobPriority::FinancialRecovery,
                ['attempt_id' => (string) $attempt['id'], 'invoice_id' => $invoiceId, 'method' => 'pix', 'remote_id' => (string) $attempt['remote_id']]
            );
        }
        $this->advanceInvoice($invoiceId);
    }

    private function pixRefunds(): PixRefundRuntime
    {
        return new PixRefundRuntime($this->pdo, fn (RefundPixRequest $request) => $this->pixService()->refund($request));
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public function emailPreSend(array $vars): array
    {
        return $this->emailPaymentFields($vars) + $this->emailPresentation($vars);
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function emailPresentation(array $vars): array
    {
        $invoiceId = (int) ($vars['relid'] ?? 0);
        if ($invoiceId < 1 || !$this->isInvoiceEmail((string) ($vars['messagename'] ?? ''))) {
            return [];
        }
        $invoice = $this->invoiceRow($invoiceId);
        $method = match ($invoice['paymentmethod'] ?? '') {
            'pagou_pix' => 'pix', 'pagou_boleto' => 'boleto', default => null,
        };
        if ($method === null) {
            return [];
        }
        // Always override the browser gateway block, including disabled/paid instructions.
        if (($invoice['status'] ?? '') !== 'Unpaid' || !$this->settings->boolean($method . '_email_details', true)) {
            return ['invoice_payment_link' => ''];
        }
        $attempt = $this->attempts->latestForInvoice($invoiceId, $method);
        if ($attempt !== null && in_array($attempt['status'] ?? '', [...PaymentAttemptStore::CLOSED_STATES, 'paid', 'refunded'], true)) {
            return ['invoice_payment_link' => ''];
        }
        $display = $this->attempts->displayForInvoice($invoiceId, $method);
        $pdf = '';
        $qr = $method === 'pix' && $attempt !== null ? $this->pixQrUrl((string) $attempt['id']) : '';
        $boletoUrl = $method === 'boleto' && $attempt !== null ? $this->publicBoletoUrl((string) ($attempt['remote_id'] ?? '')) : '';
        if ($method === 'boleto' && $attempt !== null) {
            $boleto = (new PdoBoletoAttemptRepository($this->pdo, (int) $attempt['client_id']))->get((string) $attempt['id']);
            if ($boleto?->artifacts?->localPdfKey !== null) {
                $pdf = $this->absoluteModuleUrl('modules/addons/pagou_payments/download.php?attempt=' . rawurlencode($boleto->id));
            }
            if ($boleto !== null) {
                $display = ['amount' => number_format($boleto->amountCentavos / 100, 2, ',', '.'), 'dueDate' => $boleto->dueDate,
                    'digitableLine' => $boleto->artifacts->digitableLine ?? ''];
                $boletoUrl = $this->publicBoletoUrl((string) ($boleto->remoteId ?? $attempt['remote_id'] ?? ''));
            }
        }
        $invoiceUrl = $this->absoluteModuleUrl('viewinvoice.php?id=' . $invoiceId);

        $icon = $boletoUrl === '' ? '' : $this->absoluteModuleUrl('modules/addons/pagou_payments/assets/email/barcode-white.png');

        return ['invoice_payment_link' => EmailPaymentView::render($method, $display, $invoiceUrl, $pdf, $qr, $boletoUrl, $icon)];
    }

    /** Signed address of the QR image, only while that Pix still has a payable image. */
    private function pixQrUrl(string $attemptId): string
    {
        $link = PixQrLink::fromWhmcs();
        if ($attemptId === '' || $link === null || !$this->settings->boolean('pix_show_qr', true) || (new PixQrImage($this->pdo))->find($attemptId) === null) {
            return '';
        }

        return $this->absoluteModuleUrl('modules/addons/pagou_payments/qr.php?' . $link->query($attemptId, time() + PixQrLink::LIFETIME));
    }

    /** The boleto page hosted by Pagou, which opens without a WHMCS login. */
    private function publicBoletoUrl(string $remoteId): string
    {
        return trim($remoteId) === '' ? '' : 'https://fatura.pagou.com.br/boleto/' . rawurlencode(trim($remoteId));
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function emailPaymentFields(array $vars): array
    {
        $invoiceId = (int) ($vars['relid'] ?? 0);
        if ($invoiceId < 1 || !$this->isInvoiceEmail((string) ($vars['messagename'] ?? ''))) {
            return [];
        }
        $invoice = $this->invoiceRow($invoiceId);
        if (($invoice['status'] ?? '') !== 'Unpaid') {
            return [];
        }
        $gateway = (string) ($invoice['paymentmethod'] ?? '');
        if ($gateway === 'pagou_pix') {
            if (!$this->settings->boolean('pix_email_details', true)) {
                return [];
            }
            $latest = $this->attempts->latestForInvoice($invoiceId, 'pix');
            if ($latest === null || PaymentAttemptStore::pixExpired($this->attempts->currentDisplayForInvoice($invoiceId, 'pix'), (string) ($latest['created_at'] ?? ''))) {
                // A reminder must carry a code that can still be paid.
                $this->scheduleInvoice($invoiceId);
            }
            // The newest attempt decides: a cancelled or replaced code is never emailed.
            $display = $this->attempts->currentDisplayForInvoice($invoiceId, 'pix');
            $open = !in_array((string) ($display['state'] ?? ''), [...PaymentAttemptStore::CLOSED_STATES, ...PaymentAttemptStore::RESULT_STATES], true);
            $newest = $this->attempts->latestForInvoice($invoiceId, 'pix');

            return [
                'pagou_pix_copy_paste' => $open ? (string) ($display['copyPaste'] ?? '') : '',
                // An address, not embedded data: email clients block images inside the message.
                'pagou_pix_qr_code' => $open && $newest !== null ? $this->pixQrUrl((string) $newest['id']) : '',
            ];
        }
        if ($gateway !== 'pagou_boleto' || !$this->settings->boolean('boleto_email_details', true)) {
            return [];
        }
        $attempt = $this->attempts->latestForInvoice($invoiceId, 'boleto');
        if ($attempt === null) {
            $this->scheduleInvoice($invoiceId);

            return $this->isInitialInvoiceEmail($vars)
                && $this->settings->string('boleto_email_pdf_mode', 'attach') === 'attach'
                && !$this->deliveryAllowsFallback($invoiceId)
                ? $this->holdInitialEmail($invoiceId) : [];
        }
        if (!$this->isCurrentBoletoDelivery($invoiceId, (string) $attempt['id'])) {
            return [];
        }
        $boleto = (new PdoBoletoAttemptRepository($this->pdo, (int) $attempt['client_id']))->get((string) $attempt['id']);
        if ($boleto === null) {
            return [];
        }
        $fields = [
            'pagou_boleto_line' => $boleto->artifacts->digitableLine ?? '',
            'pagou_boleto_url' => $this->publicBoletoUrl((string) ($boleto->remoteId ?? $attempt['remote_id'] ?? '')),
            'pagou_pix_copy_paste' => $boleto->artifacts->pixCopyPaste ?? '',
            'pagou_boleto_pdf_url' => $boleto->artifacts?->localPdfKey === null
                ? ''
                : $this->absoluteModuleUrl('modules/addons/pagou_payments/download.php?attempt=' . rawurlencode($boleto->id)),
        ];
        $pdfMode = $this->settings->string('boleto_email_pdf_mode', 'attach');
        if ($pdfMode === 'attach' && $boleto->artifacts?->localPdfKey !== null) {
            $storage = $this->privateStorage();
            if (defined('ROOTDIR') && (new \Pagou\Whmcs\InvoicePdf\IntegrationService($this->pdo, (string) constant('ROOTDIR')))->usesNativeAttachment()) {
                // The theme owns the invoice PDF in this mode, including its native fallback.
                // Do not add a second boleto PDF, regardless of WHMCS hook/render ordering.
                return $fields;
            }
            $fields['attachments'] = [[
                'filename' => 'boleto-fatura-' . $invoiceId . '.pdf',
                'data' => $storage->read($boleto->artifacts->localPdfKey),
            ]];

            return $fields;
        }

        if ($pdfMode === 'attach' && $this->isInitialInvoiceEmail($vars) && !$this->deliveryAllowsFallback($invoiceId)) {
            return $fields + $this->holdInitialEmail($invoiceId);
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    public function adminSummary(int $invoiceId): array
    {
        $invoice = $this->invoiceRow($invoiceId);
        $method = match ((string) ($invoice['paymentmethod'] ?? '')) {
            'pagou_pix' => 'pix',
            'pagou_boleto' => 'boleto',
            default => null,
        };
        if ($method === null) {
            return [];
        }

        $attempt = $this->attempts->latestForInvoice($invoiceId, $method);
        $display = $this->attempts->displayForInvoice($invoiceId, $method);
        if ($attempt !== null && (string) ($attempt['status'] ?? '') !== '') {
            $display['state'] = (string) $attempt['status'];
        } elseif ($attempt === null) {
            $amountCents = max(
                0,
                $this->decimalCents((string) ($invoice['total'] ?? '0'))
                - $this->decimalCents((string) ($invoice['credit'] ?? '0')),
            );
            if ($amountCents > 0 && !$this->amountAllowed($method, $amountCents)) {
                $display['state'] = 'unavailable';
            }
        }

        return $display + [
            'refund' => $method === 'pix' && $attempt !== null ? (new PixRefundView($this->pdo))->forAttempt($attempt) : [],
            'identities' => (new PaymentIdentityStore($this->pdo))->forInvoice($invoiceId, $method),
            'method' => $method,
            'attemptId' => (string) ($attempt['id'] ?? ''),
            'remoteId' => (string) ($attempt['remote_id'] ?? ''),
        ];
    }

    private function issuePix(OperationJob $job): HandlerOutcome
    {
        $attempt = $this->requiredAttemptFromJob($job);

        return $this->issuePixAttempt($attempt, $job);
    }

    private function issuePixSynchronously(string $attemptId): bool
    {
        $attempt = $this->attempts->find($attemptId);
        if ($attempt === null) {
            return false;
        }
        $outcome = $this->issuePixAttempt($attempt);
        if ($outcome->result === OperationResult::Succeeded) {
            return true;
        }
        if ($outcome->result === OperationResult::Uncertain) {
            $this->attempts->markStatus($attemptId, 'uncertain');

            return true;
        }
        $this->attempts->markStatus($attemptId, 'failed');

        return false;
    }

    /** @param array<string, mixed> $attempt */
    private function issuePixAttempt(array $attempt, ?OperationJob $job = null): HandlerOutcome
    {
        if ((string) $attempt['status'] === 'superseded') {
            return HandlerOutcome::succeeded();
        }
        try {
            $clientId = (int) $attempt['client_id'];
            if (!$this->validateLateCharges($attempt, 'pix')) {
                return HandlerOutcome::permanentFailure('late_charges_require_review');
            }
            $payer = $this->payer($clientId, false);
            $payer = (new PaymentIdentityStore($this->pdo))->snapshot((string) $attempt['id'], $payer);
            $invoiceId = (string) $attempt['invoice_id'];
            $request = $this->decode((string) ($attempt['request_json'] ?? ''));
            $dueDate = self::issuanceDueDate((string) ($request['due_date'] ?? ''));
            if ($this->settings->boolean('pix_due_enabled', false) && !$this->validateIssuanceDate($attempt, $dueDate)) {
                return HandlerOutcome::permanentFailure('due_date_requires_review');
            }
            $service = $this->pixService($job);
            if ($this->settings->boolean('pix_due_enabled', false)) {
                $applyLateCharges = $this->lateChargeAllowed('pix_due', $clientId);
                $charge = $service->createDue(new CreateDuePixRequest(
                    $invoiceId,
                    (string) $attempt['id'],
                    (string) $attempt['idempotency_key'],
                    new Money((int) $attempt['amount_cents']),
                    CivilDate::fromIso($this->civilDate($dueDate)),
                    ['name' => $payer['name'], 'document' => $payer['document']],
                    'Fatura WHMCS ' . $invoiceId,
                    $this->settings->integer('pix_due_expiration_days', 30),
                    [],
                    $this->callbackUrl(),
                    $applyLateCharges ? $this->pixAdjustment('pix_due_fine_type', 'pix_due_fine_amount') : null,
                    $applyLateCharges ? $this->pixAdjustment('pix_due_interest_type', 'pix_due_interest_amount') : null,
                ));
            } else {
                $expiration = $this->settings->integer('pix_expiration_seconds', 2592000);
                $charge = $service->create(new CreatePixRequest(
                    $invoiceId,
                    (string) $attempt['id'],
                    (string) $attempt['idempotency_key'],
                    new Money((int) $attempt['amount_cents']),
                    ['name' => $payer['name'], 'document' => $payer['document']],
                    'Fatura WHMCS ' . $invoiceId,
                    $expiration,
                    'whmcs-invoice-' . $invoiceId,
                    [],
                    $this->callbackUrl(),
                ));
            }
            $this->attempts->complete((string) $attempt['id'], $charge->id, $this->pixState($charge->status), [
                'state' => $this->pixState($charge->status),
                'amount' => number_format($charge->amountCents / 100, 2, ',', '.'),
                'copyPaste' => $charge->artifacts->copyPaste ?? '',
                'qrCodeImageUrl' => $this->qrImage($charge->artifacts->qrCodeImage ?? ''),
                // The creation response has no absolute expiry; it is known from the requested validity.
                'expiresAt' => $charge->expiresAt ?? (isset($expiration) ? gmdate('Y-m-d\TH:i:s\Z', time() + $expiration) : ''),
                'pdfValidUntil' => \Pagou\Whmcs\InvoicePdf\PixValidity::until($charge, (string) ($attempt['created_at'] ?? '')),
                'dueDate' => $charge->dueDate ?? '',
            ], $charge->split?->toArray());

            return HandlerOutcome::succeeded();
        } catch (PixUncertainOperation) {
            return HandlerOutcome::uncertain('pix_remote_outcome_unknown');
        } catch (PixApiException $exception) {
            return $exception->status >= 500 || $exception->status === 429
                ? HandlerOutcome::uncertain('pix_remote_failure')
                : HandlerOutcome::permanentFailure('pix_request_rejected');
        } catch (\Throwable $exception) {
            return HandlerOutcome::permanentFailure('pix_local_validation:' . $exception::class);
        }
    }

    private function issueBoleto(OperationJob $job): HandlerOutcome
    {
        $attempt = $this->requiredAttemptFromJob($job);
        if ((string) $attempt['status'] === 'superseded') {
            return HandlerOutcome::succeeded();
        }
        try {
            $clientId = (int) $attempt['client_id'];
            if (!$this->validateLateCharges($attempt, 'boleto')) {
                return HandlerOutcome::permanentFailure('late_charges_require_review');
            }
            $payer = $this->payer($clientId, true);
            $payer = (new PaymentIdentityStore($this->pdo))->snapshot((string) $attempt['id'], $payer);
            $repository = new PdoBoletoAttemptRepository($this->pdo, $clientId);
            $queue = new OperationOutboxJobQueue($this->scheduler);
            $handler = new BoletoEmissionHandler($repository, $this->boletoClient($job), $queue);
            $requestData = $this->decode((string) ($attempt['request_json'] ?? ''));
            $dueDate = self::issuanceDueDate((string) ($requestData['due_date'] ?? ''));
            if (!$this->validateIssuanceDate($attempt, $dueDate)) {
                return HandlerOutcome::permanentFailure('due_date_requires_review');
            }
            $applyLateCharges = $this->lateChargeAllowed('boleto', $clientId);
            $result = $handler->handle((string) $attempt['id'], new CreateBoletoRequest(
                (string) $attempt['invoice_id'],
                (string) $attempt['idempotency_key'],
                (int) $attempt['amount_cents'],
                $this->civilDate($dueDate),
                $payer,
                'Fatura WHMCS ' . (string) $attempt['invoice_id'],
                $this->settings->integer('boleto_grace_period', 30),
                'whmcs-invoice-' . (string) $attempt['invoice_id'],
                [],
                $this->callbackUrl(),
                $applyLateCharges ? $this->settings->decimal('boleto_fine') : 0.0,
                $applyLateCharges ? $this->settings->decimal('boleto_interest') : 0.0,
            ));
            $result = $this->withPdfUrl($result);
            $repository->save($result);
            $this->projectBoleto($result);
            $this->scheduleBoletoArtifacts($result);

            return HandlerOutcome::succeeded();
        } catch (\Pagou\Whmcs\Payment\Boleto\Api\BoletoApiException $exception) {
            return $exception->outcomeUnknown()
                ? HandlerOutcome::uncertain('boleto_remote_outcome_unknown')
                : HandlerOutcome::permanentFailure('boleto_request_rejected');
        } catch (\Throwable $exception) {
            return HandlerOutcome::permanentFailure('boleto_local_validation:' . $exception::class);
        }
    }

    private function scheduleBoletoArtifacts(BoletoAttempt $result): void
    {
        if ($result->status === BoletoAttempt::AWAITING_REGISTRATION) {
            $this->scheduler->schedule(
                Uuid::v4(),
                OperationType::ReconcilePayment,
                'boleto:refresh:' . $result->id,
                JobPriority::PaymentConfirmation,
                ['attempt_id' => $result->id, 'method' => 'boleto'],
            );
        } else {
            $this->schedulePdf($result);
        }
    }

    private function reconcile(OperationJob $job): HandlerOutcome
    {
        $outcome = $this->reconcileOperation($job);
        $eventKey = $job->payload['event_key'] ?? null;
        if (is_string($eventKey) && $eventKey !== '') {
            $update = $this->pdo->prepare('UPDATE pagou_webhook_deliveries SET processing_status = :status, processed_at_utc = :at, error_message = :error WHERE event_key = :key');
            $update->execute(['status' => $outcome->result->value, 'at' => $this->now(), 'error' => $outcome->reason, 'key' => $eventKey]);
        }
        return $outcome;
    }

    private function reconcileOperation(OperationJob $job): HandlerOutcome
    {
        if (($job->payload['operation'] ?? '') === 'card.card.delete' && is_string($job->payload['card_id'] ?? null)) {
            try {
                RuntimeFactory::card()->getCard($job->payload['card_id']);
            } catch (\Pagou\Whmcs\Infrastructure\Http\ApiException $error) {
                if ($error->statusCode === 404) {
                    return HandlerOutcome::succeeded();
                }
                return HandlerOutcome::retryable('card_deletion_check_unavailable');
            }
            $this->recordFinding($job->id, 'card_deletion_requires_review', 'high', 'O cartão anterior ainda existe na Pagou. A remoção não foi repetida automaticamente.');
            return HandlerOutcome::uncertain('card_deletion_requires_review');
        }
        $attempt = $this->attemptFromJob($job);
        if ($attempt === null) {
            $this->recordFinding($job->id, 'remote_identity_unavailable', 'high', 'Operação sem vínculo recuperável. Confira a operação original antes de qualquer nova tentativa.');
            return HandlerOutcome::uncertain('remote_identity_requires_review');
        }
        try {
            $method = (string) $attempt['method'];
            $remoteId = (string) ($attempt['remote_id'] ?? $job->payload['remote_id'] ?? '');
            if ($remoteId === '') {
                return HandlerOutcome::uncertain('remote_identity_not_yet_recoverable');
            }
            if ($method === 'pix') {
                $charge = $this->paymentLookup === null ? $this->pixService($job)->get($remoteId) : ($this->paymentLookup)('pix', $remoteId);
                if (!$charge instanceof \Pagou\Whmcs\Payment\Pix\Dto\PixCharge) {
                    throw new \UnexpectedValueException('Expected a Pix charge.');
                }
                $refundState = $this->pixRefunds()->reconcile((string) $attempt['id'], $charge);
                if ($refundState !== null) {
                    if ($refundState === 'applied') {
                        $refund = (new \Pagou\Whmcs\Payment\Pix\Infrastructure\PdoPixRefundStore($this->pdo))->find((string) $attempt['id']);
                        $state = (int) ($refund['amount_cents'] ?? 0) < (int) ($refund['receipt_cents'] ?? 0) ? 'paid' : 'refunded';
                        $this->attempts->complete((string) $attempt['id'], $remoteId, $state, ['state' => $state, 'remoteId' => $remoteId]);
                        $resolved = $this->pdo->prepare("UPDATE pagou_reconciliation_findings SET status = 'resolved', resolved_at_utc = ? WHERE attempt_id = ? AND finding_type = 'pix_refund_requires_review' AND status = 'open'");
                        $resolved->execute([$this->now(), $attempt['id']]);
                        return HandlerOutcome::succeeded();
                    }
                    if ($refundState === 'review') {
                        $this->recordFinding((string) $attempt['id'], 'pix_refund_requires_review', 'high', 'O reembolso precisa de conferência. Não solicite outra devolução; confira o registro na fatura e contate o suporte.');
                        return HandlerOutcome::uncertain('pix_refund_requires_review');
                    }
                    return $refundState === 'rejected' ? HandlerOutcome::succeeded() : HandlerOutcome::pending('pix_refund_pending');
                }
                if ($charge->status === 'refunded') {
                    $this->recordFinding((string) $attempt['id'], 'pix_refund_external_requires_review', 'high', 'A Pagou informa uma devolução sem solicitação registrada neste módulo. Confira a fatura antes de registrar o reembolso.');
                    return HandlerOutcome::uncertain('pix_refund_external_requires_review');
                }
                $state = $this->pixState($charge->status);
                if (in_array($attempt['status'], ['paid', 'cancelled', 'superseded', 'refunded'], true) && in_array($state, ['ready', 'pending'], true)) {
                    $state = (string) $attempt['status'];
                }
                $this->attempts->complete((string) $attempt['id'], $remoteId, $state, [
                    'state' => $state,
                    'amount' => number_format($charge->amountCents / 100, 2, ',', '.'),
                    'copyPaste' => $charge->artifacts->copyPaste ?? '',
                    'qrCodeImageUrl' => $this->qrImage($charge->artifacts->qrCodeImage ?? ''),
                    'expiresAt' => $charge->expiresAt ?? '',
                    'pdfValidUntil' => \Pagou\Whmcs\InvoicePdf\PixValidity::until($charge, (string) ($attempt['created_at'] ?? '')),
                    'dueDate' => $charge->dueDate ?? '',
                ], $charge->split?->toArray());
            } elseif ($method === 'boleto') {
                $charge = $this->paymentLookup === null ? $this->boletoClient($job)->get($remoteId) : ($this->paymentLookup)('boleto', $remoteId);
                if (!$charge instanceof \Pagou\Whmcs\Payment\Boleto\Domain\BoletoCharge) {
                    throw new \UnexpectedValueException('Expected a boleto charge.');
                }
                $repository = new PdoBoletoAttemptRepository($this->pdo, (int) $attempt['client_id']);
                $current = $repository->get((string) $attempt['id']);
                if ($current === null) {
                    return HandlerOutcome::permanentFailure('boleto_attempt_missing');
                }
                $current = $this->withPdfUrl($current->issued($charge));
                $repository->save($current);
                $this->projectBoleto($current);
                if (($attempt['status'] ?? '') === 'paid' && $current->status !== 'paid') {
                    // Paid through the embedded Pix: the boleto charge itself never turns paid.
                    $this->attempts->markStatus((string) $attempt['id'], 'paid');
                }
                if ($current->status === BoletoAttempt::AWAITING_REGISTRATION) {
                    return HandlerOutcome::pending('boleto_registration_pending');
                }
                $this->schedulePdf($current);
            } elseif ($method === 'card') {
                RuntimeFactory::cardReconciliation($this->pdo)->refresh($remoteId);
            } else {
                return HandlerOutcome::permanentFailure('reconciliation_method_not_supported');
            }

            if ($method !== 'card') {
                (new PaymentIdentityStore($this->pdo))->observeCharge((string) $attempt['id'], $method, $charge->raw);
                $payments = (new \Pagou\Whmcs\Infrastructure\Persistence\Webhook\StoredPaymentEvidence($this->pdo))
                    ->forCharge($remoteId, $method, $charge->paidAt);
                if ($method === 'boleto' && $payments === []) {
                    $payments = $this->embeddedPixPayments($attempt, $job);
                }
                // The charge fee also applies when a boleto is paid through its embedded Pix.
                $fee = $this->providerFee($charge->raw['fee'] ?? null);
                foreach ($payments as $payment) {
                    if ($fee !== null) {
                        $payment['fee_cents'] = $fee;
                    }
                    $this->applyPayment($attempt, new OperationJob(
                        $job->id,
                        $job->type,
                        $job->deduplicationKey,
                        $job->priority,
                        $payment,
                        $job->createdAt,
                        $job->availableAt,
                    ));
                }
                if ($payments === [] && in_array($charge->status, ['paid', 'completed', 'paid_with_discount', 'paid_with_fine_or_interest'], true)) {
                    $display = $this->attempts->displayForInvoice((int) $attempt['invoice_id'], $method);
                    $display['state'] = 'processing';
                    $display['message'] = 'Pagamento recebido pela Pagou. A confirmação na fatura está em conferência. Não pague novamente.';
                    $this->attempts->complete((string) $attempt['id'], $remoteId, 'paid', $display);
                    $this->recordFinding((string) $attempt['id'], 'payment_evidence_missing', 'high', 'Pagamento remoto confirmado, mas faltam os dados assinados do recebimento para baixar a fatura.');
                    return HandlerOutcome::uncertain('payment_evidence_missing');
                }
                if ($payments !== []) {
                    $resolved = $this->pdo->prepare("UPDATE pagou_reconciliation_findings SET status = 'resolved', resolved_at_utc = :at WHERE attempt_id = :id AND finding_type = 'payment_evidence_missing' AND status = 'open'");
                    $resolved->execute(['at' => $this->now(), 'id' => $attempt['id']]);
                }
            }

            return HandlerOutcome::succeeded();
        } catch (\Pagou\Whmcs\Payment\Boleto\Api\BoletoApiException $exception) {
            return HandlerOutcome::retryable('boleto_lookup_failed', $exception->retryAt);
        } catch (\Throwable $exception) {
            return HandlerOutcome::retryable('reconciliation_failure:' . $exception::class);
        }
    }

    private function fetchBoletoPdf(OperationJob $job): HandlerOutcome
    {
        $attempt = $this->requiredAttemptFromJob($job);
        try {
            $repository = new PdoBoletoAttemptRepository($this->pdo, (int) $attempt['client_id']);
            $handler = new BoletoPdfCacheHandler(
                $repository,
                new SecurePdfFetcher(
                    new PdfCurlHttpClient(['fatura.pagou.com.br']),
                    new PrivatePdfStorage($this->privateStorage()),
                    new PdfFetchPolicy(['fatura.pagou.com.br']),
                ),
            );
            $boleto = $handler->handle((string) $attempt['id']);
            $this->projectBoleto($boleto);
            $this->scheduleDelivery($boleto, true);

            return HandlerOutcome::succeeded();
        } catch (\Throwable $exception) {
            if ($job->attempts >= 3) {
                $boleto = (new PdoBoletoAttemptRepository($this->pdo, (int) $attempt['client_id']))
                    ->get((string) $attempt['id']);
                if ($boleto !== null) {
                    $this->scheduleDelivery($boleto, false);
                }
            }

            return HandlerOutcome::retryable('boleto_pdf_unavailable');
        }
    }

    private function deliverInvoiceEmail(OperationJob $job): HandlerOutcome
    {
        $invoiceId = (int) ($job->payload['invoice_id'] ?? 0);
        if ($invoiceId > 0 && ($job->payload['deadline'] ?? false) === true) {
            return $this->deliverHeldInvoiceEmail($invoiceId);
        }
        $attemptId = (string) ($job->payload['attempt_id'] ?? '');
        $attachPdf = (bool) ($job->payload['attach_pdf'] ?? false);
        if ($invoiceId < 1 || $attemptId === '') {
            return HandlerOutcome::permanentFailure('invalid_invoice_delivery');
        }
        if (
            !$this->isCurrentBoletoDelivery($invoiceId, $attemptId)
            || !$this->settings->boolean('boleto_email_details', true)
            || $this->settings->string('boleto_email_pdf_mode', 'attach') !== 'attach'
        ) {
            return HandlerOutcome::succeeded();
        }

        return $this->sendInvoiceEmail($invoiceId, $attemptId, $attachPdf);
    }

    /**
     * The deadline of a held Invoice Created. Nothing was sent for the invoice yet, so
     * it goes out now: with the boleto when it became ready, otherwise without it.
     */
    private function deliverHeldInvoiceEmail(int $invoiceId): HandlerOutcome
    {
        if (($this->invoiceRow($invoiceId)['status'] ?? '') !== 'Unpaid') {
            return HandlerOutcome::succeeded();
        }
        $any = $this->pdo->prepare('SELECT 1 FROM pagou_invoice_deliveries WHERE invoice_id = :invoice_id LIMIT 1');
        $any->execute(['invoice_id' => $invoiceId]);
        if ($any->fetchColumn() !== false) {
            return HandlerOutcome::succeeded();
        }
        $attempt = $this->attempts->latestForInvoice($invoiceId, 'boleto');
        $attemptId = (string) ($attempt['id'] ?? '');
        $boleto = $attempt === null ? null : (new PdoBoletoAttemptRepository($this->pdo, (int) $attempt['client_id']))->get($attemptId);

        return $this->sendInvoiceEmail($invoiceId, $attemptId === '' ? 'none' : $attemptId, $boleto?->artifacts?->localPdfKey !== null);
    }

    /** Records the intent, sends the invoice template once and records the result. */
    private function sendInvoiceEmail(int $invoiceId, string $attemptId, bool $attachPdf): HandlerOutcome
    {
        $key = hash('sha256', 'invoice:' . $invoiceId . ':' . $attemptId);
        $now = $this->now();
        $dispatchStarted = false;
        try {
            $existing = $this->delivery($key);
            if (($existing['status'] ?? null) === 'sent') {
                return HandlerOutcome::succeeded();
            }
            if ($existing !== null) {
                $this->recordFinding(
                    $attemptId,
                    'invoice_email_delivery_uncertain',
                    'medium',
                    'Confira o histórico de e-mails da fatura antes de reenviar as instruções.'
                );
                return HandlerOutcome::uncertain('invoice_email_delivery_uncertain');
            }
            $template = $this->settings->string('boleto_email_template', 'Invoice Created');
            if (!$this->isInvoiceEmail($template)) {
                $this->recordFinding(
                    $attemptId,
                    'invoice_email_template_invalid',
                    'medium',
                    'Selecione um template do tipo fatura para enviar as instruções do boleto.'
                );
                return HandlerOutcome::permanentFailure('invoice_email_template_invalid');
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO pagou_invoice_deliveries '
                . '(delivery_key, invoice_id, attempt_id, status, attach_pdf, created_at, updated_at) '
                . 'VALUES (:key, :invoice_id, :attempt_id, :status, :attach_pdf, :created_at, :updated_at)'
            );
            $insert->execute([
                'key' => $key,
                'invoice_id' => $invoiceId,
                'attempt_id' => $attemptId,
                'status' => 'sending',
                'attach_pdf' => $attachPdf ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            // Persist intent before the side effect. A crash must never authorize a blind resend.
            $dispatchStarted = true;
            $result = $this->api('SendEmail', [
                'messagename' => $template,
                'id' => $invoiceId,
            ]);
            if (($result['result'] ?? null) !== 'success') {
                throw new \RuntimeException('WHMCS email delivery failed.');
            }
            $sent = $this->pdo->prepare(
                'UPDATE pagou_invoice_deliveries SET status = :status, sent_at_utc = :sent_at, '
                . 'updated_at = :updated_at WHERE delivery_key = :key'
            );
            $sent->execute(['status' => 'sent', 'sent_at' => $now, 'updated_at' => $now, 'key' => $key]);

            return HandlerOutcome::succeeded();
        } catch (\Throwable $exception) {
            if ($dispatchStarted) {
                $this->recordFinding(
                    $attemptId,
                    'invoice_email_delivery_uncertain',
                    'medium',
                    'Confira o histórico de e-mails da fatura antes de reenviar as instruções.'
                );
                return HandlerOutcome::uncertain('invoice_email_delivery_uncertain');
            }
            return HandlerOutcome::retryable('invoice_email_delivery_failed');
        }
    }

    private function isCurrentBoletoDelivery(int $invoiceId, string $attemptId): bool
    {
        $invoice = $this->invoiceRow($invoiceId);
        $attempt = $this->attempts->latestForInvoice($invoiceId, 'boleto');
        return ($invoice['status'] ?? '') === 'Unpaid'
            && ($invoice['paymentmethod'] ?? '') === 'pagou_boleto'
            && ($attempt['id'] ?? '') === $attemptId
            && ($attempt['method'] ?? '') === 'boleto'
            && in_array($attempt['status'] ?? '', ['queued', 'awaiting_registration', 'ready'], true);
    }

    private function isInvoiceEmail(string $name): bool
    {
        if ($name === '') {
            return false;
        }
        $statement = $this->pdo->prepare(
            "SELECT name FROM tblemailtemplates WHERE name = :name AND type = 'invoice' LIMIT 1"
        );
        $statement->execute(['name' => $name]);
        return $statement->fetchColumn() !== false;
    }

    private function cancelPix(OperationJob $job): HandlerOutcome
    {
        $remoteId = (string) ($job->payload['remote_id'] ?? '');
        if ($remoteId === '') {
            return HandlerOutcome::permanentFailure('pix_cancel_without_remote_id');
        }
        try {
            $this->pixService($job)->cancel(
                (string) ($job->payload['attempt_id'] ?? ''),
                $remoteId,
                hash('sha256', 'cancel:' . $remoteId),
            );
            $attemptId = (string) ($job->payload['attempt_id'] ?? '');
            if ($attemptId !== '') {
                $this->attempts->markStatus($attemptId, 'cancelled');
            }
            $this->releaseReplacementAttempt($job);

            return HandlerOutcome::succeeded();
        } catch (PixUncertainOperation) {
            return HandlerOutcome::uncertain('pix_cancel_unknown');
        } catch (\Throwable) {
            return HandlerOutcome::retryable('pix_cancel_failed');
        }
    }

    private function cancelBoleto(OperationJob $job): HandlerOutcome
    {
        $remoteId = (string) ($job->payload['remote_id'] ?? '');
        if ($remoteId === '') {
            return HandlerOutcome::permanentFailure('boleto_cancel_without_remote_id');
        }
        try {
            $this->boletoClient($job)->cancel($remoteId);
            $this->scheduler->schedule(
                Uuid::v4(),
                OperationType::ReplaceBoleto,
                'boleto:cancel-confirm:' . (string) ($job->payload['attempt_id'] ?? hash('sha256', $remoteId)),
                JobPriority::FinancialRecovery,
                [
                    'attempt_id' => (string) ($job->payload['attempt_id'] ?? ''),
                    'invoice_id' => (int) ($job->payload['invoice_id'] ?? 0),
                    'remote_id' => $remoteId,
                    'replace' => (bool) ($job->payload['replace'] ?? false),
                    'release_attempt_id' => (string) ($job->payload['release_attempt_id'] ?? ''),
                ],
            );

            return HandlerOutcome::succeeded();
        } catch (\Throwable) {
            // A boleto already cancelled at Pagou refuses another cancellation.
            try {
                $charge = $this->paymentLookup === null ? $this->boletoClient($job)->get($remoteId) : ($this->paymentLookup)('boleto', $remoteId);
                $status = strtolower($charge->status);
            } catch (\Throwable) {
                $status = '';
            }

            return in_array($status, ['cancelled', 'canceled', 'voided'], true)
                ? HandlerOutcome::succeeded()
                : HandlerOutcome::uncertain('boleto_cancel_unknown');
        }
    }

    private function confirmBoletoCancellation(OperationJob $job): HandlerOutcome
    {
        $attemptId = (string) ($job->payload['attempt_id'] ?? '');
        $remoteId = (string) ($job->payload['remote_id'] ?? '');
        $invoiceId = (int) ($job->payload['invoice_id'] ?? 0);
        if ($attemptId === '' || $remoteId === '' || $invoiceId < 1) {
            return HandlerOutcome::permanentFailure('boleto_cancel_confirmation_invalid');
        }
        try {
            $charge = $this->boletoClient($job)->get($remoteId);
            $status = strtolower($charge->status);
            if (in_array($status, ['paid', 'completed'], true)) {
                $this->attempts->markStatus($attemptId, 'paid');
                $this->recordFinding(
                    $attemptId,
                    'payment_during_cancellation',
                    'critical',
                    'O boleto foi pago durante a tentativa de cancelamento. A substituição foi interrompida.',
                );

                return HandlerOutcome::permanentFailure('boleto_paid_during_cancellation');
            }
            if (!in_array($status, ['cancelled', 'canceled', 'voided'], true)) {
                return HandlerOutcome::pending('boleto_cancellation_pending');
            }

            $this->attempts->markStatus($attemptId, 'cancelled');
            $this->releaseReplacementAttempt($job);
            if ((bool) ($job->payload['replace'] ?? false)) {
                $this->scheduleInvoice($invoiceId);
            }

            return HandlerOutcome::succeeded();
        } catch (\Pagou\Whmcs\Payment\Boleto\Api\BoletoApiException $exception) {
            return HandlerOutcome::retryable('boleto_cancel_lookup_failed', $exception->retryAt);
        } catch (\Throwable $exception) {
            return HandlerOutcome::retryable('boleto_cancel_confirmation_failed:' . $exception::class);
        }
    }

    private function paymentApplier(): ReceivedPaymentApplier
    {
        return new ReceivedPaymentApplier(
            new PdoLedgerRepository(
                $this->pdo,
                new PdoInvoiceClientResolver($this->pdo),
                new PdoReconciliationFindingRepository($this->pdo),
            ),
            new NativeWhmcsFinancialPort($this->pdo, $this->localApi),
            new OutboxFollowUpQueue($this->pdo, $this->outbox),
        );
    }

    /** Provider fee in reais, as returned by the Pix detail; unknown values are not guessed. */
    private function providerFee(mixed $value): ?int
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $cents = (int) round((float) $value * 100);

        return $cents > 0 ? $cents : null;
    }

    /** @param array<string, mixed> $attempt */
    private function applyPayment(array $attempt, OperationJob $job): void
    {
        $transactionId = trim((string) ($job->payload['transaction_id'] ?? ''));
        $amount = (string) ($job->payload['amount'] ?? '');
        if ($transactionId === '' || $amount === '') {
            throw new \RuntimeException('Payment event lacks financial identity or amount.');
        }
        $amountCents = $this->decimalCents($amount);
        $paidAt = $this->utcInstant((string) ($job->payload['paid_at'] ?? ''));
        $key = EconomicPaymentKey::fromRemotePayment((int) $attempt['invoice_id'], 'pagou', $transactionId);
        $native = trim((string) ($job->payload['native_transaction_id'] ?? ''));
        $fee = $job->payload['fee_cents'] ?? null;
        $payment = new ReceivedPayment(
            $key,
            (string) ($job->payload['event_key'] ?? $transactionId),
            (int) $attempt['invoice_id'],
            $amountCents,
            'pagou',
            $transactionId,
            $paidAt,
            (string) $attempt['method'],
            (string) $attempt['remote_id'],
            $native !== '' ? $native : null,
            is_int($fee) && $fee > 0 ? $fee : null,
            (int) $attempt['amount_cents'] > 0 ? (int) $attempt['amount_cents'] : null,
        );
        $result = $this->paymentApplier()->apply($payment);
        if ($result->outcome === 'quarantined') {
            throw new \RuntimeException('Payment was quarantined for manual review.');
        }
        if (in_array($result->outcome, ['applied', 'replay'], true)) {
            $invoiceId = (int) $attempt['invoice_id'];
            // The attempt that received the payment is closed as paid, so it is never cancelled below.
            $current = $this->attempts->find((string) $attempt['id']);
            if ($current !== null && in_array((string) $current['status'], ['queued', 'pending', 'ready', 'active', 'awaiting_registration', 'uncertain'], true)) {
                $this->attempts->markStatus((string) $attempt['id'], 'paid');
            }
            $this->scheduleCancellations($this->attempts->supersedeInvoice($invoiceId), null, $invoiceId);
        }
    }

    /**
     * A boleto paid through its embedded Pix is reported only by the signed Pix
     * notification; the boleto charge is not marked paid. The linked Pix is
     * confirmed remotely before its signed receipt is booked for the boleto.
     * @param array<string, mixed> $attempt
     * @return list<array<string, mixed>>
     */
    private function embeddedPixPayments(array $attempt, OperationJob $job): array
    {
        $link = $this->pdo->prepare("SELECT pix_id FROM pagou_issued_parties WHERE attempt_id = :id AND link_conflict = 0 AND pix_id <> ''");
        $link->execute(['id' => $attempt['id']]);
        $pixId = (string) ($link->fetchColumn() ?: '');
        $evidence = new \Pagou\Whmcs\Infrastructure\Persistence\Webhook\StoredPaymentEvidence($this->pdo);
        // Without a signed Pix receipt there is nothing to book and no remote lookup is made.
        if ($pixId === '' || $evidence->forCharge($pixId, 'pix', gmdate('Y-m-d\TH:i:s\Z')) === []) {
            return [];
        }
        $pix = $this->paymentLookup === null ? $this->pixService($job)->get($pixId) : ($this->paymentLookup)('pix', $pixId);
        if (!$pix instanceof \Pagou\Whmcs\Payment\Pix\Dto\PixCharge || $pix->status !== 'paid' || $pix->paidAt === null) {
            return [];
        }
        $payments = $evidence->forCharge($pixId, 'pix', $pix->paidAt);
        if ($payments !== []) {
            // As in the Pix flow, the confirmed charge is paid before booking, so it is not cancelled as a sibling.
            $this->attempts->markStatus((string) $attempt['id'], 'paid');
        }

        return $payments;
    }

    private function pixService(?OperationJob $job = null): PixPaymentService
    {
        $http = new SafeCurlApiClient(ApiClientConfig::fromInternalOverride(
            $this->settings->apiKey(),
            $this->internalApiBaseUrl(),
        ), $job === null ? null : fn (float $milliseconds) => (new OperationTelemetry($this->pdo))->apiTime($job, $milliseconds));
        $client = new PagouPixClient(new PixHttpClientAdapter($http), $this->settings->apiKey());

        return new PixPaymentService(
            $client,
            new PixPayloadMapper(),
            new PixResponseMapper(),
            new PdoOperationJournal($this->pdo),
            new OutboxReconciliationScheduler($this->outbox),
        );
    }

    private function boletoClient(?OperationJob $job = null): BoletoClient
    {
        $http = new SafeCurlApiClient(ApiClientConfig::fromInternalOverride(
            $this->settings->apiKey(),
            $this->internalApiBaseUrl(),
        ), $job === null ? null : fn (float $milliseconds) => (new OperationTelemetry($this->pdo))->apiTime($job, $milliseconds));

        return new BoletoClient(new BoletoHttpClientAdapter($http), new BoletoPayloadMapper(), new BoletoResponseMapper());
    }

    /** @return array<string, string> */
    private function payer(int $clientId, bool $fullAddress): array
    {
        $client = $this->api('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
        $document = (new ClientDocumentResolver($this->pdo, $this->settings))->resolve($client, $clientId);
        $person = trim((string) ($client['firstname'] ?? '') . ' ' . (string) ($client['lastname'] ?? ''));
        $company = trim((string) ($client['companyname'] ?? ''));
        $payer = [
            // A CNPJ belongs to the company: the charge carries its name, not the contact person's.
            'name' => strlen((string) preg_replace('/\D/', '', (string) $document)) === 14 && $company !== '' ? $company : $person,
            'document' => $document,
        ];
        if (!$fullAddress) {
            return $payer;
        }

        return $payer + [
            'email' => (string) ($client['email'] ?? ''),
            'phone' => preg_replace('/\D+/', '', (string) ($client['phonenumber'] ?? '')) ?? '',
            'zip' => preg_replace('/\D+/', '', (string) ($client['postcode'] ?? '')) ?? '',
            'street' => (string) ($client['address1'] ?? ''),
            'number' => $this->settings->string('address_number_fallback', 'S/N'),
            'complement' => (string) ($client['address2'] ?? ''),
            'neighborhood' => $this->settings->string('neighborhood_fallback', 'Centro'),
            'city' => (string) ($client['city'] ?? ''),
            'state' => strtoupper((string) ($client['state'] ?? '')),
        ];
    }

    /** @return array<string, mixed>|null */
    private function attemptFromJob(OperationJob $job): ?array
    {
        $attemptId = $job->payload['attempt_id'] ?? null;
        $attempt = is_string($attemptId) && $attemptId !== '' ? $this->attempts->find($attemptId) : null;
        if ($attempt === null && isset($job->payload['remote_id']) && is_string($job->payload['remote_id'])) {
            $statement = $this->pdo->prepare(
                'SELECT * FROM pagou_payment_attempts WHERE remote_id = :remote_id ORDER BY created_at DESC LIMIT 1'
            );
            $statement->execute(['remote_id' => $job->payload['remote_id']]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $attempt = $row === false ? null : $row;
        }
        if ($attempt === null && is_string($job->payload['idempotency_key_hash'] ?? null)) {
            $query = $this->pdo->query("SELECT * FROM pagou_payment_attempts WHERE method = 'card' AND status IN ('dispatching', 'uncertain', 'pending')");
            foreach ($query === false ? [] : $query->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                if (hash_equals(hash('sha256', (string) $candidate['idempotency_key']), $job->payload['idempotency_key_hash'])) {
                    $attempt = $candidate;
                    break;
                }
            }
        }
        return $attempt;
    }

    /** @return array<string, mixed> */
    private function requiredAttemptFromJob(OperationJob $job): array
    {
        $attempt = $this->attemptFromJob($job);
        if ($attempt === null) {
            throw new \OutOfBoundsException('Payment attempt was not found.');
        }

        return $attempt;
    }

    /** @param list<array{id:string,method:string,remote_id:string}> $attempts */
    private function scheduleCancellations(array $attempts, ?string $releaseAttemptId = null, ?int $invoiceId = null): void
    {
        foreach ($attempts as $attempt) {
            $type = $attempt['method'] === 'pix' ? OperationType::CancelPix : OperationType::CancelBoleto;
            $this->scheduler->schedule(
                Uuid::v4(),
                $type,
                sprintf('%s:cancel:%s:%s', $attempt['method'], $attempt['id'], hash('sha256', $attempt['remote_id'])),
                JobPriority::FinancialRecovery,
                [
                    'attempt_id' => $attempt['id'],
                    'remote_id' => $attempt['remote_id'],
                    'invoice_id' => $invoiceId,
                    'release_attempt_id' => $releaseAttemptId,
                ],
            );
        }
    }

    private function releaseReplacementAttempt(OperationJob $job): void
    {
        $replacementId = (string) ($job->payload['release_attempt_id'] ?? '');
        $invoiceId = (int) ($job->payload['invoice_id'] ?? 0);
        if ($replacementId === '' || $invoiceId < 1) {
            return;
        }
        $pending = $this->pdo->prepare(
            "SELECT COUNT(*) FROM pagou_payment_attempts WHERE invoice_id = :invoice_id "
            . "AND status = 'cancel_requested' AND id <> :replacement_id"
        );
        $pending->execute(['invoice_id' => $invoiceId, 'replacement_id' => $replacementId]);
        if ((int) $pending->fetchColumn() > 0) {
            return;
        }
        $replacement = $this->attempts->find($replacementId);
        if ($replacement === null || (string) $replacement['status'] !== 'queued') {
            return;
        }
        $request = $this->decode((string) ($replacement['request_json'] ?? ''));
        $this->scheduleAttemptIssuance(
            $replacementId,
            $invoiceId,
            (string) $replacement['method'],
            max(1, (int) ($request['revision'] ?? 1)),
        );
    }

    private function scheduleAttemptIssuance(string $attemptId, int $invoiceId, string $method, int $revision): bool
    {
        $type = $method === 'pix' ? OperationType::IssuePix : OperationType::IssueBoleto;

        return $this->scheduler->schedule(
            Uuid::v4(),
            $type,
            sprintf('%s:issue:%s:%d', $method, $attemptId, $revision),
            JobPriority::Issuance,
            ['attempt_id' => $attemptId, 'invoice_id' => $invoiceId],
        );
    }

    private function withPdfUrl(BoletoAttempt $attempt): BoletoAttempt
    {
        if ($attempt->remoteId === null || $attempt->artifacts === null || $attempt->artifacts->pdfUrl !== null) {
            return $attempt;
        }
        $artifacts = new BoletoArtifacts(
            $attempt->artifacts->digitableLine,
            $attempt->artifacts->barcode,
            $attempt->artifacts->pixCopyPaste,
            $attempt->artifacts->pixQrCode,
            'https://fatura.pagou.com.br/api/generatePDF/' . rawurlencode($attempt->remoteId),
            $attempt->artifacts->localPdfKey,
        );

        return $attempt->withArtifacts($artifacts);
    }

    /** @param array<string, mixed> $storedAttempt */
    private function recoverLegacyBoletoArtifacts(BoletoAttempt $attempt, array $storedAttempt): BoletoAttempt
    {
        if ($attempt->artifacts !== null) {
            return $attempt;
        }
        $display = $this->decode((string) ($storedAttempt['response_json'] ?? ''));
        $value = static function (array $source, string $key): ?string {
            if (!isset($source[$key]) || !is_scalar($source[$key])) {
                return null;
            }
            $text = trim((string) $source[$key]);

            return $text === '' ? null : $text;
        };
        $artifacts = new BoletoArtifacts(
            $value($display, 'digitableLine'),
            $value($display, 'barcode'),
            $value($display, 'copyPaste'),
            $value($display, 'qrCodeImageUrl'),
        );

        return $attempt->withArtifacts($artifacts);
    }

    private function projectBoleto(BoletoAttempt $attempt): void
    {
        $this->attempts->complete($attempt->id, (string) $attempt->remoteId, $attempt->status, [
            'state' => $attempt->status,
            'amount' => number_format($attempt->amountCentavos / 100, 2, ',', '.'),
            'digitableLine' => $attempt->artifacts->digitableLine ?? '',
            'barcode' => $attempt->artifacts->barcode ?? '',
            'copyPaste' => $attempt->artifacts->pixCopyPaste ?? '',
            'qrCodeImageUrl' => $this->qrImage($attempt->artifacts->pixQrCode ?? ''),
            'pdfUrl' => $attempt->artifacts?->localPdfKey === null
                ? ''
                : 'modules/addons/pagou_payments/download.php?attempt=' . rawurlencode($attempt->id),
            'dueDate' => $attempt->dueDate,
        ], $attempt->split?->toArray());
    }

    private function schedulePdf(BoletoAttempt $attempt): void
    {
        if ($attempt->artifacts?->pdfUrl === null || $attempt->artifacts->localPdfKey !== null) {
            return;
        }
        $this->scheduler->fetchBoletoPdf(Uuid::v4(), $attempt->id, $attempt->revision, [
            'attempt_id' => $attempt->id,
            'invoice_id' => $attempt->invoiceId,
        ]);
    }

    private function scheduleDelivery(BoletoAttempt $attempt, bool $attachPdf): void
    {
        if (!$this->settings->boolean('boleto_email_details', true)) {
            return;
        }
        $mode = $this->settings->string('boleto_email_pdf_mode', 'attach');
        if ($mode !== 'attach') {
            return;
        }
        $this->scheduler->deliverInvoiceEmail(
            Uuid::v4(),
            $attempt->invoiceId,
            (string) $attempt->revision,
            [
                'attempt_id' => $attempt->id,
                'invoice_id' => (int) $attempt->invoiceId,
                'attach_pdf' => $attachPdf,
            ],
        );
    }

    private function privateStorage(): PrivateStorage
    {
        $configured = getenv('PAGOU_PRIVATE_STORAGE_DIR');
        $root = defined('ROOTDIR') ? (string) constant('ROOTDIR') : '';
        $directory = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : ($root !== '' ? dirname($root) . '/pagou-whmcs-private' : sys_get_temp_dir() . '/pagou-whmcs-private');

        return new PrivateStorage($directory, $root === '' ? null : $root);
    }

    private function callbackUrl(): string
    {
        $override = getenv('PAGOU_WHMCS_CALLBACK_URL');
        if (is_string($override) && preg_match('#^https://#i', $override) === 1) {
            return $override;
        }
        $config = $this->api('GetConfigurationValue', ['setting' => 'SystemURL']);
        $base = (string) ($config['value'] ?? '');
        if (preg_match('#^https://#i', $base) !== 1) {
            throw new \RuntimeException('O WHMCS precisa ter uma System URL HTTPS para receber notificações.');
        }

        return rtrim($base, '/') . '/modules/gateways/callback/pagou.php';
    }

    private function absoluteModuleUrl(string $path): string
    {
        $config = $this->api('GetConfigurationValue', ['setting' => 'SystemURL']);

        return rtrim((string) ($config['value'] ?? ''), '/') . '/' . ltrim($path, '/');
    }

    private function internalApiBaseUrl(): ?string
    {
        $value = getenv('PAGOU_INTERNAL_API_BASE_URL');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function api(string $command, array $parameters): array
    {
        $result = ($this->localApi)($command, $parameters);
        if (($result['result'] ?? 'success') !== 'success') {
            throw new \RuntimeException('WHMCS local operation failed: ' . $command . '.');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function invoiceRow(int $invoiceId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tblinvoices WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $invoiceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? [] : $row;
    }

    /**
     * The invoice email already went out, so a resend is not held, or a send without
     * the PDF is under way, so the held email must not be held again.
     */
    private function deliveryAllowsFallback(int $invoiceId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM pagou_invoice_deliveries WHERE invoice_id = :invoice_id "
            . "AND (status = 'sent' OR (status = 'sending' AND attach_pdf = 0)) LIMIT 1"
        );
        $statement->execute(['invoice_id' => $invoiceId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Holds the Invoice Created until the boleto is ready, with a deadline: a boleto that
     * fails or never registers must not keep the invoice email from the customer.
     * @return array{abortsend:true}
     */
    private function holdInitialEmail(int $invoiceId): array
    {
        $this->scheduler->schedule(
            Uuid::v4(),
            OperationType::DeliverInvoiceEmail,
            'invoice:delivery-deadline:' . $invoiceId,
            JobPriority::Delivery,
            ['invoice_id' => $invoiceId, 'deadline' => true],
            (new SystemAsyncClock())->now()->modify('+' . self::INVOICE_EMAIL_HOLD_SECONDS . ' seconds'),
        );

        return ['abortsend' => true];
    }

    private function recordWorkerHeartbeat(): void
    {
        $this->writeOperationalSetting('worker_last_run_utc', $this->now());
    }

    private function runRetentionIfDue(): void
    {
        $last = $this->operationalSetting('retention_last_run_utc');
        if ($last !== null) {
            try {
                $lastRun = new \DateTimeImmutable($last, new \DateTimeZone('UTC'));
                if ($lastRun > new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC'))) {
                    return;
                }
            } catch (\Throwable) {
                // Invalid operational metadata is replaced by a fresh run.
            }
        }
        $days = max(30, min(730, $this->settings->integer('retention_operational_days', 90)));
        (new RetentionService($this->pdo))->run($days);
        $this->writeOperationalSetting('retention_last_run_utc', $this->now());
    }

    private function operationalSetting(string $key): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT setting_value FROM pagou_settings WHERE setting_key = :key AND is_secret = 0 LIMIT 1'
        );
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function writeOperationalSetting(string $key, string $value): void
    {
        $now = $this->now();
        $update = $this->pdo->prepare(
            'UPDATE pagou_settings SET setting_value = :value, is_secret = 0, updated_at = :updated_at '
            . 'WHERE setting_key = :key'
        );
        $parameters = ['value' => $value, 'updated_at' => $now, 'key' => $key];
        $update->execute($parameters);
        if ($update->rowCount() > 0) {
            return;
        }
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO pagou_settings (setting_key, setting_value, is_secret, updated_at) '
                . 'VALUES (:key, :value, 0, :updated_at)'
            );
            $insert->execute($parameters);
        } catch (\PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['19', '23000', '23505'], true)) {
                throw $exception;
            }
            $update->execute($parameters);
        }
    }

    private function audit(int $actorId, string $action, string $subjectId, string $reason): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pagou_audit_log '
            . '(actor_type, actor_id, action, subject_type, subject_id, correlation_id, ip_address, metadata_json, occurred_at_utc) '
            . 'VALUES (:actor_type, :actor_id, :action, :subject_type, :subject_id, :correlation_id, NULL, :metadata_json, :occurred_at)'
        );
        $statement->execute([
            'actor_type' => 'admin',
            'actor_id' => (string) $actorId,
            'action' => $action,
            'subject_type' => 'payment_attempt',
            'subject_id' => $subjectId,
            'correlation_id' => Uuid::v4(),
            'metadata_json' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'occurred_at' => $this->now(),
        ]);
    }

    private function recordFinding(string $attemptId, string $type, string $severity, string $message): void
    {
        $now = $this->now();
        $findingKey = hash('sha256', implode('|', [$attemptId, $type]));
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO pagou_reconciliation_findings '
                . '(id, finding_key, attempt_id, severity, finding_type, status, details_json, detected_at_utc, '
                . 'resolved_at_utc, resolved_by, resolution_json, created_at, updated_at, version) '
                . 'VALUES (:id, :finding_key, :attempt_id, :severity, :finding_type, :status, :details_json, '
                . ':detected_at, NULL, NULL, NULL, :created_at, :updated_at, 1)'
            );
            $statement->execute([
                'id' => Uuid::v4(),
                'finding_key' => $findingKey,
                'attempt_id' => $attemptId,
                'severity' => $severity,
                'finding_type' => $type,
                'status' => 'open',
                'details_json' => json_encode(['message' => $message], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['19', '23000', '23505'], true)) {
                throw $exception;
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function delivery(string $key): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pagou_invoice_deliveries WHERE delivery_key = :key LIMIT 1');
        $statement->execute(['key' => $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $vars */
    private function isInitialInvoiceEmail(array $vars): bool
    {
        return strtolower((string) ($vars['messagename'] ?? '')) === 'invoice created';
    }

    /** @param array<string, mixed> $display */
    private function pixArtifacts(array $display): string
    {
        $html = '';
        $image = (string) ($display['qrCodeImageUrl'] ?? '');
        if ($this->settings->boolean('pix_show_qr', true) && str_starts_with($image, 'data:image/')) {
            $html .= '<div class="pagou-qr-frame"><img class="pagou-qr" alt="QR Code Pix" src="'
                . $this->safe($image) . '"></div>';
        }
        $copy = (string) ($display['copyPaste'] ?? '');
        if ($this->settings->boolean('pix_show_copy_paste', true) && $copy !== '') {
            $html .= $this->copyField('Pix copia e cola', 'pix', $copy);
        }

        return $html;
    }

    /** @param array<string, mixed> $display */
    private function boletoArtifacts(array $display): string
    {
        $html = '';
        $remoteId = trim((string) ($display['remoteId'] ?? ''));
        $registered = in_array((string) ($display['state'] ?? ''), ['ready', 'active'], true);
        if ($this->settings->boolean('boleto_show_pdf', true)) {
            if ($registered && $remoteId !== '') {
                $html .= '<div class="pagou-document-actions">';
                $html .= '<a class="pagou-button" target="_blank" rel="noopener" href="'
                    . $this->safe('https://fatura.pagou.com.br/boleto/' . rawurlencode($remoteId)) . '">'
                    . \Pagou\Payments\Admin\Support\Html::icon('barcode', 16) . 'Visualizar boleto</a>';
                $html .= '<a class="pagou-document-download" target="_blank" rel="noopener" href="' . $this->safe('https://fatura.pagou.com.br/boleto/pdf/' . rawurlencode($remoteId)) . '">'
                    . \Pagou\Payments\Admin\Support\Html::icon('download', 16) . 'Baixar boleto em PDF</a></div>';
            } else {
                $html .= $this->boletoLoading();
            }
        } elseif (in_array((string) ($display['state'] ?? ''), ['queued', 'pending', 'awaiting_registration'], true)) {
            $html .= $this->boletoLoading();
        }
        $line = (string) ($display['digitableLine'] ?? '');
        if ($this->settings->boolean('boleto_show_line', true) && $line !== '') {
            $html .= $this->copyField(
                'Linha digitável',
                'boleto-line',
                $line,
                'Copiar linha digitável',
                'Linha digitável copiada.',
            );
        }
        $image = (string) ($display['qrCodeImageUrl'] ?? '');
        if ($this->settings->boolean('boleto_show_qr', false) && str_starts_with($image, 'data:image/')) {
            $html .= '<div class="pagou-qr-frame"><img class="pagou-qr" alt="QR Code Pix" src="'
                . $this->safe($image) . '"></div>';
        }

        return $html;
    }

    private function boletoLoading(): string
    {
        return '<div class="pagou-document-loading" role="status" aria-live="polite">'
            . '<span class="pagou-document-pixels" aria-hidden="true">' . str_repeat('<span></span>', 9) . '</span>'
            . '<div><strong>Preparando seu boleto</strong><small>Ele aparecerá aqui assim que estiver disponível.</small></div></div>';
    }

    private function copyField(
        string $label,
        string $prefix,
        string $value,
        string $buttonLabel = 'Copiar código Pix',
        string $feedbackLabel = 'Código Pix copiado.',
    ): string {
        $id = 'pagou-' . $prefix . '-copy-' . substr(hash('sha256', $value), 0, 10);

        return '<div class="pagou-copy-group"><label class="pagou-copy-label" for="' . $id . '">'
            . $this->safe($label) . '</label><div class="pagou-copy-row">'
            . '<input id="' . $id . '" class="pagou-copy-value" type="text" readonly value="'
            . $this->safe($value) . '" aria-label="' . $this->safe($label) . '">'
            . '<button class="pagou-copy-button" type="button" data-pagou-copy-target="' . $id . '"'
            . ' data-pagou-copy-label="' . $this->safe($buttonLabel) . '"'
            . ' data-pagou-copy-feedback="' . $this->safe($feedbackLabel) . '">'
            . '<svg aria-hidden="true" viewBox="0 0 24 24"><path d="M8 7V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-2" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"/><rect x="4" y="7" width="12" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>'
            . '<span>' . $this->safe($buttonLabel) . '</span></button></div>'
            . '<span class="pagou-copy-feedback" aria-live="polite"></span></div>';
    }

    private function clientAssets(): string
    {
        $assetDirectory = dirname(__DIR__, 5) . '/addons/pagou_payments/assets';
        $cssVersion = substr(hash_file('sha256', $assetDirectory . '/client.css') ?: 'client', 0, 12);
        $jsVersion = substr(hash_file('sha256', $assetDirectory . '/client.js') ?: 'client', 0, 12);

        return '<link rel="stylesheet" href="modules/addons/pagou_payments/assets/client.css?v=' . $cssVersion . '">'
            . '<script src="modules/addons/pagou_payments/assets/client.js?v=' . $jsVersion . '" defer></script>';
    }

    /** @return array{type:string,amount:float}|null */
    private function pixAdjustment(string $typeKey, string $amountKey): ?array
    {
        $type = $this->settings->string($typeKey, 'none');
        $amount = $this->settings->decimal($amountKey);

        if ($type === 'none' || $amount == 0.0) {
            return null;
        }
        $rule = ['type' => $type, 'amount' => $amount];
        \Pagou\Whmcs\Payment\LateChargeRules::pix($rule, $typeKey === 'pix_due_interest_type');
        return $rule;
    }

    private function amountAllowed(string $method, int $amountCents): bool
    {
        $minimum = max($method === 'boleto' ? 5.0 : 0.0, $this->settings->decimal($method . '_min_amount'));
        $maximum = $this->settings->decimal($method . '_max_amount');
        $amount = $amountCents / 100;

        return ($minimum <= 0 || $amount >= $minimum) && ($maximum <= 0 || $amount <= $maximum);
    }

    private function amountLimitMessage(string $method): string
    {
        $label = match ($method) {
            'pix' => 'Pix', 'card' => 'Cartão', default => 'boleto'
        };
        $minimum = max($method === 'boleto' ? 5.0 : 0.0, $this->settings->decimal($method . '_min_amount'));
        $maximum = $this->settings->decimal($method . '_max_amount');
        if ($minimum > 0 && $maximum > 0) {
            return sprintf('%s disponível para faturas entre R$ %.2f e R$ %.2f.', $label, $minimum, $maximum);
        }
        if ($minimum > 0) {
            return sprintf('%s disponível para faturas a partir de R$ %.2f.', $label, $minimum);
        }

        return sprintf('%s disponível para faturas de até R$ %.2f.', $label, $maximum);
    }

    /**
     * Fee the method adds over the invoice balance without any Pagou fee item.
     * @param array<string, mixed> $invoice
     */
    private function methodFeeCents(array $invoice, string $method, int $baseCents): int
    {
        $percentage = $this->settings->decimal($method . '_fee_percent');
        $fixedCents = (int) round($this->settings->decimal($method . '_fee_fixed') * 100);
        $exemptAt = $this->settings->decimal($method . '_fee_exempt_at');
        $skipForLateFeePolicy = $this->settings->boolean($method . '_fee_respect_late_fees')
            && $this->clientDisablesLateFees((int) ($invoice['userid'] ?? 0));

        return ($skipForLateFeePolicy || ($exemptAt > 0 && $baseCents >= (int) round($exemptAt * 100)))
            ? 0
            : max(0, (int) round($baseCents * $percentage / 100) + $fixedCents);
    }

    /**
     * What a method would charge for the invoice now, computed without touching
     * the invoice: the balance without Pagou fee items plus that method's fee.
     * @param array<string, mixed> $invoice
     */
    private function expectedChargeCents(array $invoice, string $method): int
    {
        $items = $invoice['items']['item'] ?? [];
        if (is_array($items) && !array_is_list($items)) {
            $items = [$items];
        }
        $feeCents = 0;
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && str_starts_with((string) ($item['description'] ?? ''), '[Pagou] Acréscimo ')) {
                $feeCents += $this->decimalCents((string) ($item['amount'] ?? '0'));
            }
        }
        $baseCents = max(0, $this->decimalCents((string) ($invoice['balance'] ?? $invoice['total'] ?? '0')) - $feeCents);

        return $baseCents + $this->methodFeeCents($invoice, $method, $baseCents);
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    private function applyConfiguredFee(array $invoice, string $method): array
    {
        $invoiceId = (int) ($invoice['invoiceid'] ?? $invoice['id'] ?? 0);
        if ($invoiceId < 1) {
            return $invoice;
        }
        $items = $invoice['items']['item'] ?? [];
        if (is_array($items) && !array_is_list($items)) {
            $items = [$items];
        }
        $feeItems = [];
        $existingCents = 0;
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item) || !str_starts_with((string) ($item['description'] ?? ''), '[Pagou] Acréscimo ')) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            if ($id > 0) {
                $feeItems[$id] = $this->decimalCents((string) ($item['amount'] ?? '0'));
                $existingCents += $feeItems[$id];
            }
        }
        $balanceCents = $this->decimalCents((string) ($invoice['balance'] ?? $invoice['total'] ?? '0'));
        $baseCents = max(0, $balanceCents - $existingCents);
        $desiredCents = $this->methodFeeCents($invoice, $method, $baseCents);
        $currentDescription = '[Pagou] Acréscimo ' . match ($method) {
            'pix' => 'Pix',
            'boleto' => 'Boleto',
            default => 'Cartão de crédito',
        };
        $currentId = null;
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && (string) ($item['description'] ?? '') === $currentDescription) {
                $currentId = (int) ($item['id'] ?? 0);
                break;
            }
        }
        $deleteIds = array_values(array_filter(array_keys($feeItems), static fn (int $id): bool => $id !== $currentId));
        if ($desiredCents === 0 && $currentId !== null) {
            $deleteIds[] = $currentId;
            $currentId = null;
        }
        $currentCents = $currentId === null ? 0 : ($feeItems[$currentId] ?? 0);
        if ($deleteIds === [] && $desiredCents === $currentCents) {
            return $invoice;
        }
        $parameters = ['invoiceid' => $invoiceId];
        if ($deleteIds !== []) {
            $parameters['deletelineids'] = array_values(array_unique($deleteIds));
        }
        $decimal = number_format($desiredCents / 100, 2, '.', '');
        if ($desiredCents > 0 && $currentId !== null) {
            $parameters['itemdescription'] = [$currentId => $currentDescription];
            $parameters['itemamount'] = [$currentId => $decimal];
            $parameters['itemtaxed'] = [$currentId => false];
        } elseif ($desiredCents > 0) {
            $parameters['newitemdescription'] = [$currentDescription];
            $parameters['newitemamount'] = [$decimal];
            $parameters['newitemtaxed'] = [false];
        }
        $this->api('UpdateInvoice', $parameters);

        return $this->api('GetInvoice', ['invoiceid' => $invoiceId]);
    }

    private function clientDisablesLateFees(int $clientId): bool
    {
        if ($clientId < 1) {
            return false;
        }
        try {
            $statement = $this->pdo->prepare('SELECT latefeeoveride FROM tblclients WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $clientId]);

            return (int) $statement->fetchColumn() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function lateChargeAllowed(string $method, int $clientId): bool
    {
        return !$this->settings->boolean($method . '_respect_late_fees')
            || !$this->clientDisablesLateFees($clientId);
    }

    private function message(string $message): string
    {
        return '<div class="alert alert-info pagou-payment">' . $this->safe($message) . '</div>';
    }

    private function stateLabel(string $state, string $method = ''): string
    {
        return match ($state) {
            'ready', 'active' => 'Pronto para pagamento',
            'paid' => 'Pagamento confirmado',
            'cancelled', 'canceled' => 'Cobrança cancelada',
            'cancel_requested' => 'Cancelamento em andamento',
            'failed' => 'Falha na emissão',
            'expired' => 'Cobrança expirada',
            'refunded' => 'Estornado',
            'superseded' => 'Cobrança substituída',
            'processing' => 'Confirmando pagamento',
            'awaiting_registration' => 'Aguardando registro bancário',
            'queued', 'pending', 'empty' => 'Preparando pagamento',
            default => 'Atualização pendente',
        };
    }

    private function pixState(string $status): string
    {
        return match (strtolower($status)) {
            'paid', 'completed' => 'paid',
            'cancelled', 'canceled', 'removed' => 'cancelled',
            'refunded' => 'refunded',
            'active' => 'ready',
            default => 'pending',
        };
    }

    private function qrImage(string $image): string
    {
        if ($image === '') {
            return '';
        }
        if (str_starts_with($image, 'data:image/')) {
            return $image;
        }

        return preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $image) === 1 ? 'data:image/png;base64,' . $image : '';
    }

    /** @param array<string, mixed> $attempt */
    private function validateLateCharges(array $attempt, string $method): bool
    {
        try {
            (new LateChargeGuard($this->pdo, $this->settings))->assertCanIssue(
                $method,
                (int) $attempt['amount_cents'],
                (int) $attempt['client_id'],
                (int) $attempt['invoice_id'],
            );
            return true;
        } catch (\Throwable $error) {
            $message = $error instanceof \InvalidArgumentException
                ? $error->getMessage() : 'Não foi possível conferir os encargos e a política de multa do WHMCS. Revise a configuração antes de emitir.';
            $this->attempts->complete((string) $attempt['id'], '', 'failed', [
                'state' => 'failed', 'message' => 'Os encargos precisam de revisão. Fale com o atendimento.',
            ]);
            $this->recordFinding((string) $attempt['id'], 'late_charges_require_review', 'high', $message);
            return false;
        }
    }

    /**
     * An overdue invoice is charged with the next day as due date, as the
     * invoice itself keeps its original date. Never earlier than the Pagou day (UTC).
     */
    public static function issuanceDueDate(string $invoiceDueDate, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now');
        $apiToday = \Pagou\Whmcs\Support\ApiCalendar::today($now);
        if (\DateTimeImmutable::createFromFormat('!Y-m-d', $invoiceDueDate) === false || $invoiceDueDate >= $apiToday) {
            return $invoiceDueDate;
        }
        $tomorrow = $now->setTimezone(new \DateTimeZone(date_default_timezone_get()))->modify('+1 day')->format('Y-m-d');

        return max($tomorrow, $apiToday);
    }

    /**
     * An immediate Pix past its validity cannot be paid. It is retired as
     * superseded, so the open invoice gets a new Pix through the usual path.
     */
    private function retireExpiredPix(int $invoiceId): void
    {
        if ($this->settings->boolean('pix_due_enabled', false)) {
            return;
        }
        $attempt = $this->attempts->latestForInvoice($invoiceId, 'pix');
        if ($attempt === null || !in_array((string) $attempt['status'], ['ready', 'pending', 'active'], true)) {
            return;
        }
        $display = $this->attempts->currentDisplayForInvoice($invoiceId, 'pix');
        if (!PaymentAttemptStore::pixExpired($display, (string) ($attempt['created_at'] ?? ''))) {
            return;
        }
        $this->attempts->markStatus((string) $attempt['id'], 'superseded');
    }

    /** @param array<string, mixed> $attempt */
    private function validateIssuanceDate(array $attempt, string $date): bool
    {
        try {
            \Pagou\Whmcs\Support\ApiCalendar::assertDueDate($date);
            return true;
        } catch (\InvalidArgumentException $error) {
            $this->attempts->complete((string) $attempt['id'], '', 'failed', [
                'state' => 'failed', 'message' => 'O vencimento precisa ser revisado. Fale com o atendimento para atualizar a fatura.',
            ]);
            $this->recordFinding((string) $attempt['id'], 'due_date_requires_review', 'high', $error->getMessage());
            return false;
        }
    }

    private function civilDate(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid WHMCS invoice due date.');
        }

        return $value;
    }

    private function decimalCents(string $value): int
    {
        return \Pagou\Whmcs\Domain\Money::fromDecimal($value)->centavos();
    }

    private function utcInstant(string $value): \DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            throw new \UnexpectedValueException('Payment date is missing or invalid.');
        }
        $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        // PHP 8.1 returns an empty error report on success; PHP 8.2+ returns false.
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new \UnexpectedValueException('Payment date is invalid.');
        }
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($value) ? $value : [];
    }

    private function safe(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
