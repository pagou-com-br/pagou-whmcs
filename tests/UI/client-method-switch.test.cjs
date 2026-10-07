const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('package/modules/addons/pagou_payments/assets/client.js', 'utf8');

function fixture() {
    const listeners = {}, windowListeners = {}, appended = [];
    class HTMLSelectElement { constructor(name, value) { this.name = name; this.value = value; } }
    const strong = { textContent: '' };
    const document = {
        hidden: false,
        querySelectorAll: () => [],
        addEventListener: (event, cb) => { listeners[event] = cb; },
        createElement: () => ({ setAttribute() {}, querySelector: selector => (selector === 'strong' ? strong : null), hidden: true, innerHTML: '' }),
        body: { appendChild: node => appended.push(node) },
    };
    const window = { addEventListener: (event, cb) => { windowListeners[event] = cb; }, setTimeout() {}, clearTimeout() {} };
    vm.runInNewContext(source, { window, document, navigator: { onLine: true }, HTMLSelectElement, AbortController, URLSearchParams });
    return {
        appended, strong,
        change: (name, value) => listeners.change({ target: new HTMLSelectElement(name, value) }),
        back: () => windowListeners.pageshow({ persisted: true }),
    };
}

test('choosing a Pagou method shows the loader while the invoice reloads', () => {
    const f = fixture();
    f.change('gateway', 'pagou_pix');
    assert.equal(f.appended.length, 1);
    assert.equal(f.appended[0].className, 'pagou-method-switch');
    assert.equal(f.appended[0].hidden, false);
    assert.equal(f.strong.textContent, 'Preparando o pagamento por Pix');
    f.change('gateway', 'pagou_boleto');
    assert.equal(f.appended.length, 1);
    assert.equal(f.strong.textContent, 'Preparando o boleto');
});

test('other methods and other selects keep the native behavior', () => {
    const f = fixture();
    f.change('gateway', 'paypal');
    f.change('currency', 'pagou_pix');
    assert.equal(f.appended.length, 0);
});

test('coming back from the browser history hides the loader', () => {
    const f = fixture();
    f.change('gateway', 'pagou_creditcard');
    f.back();
    assert.equal(f.appended[0].hidden, true);
});
