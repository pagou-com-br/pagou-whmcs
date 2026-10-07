const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('package/modules/addons/pagou_payments/assets/pix-refund-admin.js', 'utf8');
function fixture(options = {}) {
    let time = 20000, next = 0, reloads = 0, nativePosts = 0, reads = 0, nativeSubmits = 0, current = options.initial ?? 'available';
    const visited = [];
    const timers = new Map(), listeners = {}, calls = [], notices = [];
    const values = { token: 'csrf', action: 'edit', sub: 'refund', id: '19', transid: '25', amount: options.amount ?? '4.00', refundtype: options.type ?? 'sendtogateway', sendemail: '1', reverse: '1' };
    const fields = Object.entries(values).map(([name,value])=>({name,value,disabled:false,addEventListener(){}}));
    const form = { isConnected:true, elements:fields, querySelector:selector=>fields.find(f=>selector === '[name="'+f.name+'"]'),
        getAttribute:name=>name==='action'?'/native-invoices.php':null, setAttribute(){}, appendChild:node=>notices.push(node), submit(){nativeSubmits++;} };
    class Data {
        constructor(element) {this.values = new Map(element ? element.elements.filter(f=>!f.disabled).map(f=>[f.name,f.value]) : []);}
        set(k,v){this.values.set(k,v);} get(k){return this.values.get(k);}
    }
    const document = {currentScript:{src:'https://example.test/modules/addons/pagou_payments/assets/pix-refund-admin.js'},readyState:'complete',hidden:false,
        querySelectorAll:()=>[form], addEventListener:(name,cb)=>{listeners[name]=cb;},createElement:()=>({setAttribute(){},textContent:''})};
    const navigator = {onLine:true};
    const window = {setTimeout:(cb,delay)=>{timers.set(++next,{cb,delay});return next;},clearTimeout:id=>timers.delete(id),
        location:{href:'https://example.test/admin/invoices.php?action=edit&id=19#tab=5',origin:'https://example.test',reload:()=>{reloads++;},assign:url=>{reloads++;visited.push(url);}},
        fetch:async (url,params)=>{
            calls.push({url,params});
            if(url==='/native-invoices.php') {
                nativePosts++;
                if(nativePosts===1) current=options.immediate||options.initial==='confirmed'?'applied':'requested';
                else if(!options.finalError && !options.finalSilent) current='applied';
                if(options.lost && nativePosts===1) throw new Error('lost response');
                if(options.finalError && nativePosts===2) return {ok:true,text:async()=>'<div class="errorbox"> Token de segurança inválido </div>'};
                // WHMCS answers its Refund POST with a redirect to the invoice and the result.
                const result=current==='applied'?'success':'declined';
                return {ok:true,url:options.noLanding?'':'https://example.test/native-invoices.php?action=edit&id=19&refundattempted=1&refund_result_msg='+result,text:async()=>'<html>native response</html>'};
            }
            if(current==='requested' && ++reads>=(options.confirmAfter || 3)) current='confirmed';
            const known=current!=='available'&&options.refundAmount;
            return {ok:true,json:async()=>Object.assign({status:'ok',supported:options.supported!==false,refundStatus:current,ready:current==='confirmed'},
                known?{amount:options.refundAmount,amountLabel:'R$ '+options.refundAmount.replace('.',',')}:{})};
        }};
    class Parser {parseFromString(html){return {querySelector:()=>html.includes('errorbox')?{textContent:' Token de segurança inválido '}:null};}}
    const HTMLFormElement={prototype:{submit(){nativeSubmits++;}}};
    vm.runInNewContext(source,{window,document,navigator,FormData:Data,DOMParser:Parser,AbortController,URL,HTMLFormElement,Date:{now:()=>time}});
    return {calls,fields,document,navigator,timers,notices,visited,get posts(){return nativePosts;},get nativeSubmits(){return nativeSubmits;},get reloads(){return reloads;},get notice(){return notices.length?notices[notices.length-1].textContent:'';},
        submit(){const e={target:form,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}};listeners.submit(e);return e;},
        // WHMCS's unsaved-changes guard: prevents the event, asks, then calls form.submit() without an event.
        guardedSubmit(){const e={target:form,defaultPrevented:true,preventDefault(){}};listeners.submit(e);form.submit();return e;},
        field(name){return fields.find(f=>f.name===name);},
        async flush(){for(let i=0;i<30;i++)await Promise.resolve();},
        async tick(){const [id,timer]=[...timers].sort((a,b)=>a[1].delay-b[1].delay)[0];timers.delete(id);time+=timer.delay;timer.cb();await this.flush();}
    };
}
test('the native Refund form submits the selected transaction, value, email and reversal unchanged',async()=>{
    const f=fixture();await f.flush();assert.equal(f.submit().defaultPrevented,true);await f.flush();
    while(f.timers.size)await f.tick();
    assert.equal(f.posts,2);assert.equal(f.reloads,1);
    const native=f.calls.filter(c=>c.url==='/native-invoices.php');
    for(const call of native)for(const [key,value]of Object.entries({transid:'25',amount:'4.00',sendemail:'1',reverse:'1',refundtype:'sendtogateway',token:'csrf'}))assert.equal(call.params.body.get(key),value);
    assert.ok(f.calls.filter(c=>c.url.includes('native-refund.php')&&c.params.method==='POST').every(c=>c.params.body.get('action')==='refresh'));
});
test('double clicks and a lost native response only poll until the bank confirms',async()=>{
    const f=fixture({lost:true,confirmAfter:100});await f.flush();f.submit();f.submit();await f.flush();
    for(let i=0;i<5;i++)await f.tick();
    assert.equal(f.posts,1);assert.equal(f.reloads,0);
});
test('a refund completed by the first native POST is not posted again',async()=>{
    const f=fixture({immediate:true});await f.flush();f.submit();await f.flush();
    assert.equal(f.posts,1);assert.equal(f.reloads,1);assert.equal(f.timers.size,0);
});
test('manual refunds, client credit and other gateways preserve native behavior',async()=>{
    for(const options of [{type:''},{type:'addascredit'},{supported:false}]){
        const f=fixture(options);await f.flush();assert.equal(f.submit().defaultPrevented,false);assert.equal(f.posts,0);
    }
});
test('offline and background tabs pause status queries without repeating the native request',async()=>{
    const f=fixture({confirmAfter:100});await f.flush();f.submit();await f.flush();
    const requests=f.calls.length;f.document.hidden=true;await f.tick();assert.equal(f.calls.length,requests);
    f.document.hidden=false;f.navigator.onLine=false;await f.tick();assert.equal(f.calls.length,requests);
    f.navigator.onLine=true;await f.tick();assert.equal(f.posts,1);
});
test('a blank amount is sent unchanged so WHMCS refunds the full transaction',async()=>{
    const f=fixture({amount:''});await f.flush();f.submit();await f.flush();
    while(f.timers.size)await f.tick();
    const native=f.calls.filter(c=>c.url==='/native-invoices.php');
    assert.equal(native.length,2);
    for(const call of native)assert.equal(call.params.body.get('amount'),'');
});
test('a native error after confirmation stops tracking without another submission',async()=>{
    const f=fixture({finalError:true});await f.flush();f.submit();await f.flush();
    for(let i=0;i<20&&f.timers.size;i++)await f.tick();
    assert.equal(f.posts,2);assert.equal(f.reloads,0);assert.equal(f.timers.size,0);
    assert.match(f.notice,/não concluiu o registro: Token de segurança inválido/);
    assert.match(f.notice,/Não solicite outra devolução/);
});
test('a confirmed refund whose native record never appears stops after a bounded wait',async()=>{
    const f=fixture({finalSilent:true});await f.flush();f.submit();await f.flush();
    for(let i=0;i<200&&f.timers.size;i++)await f.tick();
    assert.equal(f.posts,2);assert.equal(f.reloads,0);assert.equal(f.timers.size,0);
    assert.match(f.notice,/WHMCS não concluiu o registro\./);
});
test('a refund already confirmed by Pagou is explained before submitting and completed with one native POST',async()=>{
    const f=fixture({initial:'confirmed'});await f.flush();
    assert.match(f.notice,/já foi confirmada pela Pagou e falta o registro no WHMCS/);
    assert.match(f.notice,/Nenhuma nova devolução será solicitada/);
    f.submit();await f.flush();
    for(let i=0;i<10&&f.timers.size;i++)await f.tick();
    assert.equal(f.posts,1);assert.equal(f.reloads,1);
});
test('an available Pix shows no refund notice before submitting',async()=>{
    const f=fixture();await f.flush();assert.equal(f.notice,'');
});
test('a click before the Pix is identified waits and follows the tracked refund',async()=>{
    const f=fixture();
    const e=f.submit();
    assert.equal(e.defaultPrevented,true);
    await f.flush();
    while(f.timers.size)await f.tick();
    assert.equal(f.posts,2);assert.equal(f.reloads,1);assert.equal(f.nativeSubmits,0);
});
test('a click before identification of another gateway is released to WHMCS unchanged',async()=>{
    const f=fixture({supported:false});
    assert.equal(f.submit().defaultPrevented,true);
    await f.flush();
    assert.equal(f.nativeSubmits,1);assert.equal(f.posts,0);
});
test('a resubmission after the WHMCS unsaved-changes confirmation is tracked, never sent natively',async()=>{
    const f=fixture();await f.flush();f.guardedSubmit();await f.flush();
    while(f.timers.size)await f.tick();
    assert.equal(f.nativeSubmits,0);assert.equal(f.posts,2);assert.equal(f.reloads,1);
});
test('another refund type resubmitted after the confirmation still reaches WHMCS natively',async()=>{
    const f=fixture({type:'addascredit'});await f.flush();f.guardedSubmit();await f.flush();
    assert.equal(f.nativeSubmits,1);assert.equal(f.posts,0);
});
test('an existing partial refund fills and locks its own amount before the registration',async()=>{
    const f=fixture({initial:'confirmed',amount:'',refundAmount:'5.22'});await f.flush();
    assert.equal(f.field('amount').value,'5.22');assert.equal(f.field('amount').readOnly,true);
    assert.match(f.notice,/devolução de R\$ 5,22 deste Pix já foi confirmada/);
    f.submit();await f.flush();
    for(let i=0;i<10&&f.timers.size;i++)await f.tick();
    const native=f.calls.filter(c=>c.url==='/native-invoices.php');
    assert.equal(native.length,1);assert.equal(native[0].params.body.get('amount'),'5.22');
});
test('an available Pix keeps the amount typed by the administrator',async()=>{
    const f=fixture({amount:'3.10',refundAmount:'5.22'});await f.flush();
    assert.equal(f.field('amount').value,'3.10');assert.notEqual(f.field('amount').readOnly,true);
});
test('a registered refund returns to the invoice Summary with the native result, never to the Refund tab',async()=>{
    const f=fixture();await f.flush();f.submit();await f.flush();
    while(f.timers.size)await f.tick();
    assert.deepEqual(f.visited,['https://example.test/native-invoices.php?action=edit&id=19&refundattempted=1&refund_result_msg=success']);
    const g=fixture({noLanding:true});await g.flush();g.submit();await g.flush();
    while(g.timers.size)await g.tick();
    assert.deepEqual(g.visited,['https://example.test/native-invoices.php?action=edit&id=19']);
});
test('the wait for the bank shows the elapsed time',async()=>{
    const f=fixture({confirmAfter:100});await f.flush();f.submit();await f.flush();
    for(let i=0;i<4;i++)await f.tick();
    assert.match(f.notice,/^Aguardando a confirmação da Pagou, \d+ s\. Costuma levar cerca de 30 segundos\./);
});
