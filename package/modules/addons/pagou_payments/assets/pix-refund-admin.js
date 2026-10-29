(function () {
    'use strict';
    // Explains an existing refund of the selected Pix on the WHMCS Refund form. The form itself
    // is always submitted natively: the gateway answers WHMCS as soon as Pagou holds the refund.
    var script = document.currentScript;
    if (!script || !script.src) return;
    var endpoint = new URL('../native-refund.php', script.src).href;
    var messages = {
        requested: 'A Pagou não confirmou o recebimento da devolução{value} deste Pix. Envie este formulário de novo para conferir o mesmo pedido. Nada é devolvido duas vezes.',
        uncertain: 'A Pagou não confirmou o recebimento da devolução{value} deste Pix. Envie este formulário de novo para conferir o mesmo pedido. Nada é devolvido duas vezes.',
        applying: 'A devolução{value} deste Pix já foi registrada no WHMCS. O banco conclui a devolução em alguns minutos.',
        applied: 'A devolução deste Pix já foi registrada no WHMCS.',
        review: 'O registro da devolução deste Pix precisa de conferência. Não solicite outra devolução.',
        rejected: 'A devolução deste Pix não foi aceita pela Pagou. Confira com o suporte antes de continuar.'
    };
    function start() {
        document.querySelectorAll('form').forEach(function (form) {
            var type = form.querySelector('[name="refundtype"]');
            var transaction = form.querySelector('[name="transid"]');
            var invoice = form.querySelector('[name="id"]');
            if (!type || !transaction || !invoice) return;
            var amount = form.querySelector('[name="amount"]');
            var typedAmount = null;
            var notice = null;
            function message(text) {
                if (!notice) {
                    if (!text) return;
                    notice = document.createElement('p'); notice.className = 'pagou-native-refund-notice';
                    notice.setAttribute('role', 'status'); notice.setAttribute('aria-live', 'polite'); form.appendChild(notice);
                }
                notice.textContent = text;
            }
            function describe(data) {
                var value = data.amountLabel ? ' de ' + data.amountLabel : '';
                var text = data.ready
                    ? 'A devolução' + value + ' deste Pix já foi aceita pela Pagou e falta o registro no WHMCS. Envie este formulário com esta transação para concluir. Nenhuma nova devolução será solicitada.'
                    : (messages[data.refundStatus] || '').replace('{value}', value);
                message(data.supported ? text : '');
            }
            // An existing refund can only be completed with its own amount; any other value is declined.
            function lockAmount(data) {
                var locked = data.supported && data.amount && ['requested','uncertain','accepted','confirmed'].indexOf(data.refundStatus) !== -1;
                if (!amount) return;
                if (locked) {
                    if (typedAmount === null) typedAmount = amount.value;
                    amount.value = data.amount; amount.readOnly = true;
                } else if (typedAmount !== null) {
                    amount.value = typedAmount; amount.readOnly = false; typedAmount = null;
                }
            }
            function identify() {
                var selected = transaction.value;
                var url = endpoint + '?invoice_id=' + encodeURIComponent(invoice.value) + '&transaction_id=' + encodeURIComponent(selected);
                read(url).then(function (data) {
                    if (transaction.value !== selected) return;
                    lockAmount(data); describe(data);
                }).catch(function () { /* The gateway answer on submission remains authoritative. */ });
            }
            transaction.addEventListener('change', identify);
            identify();
        });
    }
    function read(url) {
        var controller = new AbortController();
        var timeout = window.setTimeout(function () { controller.abort(); }, 45000);
        return window.fetch(url, {method:'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal}).then(function (response) {
            if (!response.ok) throw new Error('request_unavailable');
            return response.json().then(function (data) {
                if (data.status !== 'ok') throw new Error('status_unavailable');
                return data;
            });
        }).finally(function () { window.clearTimeout(timeout); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
}());
