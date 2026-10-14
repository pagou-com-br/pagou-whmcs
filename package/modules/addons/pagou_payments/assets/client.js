(function () {
    'use strict';

    if (window.pagouClientInitialized) return;
    window.pagouClientInitialized = true;

    // Choosing a Pagou method reloads the invoice while the new charge is issued.
    // Show the usual loader at once instead of a page that seems frozen.
    var preparing = {
        pagou_pix: 'Preparando o pagamento por Pix',
        pagou_boleto: 'Preparando o boleto',
        pagou_creditcard: 'Preparando o pagamento com cartão'
    };
    var overlay = null;
    document.addEventListener('change', function (event) {
        var select = event.target;
        if (!(select instanceof HTMLSelectElement) || select.name !== 'gateway' || !preparing[select.value]) return;
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'pagou-method-switch';
            overlay.setAttribute('role', 'status');
            overlay.setAttribute('aria-live', 'polite');
            overlay.innerHTML = '<div class="pagou-method-switch-card"><span class="pagou-document-pixels" aria-hidden="true">'
                + new Array(10).join('<span></span>') + '</span><div><strong></strong><small>Isso leva alguns segundos.</small></div></div>';
            document.body.appendChild(overlay);
        }
        overlay.querySelector('strong').textContent = preparing[select.value];
        overlay.hidden = false;
    }, true);
    // Returning with the browser's back button must not keep the loader on screen.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted && overlay) overlay.hidden = true;
    });

    // A charge refused for the CPF or CNPJ: the customer types it on the invoice,
    // it is saved in the profile and the charge is issued again.
    var documentMask = function (value) {
        var digits = value.replace(/\D+/g, '').slice(0, 14);
        if (digits.length <= 11) {
            return digits.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
                .replace(/^(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4');
        }
        return digits.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2}).*/, '$1.$2.$3/$4-$5');
    };
    document.addEventListener('input', function (event) {
        var input = event.target;
        if (input instanceof HTMLInputElement && input.name === 'document' && input.closest('[data-pagou-document-form]')) {
            input.value = documentMask(input.value);
        }
    });
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-pagou-document-form') || !window.fetch) return;
        event.preventDefault();
        var input = form.querySelector('input[name="document"]');
        var button = form.querySelector('button[type="submit"]');
        var feedback = form.querySelector('[data-pagou-document-feedback]');
        var show = function (message) {
            feedback.textContent = message;
            feedback.hidden = false;
            input.setAttribute('aria-invalid', 'true');
            button.disabled = false;
            button.textContent = 'Salvar e gerar';
            input.focus();
        };
        var digits = input.value.replace(/\D+/g, '');
        if (digits.length !== 11 && digits.length !== 14) {
            show('Informe os 11 números do CPF ou os 14 do CNPJ.');
            return;
        }
        feedback.hidden = true;
        input.removeAttribute('aria-invalid');
        button.disabled = true;
        button.textContent = 'Salvando...';
        window.fetch(form.action, {
            method: 'POST', body: new FormData(form), credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json' }
        })
            .then(function (response) {
                return response.json().catch(function () { return { status: 'unavailable' }; });
            })
            .then(function (payload) {
                if (payload && payload.status === 'ok') {
                    button.textContent = 'Gerando...';
                    window.location.reload();
                    return;
                }
                show(payload && typeof payload.message === 'string' ? payload.message : 'Não foi possível salvar agora. Tente novamente em instantes.');
            })
            .catch(function () {
                show('Não foi possível salvar agora. Tente novamente em instantes.');
            });
    });

    var elements = document.querySelectorAll('[data-pagou-status-url]');
    elements.forEach(function (element) {
        var url = element.getAttribute('data-pagou-status-url');
        var initial = element.getAttribute('data-pagou-state');
        var revision = element.getAttribute('data-pagou-revision');
        var progressToken = element.getAttribute('data-pagou-progress-token');
        var invoiceState = element.getAttribute('data-pagou-invoice-state') || 'unpaid';
        var timer;
        var busy = false;
        var stopped = false;
        var failures = 0;
        // Nothing changes on a settled charge or a closed invoice: no status queries at all.
        if (!url || ['paid', 'refunded', 'partially_refunded'].indexOf(initial) !== -1
            || ['paid', 'cancelled', 'refunded', 'collections'].indexOf(invoiceState) !== -1) return;
        // A closed charge on an open invoice only waits for a possible replacement.
        var pollInterval = ['cancelled', 'canceled', 'expired', 'failed', 'superseded'].indexOf(initial) !== -1 ? 30000 : 2000;

        var schedule = function () {
            if (!stopped) timer = window.setTimeout(poll, Math.min(30000, pollInterval * Math.pow(2, failures)));
        };
        var poll = function () {
            if (busy || stopped) return;
            if (document.hidden || navigator.onLine === false) {
                schedule();
                return;
            }
            busy = true;
            var controller = new AbortController();
            var timeout = window.setTimeout(function () { controller.abort(); }, progressToken ? 45000 : 15000);
            window.fetch(url, {
                method: progressToken ? 'POST' : 'GET',
                body: progressToken ? new URLSearchParams({progress_token: progressToken}) : undefined,
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'application/json' }
            })
                .then(function (response) {
                    if (!response.ok) throw new Error('status_unavailable');
                    return response.json();
                })
                .then(function (payload) {
                    if (!payload || payload.status !== 'ok' || typeof payload.state !== 'string'
                        || typeof payload.revision !== 'string') throw new Error('status_unavailable');
                    failures = 0;
                    var connection = element.querySelector('[data-pagou-connection]');
                    if (connection) { connection.textContent = ''; connection.hidden = true; }
                    if (payload.operationIssue) {
                        progressToken = null;
                        var loading = element.querySelector('.pagou-document-loading');
                        if (loading) {
                            loading.textContent = 'A preparação do boleto precisa de verificação. Entre em contato com o atendimento.';
                        }
                    }
                    if (payload.state !== initial || (revision && payload.revision !== revision)
                        || (payload.invoiceState && payload.invoiceState !== invoiceState)) {
                        stopped = true;
                        window.location.reload();
                    }
                })
                .catch(function () {
                    // A temporary read failure must not invalidate the displayed payment code.
                    failures = Math.min(failures + 1, 4);
                    var connection = element.querySelector('[data-pagou-connection]');
                    if (connection && failures >= 3) { connection.hidden = false; connection.textContent = 'A conexão está demorando. A consulta continuará automaticamente; aguarde antes de tentar pagar novamente.'; }
                })
                .finally(function () {
                    window.clearTimeout(timeout);
                    busy = false;
                    schedule();
                });
        };
        var resume = function () {
            if (document.hidden || busy || stopped) return;
            window.clearTimeout(timer);
            poll();
        };
        document.addEventListener('visibilitychange', resume);
        window.addEventListener('online', resume);
        timer = window.setTimeout(poll, progressToken ? 0 : pollInterval);
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-pagou-copy-target]');
        if (!button) return;
        event.preventDefault();

        var input = document.getElementById(button.getAttribute('data-pagou-copy-target'));
        if (!input) return;

        var confirmCopy = function () {
            var feedback = button.parentElement.parentElement.querySelector('.pagou-copy-feedback');
            if (feedback) feedback.textContent = button.getAttribute('data-pagou-copy-feedback') || 'Código copiado.';
            var label = button.querySelector('span');
            if (label) label.textContent = 'Copiado';
            window.setTimeout(function () {
                if (label) label.textContent = button.getAttribute('data-pagou-copy-label') || 'Copiar código';
            }, 2000);
        };
        var fallback = function () {
            input.focus();
            input.select();
            if (document.execCommand('copy')) confirmCopy();
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(confirmCopy).catch(fallback);
        } else {
            fallback();
        }
    });
}());
