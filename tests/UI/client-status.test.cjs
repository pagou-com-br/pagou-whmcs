const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('package/modules/addons/pagou_payments/assets/client.js', 'utf8');
function fixture(fetch, extraAttrs = {}) {
    let next = 0, reloads = 0, requests = 0;
    const timers = new Map(), listeners = {};
    const attrs = { 'data-pagou-status-url': '/status', 'data-pagou-state': 'pending',
        'data-pagou-revision': 'before', 'data-pagou-invoice-state': 'unpaid', ...extraAttrs };
    const connection = {textContent:'', hidden:true};
    const document = { hidden: false, querySelectorAll: () => [{ getAttribute: key => attrs[key], querySelector: selector => selector === '[data-pagou-connection]' ? connection : null }],
        addEventListener: (event, cb) => { listeners[event] = cb; } };
    const navigator = { onLine: true };
    const window = { setTimeout: (cb, delay) => { timers.set(++next, { cb, delay }); return next; },
        clearTimeout: id => timers.delete(id), fetch: (...args) => { requests++; return fetch(...args); },
        location: { reload: () => { reloads++; } }, addEventListener: (event, cb) => { listeners[event] = cb; } };
    vm.runInNewContext(source, { window, document, navigator, AbortController, URLSearchParams });
    return { document, navigator, listeners, timers, connection,
        get reloads() { return reloads; }, get requests() { return requests; },
        async tick() {
            const [id, timer] = [...timers].sort((a, b) => a[1].delay - b[1].delay)[0];
            timers.delete(id); timer.cb();
            for (let i = 0; i < 10; i++) await Promise.resolve();
        }
    };
}
const unchanged = () => Promise.resolve({ ok: true, json: async () => ({status:'ok', state:'pending', revision:'before', invoiceState:'unpaid'}) });
test('polling starts and continues every two seconds for more than ten minutes', async () => {
    const f = fixture(unchanged);
    assert.equal([...f.timers.values()][0].delay, 2000);
    for (let i = 0; i < 305; i++) await f.tick();
    assert.equal(f.requests, 305); assert.equal(f.reloads, 0); assert.equal(f.timers.size, 1);
    assert.equal([...f.timers.values()][0].delay, 2000);
});
test('network and permission failures retry with capped backoff, then recover', async () => {
    let failures = 0;
    const f = fixture(() => ++failures <= 5 ? Promise.resolve({ok:false, status:403}) : unchanged());
    for (const delay of [4000, 8000, 16000, 30000, 30000, 2000]) {
        await f.tick(); assert.equal([...f.timers.values()][0].delay, delay);
    }
    assert.equal(f.reloads, 0);
});
test('webhook state or artifact changes reload exactly once', async () => {
    for (const payload of [ {state:'paid', revision:'before'}, {state:'pending', revision:'after'},
        {state:'pending', revision:'before', invoiceState:'paid'} ]) {
        const f = fixture(async () => ({ok:true, json:async () => ({status:'ok', ...payload})}));
        await f.tick(); assert.equal(f.reloads, 1); assert.equal(f.timers.size, 0);
    }
});
test('background and offline tabs pause and resume without overlapping requests', async () => {
    let resolve;
    const f = fixture(() => new Promise(r => { resolve = r; }));
    f.document.hidden = true; await f.tick(); assert.equal(f.requests, 0);
    f.document.hidden = false; f.navigator.onLine = false; await f.tick(); assert.equal(f.requests, 0);
    f.navigator.onLine = true; f.listeners.online(); assert.equal(f.requests, 1);
    f.listeners.visibilitychange(); f.listeners.online(); assert.equal(f.requests, 1);
    resolve(await unchanged());
    for (let i = 0; i < 10; i++) await Promise.resolve();
    assert.equal(f.timers.size, 1);
});
test('hung read is aborted and retried without invalidating the payment', async () => {
    const f = fixture((url, options) => new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new Error('timeout')));
    }));
    await f.tick(); assert.equal(f.requests, 1);
    await f.tick(); assert.equal([...f.timers.values()][0].delay, 4000);
    assert.equal(f.reloads, 0);
});

test('pending boleto starts immediately and sends only its session progress token', async () => {
    let options;
    const f = fixture(async (url, opts) => { options = opts; return unchanged(); }, {'data-pagou-progress-token':'session-token'});
    assert.equal([...f.timers.values()][0].delay, 0);
    await f.tick();
    assert.equal(options.method, 'POST');
    assert.equal(options.body.toString(), 'progress_token=session-token');
    assert.equal(options.credentials, 'same-origin');
});
test('uncertain operation stops advancement while read-only monitoring continues', async () => {
    const methods = [];
    const f = fixture(async (url, opts) => {
        methods.push(opts.method);
        return {ok:true,json:async()=>({status:'ok',state:'pending',revision:'before',operationIssue:true})};
    }, {'data-pagou-progress-token':'session-token'});
    await f.tick(); await f.tick();
    assert.deepEqual(methods, ['POST','GET']); assert.equal(f.reloads,0);
});

test('interactive preparation polls every two seconds and backs off on transport failure', async () => {
    let calls = 0;
    const f = fixture(() => ++calls === 3 ? Promise.reject(new Error('offline')) : unchanged(), {'data-pagou-progress-token':'session-token'});
    for (const delay of [2000, 2000, 4000, 2000]) {
        await f.tick(); assert.equal([...f.timers.values()][0].delay, delay);
    }
});

test('card polling uses only GET and shows a recoverable delay without another payment', async () => {
    const methods = [];
    let count = 0;
    const f = fixture(async (url, options) => {
        methods.push(options.method);
        if (++count <= 3) throw new Error('offline');
        return unchanged();
    }, {'data-pagou-status-url':'/status?method=card'});
    await f.tick(); await f.tick(); await f.tick();
    assert.match(f.connection.textContent, /consulta continuará automaticamente/);
    await f.tick();
    assert.equal(f.connection.textContent, '');
    assert.deepEqual(methods, ['GET','GET','GET','GET']);
    assert.equal(f.reloads, 0);
});

test('reconnection notice becomes visible and clears after recovery', async () => {
    let count = 0;
    const f = fixture(() => ++count <= 3 ? Promise.resolve({ok:false, status:503}) : unchanged());
    await f.tick(); await f.tick(); await f.tick();
    assert.equal(f.connection.hidden, false);
    assert.match(f.connection.textContent, /automaticamente/);
    await f.tick(); assert.equal(f.connection.hidden, true); assert.equal(f.connection.textContent, '');
});
test('a settled charge or a closed invoice never queries the status again', async () => {
    for (const attrs of [{'data-pagou-state':'paid', 'data-pagou-invoice-state':'paid'}, {'data-pagou-state':'refunded'},
        {'data-pagou-state':'partially_refunded'}, {'data-pagou-state':'cancelled', 'data-pagou-invoice-state':'cancelled'},
        {'data-pagou-state':'pending', 'data-pagou-invoice-state':'paid'}]) {
        const f = fixture(unchanged, attrs);
        assert.equal(f.timers.size, 0); assert.equal(f.requests, 0);
        f.listeners.visibilitychange?.(); f.listeners.online?.();
        assert.equal(f.requests, 0);
    }
});
test('a closed charge on an open invoice checks every thirty seconds for a replacement', async () => {
    const f = fixture(async () => ({ok:true, json:async () => ({status:'ok', state:'expired', revision:'before', invoiceState:'unpaid'})}),
        {'data-pagou-state':'expired'});
    assert.equal([...f.timers.values()][0].delay, 30000);
    await f.tick(); assert.equal(f.requests, 1); assert.equal(f.reloads, 0);
    assert.equal([...f.timers.values()][0].delay, 30000);
});
test('a charge still being confirmed keeps the two-second check', async () => {
    const f = fixture(async () => ({ok:true, json:async () => ({status:'ok', state:'processing', revision:'before', invoiceState:'unpaid'})}),
        {'data-pagou-state':'processing'});
    assert.equal([...f.timers.values()][0].delay, 2000);
});
