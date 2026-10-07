const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const php = fs.readFileSync('package/modules/gateways/pagou/card-input.php', 'utf8');
const source = php.slice(php.indexOf('(() => {'), php.lastIndexOf('</script>')).replace(/<\?=[\s\S]*?\?>/g, 'Continuar');
function fixture(setKey) {
    const nodes = {}, handlers = {};
    let posts = 0, encryptions = 0;
    for (const id of ['card-form', 'callback-form', 'submit', 'error', 'number', 'cvv', 'expiry', 'holder', 'card-token',
        'posted-holder', 'expires-at', 'installments', 'posted-installments', 'device-color-depth', 'device-java-enabled',
        'device-language', 'device-screen-height', 'device-screen-width', 'device-timezone-offset']) {
        nodes[id] = {value:'', disabled:false, textContent:'', classList:{add(){},remove(){}},
            addEventListener:(name, cb) => { handlers[id + ':' + name] = cb; }};
    }
    nodes['callback-form'].submit = () => { posts++; };
    class BaasCard {
        async _setPublicKey() { await setKey(); }
        newCard() { return {validate(){}, encrypt(){ encryptions++; return 'opaque-fixture-token'; }}; }
    }
    Object.assign(nodes.number, {value:'4242424242424242'});
    nodes.cvv.value = '123'; nodes.expiry.value = '12/30'; nodes.holder.value = 'Cliente Teste'; nodes.installments.value = '1';
    vm.runInNewContext(source, {window:{BaasCard}, document:{getElementById:id=>nodes[id]},
        screen:{colorDepth:24,height:1080,width:1920}, navigator:{language:'pt-BR'}, Date});
    return {nodes, send:()=>handlers['card-form:submit']({preventDefault(){}}), get posts(){return posts;}, get encryptions(){return encryptions;}};
}
test('double submit encrypts and sends exactly once while awaiting the provider', async () => {
    let resolve;
    const f = fixture(() => new Promise(r=>{resolve=r;}));
    const first = f.send();
    await f.send();
    assert.equal(f.posts,0);
    assert.equal(f.nodes.submit.disabled,true);
    resolve(); await first;
    assert.equal(f.posts,1); assert.equal(f.encryptions,1);
    assert.equal(f.nodes.number.value,''); assert.equal(f.nodes.cvv.value,'');
    assert.equal(f.nodes.submit.textContent,'Aguardando confirmação...');
    await f.send(); assert.equal(f.posts,1);
});
test('tokenization failure clears sensitive fields and allows an explicit correction', async () => {
    let calls = 0;
    const f = fixture(() => ++calls === 1 ? Promise.reject(new Error('fixture failure')) : Promise.resolve());
    await f.send();
    assert.equal(f.posts,0); assert.equal(f.nodes.submit.disabled,false);
    assert.equal(f.nodes.number.value,''); assert.equal(f.nodes.cvv.value,'');
    assert.equal(f.nodes.error.textContent,'Não foi possível proteger o cartão.');
    f.nodes.number.value = '4242424242424242'; f.nodes.cvv.value = '123';
    await f.send(); assert.equal(f.posts,1);
});
