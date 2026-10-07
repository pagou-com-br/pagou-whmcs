<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/init.php';
require_once dirname(__DIR__) . '/pagou/bootstrap.php';

use Pagou\Whmcs\Application\Runtime\AddonSettings;
use Pagou\Whmcs\Application\Runtime\ClientDocumentResolver;
use Pagou\Whmcs\Application\Runtime\RuntimeFactory;
use Pagou\Whmcs\Infrastructure\Http\ApiException;
use Pagou\Whmcs\Payment\Card\Dto\CardChargeRequest;
use Pagou\Whmcs\Payment\Card\Exception\CardUncertainOperation;
use Pagou\Whmcs\Payment\Card\IdempotencyKey;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardAttemptStore;
use Pagou\Whmcs\Payment\Card\Infrastructure\PdoCardCustomerStore;
use Pagou\Whmcs\Payment\Card\RemoteCardReference;
use Pagou\Whmcs\Payment\Card\RemoteInput\PdoRemoteInputSessionStore;

\App::load_function('gateway');
\App::load_function('invoice');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

/** @return never */
function pagou_card_callback_message(string $title, string $message, bool $success = false): void
{
    http_response_code($success ? 200 : 400);
    header('Content-Type: text/html; charset=utf-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>' . $safeTitle . '</title>'
        . '<body style="font:15px system-ui,sans-serif;color:#172033;background:#fff;padding:24px;text-align:center">'
        . '<h1 style="font-size:19px">' . $safeTitle . '</h1><p style="color:#667085">' . $safeMessage . '</p></body></html>';
    exit;
}

/** @return never */
function pagou_card_pending_redirect(int $invoiceId): void
{
    $base = rtrim((string) (getGatewayVariables('pagou_creditcard')['systemurl'] ?? ''), '/');
    if (filter_var($base, FILTER_VALIDATE_URL) === false || parse_url($base, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('A URL da fatura não está configurada corretamente.');
    }
    $path = $base . '/viewinvoice.php?id=' . $invoiceId;
    $encoded = json_encode($path, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Pagamento em processamento</title>'
        . '<body style="font:15px system-ui,sans-serif;color:#172033;background:#fff;padding:24px;text-align:center">'
        . '<h1 style="font-size:19px">Pagamento em processamento</h1><p style="color:#667085">A Pagou está confirmando o cartão. A fatura será atualizada automaticamente.</p>'
        . '<script>window.top.location.href=' . $encoded . ';</script></body></html>';
    exit;
}

/** @return never */
function pagou_card_three_ds_redirect(string $url): void
{
    $url = trim($url);
    if (filter_var($url, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
        throw new RuntimeException('A Pagou não retornou um endereço HTTPS válido para a autenticação do cartão.');
    }
    $encoded = json_encode($url, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Autenticação do cartão</title>'
        . '<body style="font:15px system-ui,sans-serif;color:#172033;background:#fff;padding:24px;text-align:center">'
        . '<h1 style="font-size:19px">Confirme o pagamento com seu banco</h1>'
        . '<p style="color:#667085">Você será direcionado para a autenticação segura do emissor.</p>'
        . '<script>window.top.location.replace(' . $encoded . ');</script></body></html>';
    exit;
}

/** @param array<string, mixed> $client @return array<string, mixed> */
function pagou_card_customer_payload(array $client, string $document): array
{
    $name = trim((string) ($client['firstname'] ?? '') . ' ' . (string) ($client['lastname'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($client['companyname'] ?? ''));
    }
    $phone = preg_replace('/\D+/', '', (string) ($client['phonenumber'] ?? '')) ?? '';
    $zip = preg_replace('/\D+/', '', (string) ($client['postcode'] ?? '')) ?? '';
    $state = strtoupper(trim((string) ($client['state'] ?? '')));
    $street = trim((string) ($client['address1'] ?? ''));
    $complement = trim((string) ($client['address2'] ?? ''));
    $city = trim((string) ($client['city'] ?? ''));
    if (mb_strlen($name) < 3 || filter_var((string) ($client['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Complete o nome e o e-mail do cadastro antes de usar o cartão.');
    }
    if (strlen($phone) < 10 || strlen($phone) > 14 || strlen($zip) !== 8 || strlen($state) !== 2 || $street === '' || $city === '') {
        throw new RuntimeException('Complete telefone, CEP, endereço, cidade e estado antes de usar o cartão.');
    }

    return [
        'document' => $document,
        'document_type' => strlen($document) === 14 ? 'cnpj' : 'cpf',
        'name' => $name,
        'email' => (string) $client['email'],
        'phone' => $phone,
        'address' => [
            'zip_code' => $zip,
            'street' => $street,
            'number' => 'S/N',
            'complement' => $complement,
            'neighborhood' => $complement !== '' ? mb_substr($complement, 0, 120) : 'Não informado',
            'city' => $city,
            'state' => $state,
        ],
    ];
}

/** @param array<string, mixed> $session @param array<string, mixed> $gatewayParams @return array<string, mixed> */
function pagou_card_three_ds(array $session, array $gatewayParams): array
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || (string) ($session['workflow'] ?? '') !== 'payment') {
        return [];
    }
    $read = static function (string $key, int $max = 64): string {
        $value = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        return mb_substr($value, 0, $max);
    };
    $height = $read('device_screen_height', 8);
    $width = $read('device_screen_width', 8);
    $depth = $read('device_color_depth', 3);
    $language = $read('device_language', 8);
    if (!ctype_digit($height) || !ctype_digit($width) || !ctype_digit($depth) || $language === '') {
        return [];
    }
    $systemUrl = rtrim((string) ($gatewayParams['systemurl'] ?? ''), '/');
    $invoiceId = (int) ($session['invoice_id'] ?? 0);
    if (filter_var($systemUrl, FILTER_VALIDATE_URL) === false || parse_url($systemUrl, PHP_URL_SCHEME) !== 'https' || $invoiceId < 1) {
        return [];
    }

    return ['three_ds' => ['internal_emv' => [
        'ip' => $ip,
        'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'redirect_url_3ds' => $systemUrl . '/viewinvoice.php?id=' . $invoiceId,
        'device' => [
            'color_depth' => $depth,
            'device_type_3ds' => 'BROWSER',
            'java_enabled' => $read('device_java_enabled', 1) === '1',
            'language' => $language,
            'screen_height' => $height,
            'screen_width' => $width,
            'time_zone_offset' => $read('device_timezone_offset', 8),
        ],
    ]]];
}

/** @param array<string, mixed> $session */
function pagou_card_pay_method(int $clientId, array $session, bool $lock = false): object
{
    $query = \WHMCS\User\Client::findOrFail($clientId)->payMethods();
    if ($lock) {
        $query->lockForUpdate();
    }
    $payMethod = $query->findOrFail((int) ($session['pay_method_id'] ?? 0));
    if (
        !$payMethod->isRemoteCreditCard()
        || !hash_equals((string) ($session['existing_reference'] ?? ''), (string) $payMethod->payment->getRemoteToken())
    ) {
        throw new RuntimeException('O método de pagamento mudou. Atualize a página antes de substituir o cartão.');
    }
    return $payMethod;
}

function pagou_card_save_invoice_reference(int $invoiceId, string $last4, string $brand, string $expiry, string $reference): void
{
    try {
        invoiceSaveRemoteCard($invoiceId, $last4, $brand, $expiry, $reference);
    } catch (Throwable $exception) {
        // Saving a Pay Method must never hide an already dispatched payment.
        if (function_exists('logActivity')) {
            logActivity('Pagou Payments: pagamento preservado; salvamento do cartão precisa de revisão. ' . $exception::class);
        }
    }
}

$gatewayParams = getGatewayVariables('pagou_creditcard');
if (empty($gatewayParams['type'])) {
    pagou_card_callback_message('Cartão indisponível', 'O meio de pagamento não está ativo nesta instalação.');
}

$sessionId = '';
$sessionStore = null;
$ownsSession = false;
$chargeDispatched = false;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Método não permitido.');
    }
    $sessionId = is_string($_POST['session'] ?? null) ? trim($_POST['session']) : '';
    $authorization = is_string($_POST['authorization'] ?? null) ? trim($_POST['authorization']) : '';
    $opaqueToken = is_string($_POST['card_token'] ?? null) ? trim($_POST['card_token']) : '';
    $holder = is_string($_POST['holder'] ?? null) ? trim($_POST['holder']) : '';
    $expiresAt = is_string($_POST['expires_at'] ?? null) ? trim($_POST['expires_at']) : '';
    if (
        $opaqueToken === '' || strlen($opaqueToken) > 32768 || mb_strlen($holder) < 2 || mb_strlen($holder) > 80
        || preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $expiresAt) !== 1
    ) {
        throw new InvalidArgumentException('Os dados tokenizados do cartão são inválidos.');
    }

    RuntimeFactory::migrate();
    $pdo = RuntimeFactory::pdo();
    $sessionStore = new PdoRemoteInputSessionStore($pdo, static fn (callable $write) => \WHMCS\Database\Capsule::connection()->transaction($write));
    $session = $sessionStore->inspect($sessionId, $authorization);
    if (($session['status'] ?? '') === 'processing') {
        if (($session['workflow'] ?? '') === 'payment') {
            pagou_card_pending_redirect((int) $session['invoice_id']);
        }
        pagou_card_callback_message('Cartão em processamento', 'Aguarde a confirmação desta operação antes de tentar novamente.', true);
    }
    $session = $sessionStore->claim($sessionId, $authorization);
    $workflow = (string) ($session['workflow'] ?? '');
    if ((string) ($session['status'] ?? '') === 'completed') {
        $result = json_decode((string) ($session['result_json'] ?? '{}'), true);
        if ($workflow === 'payment' && (int) ($session['invoice_id'] ?? 0) > 0) {
            if (!empty($result['pending'])) {
                pagou_card_pending_redirect((int) $session['invoice_id']);
            }
            callback3DSecureRedirect((int) $session['invoice_id'], (bool) ($result['paid'] ?? false));
        }
        if (!empty($result['pending'])) {
            pagou_card_callback_message('Confirmação pendente', 'Confira seus métodos de pagamento antes de tentar novamente.', true);
        }
        pagou_card_callback_message('Operação concluída', 'O cartão já foi processado.', true);
    }

    $ownsSession = true;
    $clientId = (int) ($session['client_id'] ?? 0);
    if ($workflow === 'payment') {
        RuntimeFactory::runtime()->assertCardInvoice((int) ($session['invoice_id'] ?? 0), $clientId, (int) ($session['amount_cents'] ?? 0));
        $existing = (new PdoCardAttemptStore($pdo))->latestForInvoice((int) $session['invoice_id']);
        if ($existing !== null && \Pagou\Whmcs\Payment\Card\CardStatus::blocksNewAttempt((string) $existing['status'])) {
            $sessionStore->complete($sessionId, ['workflow' => 'payment', 'paid' => false, 'pending' => true]);
            pagou_card_pending_redirect((int) $session['invoice_id']);
        }
    }
    if ($workflow === 'update') {
        pagou_card_pay_method($clientId, $session);
    }
    $localApi = RuntimeFactory::localApi();
    $client = $localApi('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
    if (($client['result'] ?? '') !== 'success') {
        throw new RuntimeException('Não foi possível consultar o cadastro do cliente.');
    }
    $settings = AddonSettings::fromPdo($pdo);
    \Pagou\Whmcs\Payment\Card\CardCheckoutPolicy::assertSupported($settings->integer('card_max_installments', 1), $settings->boolean('card_auto_capture', true));
    $document = (new ClientDocumentResolver($pdo, $settings))->resolve($client, $clientId);
    $customerStore = new PdoCardCustomerStore($pdo);
    $providerCustomerId = $customerStore->find($clientId, $document);
    if ($providerCustomerId === null) {
        $providerCustomer = RuntimeFactory::card()->createCustomer(
            pagou_card_customer_payload($client, $document),
            IdempotencyKey::create('customer_create', 'whmcs-client:' . $clientId . ':document:' . hash('sha256', $document)),
        );
        $providerCustomerId = $providerCustomer->id;
        $customerStore->save($clientId, $providerCustomerId, $document);
    }

    $card = RuntimeFactory::card()->registerOpaqueCard(
        $providerCustomerId,
        ['card_token' => $opaqueToken, 'holder' => $holder, 'expires_at' => $expiresAt],
        IdempotencyKey::create('card_create', 'session:' . $sessionId),
    );
    unset($opaqueToken, $_POST['card_token']);
    $reference = new RemoteCardReference($providerCustomerId, $card->id);
    $remoteReference = $reference->encode();
    if (preg_match('/^\d{4}$/', (string) $card->lastFour) !== 1) {
        throw new RuntimeException('A Pagou não retornou a identificação mascarada do cartão.');
    }
    $last4 = (string) $card->lastFour;
    $brand = mb_substr(trim((string) ($card->brand ?? '')), 0, 32);
    if ($brand === '') {
        $brand = 'Cartão';
    }
    $expiryParts = explode('-', $expiresAt, 2);
    $expiryMmyy = ($expiryParts[1] ?? '') . substr((string) ($expiryParts[0] ?? ''), -2);

    if ($workflow === 'create') {
        $sessionStore->persistResult(
            $sessionId,
            ['workflow' => 'create', 'card_id' => $card->id],
            static function () use ($clientId, $last4, $expiryMmyy, $brand, $remoteReference): void {
                createCardPayMethod($clientId, 'pagou_creditcard', $last4, $expiryMmyy, $brand, null, null, $remoteReference);
            }
        );
        pagou_card_callback_message('Cartão salvo', 'O cartão foi adicionado com segurança.', true);
    }

    if ($workflow === 'update') {
        $payMethodId = (int) ($session['pay_method_id'] ?? 0);
        $sessionStore->persistResult(
            $sessionId,
            ['workflow' => 'update', 'card_id' => $card->id],
            static function () use ($clientId, $session, $payMethodId, $expiryMmyy, $remoteReference, $last4, $brand): void {
                $payMethod = pagou_card_pay_method($clientId, $session, true);
                $payment = $payMethod->payment;
                updateCardPayMethod($clientId, $payMethodId, $expiryMmyy, null, null, $remoteReference);
                $payment->refresh();
                $payment->setCardNumber($last4);
                $payment->setCardType($brand);
                $payment->saveOrFail();
            }
        );
        try {
            $previous = RemoteCardReference::decode((string) ($session['existing_reference'] ?? ''));
            RuntimeFactory::card()->deleteCard($previous->cardId, IdempotencyKey::create('card_delete', 'replaced-card:' . $previous->cardId));
        } catch (Throwable $cleanupFailure) {
            if (function_exists('logActivity')) {
                logActivity('Pagou Payments: cartão atualizado; limpeza remota anterior pendente. ' . $cleanupFailure::class);
            }
        }
        pagou_card_callback_message('Cartão atualizado', 'Os novos dados foram salvos com segurança.', true);
    }

    if ($workflow !== 'payment') {
        throw new RuntimeException('O fluxo desta sessão de cartão é inválido.');
    }
    $invoiceId = checkCbInvoiceID((int) ($session['invoice_id'] ?? 0), (string) ($gatewayParams['paymentmethod'] ?? 'pagou_creditcard'));
    $amountCents = (int) ($session['amount_cents'] ?? 0);
    $maxInstallments = max(1, min(12, $settings->integer('card_max_installments', 1)));
    $requestedInstallments = is_string($_POST['installments'] ?? null) && ctype_digit($_POST['installments']) ? (int) $_POST['installments'] : 1;
    $installments = max(1, min($maxInstallments, $requestedInstallments));
    $attempts = new PdoCardAttemptStore($pdo);
    RuntimeFactory::runtime()->assertCardInvoice($invoiceId, $clientId, $amountCents);
    $attempt = $attempts->begin($invoiceId, $clientId, $amountCents, $remoteReference, $installments);
    if (!$attempt['created']) {
        $sessionStore->complete($sessionId, ['workflow' => 'payment', 'paid' => $attempt['status'] === 'paid', 'charge_id' => $attempt['remote_id']]);
        if ($attempt['status'] === 'paid') {
            callback3DSecureRedirect($invoiceId, true);
        }
        pagou_card_pending_redirect($invoiceId);
    }
    try {
        $chargeDispatched = true;
        $charge = RuntimeFactory::card()->charge(
            new CardChargeRequest(
                $amountCents,
                $providerCustomerId,
                $card->id,
                'Fatura WHMCS ' . $invoiceId,
                $installments,
                $settings->boolean('card_auto_capture', true),
                pagou_card_three_ds($session, $gatewayParams),
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d'),
                $settings->string('card_soft_descriptor', 'PAGOU'),
            ),
            $attempt['key'],
        );
        $attempts->rememberRemoteId($attempt['id'], $charge->id);
        $attempts->complete($attempt['id'], $charge, $remoteReference, $card->brand, $card->lastFour, $installments);
    } catch (CardUncertainOperation $exception) {
        $attempts->uncertain($attempt['id']);
        throw $exception;
    } catch (Throwable $exception) {
        $exception instanceof ApiException && $exception->isConclusiveRejection()
            ? $attempts->failed($attempt['id'])
            : $attempts->uncertain($attempt['id']);
        if (!$exception instanceof ApiException || !$exception->isConclusiveRejection()) {
            throw new CardUncertainOperation('O resultado exige conciliação.', 0, $exception);
        }
        throw $exception;
    }

    if ($charge->status->permitsAutomaticInvoiceSettlement()) {
        pagou_card_save_invoice_reference($invoiceId, $last4, $brand, $expiryMmyy, $remoteReference);
        $outcome = RuntimeFactory::cardReconciliation($pdo)->process($charge);
        if ($outcome === 'applied') {
            $sessionStore->complete($sessionId, ['workflow' => 'payment', 'paid' => true, 'charge_id' => $charge->id]);
            callback3DSecureRedirect($invoiceId, true);
        }
        $sessionStore->complete($sessionId, ['workflow' => 'payment', 'paid' => false, 'pending' => true, 'charge_id' => $charge->id]);
        pagou_card_pending_redirect($invoiceId);
    }
    if ($charge->status === \Pagou\Whmcs\Payment\Card\CardStatus::ActionRequired && $charge->threeDsUrl !== null) {
        pagou_card_save_invoice_reference($invoiceId, $last4, $brand, $expiryMmyy, $remoteReference);
        $sessionStore->complete($sessionId, [
            'workflow' => 'payment',
            'paid' => false,
            'pending' => true,
            'three_ds' => true,
            'charge_id' => $charge->id,
        ]);
        pagou_card_three_ds_redirect($charge->threeDsUrl);
    }
    if ($charge->status->requiresReconciliation()) {
        pagou_card_save_invoice_reference($invoiceId, $last4, $brand, $expiryMmyy, $remoteReference);
        $sessionStore->complete($sessionId, ['workflow' => 'payment', 'paid' => false, 'pending' => true, 'charge_id' => $charge->id]);
        pagou_card_pending_redirect($invoiceId);
    }

    $sessionStore->complete($sessionId, ['workflow' => 'payment', 'paid' => false, 'charge_id' => $charge->id]);
    if (function_exists('sendMessage')) {
        sendMessage('Credit Card Payment Failed', $invoiceId);
    }
    callback3DSecureRedirect($invoiceId, false);
} catch (CardUncertainOperation) {
    if ($ownsSession && $sessionStore instanceof PdoRemoteInputSessionStore && $sessionId !== '') {
        $sessionStore->complete($sessionId, ['workflow' => $workflow ?? '', 'paid' => false, 'pending' => true]);
    }
    $invoiceId = isset($session) && is_array($session) ? (int) ($session['invoice_id'] ?? 0) : 0;
    if ($invoiceId > 0) {
        pagou_card_pending_redirect($invoiceId);
    }
    pagou_card_callback_message('Confirmação pendente', 'Ainda não foi possível confirmar o cadastro do cartão. Confira seus métodos de pagamento antes de tentar novamente.', true);
} catch (ApiException $exception) {
    if ($ownsSession && $sessionStore instanceof PdoRemoteInputSessionStore && $sessionId !== '') {
        $sessionStore->fail($sessionId);
    }
    if (function_exists('logActivity')) {
        logActivity('Pagou Payments: cartão não autorizado. ' . $exception->kind);
    }
    pagou_card_callback_message(
        'Cartão não autorizado',
        'Confira os dados do cartão ou escolha outro meio de pagamento.',
    );
} catch (Throwable $exception) {
    if ($ownsSession && $chargeDispatched && isset($invoiceId) && $invoiceId > 0) {
        if (function_exists('logActivity')) {
            logActivity('Pagou Payments: confirmação local do cartão pendente. ' . $exception::class);
        }
        $sessionStore->complete($sessionId, ['workflow' => 'payment', 'paid' => false, 'pending' => true]);
        pagou_card_pending_redirect($invoiceId);
    }
    if ($ownsSession && $sessionStore instanceof PdoRemoteInputSessionStore && $sessionId !== '') {
        $sessionStore->fail($sessionId);
    }
    if (function_exists('logActivity')) {
        logActivity('Pagou Payments: operação segura de cartão recusada. ' . $exception::class);
    }
    pagou_card_callback_message(
        'Não foi possível concluir',
        'Confira os dados do cadastro e do cartão. Se o problema continuar, escolha outro meio de pagamento ou fale com o suporte.',
    );
}
