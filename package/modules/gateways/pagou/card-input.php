<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/init.php';
require_once __DIR__ . '/bootstrap.php';

use Pagou\Whmcs\Application\Runtime\AddonSettings;
use Pagou\Whmcs\Application\Runtime\RuntimeFactory;
use Pagou\Whmcs\Payment\Card\RemoteInput\PdoRemoteInputSessionStore;

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
header(
    "Content-Security-Policy: default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'; "
    . "script-src 'nonce-{$nonce}' https://cdn.jsdelivr.net https://js.cel.cash; "
    . "style-src 'nonce-{$nonce}'; img-src 'self' data:; connect-src https:; font-src 'none'"
);

/** @return never */
function pagou_card_input_error(string $message, int $status = 400): void
{
    global $nonce;
    http_response_code($status);
    $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Cartão Pagou</title>'
        . '<style nonce="' . htmlspecialchars((string) $nonce, ENT_QUOTES, 'UTF-8') . '">body{font:15px system-ui,sans-serif;color:#172033;background:#f7f9fc;padding:24px}'
        . '.box{max-width:560px;margin:auto;padding:22px;border:1px solid #dce3ee;border-radius:8px;background:#fff}'
        . 'h1{font-size:18px;margin:0 0 8px}p{margin:0;color:#667085;line-height:1.5}</style>'
        . '<body><main class="box"><h1>Não foi possível abrir o pagamento seguro</h1><p>' . $safe
        . '</p></main></body></html>';
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        pagou_card_input_error('Atualize a fatura e tente novamente.', 405);
    }
    $sessionId = is_string($_POST['session'] ?? null) ? trim($_POST['session']) : '';
    $authorization = is_string($_POST['authorization'] ?? null) ? trim($_POST['authorization']) : '';
    RuntimeFactory::migrate();
    $session = (new PdoRemoteInputSessionStore(RuntimeFactory::pdo()))->inspect($sessionId, $authorization);
    if ((string) ($session['status'] ?? '') === 'completed') {
        pagou_card_input_error('Esta operação já foi concluída. Feche esta janela e atualize a página.', 409);
    }
    if ((string) ($session['status'] ?? '') === 'processing') {
        pagou_card_input_error('Esta operação já está em processamento. Aguarde a confirmação antes de tentar novamente.', 409);
    }
    $settings = AddonSettings::fromPdo(RuntimeFactory::pdo());
    \Pagou\Whmcs\Payment\Card\CardCheckoutPolicy::assertSupported($settings->integer('card_max_installments', 1), $settings->boolean('card_auto_capture', true));
    $maxInstallments = (string) ($session['workflow'] ?? '') === 'payment'
        ? max(1, min(12, $settings->integer('card_max_installments', 1)))
        : 1;
    $callback = '../callback/pagou_creditcard.php';
} catch (Throwable) {
    pagou_card_input_error('A sessão expirou ou não é mais válida. Atualize a página e tente novamente.', 403);
}

$safeSession = htmlspecialchars($sessionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeAuthorization = htmlspecialchars($authorization, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$workflow = (string) ($session['workflow'] ?? 'payment');
$title = $workflow === 'update' ? 'Atualizar cartão' : ($workflow === 'create' ? 'Adicionar cartão' : 'Pagar com cartão');
$amount = (int) ($session['amount_cents'] ?? 0);
$amountText = number_format($amount / 100, 2, ',', '.');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | Pagou</title>
    <style nonce="<?= $nonce ?>">
        :root{color-scheme:light;--blue:#2856d9;--ink:#172033;--muted:#667085;--line:#d9e1ec;--soft:#f6f8fc;--danger:#b42318}
        *{box-sizing:border-box}body{margin:0;padding:18px;background:#fff;color:var(--ink);font:14px/1.45 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        .shell{max-width:620px;margin:0 auto;border:1px solid var(--line);border-radius:8px;box-shadow:0 8px 28px rgba(29,45,76,.08);overflow:hidden;background:#fff}
        .head{padding:20px 22px 16px;border-bottom:1px solid var(--line);background:linear-gradient(135deg,#fff,#f7f9ff)}
        .eyebrow{display:block;color:var(--blue);font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase}.head h1{font-size:21px;margin:4px 0}.head p{margin:0;color:var(--muted)}
        .amount{font-weight:700;color:var(--ink)}form{padding:20px 22px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.wide{grid-column:1/-1}
        label{display:block;margin-bottom:6px;font-weight:600;color:#344054}.input{width:100%;height:42px;border:1px solid #cbd5e1;border-radius:5px;padding:0 12px;background:#fff;color:var(--ink);font:inherit;outline:none}
        .input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(40,86,217,.12)}.hint{margin:5px 0 0;color:var(--muted);font-size:12px}
        .secure{display:flex;align-items:center;gap:8px;margin:18px 0;padding:11px 12px;border:1px solid #dbe5fb;border-left:3px solid var(--blue);border-radius:5px;background:#f7f9ff;color:#43506a}
        .secure svg{width:18px;height:18px;color:var(--blue);flex:none}.actions{display:flex;justify-content:flex-end;align-items:center;gap:12px}.button{min-height:42px;border:0;border-radius:4px;padding:0 18px;background:var(--blue);color:#fff;font:600 14px inherit;cursor:pointer}
        .button:disabled{cursor:wait;opacity:.65}.error{display:none;margin:0 0 14px;padding:10px 12px;border:1px solid #f4c7c3;border-radius:5px;background:#fff5f4;color:var(--danger)}.error.show{display:block}
        @media(max-width:560px){body{padding:0}.shell{border:0;border-radius:0;box-shadow:none}.grid{grid-template-columns:1fr}.wide{grid-column:auto}.actions{justify-content:stretch}.button{width:100%}}
    </style>
</head>
<body>
<main class="shell">
    <header class="head">
        <span class="eyebrow">Pagamento protegido pela Pagou</span>
        <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= $amount > 0 ? 'Valor desta cobrança: <span class="amount">R$ ' . $amountText . '</span>' : 'Cadastre o cartão com segurança para pagamentos futuros.' ?></p>
    </header>
    <form id="card-form" autocomplete="on" novalidate>
        <p id="error" class="error" role="alert"></p>
        <div class="grid">
            <div class="wide"><label for="holder">Nome impresso no cartão</label><input class="input" id="holder" autocomplete="cc-name" maxlength="80" required></div>
            <div class="wide"><label for="number">Número do cartão</label><input class="input" id="number" inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="0000 0000 0000 0000" required></div>
            <div><label for="expiry">Validade</label><input class="input" id="expiry" inputmode="numeric" autocomplete="cc-exp" maxlength="5" placeholder="MM/AA" required></div>
            <div><label for="cvv">Código de segurança</label><input class="input" id="cvv" type="password" inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="CVV" required></div>
<?php if ($maxInstallments > 1) : ?>
            <div class="wide"><label for="installments">Parcelamento</label><select class="input" id="installments">
    <?php for ($index = 1; $index <= $maxInstallments; ++$index) : ?>
                <option value="<?= $index ?>"><?= $index === 1 ? 'Crédito à vista' : $index . ' parcelas' ?></option>
    <?php endfor; ?>
            </select><p class="hint">A fatura permanece única no WHMCS.</p></div>
<?php endif; ?>
        </div>
        <div class="secure"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><span>Os dados do cartão são transformados em um token no navegador. O WHMCS não recebe número nem CVV.</span></div>
        <div class="actions"><button id="submit" class="button" type="submit"><?= $workflow === 'payment' ? 'Pagar com segurança' : 'Salvar cartão' ?></button></div>
    </form>
    <form id="callback-form" method="post" action="<?= htmlspecialchars($callback, ENT_QUOTES, 'UTF-8') ?>" hidden>
        <input type="hidden" name="session" value="<?= $safeSession ?>">
        <input type="hidden" name="authorization" value="<?= $safeAuthorization ?>">
        <input type="hidden" name="card_token" id="card-token">
        <input type="hidden" name="holder" id="posted-holder">
        <input type="hidden" name="expires_at" id="expires-at">
        <input type="hidden" name="installments" id="posted-installments" value="1">
        <input type="hidden" name="device_color_depth" id="device-color-depth">
        <input type="hidden" name="device_type_3ds" value="BROWSER">
        <input type="hidden" name="device_java_enabled" id="device-java-enabled">
        <input type="hidden" name="device_language" id="device-language">
        <input type="hidden" name="device_screen_height" id="device-screen-height">
        <input type="hidden" name="device_screen_width" id="device-screen-width">
        <input type="hidden" name="device_timezone_offset" id="device-timezone-offset">
    </form>
</main>
<script nonce="<?= $nonce ?>" src="https://cdn.jsdelivr.net/npm/node-forge@1.3.1/dist/forge.min.js"></script>
<script nonce="<?= $nonce ?>" src="https://js.cel.cash/checkout-v2.min.js"></script>
<script nonce="<?= $nonce ?>">
(() => {
    'use strict';
    const form = document.getElementById('card-form');
    const callback = document.getElementById('callback-form');
    const submit = document.getElementById('submit');
    const error = document.getElementById('error');
    const number = document.getElementById('number');
    const cvv = document.getElementById('cvv');
    const expiry = document.getElementById('expiry');
    number.addEventListener('input', () => { number.value = number.value.replace(/\D/g, '').slice(0, 19).replace(/(.{4})/g, '$1 ').trim(); });
    expiry.addEventListener('input', () => { const v = expiry.value.replace(/\D/g, '').slice(0, 4); expiry.value = v.length > 2 ? v.slice(0, 2) + '/' + v.slice(2) : v; });
    cvv.addEventListener('input', () => { cvv.value = cvv.value.replace(/\D/g, '').slice(0, 4); });
    let submitting = false;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submitting) return;
        submitting = true; error.classList.remove('show'); submit.disabled = true; submit.textContent = 'Protegendo o cartão...';
        try {
            const holder = document.getElementById('holder').value.trim();
            const digits = number.value.replace(/\D/g, '');
            const match = expiry.value.match(/^(0[1-9]|1[0-2])\/(\d{2})$/);
            if (!holder || digits.length < 13 || digits.length > 19 || !match || cvv.value.length < 3) throw new Error('Confira os dados do cartão e tente novamente.');
            if (typeof window.BaasCard !== 'function') throw new Error('A biblioteca segura do cartão não ficou disponível. Atualize a página.');
            const expiresAt = '20' + match[2] + '-' + match[1];
            const baasCard = new window.BaasCard(true);
            await baasCard._setPublicKey();
            const card = baasCard.newCard({number:digits,holder:holder,expiresAt:expiresAt,cvv:cvv.value});
            card.validate();
            const token = card.encrypt(baasCard._publicKey);
            if (typeof token !== 'string' || token.length < 16) throw new Error('Não foi possível proteger o cartão.');
            document.getElementById('card-token').value = token;
            document.getElementById('posted-holder').value = holder;
            document.getElementById('expires-at').value = expiresAt;
            const installmentInput = document.getElementById('installments');
            document.getElementById('posted-installments').value = installmentInput ? installmentInput.value : '1';
            document.getElementById('device-color-depth').value = String(screen.colorDepth || 24);
            document.getElementById('device-java-enabled').value = navigator.javaEnabled && navigator.javaEnabled() ? '1' : '0';
            document.getElementById('device-language').value = String(navigator.language || 'pt-BR').slice(0, 8);
            document.getElementById('device-screen-height').value = String(screen.height || 0);
            document.getElementById('device-screen-width').value = String(screen.width || 0);
            document.getElementById('device-timezone-offset').value = String(new Date().getTimezoneOffset());
            number.value = ''; cvv.value = '';
            submit.textContent = 'Aguardando confirmação...';
            callback.submit();
        } catch (reason) {
            submitting = false;
            number.value = ''; cvv.value = '';
            error.textContent = reason instanceof Error ? reason.message : 'Não foi possível proteger o cartão.';
            error.classList.add('show'); submit.disabled = false; submit.textContent = '<?= $workflow === 'payment' ? 'Pagar com segurança' : 'Salvar cartão' ?>';
        }
    });
})();
</script>
</body>
</html>
