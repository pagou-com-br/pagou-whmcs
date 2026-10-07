(function () {
    'use strict';

    function startAction(form, override) {
        var panel = form.closest('.pagou-invoice-admin');
        if (!panel || panel.getAttribute('aria-busy') === 'true') return;
        var data = new FormData(form);
        if (override) data.set('action', override);
        var action = data.get('action');
        var userRequested = action === 'replace-boleto' || action === 'cancel-invoice';
        var originalAttempt = data.get('expected_attempt');
        var endpoint = panel.getAttribute('data-endpoint');
        var invoiceId = panel.getAttribute('data-invoice-id');
        var started = Date.now();
        var failures = 0;
        var acknowledged = false;
        var stopped = false;
        var statusUrl = endpoint + '?invoice_id=' + encodeURIComponent(invoiceId);
        var label = panel.querySelector('.pagou-invoice-loading-label');
        var detail = panel.querySelector('.pagou-invoice-loading-copy small');
        var notice = panel.querySelector('.pagou-invoice-notice');
        if (notice) notice.hidden = true;
        if (label) {
            label.textContent = action === 'replace-boleto' ? 'Gerando novo boleto' : (action === 'advance-invoice' ? 'Preparando boleto' : 'Cancelando cobrança');
            label.setAttribute('data-pagou-label', label.textContent);
        }
        if (detail) detail.textContent = action === 'replace-boleto'
            ? 'Aguardando cancelamento e emissão pela Pagou.' : 'Aguardando confirmação da Pagou.';
        panel.classList.add('is-loading');
        panel.setAttribute('aria-busy', 'true');
        panel.querySelector('.pagou-invoice-loading-layer').setAttribute('aria-hidden', 'false');
        panel.querySelectorAll('button').forEach(function (button) { button.disabled = true; });

        function finish(payload, message) {
            stopped = true;
            if (payload && payload.html) {
                var template = document.createElement('template');
                template.innerHTML = payload.html;
                var replacement = template.content.querySelector('.pagou-invoice-admin');
                if (replacement) { panel.replaceWith(replacement); panel = replacement; }
            }
            panel.classList.remove('is-loading');
            panel.setAttribute('aria-busy', 'false');
            panel.querySelector('.pagou-invoice-loading-layer').setAttribute('aria-hidden', 'true');
            panel.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
            var feedback = panel.querySelector('.pagou-invoice-notice');
            if (feedback && message) { feedback.hidden = false; feedback.textContent = message; }
        }
        function request(url, options) {
            var controller = new AbortController();
            var timeout = window.setTimeout(function () { controller.abort(); }, 45000);
            options.credentials = 'same-origin'; options.cache = 'no-store'; options.signal = controller.signal;
            options.headers = { Accept: 'application/json' };
            return window.fetch(url, options).then(function (response) {
                return response.json().then(function (payload) {
                    return { ok: response.ok, payload: payload };
                });
            }).finally(function () { window.clearTimeout(timeout); });
        }
        function inspect(payload) {
            var state = payload.state;
            if (payload.operationIssue || ['failed', 'uncertain', 'unavailable'].indexOf(state) !== -1) {
                finish(payload, 'A operação precisa de verificação. Consulte o diagnóstico do Pagou antes de tentar novamente.');
            } else if (state === 'paid' || state === 'refunded') {
                finish(payload, 'O estado financeiro da cobrança mudou. Confira a situação atual antes de qualquer nova ação.');
            } else if ((action === 'advance-invoice' || (action === 'replace-boleto' && payload.attemptId !== originalAttempt))
                && payload.remoteId && ['ready', 'active'].indexOf(state) !== -1) {
                // The status badge and the new identifiers already say it; no message repeats them.
                finish(payload, '');
            } else if (action === 'cancel-invoice' && ['cancelled', 'canceled', 'superseded'].indexOf(state) !== -1) {
                finish(payload, '');
            }
        }
        function schedule() {
            if (!stopped) window.setTimeout(poll, Math.min(15000, 2000 * Math.pow(2, failures)));
        }
        function poll() {
            if (stopped) return;
            if ((!userRequested && document.hidden) || navigator.onLine === false) { schedule(); return; }
            var progress = new FormData();
            progress.set('invoice_id', invoiceId); progress.set('token', data.get('token'));
            progress.set('action', 'advance-invoice');
            request(acknowledged ? endpoint : statusUrl, acknowledged ? { method: 'POST', body: progress } : { method: 'GET' }).then(function (result) {
                if (!result.ok || result.payload.status !== 'ok') {
                    if (result.payload.status === 'forbidden') {
                        finish(null, result.payload.message); return;
                    }
                    throw new Error('status_unavailable');
                }
                failures = 0;
                inspect(result.payload);
                if (!acknowledged && (result.payload.attemptId !== originalAttempt || result.payload.state === 'cancel_requested')) acknowledged = true;
                if (!acknowledged && !stopped) {
                    finish(result.payload, 'Não foi possível confirmar a solicitação. Confira o estado atualizado antes de tentar novamente.');
                } else if (detail && Date.now() - started > 60000) {
                    detail.textContent = 'Ainda aguardando processamento. O acompanhamento é automático.';
                }
            }).catch(function () {
                failures = Math.min(failures + 1, 3);
                if (detail) detail.textContent = 'Reconectando para acompanhar a operação. Não é necessário repetir a solicitação.';
            }).finally(schedule);
        }
        // Submit once. An uncertain response is followed only by read-only checks.
        request(endpoint, { method: 'POST', body: data }).then(function (result) {
            if (!result.ok || result.payload.status !== 'ok') {
                finish(null, result.payload.message || 'Não foi possível confirmar a solicitação. Consulte o diagnóstico do Pagou.');
                return;
            }
            acknowledged = true;
            inspect(result.payload);
        }).catch(function () {
            if (detail) detail.textContent = 'Verificando o resultado da solicitação. Aguarde.';
        }).finally(schedule);
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('.pagou-invoice-action');
        if (!form) return;
        event.preventDefault();
        event.stopPropagation();
        startAction(form);
    }, true);
    // WHMCS binds its own navigation (window.location) to the links of the invoice page,
    // which ignores target="_blank" and Ctrl/Cmd. Stopping the event here, before it reaches
    // the link, leaves the browser's own new-tab opening untouched.
    document.addEventListener('click', function (event) {
        var link = event.target instanceof Element ? event.target.closest('.pagou-invoice-admin a[target="_blank"]') : null;
        if (link) event.stopPropagation();
    }, true);
    // Native WHMCS handlers may submit forms directly on submit-button clicks.
    // These buttons belong to our async flow and must never navigate the invoice.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-pagou-invoice-submit]');
        if (!button) return;
        event.preventDefault();
        event.stopPropagation();
        var form = button.closest('.pagou-invoice-action');
        if (form) startAction(form);
    }, true);
    function prepareDisplayedInvoices() {
        document.querySelectorAll('.pagou-invoice-admin[data-auto-progress="1"]').forEach(function (panel) {
            var form = panel.querySelector('.pagou-invoice-action');
            if (form) startAction(form, 'advance-invoice');
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', prepareDisplayedInvoices);
    else prepareDisplayedInvoices();


    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-pagou-admin-copy]');
        if (!button) return;

        var code = document.getElementById(button.getAttribute('data-pagou-admin-copy'));
        if (!code) return;

        var confirmCopy = function () {
            // Icon buttons confirm with a check mark and their accessible name; text buttons with their label.
            if (button.classList.contains('pagou-invoice-copy')) {
                var label = button.getAttribute('aria-label');
                button.classList.add('is-copied');
                button.setAttribute('aria-label', 'Copiado');
                button.setAttribute('title', 'Copiado');
                window.setTimeout(function () {
                    button.classList.remove('is-copied');
                    button.setAttribute('aria-label', label);
                    button.setAttribute('title', 'Copiar');
                }, 1600);
                return;
            }
            button.textContent = 'Copiado';
            window.setTimeout(function () { button.textContent = 'Copiar'; }, 1600);
        };
        var fallback = function () {
            var range = document.createRange();
            range.selectNodeContents(code);
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            if (document.execCommand('copy')) confirmCopy();
            selection.removeAllRanges();
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(code.textContent).then(confirmCopy).catch(fallback);
        } else {
            fallback();
        }
    });
}());
