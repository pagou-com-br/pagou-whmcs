const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('package/modules/addons/pagou_payments/assets/pix-refund-admin.js', 'utf8');
function fixture(options = {}) {
    const calls = [], notices = [], listeners = {}, documentListeners = {};
    const states = options.states ?? {'25': {status: options.status ?? 'available', amount: options.refundAmount}};
    const values = { token: 'csrf', action: 'edit', sub: 'refund', id: '19', transid: '25', amount: options.amount ?? '4.00', refundtype: 'sendtogateway', sendemail: '1' };
    const fields = Object.entries(values).map(([name,value])=>({name,value,addEventListener:(event,cb)=>{listeners[name+':'+event]=cb;}}));
    const nativeSubmit = function () {};
    const form = { elements:fields, submit:nativeSubmit, querySelector:selector=>fields.find(f=>selector === '[name="'+f.name+'"]'), appendChild:node=>notices.push(node) };
    const document = {currentScript:{src:'https://example.test/modules/addons/pagou_payments/assets/pix-refund-admin.js'},readyState:'complete',
        querySelectorAll:()=>[form], addEventListener:(name,cb)=>{documentListeners[name]=cb;},createElement:()=>({setAttribute(){},textContent:''})};
    const window = {setTimeout:()=>1,clearTimeout(){},
        fetch:async (url,params)=>{
            calls.push({url,params});
            const state = states[new URL(url).searchParams.get('transaction_id')] ?? {supported:false};
            const known = state.status && state.status !== 'available' && state.amount;
            return {ok:true,json:async()=>Object.assign({status:'ok',supported:state.supported!==false,refundStatus:state.status,
                ready:['accepted','confirmed'].includes(state.status)},known?{amount:state.amount,amountLabel:'R$ '+state.amount.replace('.',',')}:{})};
        }};
    vm.runInNewContext(source,{window,document,FormData:class {},AbortController,URL});
    return {calls,form,nativeSubmit,documentListeners,
        get notice(){return notices.length?notices[notices.length-1].textContent:'';},
        field(name){return fields.find(f=>f.name===name);},
        async select(id){this.field('transid').value=id;listeners['transid:change']();await this.flush();},
        async flush(){for(let i=0;i<30;i++)await Promise.resolve();}
    };
}
test('the native Refund form is submitted by WHMCS itself, never intercepted',async()=>{
    const f=fixture();await f.flush();
    assert.equal(f.documentListeners.submit,undefined);
    assert.equal(f.form.submit,f.nativeSubmit);
    assert.ok(f.calls.every(c=>c.params.method==='GET'&&c.url.includes('native-refund.php')&&!c.params.body));
});
test('an available Pix shows no refund notice and keeps the typed amount',async()=>{
    const f=fixture({amount:'3.10'});await f.flush();
    assert.equal(f.notice,'');
    assert.equal(f.field('amount').value,'3.10');assert.notEqual(f.field('amount').readOnly,true);
});
test('a refund Pagou already accepted is explained and completed with its own amount',async()=>{
    for(const status of ['accepted','confirmed']){
        const f=fixture({status,amount:'',refundAmount:'5.22'});await f.flush();
        assert.match(f.notice,/devolução de R\$ 5,22 deste Pix já foi aceita pela Pagou e falta o registro no WHMCS/);
        assert.match(f.notice,/Nenhuma nova devolução será solicitada/);
        assert.equal(f.field('amount').value,'5.22');assert.equal(f.field('amount').readOnly,true);
    }
});
test('an unanswered request asks to send the same form again without a second refund',async()=>{
    const f=fixture({status:'uncertain',refundAmount:'12.00'});await f.flush();
    assert.match(f.notice,/não confirmou o recebimento da devolução de R\$ 12,00/);
    assert.match(f.notice,/Nada é devolvido duas vezes/);
    assert.equal(f.field('amount').readOnly,true);
});
test('a refund already booked in WHMCS is reported as done, without locking the form',async()=>{
    const f=fixture({status:'applying',refundAmount:'12.00'});await f.flush();
    assert.match(f.notice,/já foi registrada no WHMCS\. O banco conclui a devolução em alguns minutos/);
    assert.notEqual(f.field('amount').readOnly,true);
    const g=fixture({status:'applied',refundAmount:'12.00'});await g.flush();
    assert.equal(g.notice,'A devolução deste Pix já foi registrada no WHMCS.');
});
test('review and rejection warn against another refund',async()=>{
    const f=fixture({status:'review'});await f.flush();
    assert.match(f.notice,/Não solicite outra devolução/);
    const g=fixture({status:'rejected'});await g.flush();
    assert.match(g.notice,/não foi aceita pela Pagou/);
});
test('selecting another transaction clears the notice and restores the typed amount',async()=>{
    const f=fixture({amount:'3.10',states:{'25':{status:'confirmed',amount:'5.22'},'26':{supported:false}}});await f.flush();
    assert.equal(f.field('amount').value,'5.22');
    await f.select('26');
    assert.equal(f.notice,'');
    assert.equal(f.field('amount').value,'3.10');assert.equal(f.field('amount').readOnly,false);
});
