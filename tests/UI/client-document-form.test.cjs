const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('package/modules/addons/pagou_payments/assets/client.js', 'utf8');

function fixture(answer) {
    const listeners = {}, requests = [];
    let reloaded = false;
    class HTMLInputElement {
        constructor(form) { this.name = 'document'; this.value = ''; this.form = form; this.attributes = {}; }
        closest(selector) { return selector === '[data-pagou-document-form]' ? this.form : null; }
        setAttribute(name, value) { this.attributes[name] = value; }
        removeAttribute(name) { delete this.attributes[name]; }
        focus() {}
    }
    class HTMLFormElement {
        constructor() {
            this.action = 'modules/addons/pagou_payments/document.php';
            this.input = new HTMLInputElement(this);
            this.button = { disabled: false, textContent: 'Salvar e gerar' };
            this.feedback = { hidden: true, textContent: '' };
        }
        hasAttribute(name) { return name === 'data-pagou-document-form'; }
        querySelector(selector) {
            return { 'input[name="document"]': this.input, 'button[type="submit"]': this.button, '[data-pagou-document-feedback]': this.feedback }[selector];
        }
    }
    const document = {
        hidden: false,
        querySelectorAll: () => [],
        addEventListener: (event, cb) => { listeners[event] = cb; },
    };
    const window = {
        addEventListener() {}, setTimeout() {}, clearTimeout() {},
        location: { reload: () => { reloaded = true; } },
        fetch: (url, options) => {
            requests.push({ url, options });
            return Promise.resolve({ json: () => Promise.resolve(answer) });
        },
    };
    vm.runInNewContext(source, {
        window, document, navigator: { onLine: true }, HTMLInputElement, HTMLFormElement,
        HTMLSelectElement: class {}, AbortController, URLSearchParams, FormData: class { constructor(form) { this.form = form; } },
    });
    const form = new HTMLFormElement();
    return {
        form, requests, reloaded: () => reloaded,
        type: value => { form.input.value = value; listeners.input({ target: form.input }); },
        submit: () => { let prevented = false; listeners.submit({ target: form, preventDefault: () => { prevented = true; } }); return prevented; },
    };
}

const settle = () => new Promise(resolve => setImmediate(resolve));

test('the document is formatted as a CPF or a CNPJ while it is typed', () => {
    const f = fixture({ status: 'ok' });
    f.type('52998224725');
    assert.equal(f.form.input.value, '529.982.247-25');
    f.type('11222333000181');
    assert.equal(f.form.input.value, '11.222.333/0001-81');
    f.type('529.98');
    assert.equal(f.form.input.value, '529.98');
});

test('an incomplete number is explained without any request', () => {
    const f = fixture({ status: 'ok' });
    f.type('5299822');
    assert.equal(f.submit(), true);
    assert.equal(f.requests.length, 0);
    assert.equal(f.form.feedback.hidden, false);
    assert.equal(f.form.feedback.textContent, 'Informe os 11 números do CPF ou os 14 do CNPJ.');
    assert.equal(f.form.input.attributes['aria-invalid'], 'true');
});

test('a saved document reloads the invoice with the new charge', async () => {
    const f = fixture({ status: 'ok' });
    f.type('52998224725');
    f.submit();
    assert.equal(f.form.button.disabled, true);
    await settle();
    assert.equal(f.requests.length, 1);
    assert.equal(f.requests[0].url, 'modules/addons/pagou_payments/document.php');
    assert.equal(f.requests[0].options.method, 'POST');
    assert.equal(f.requests[0].options.headers.Accept, 'application/json');
    assert.equal(f.reloaded(), true);
});

test('a refused document shows the reason and can be tried again', async () => {
    const f = fixture({ status: 'invalid', message: 'Este CPF ou CNPJ não é válido. Confira os números e tente de novo.' });
    f.type('12345678901');
    f.submit();
    await settle();
    assert.equal(f.reloaded(), false);
    assert.equal(f.form.feedback.textContent, 'Este CPF ou CNPJ não é válido. Confira os números e tente de novo.');
    assert.equal(f.form.button.disabled, false);
    assert.equal(f.form.button.textContent, 'Salvar e gerar');
});
