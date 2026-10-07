(function () {
    'use strict';
    // Enhance only the existing WHMCS Refund form. Its original POST invokes the gateway.
    var script = document.currentScript;
    if (!script || !script.src) return;
    var endpoint = new URL('../native-refund.php', script.src).href;
    function start() {
        document.querySelectorAll('form').forEach(function (form) {
            var type = form.querySelector('[name="refundtype"]');
            var transaction = form.querySelector('[name="transid"]');
            var invoice = form.querySelector('[name="id"]');
            var token = form.querySelector('[name="token"]');
            if (!type || !transaction || !invoice || !token) return;
            var supportedId = null;
            var busy = false;
            var notice = null;
            function message(text) {
                if (!notice) {
                    notice = document.createElement('p'); notice.className = 'pagou-native-refund-notice';
                    notice.setAttribute('role', 'status'); notice.setAttribute('aria-live', 'polite'); form.appendChild(notice);
                }
                notice.textContent = text;
            }
            function query(action) {
                var body = new FormData();
                body.set('invoice_id', invoice.value); body.set('transaction_id', transaction.value);
                body.set('token', token.value); body.set('action', 'refresh');
                var url = endpoint + '?invoice_id=' + encodeURIComponent(invoice.value) + '&transaction_id=' + encodeURIComponent(transaction.value);
                return request(action === 'refresh' ? endpoint : url, action === 'refresh' ? {method:'POST',body:body} : {method:'GET'}, true);
            }
            // Explains an existing refund of the selected Pix before anything is submitted.
            function describe(data) {
                var status = data.refundStatus;
                var value = data.amountLabel ? ' de ' + data.amountLabel : '';
                var text = data.ready
                    ? 'A devolução' + value + ' deste Pix já foi confirmada pela Pagou e falta o registro no WHMCS. Envie este formulário com esta transação para concluir. Nenhuma nova devolução será solicitada.'
                    : ({requested:'Há uma devolução' + value + ' deste Pix em processamento na Pagou. Aguarde a confirmação e envie este formulário para concluir o registro no WHMCS.',
                        uncertain:'Há uma devolução' + value + ' deste Pix em processamento na Pagou. Aguarde a confirmação e envie este formulário para concluir o registro no WHMCS.',
                        applied:'A devolução deste Pix já foi registrada no WHMCS.',
                        applying:'O registro da devolução deste Pix precisa de conferência. Não solicite outra devolução.',
                        review:'O registro da devolução deste Pix precisa de conferência. Não solicite outra devolução.',
                        rejected:'A devolução deste Pix não foi aceita pela Pagou. Confira com o suporte antes de continuar.'})[status] || '';
                if (text) message(text);
                else if (notice) notice.textContent = '';
            }
            var amount = form.querySelector('[name="amount"]');
            var typedAmount = null;
            // An existing refund can only be completed with its own amount; any other value is declined.
            function lockAmount(data) {
                var locked = data.supported && data.amount && ['requested','uncertain','confirmed'].indexOf(data.refundStatus) !== -1;
                if (!amount) return;
                if (locked) {
                    if (typedAmount === null) typedAmount = amount.value;
                    amount.value = data.amount; amount.readOnly = true;
                } else if (typedAmount !== null) {
                    amount.value = typedAmount; amount.readOnly = false; typedAmount = null;
                }
            }
            var identification = {value: null, done: true, promise: null};
            function identify() {
                supportedId = null;
                var selected = transaction.value;
                var current = {value: selected, done: false, promise: null};
                identification = current;
                current.promise = query('read').then(function (data) {
                    if (transaction.value !== selected) return;
                    if (data.supported) supportedId = selected;
                    if (!busy) { lockAmount(data); describe(data); }
                }).catch(function () { /* The native gateway callback remains the safe fallback. */ }).finally(function () {
                    current.done = true;
                });
            }
            transaction.addEventListener('change', identify);
            identify();
            // Decides whether the module follows this submission; prevent() stops the native one.
            function route(prevent) {
                if (type.value !== 'sendtogateway') return false;
                if (busy) { prevent(); return true; }
                if (transaction.value !== supportedId) {
                    if (identification.value !== transaction.value || identification.done) return false;
                    // A click right after opening the invoice waits for the Pix identification instead of bypassing it.
                    prevent(); busy = true;
                    var body = new FormData(form);
                    message('Conferindo a transação selecionada.');
                    identification.promise.then(function () {
                        if (transaction.value === supportedId) { track(body); return; }
                        busy = false;
                        HTMLFormElement.prototype.submit.call(form);
                    });
                    return true;
                }
                prevent(); busy = true;
                track(new FormData(form));
                return true;
            }
            // Bubble after WHMCS's own form handlers so native credit/options processing is preserved.
            document.addEventListener('submit', function (event) {
                if (event.target !== form || (event.defaultPrevented && !busy)) return;
                route(function () { event.preventDefault(); });
            });
            // After its unsaved-changes confirmation WHMCS resubmits through form.submit(),
            // which fires no submit event; that path must be followed as well.
            form.submit = function () {
                if (!route(function () {})) HTMLFormElement.prototype.submit.call(form);
            };
            function track(body) {
                var destination = form.getAttribute('action') || window.location.href;
                var finalized = false;
                var finalizedAt = 0;
                var stopped = false;
                var failures = 0;
                var lastRefresh = 0;
                var nativeMessage = '';
                var landing = '';
                var startedAt = Date.now();
                form.setAttribute('aria-busy', 'true');
                Array.from(form.elements).forEach(function (field) { field.disabled = true; });
                message('Solicitando reembolso Pix. Aguarde a confirmação da Pagou.');
                function nativePost() {
                    // The native form is authoritative for amount, transaction, email and reversal.
                    return request(destination, {method:'POST',body:body}, false).then(function (result) {
                        landing = result.url;
                        var page = new DOMParser().parseFromString(result.html, 'text/html');
                        var error = page.querySelector('.errorbox, .alert-danger');
                        nativeMessage = error ? error.textContent.trim() : '';
                    });
                }
                // Like the native form: back to the invoice Summary, with the result WHMCS gave to its own POST.
                function summary() {
                    var fallback = new URL(destination, window.location.href);
                    fallback.search = '?action=edit&id=' + encodeURIComponent(invoice.value);
                    var url = fallback;
                    try {
                        var native = new URL(landing, window.location.href);
                        if (landing && native.origin === window.location.origin && /invoices\.php$/.test(native.pathname)
                            && native.searchParams.get('id') === invoice.value && native.searchParams.get('refund_result_msg') !== 'declined') url = native;
                    } catch (ignore) { /* The plain invoice address is enough. */ }
                    url.hash = '';
                    return url.href;
                }
                function halt(text) {
                    // Stop safely: the native form is never submitted again automatically.
                    stopped = true; message(text);
                }
                function unrecorded() {
                    halt('A devolução foi confirmada na Pagou, mas o WHMCS não concluiu o registro'
                        + (nativeMessage ? ': ' + nativeMessage : '.')
                        + ' Atualize a fatura e confira a transação antes de qualquer nova tentativa. Não solicite outra devolução.');
                }
                function inspect(data) {
                    if (data.refundStatus === 'applied') {
                        stopped = true; message('Devolução registrada. Abrindo o resumo da fatura.'); window.location.assign(summary()); return;
                    }
                    if (data.ready && !finalized) {
                        finalized = true; finalizedAt = Date.now();
                        message('Devolução confirmada. Concluindo o registro no WHMCS.');
                        return nativePost().then(function () {
                            if (nativeMessage) unrecorded();
                        }).catch(function () {
                            message('Conferindo o registro no WHMCS. Não repita a solicitação.');
                        });
                    }
                    if (finalized && data.ready) {
                        // The confirmed refund still lacks its native outflow; bound the wait.
                        if (nativeMessage || Date.now() - finalizedAt >= 120000) unrecorded();
                        return;
                    }
                    if (['review','rejected'].indexOf(data.refundStatus) !== -1) {
                        stopped = true;
                        message(data.refundStatus === 'rejected' ? 'O pedido não foi aceito pela Pagou. Confira com o suporte antes de continuar.'
                            : 'O reembolso precisa de conferência. Não solicite outra devolução; confira o registro com o suporte.');
                    } else if (!data.supported || data.refundStatus === 'available') {
                        stopped = true;
                        message(nativeMessage || 'Não foi possível confirmar o pedido. Atualize a fatura e confira o resultado antes de continuar.');
                    } else if (!finalized) {
                        // The bank confirms a Pix refund in about half a minute; show that time is passing.
                        message('Aguardando a confirmação da Pagou, ' + Math.round((Date.now() - startedAt) / 1000)
                            + ' s. Costuma levar cerca de 30 segundos. Ao final, a fatura volta para o resumo.');
                    }
                }
                function schedule() {
                    if (!stopped) window.setTimeout(poll, Math.min(30000, 2000 * Math.pow(2, failures)));
                }
                function poll() {
                    if (stopped || !form.isConnected) return;
                    if (document.hidden || navigator.onLine === false) { schedule(); return; }
                    var refresh = Date.now() - lastRefresh >= 15000;
                    if (refresh) lastRefresh = Date.now();
                    query(refresh ? 'refresh' : 'read').then(function (data) {
                        failures = 0; return inspect(data);
                    }).catch(function () {
                        failures = Math.min(4, failures + 1);
                        message('Reconectando para acompanhar o reembolso. Não repita a solicitação.');
                    }).finally(schedule);
                }
                // A lost response is followed by status queries, never by another blind refund POST.
                nativePost().catch(function () {
                    message('Conferindo o resultado da solicitação. Não repita o pedido.');
                }).finally(poll);
            }
        });
    }
    function request(url, options, json) {
        var controller = new AbortController();
        var timeout = window.setTimeout(function () { controller.abort(); }, 45000);
        return window.fetch(url, Object.assign({credentials:'same-origin',cache:'no-store',signal:controller.signal}, options)).then(function (response) {
            if (!response.ok) throw new Error('request_unavailable');
            if (!json) return response.text().then(function (html) { return {html: html, url: response.url || ''}; });
            return response.json().then(function (data) {
                if (data.status !== 'ok') throw new Error('status_unavailable');
                return data;
            });
        }).finally(function () { window.clearTimeout(timeout); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
}());
