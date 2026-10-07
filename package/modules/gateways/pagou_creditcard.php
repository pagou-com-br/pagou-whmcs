<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    exit('Acesso direto não permitido.');
}

require_once __DIR__ . '/pagou/bootstrap.php';

use Pagou\Whmcs\Application\Runtime\AddonSettings;
use Pagou\Whmcs\Application\Runtime\RuntimeFactory;
use Pagou\Whmcs\Infrastructure\Http\ApiException;
use Pagou\Whmcs\Payment\Card\CardStatus;
use Pagou\Whmcs\Payment\Card\Dto\CardChargeRequest;
use Pagou\Whmcs\Payment\Card\Exception\CardUncertainOperation;
use Pagou\Whmcs\Payment\Card\IdempotencyKey;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardAttemptStore;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardRefundStore;
use Pagou\Whmcs\Payment\Card\RemoteCardReference;
use Pagou\Whmcs\Payment\Card\RemoteInput\PdoRemoteInputSessionStore;

function pagou_creditcard_MetaData(): array
{
    return [
        'DisplayName' => 'Pagou - Cartão de crédito',
        'APIVersion' => '1.1',
        'failedEmail' => 'Credit Card Payment Failed',
        'successEmail' => 'Credit Card Payment Confirmation',
        'pendingEmail' => 'Credit Card Payment Pending',
    ];
}

function pagou_creditcard_config(): array
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'Pagou - Cartão de crédito'],
        'PagouSettings' => pagou_gateway_settings_notice(
            'card',
            'cartão',
            'Parcelamento, captura, identificação, limites e acréscimos',
        ),
    ];
}

/** O WHMCS nunca deve renderizar nem processar campos locais de cartão. */
function pagou_creditcard_nolocalcc(): void
{
}

/** @param array<string, mixed> $params */
function pagou_creditcard_remoteinput(array $params): string
{
    try {
        RuntimeFactory::migrate();
        $clientId = (int) ($params['clientdetails']['id'] ?? $params['clientdetails']['userid'] ?? 0);
        $invoiceId = (int) ($params['invoiceid'] ?? 0);
        $amount = \Pagou\Whmcs\Domain\Money::fromDecimal((string) ($params['amount'] ?? '0'));
        $workflow = $amount->isPositive() ? 'payment' : 'create';
        if ($workflow === 'payment') {
            $amount = RuntimeFactory::runtime()->assertCardInvoice($invoiceId, $clientId);
            $snapshot = (new \Pagou\Whmcs\Application\Runtime\ClientPaymentStatus(RuntimeFactory::pdo()))->read($invoiceId, 'card', $clientId, false);
            if (($snapshot['status'] ?? '') === 'ok' && \Pagou\Whmcs\Payment\Card\CardStatus::blocksNewAttempt($snapshot['state'] ?? '')) {
                return \Pagou\Whmcs\Presentation\CardInvoiceStatus::render($invoiceId, $snapshot, (string) ($params['systemurl'] ?? ''));
            }
        }
        $currency = strtoupper((string) ($params['currency'] ?? 'BRL'));
        if ($workflow === 'payment' && $currency !== 'BRL') {
            throw new InvalidArgumentException('O cartão Pagou aceita somente BRL.');
        }
        $session = (new PdoRemoteInputSessionStore(RuntimeFactory::pdo()))->issue(
            $workflow,
            $clientId,
            $workflow === 'payment' ? $invoiceId : null,
            null,
            $workflow === 'payment' ? $amount->centavos() : 0,
            $workflow === 'payment' ? $currency : 'BRL',
            null,
        );

        return pagou_creditcard_remote_form($params, $session['id'], $session['secret'], false)
            . pagou_creditcard_auto_submit_script();
    } catch (Throwable $exception) {
        if (function_exists('logActivity')) {
            logActivity('Pagou Payments: Remote Input recusado com segurança. ' . $exception::class);
        }

        return '<div class="alert alert-danger text-center">Não foi possível iniciar o pagamento seguro com cartão. '
            . 'Atualize a página ou escolha outro meio de pagamento.</div>';
    }
}

/** @param array<string, mixed> $params */
function pagou_creditcard_remoteupdate(array $params): string
{
    try {
        RuntimeFactory::migrate();
        $reference = RemoteCardReference::decode((string) ($params['gatewayid'] ?? ''));
        $clientId = (int) ($params['clientdetails']['id'] ?? $params['clientdetails']['userid'] ?? 0);
        $payMethodId = (int) ($params['paymethodid'] ?? 0);
        $session = (new PdoRemoteInputSessionStore(RuntimeFactory::pdo()))->issue(
            'update',
            $clientId,
            null,
            $payMethodId,
            0,
            'BRL',
            $reference->encode(),
        );

        return '<div id="pagou-card-update" class="text-center">'
            . pagou_creditcard_remote_form($params, $session['id'], $session['secret'], true)
            . '<iframe name="pagouCardUpdateFrame" class="auth3d-area" title="Atualização segura do cartão" '
            . 'width="100%" height="650" scrolling="auto" src="about:blank"></iframe></div>'
            . pagou_creditcard_auto_submit_script();
    } catch (Throwable $exception) {
        return '<div class="alert alert-danger text-center">Não foi possível iniciar a atualização segura do cartão.</div>';
    }
}

/** @param array<string, mixed> $params */
function pagou_creditcard_remote_form(array $params, string $sessionId, string $secret, bool $targetFrame): string
{
    $baseUrl = rtrim((string) ($params['systemurl'] ?? ''), '/') . '/';
    if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
        throw new InvalidArgumentException('O WHMCS precisa usar uma URL HTTPS válida para receber cartões.');
    }
    $action = htmlspecialchars($baseUrl . 'modules/gateways/pagou/card-input.php', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $target = $targetFrame ? ' target="pagouCardUpdateFrame"' : '';

    return '<form id="pagou-card-session-form" method="post" action="' . $action . '"' . $target . '>'
        . '<input type="hidden" name="session" value="' . htmlspecialchars($sessionId, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="authorization" value="' . htmlspecialchars($secret, ENT_QUOTES, 'UTF-8') . '">'
        . '<noscript><button type="submit" class="btn btn-primary">Continuar para o cartão</button></noscript></form>';
}

function pagou_creditcard_auto_submit_script(): string
{
    return '<script>setTimeout(function(){var f=document.getElementById("pagou-card-session-form");if(f){f.submit();}},100);</script>';
}

/** @param array<string, mixed> $params @return array<string, mixed> */
function pagou_creditcard_capture(array $params): array
{
    $attemptId = null;
    try {
        if (strtoupper((string) ($params['currency'] ?? '')) !== 'BRL') {
            throw new InvalidArgumentException('O cartão Pagou aceita somente BRL.');
        }
        $reference = RemoteCardReference::decode((string) ($params['gatewayid'] ?? ''));
        $invoiceId = (int) ($params['invoiceid'] ?? 0);
        $clientId = (int) ($params['clientdetails']['id'] ?? $params['clientdetails']['userid'] ?? 0);
        $settings = AddonSettings::fromPdo(RuntimeFactory::pdo());
        \Pagou\Whmcs\Payment\Card\CardCheckoutPolicy::assertSupported($settings->integer('card_max_installments', 1), $settings->boolean('card_auto_capture', true));
        $expectedCents = \Pagou\Whmcs\Domain\Money::fromDecimal((string) ($params['amount'] ?? '0'))->centavos();
        $amount = RuntimeFactory::runtime()->assertCardInvoice($invoiceId, $clientId, $expectedCents);
        $attempts = new PdoCardAttemptStore(RuntimeFactory::pdo());
        $attempt = $attempts->begin($invoiceId, $clientId, $amount->centavos(), $reference->encode(), 1);
        $attemptId = $attempt['id'];
        if (!$attempt['created']) {
            $stored = $attempts->latestForInvoice($invoiceId);
            if ($attempt['status'] === 'paid' && is_string($attempt['remote_id']) && (int) ($stored['amount_cents'] ?? 0) === $amount->centavos()) {
                // WHMCS records the Pagou charge identifier; the ledger keeps its economic hash.
                return ['status' => 'success', 'transid' => $attempt['remote_id'], 'rawdata' => ['status' => 'idempotent_replay']];
            }
            return ['status' => 'pending', 'transid' => $attempt['remote_id'] ?? '', 'rawdata' => ['status' => $attempt['status']]];
        }
        $charge = RuntimeFactory::card()->chargeRecurring(
            new CardChargeRequest(
                $amount->centavos(),
                $reference->customerId,
                $reference->cardId,
                'Fatura WHMCS ' . $invoiceId,
                1,
                $settings->boolean('card_auto_capture', true),
                [],
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d'),
                $settings->string('card_soft_descriptor', 'PAGOU'),
            ),
            $attempt['key'],
        );
        // A cobrança concluída nunca pode ser convertida em falha apenas porque
        // uma leitura auxiliar dos dados mascarados do cartão ficou indisponível.
        $attempts->rememberRemoteId($attemptId, $charge->id);
        if (!$attempts->complete($attemptId, $charge, $reference->encode(), null, null, 1)) {
            return ['status' => 'pending', 'rawdata' => ['reason' => 'stale_card_response']];
        }

        return pagou_creditcard_result($charge->status, $charge->id);
    } catch (CardUncertainOperation) {
        if (is_string($attemptId)) {
            (new PdoCardAttemptStore(RuntimeFactory::pdo()))->uncertain($attemptId);
        }
        return ['status' => 'pending', 'rawdata' => ['reason' => 'card_outcome_requires_reconciliation']];
    } catch (ApiException $exception) {
        if (!$exception->isConclusiveRejection()) {
            if (is_string($attemptId)) {
                (new PdoCardAttemptStore(RuntimeFactory::pdo()))->uncertain($attemptId);
            }
            return ['status' => 'pending', 'rawdata' => ['reason' => 'card_outcome_requires_reconciliation']];
        }
        if (is_string($attemptId)) {
            (new PdoCardAttemptStore(RuntimeFactory::pdo()))->failed($attemptId);
        }
        return [
            'status' => 'declined',
            'declinereason' => 'A cobrança não foi autorizada. Confira o cartão ou escolha outro meio de pagamento.',
            'rawdata' => ['reason' => $exception->kind],
        ];
    } catch (Throwable $exception) {
        if ($attemptId === null) {
            return ['status' => 'declined', 'declinereason' => 'Revise a fatura e o valor antes de tentar novamente.', 'rawdata' => ['reason' => 'card_invoice_precondition_failed']];
        }
        if (is_string($attemptId)) {
            (new PdoCardAttemptStore(RuntimeFactory::pdo()))->uncertain($attemptId);
        }
        return ['status' => 'pending', 'rawdata' => ['reason' => 'card_outcome_requires_reconciliation']];
    }
}

/** @param array<string, mixed> $params @return array<string, mixed> */
function pagou_creditcard_refund(array $params): array
{
    $refundId = null;
    try {
        $transactionId = trim((string) ($params['transid'] ?? ''));
        if ($transactionId === '') {
            throw new InvalidArgumentException('A transação original não foi informada.');
        }
        $original = (new \Pagou\Whmcs\Payment\Ledger\Infrastructure\PaymentTransactionResolver(RuntimeFactory::pdo()))
            ->resolve((int) ($params['invoiceid'] ?? 0), $transactionId, 'card');
        $transactionId = (string) $original['remote_id'];
        $originalCents = (int) $original['amount_cents'];
        $requested = \Pagou\Whmcs\Domain\Money::fromDecimal((string) ($params['amount'] ?? '0'))->centavos();
        if (!is_numeric($originalCents) || (int) $originalCents !== $requested) {
            return ['status' => 'error', 'rawdata' => ['reason' => 'partial_refund_not_supported']];
        }
        $key = IdempotencyKey::create('refund', 'transaction:' . $transactionId . ':amount:' . $requested);
        $refundStore = new PdoCardRefundStore(RuntimeFactory::pdo());
        $localRefund = $refundStore->begin($transactionId, $requested, $key);
        $refundId = $localRefund['id'];
        if (!$localRefund['created']) {
            return pagou_creditcard_refund_result($localRefund['status'], $refundId);
        }
        $refund = RuntimeFactory::card()->refund(
            $transactionId,
            $key,
        );
        $refundStore->complete($refundId, $refund);
        (new PdoCardAttemptStore(RuntimeFactory::pdo()))->synchronize($refund);

        return pagou_creditcard_refund_result($refund->status->value, $refundId);
    } catch (CardUncertainOperation $exception) {
        if (is_string($refundId)) {
            (new PdoCardRefundStore(RuntimeFactory::pdo()))->mark($refundId, 'uncertain');
        }
        return pagou_creditcard_refund_result('uncertain', $refundId ?? '');
    } catch (Throwable $exception) {
        if (is_string($refundId)) {
            $conclusive = $exception instanceof ApiException && $exception->isConclusiveRejection();
            (new PdoCardRefundStore(RuntimeFactory::pdo()))->mark($refundId, $conclusive ? 'failed' : 'uncertain');
            if (!$conclusive) {
                return pagou_creditcard_refund_result('uncertain', $refundId ?? '');
            }
        }
        return ['status' => 'error', 'rawdata' => ['reason' => $exception::class]];
    }
}

/** @param array<string, mixed> $params @return array<string, mixed> */
function pagou_creditcard_storeremote(array $params): array
{
    $action = strtolower((string) ($params['action'] ?? ''));
    if ($action !== 'delete') {
        return ['status' => 'error', 'rawdata' => ['reason' => 'remote_input_required']];
    }
    try {
        $reference = RemoteCardReference::decode((string) ($params['gatewayid'] ?? ''));
        RuntimeFactory::card()->deleteCard(
            $reference->cardId,
            IdempotencyKey::create('card_delete', 'card:' . $reference->cardId),
        );

        return ['status' => 'success'];
    } catch (Throwable $exception) {
        return ['status' => 'error', 'rawdata' => ['reason' => $exception::class]];
    }
}

/** @return array{string, string} */
function pagou_creditcard_token(string $token): array
{
    $reference = RemoteCardReference::decode($token);

    return [$reference->customerId, $reference->cardId];
}

/** @return array<string, mixed> */
function pagou_creditcard_result(CardStatus $status, string $transactionId): array
{
    $whmcsStatus = match ($status) {
        CardStatus::Paid, CardStatus::Settled => 'success',
        CardStatus::Pending, CardStatus::Authorized, CardStatus::ActionRequired, CardStatus::Unknown => 'pending',
        default => 'declined',
    };

    return ['status' => $whmcsStatus, 'transid' => $transactionId, 'rawdata' => ['status' => $status->value]];
}

/** @return array<string, mixed> */
function pagou_creditcard_refund_result(string $status, string $refundId): array
{
    if (in_array($status, ['reversed', 'refunded'], true) && $refundId !== '') {
        return [
            'status' => 'success',
            'transid' => 'pagou-card-refund:' . $refundId,
            'rawdata' => ['status' => $status],
        ];
    }

    // WHMCS refunds accept success/declined/error, never pending.
    return [
        'status' => 'error',
        'rawdata' => [
            'reason' => $status === 'failed' ? 'refund_rejected' : 'refund_requires_reconciliation',
            'message' => $status === 'failed'
                ? 'O estorno foi recusado. Confira a operação antes de tentar novamente.'
                : 'O estorno ainda não foi confirmado. Aguarde a conciliação e confira a operação no módulo Pagou.',
        ],
    ];
}
