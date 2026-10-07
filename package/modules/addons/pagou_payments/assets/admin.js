(function () {
    'use strict';
    // Keep links meant for a new tab away from WHMCS's own same-tab navigation handlers.
    document.addEventListener('click', function (event) {
        var link = event.target instanceof Element ? event.target.closest('.pagou-shell a[target="_blank"]') : null;
        if (link) event.stopPropagation();
    }, true);
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        var label = form.getAttribute('data-pagou-confirm');
        if (label && !window.confirm('Confirma a ação: ' + label + '?')) event.preventDefault();
    });

    document.addEventListener('click', function (event) {
        var resetOpen = event.target instanceof Element ? event.target.closest('[data-pagou-reset-open]') : null;
        if (resetOpen instanceof HTMLButtonElement) {
            var resetOpenId = resetOpen.getAttribute('data-pagou-reset-open');
            var resetPanel = resetOpenId ? document.getElementById(resetOpenId) : null;
            if (resetPanel) {
                resetPanel.hidden = false;
                resetOpen.setAttribute('aria-expanded', 'true');
                resetPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            return;
        }
        var resetClose = event.target instanceof Element ? event.target.closest('[data-pagou-reset-close]') : null;
        if (resetClose instanceof HTMLButtonElement) {
            var resetCloseId = resetClose.getAttribute('data-pagou-reset-close');
            var openButton = resetCloseId
                ? document.querySelector('[data-pagou-reset-open="' + resetCloseId + '"]')
                : null;
            var openPanel = resetCloseId ? document.getElementById(resetCloseId) : null;
            if (openPanel) openPanel.hidden = true;
            if (openButton) openButton.setAttribute('aria-expanded', 'false');
            return;
        }
        var button = event.target instanceof Element ? event.target.closest('[data-pagou-copy], [data-pagou-copy-value]') : null;
        if (!(button instanceof HTMLButtonElement)) return;
        // Table identifiers carry their full value; other buttons point at the element to copy.
        var direct = button.getAttribute('data-pagou-copy-value');
        var id = button.getAttribute('data-pagou-copy');
        var source = direct === null && id ? document.getElementById(id) : null;
        if (direct === null && !source) return;
        var value = direct !== null ? direct : source instanceof HTMLInputElement || source instanceof HTMLTextAreaElement
            ? source.value
            : source.textContent || '';
        if (!value || value === 'Indisponível') return;
        var original = button.innerHTML;
        var done = function () {
            // Compact icon buttons confirm with a check mark; labelled buttons with their text.
            if (button.classList.contains('pagou-id-copy')) {
                var label = button.getAttribute('aria-label');
                button.classList.add('is-copied');
                button.setAttribute('aria-label', 'Copiado');
                window.setTimeout(function () { button.classList.remove('is-copied'); button.setAttribute('aria-label', label); }, 1800);
                return;
            }
            button.textContent = 'Copiado';
            window.setTimeout(function () { button.innerHTML = original; }, 1800);
        };
        // The selection fallback covers browsers or moments in which the Clipboard API refuses.
        var fallback = function () {
            var area = source instanceof HTMLInputElement || source instanceof HTMLTextAreaElement ? source : document.createElement('textarea');
            if (area !== source) {
                area.value = value; area.setAttribute('readonly', ''); area.style.position = 'fixed'; area.style.opacity = '0';
                document.body.appendChild(area);
            }
            area.focus();
            area.select();
            try { if (document.execCommand('copy')) done(); } catch (ignore) { /* Nothing else to try. */ }
            if (area !== source) area.remove();
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(done).catch(fallback);
            return;
        }
        fallback();
    });
}());

(function () {
    'use strict';
    var shell = document.querySelector('.pagou-shell.pagou-admin');
    if (!shell) return;
    shell.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        if (!target) return;
        // Details open in the full-width row right below their own row.
        var toggle = target.closest('.pagou-row-toggle');
        if (toggle) {
            var details = document.getElementById(toggle.getAttribute('aria-controls') || '');
            if (!details) return;
            var open = toggle.getAttribute('aria-expanded') !== 'true';
            details.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            return;
        }
        // Repeated routine checks open under their newest row, one group or all at once.
        var group = target.closest('.pagou-group-toggle');
        var all = group ? null : target.closest('[data-pagou-group-all]');
        if (group || all) {
            var card = (group || all).closest('.pagou-table-card');
            var toggles = group ? [group] : Array.prototype.slice.call(card ? card.querySelectorAll('.pagou-group-toggle') : []);
            var expand = group ? group.getAttribute('aria-expanded') !== 'true' : all.getAttribute('aria-pressed') !== 'true';
            toggles.forEach(function (button) { setGroup(button, expand); });
            if (all) {
                all.setAttribute('aria-pressed', expand ? 'true' : 'false');
                all.textContent = expand ? 'Agrupar consultas repetidas' : 'Mostrar todas as consultas';
            }
            return;
        }
        // A click anywhere on an invoice row opens that invoice, except on its own controls or while selecting text.
        var row = target.closest('tr[data-pagou-row-href]');
        if (!row || target.closest('a, button, input, select, textarea, summary, label, details')) return;
        if (window.getSelection && String(window.getSelection()).length > 0) return;
        var href = row.getAttribute('data-pagou-row-href');
        if (event.metaKey || event.ctrlKey) { window.open(href, '_blank', 'noopener'); return; }
        var link = row.querySelector('a.pagou-invoice-link') || row.querySelector('a[href*="view=charge"]');
        if (link) link.click(); else window.location.href = href;
    });

    function setGroup(button, expand) {
        var id = button.getAttribute('data-pagou-group');
        button.setAttribute('aria-expanded', expand ? 'true' : 'false');
        shell.querySelectorAll('tr[data-pagou-group-member="' + id + '"]').forEach(function (row) {
            row.hidden = !expand;
            // A collapsed member also closes its details.
            var toggle = expand ? null : row.querySelector('.pagou-row-toggle[aria-expanded="true"]');
            if (toggle) {
                var details = document.getElementById(toggle.getAttribute('aria-controls') || '');
                if (details) details.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
        fit();
    }

    // Sticky table heads need a table that fits; wider tables keep their horizontal scroll.
    function fit() {
        shell.querySelectorAll('.pagou-table-wrap').forEach(function (wrap) {
            wrap.classList.remove('is-fit');
            if (wrap.scrollWidth <= wrap.clientWidth + 1) wrap.classList.add('is-fit');
        });
    }
    var resizeTimer = 0;
    window.addEventListener('resize', function () { window.clearTimeout(resizeTimer); resizeTimer = window.setTimeout(fit, 150); });
    fit();

    function isSet(field) {
        if (!field || field.disabled) return false;
        if (field.tagName === 'SELECT') return field.selectedIndex > 0;
        if (field.type === 'checkbox' || field.type === 'radio') return field.checked;
        return field.value.trim() !== '';
    }
    function labelOf(field) {
        var label = field.closest('label');
        var text = label && label.firstChild && label.firstChild.nodeType === 3 ? label.firstChild.textContent.trim() : '';
        return text || field.name;
    }
    // Active criteria as removable tags; each tag reloads the list without that criterion.
    function tags(form) {
        var fields = Array.prototype.filter.call(form.elements, function (field) {
            return field.name && field.type !== 'hidden' && field.type !== 'date' && field.type !== 'submit' && isSet(field);
        });
        if (!fields.length) return;
        var bar = document.createElement('div');
        bar.className = 'pagou-active-filters';
        bar.setAttribute('role', 'group');
        bar.setAttribute('aria-label', 'Filtros ativos');
        fields.forEach(function (removed) {
            var url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
            url.search = '';
            Array.prototype.forEach.call(form.elements, function (field) {
                if (!field.name || field === removed || field.disabled || field.type === 'submit' || field.name === 'page') return;
                if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) return;
                if (field.value === '') return;
                url.searchParams.append(field.name, field.value);
            });
            var value = removed.tagName === 'SELECT' ? removed.options[removed.selectedIndex].text : removed.value.trim();
            var tag = document.createElement('a');
            tag.className = 'pagou-filter-tag';
            tag.href = url.pathname + url.search;
            tag.setAttribute('aria-label', 'Remover filtro ' + labelOf(removed) + ': ' + value);
            var name = document.createElement('span'); name.textContent = labelOf(removed) + ':';
            var strong = document.createElement('strong'); strong.textContent = value;
            var close = document.createElement('span'); close.className = 'pagou-filter-tag-x'; close.setAttribute('aria-hidden', 'true'); close.textContent = '×';
            tag.appendChild(name); tag.appendChild(strong); tag.appendChild(close);
            bar.appendChild(tag);
        });
        form.appendChild(bar);
    }
    shell.querySelectorAll('form.pagou-filter-form, form.pagou-report-filters').forEach(function (form) {
        var secondary = form.querySelectorAll('label.is-secondary');
        var actions = form.querySelector('.pagou-actions, .pagou-report-filter-actions');
        if (secondary.length && actions) {
            var used = Array.prototype.filter.call(secondary, function (label) { return isSet(label.querySelector('input, select')); }).length;
            var more = document.createElement('button');
            more.type = 'button';
            more.className = 'btn btn-default pagou-button pagou-filter-more';
            more.textContent = 'Mais filtros' + (used ? ' (' + used + ')' : '');
            more.setAttribute('aria-expanded', used ? 'true' : 'false');
            form.classList.add('has-more');
            if (used) form.classList.add('is-expanded');
            more.addEventListener('click', function () {
                var open = !form.classList.contains('is-expanded');
                form.classList.toggle('is-expanded', open);
                more.setAttribute('aria-expanded', open ? 'true' : 'false');
                var first = open ? secondary[0].querySelector('input, select') : null;
                if (first) first.focus();
            });
            actions.insertBefore(more, actions.firstChild);
        }
        tags(form);
    });
}());

(function(){
'use strict';
try{
var shell=document.querySelector('.pagou-shell.pagou-admin');if(!shell)return;
var layer=shell.querySelector('.pagou-loading-layer');
var overlayTimer=0;var fallbackTimer=0;var elapsedRevealTimer=0;var elapsedTicker=0;var elapsedStartedAt=0;var active=null;
var elapsed=shell.querySelector('.pagou-loading-elapsed');
function pixelLoader(className){
var grid=document.createElement('span');grid.className='pagou-pixel-loader '+className;grid.setAttribute('aria-hidden','true');
for(var i=0;i<9;i++){var cell=document.createElement('span');cell.className='pagou-pixel-cell';grid.appendChild(cell);}return grid;
}
function formatElapsed(milliseconds){
var total=Math.max(0,milliseconds)/1000;if(total<60)return total.toFixed(1)+'s';
return Math.floor(total/60)+'m '+(total%60).toFixed(1)+'s';
}
function updateElapsed(){if(elapsed)elapsed.textContent=formatElapsed(Date.now()-elapsedStartedAt);}
function startElapsed(){
if(!elapsed)return;elapsedStartedAt=Date.now();elapsed.textContent='0.0s';elapsed.classList.remove('is-visible');
elapsedRevealTimer=window.setTimeout(function(){updateElapsed();elapsed.classList.add('is-visible');elapsedTicker=window.setInterval(updateElapsed,100);},1000);
}
function stopElapsed(){
window.clearTimeout(elapsedRevealTimer);window.clearInterval(elapsedTicker);elapsedRevealTimer=0;elapsedTicker=0;elapsedStartedAt=0;
if(elapsed){elapsed.classList.remove('is-visible');elapsed.textContent='0.0s';}
}
function restoreControl(){
if(!active)return;
if(active.hasAttribute('data-pagou-original-html')){
active.innerHTML=active.getAttribute('data-pagou-original-html')||'';
active.removeAttribute('data-pagou-original-html');
}
active.classList.remove('pagou-is-pending');active.removeAttribute('aria-disabled');active=null;
}
function reset(){
window.clearTimeout(overlayTimer);window.clearTimeout(fallbackTimer);
stopElapsed();
shell.classList.remove('is-loading','is-loading-visible');shell.setAttribute('aria-busy','false');
if(layer)layer.setAttribute('aria-hidden','true');restoreControl();
}
function mark(control){
if(!control||!control.matches('a,button,input[type="submit"],input[type="button"]'))return;
active=control;active.classList.add('pagou-is-pending');active.setAttribute('aria-disabled','true');
if(active.matches('input[type="submit"],input[type="button"]'))return;
active.setAttribute('data-pagou-original-html',active.innerHTML);
var icon=active.querySelector('.pagou-icon');
var slot=document.createElement('span');slot.className='pagou-loading-icon';slot.setAttribute('aria-hidden','true');
slot.appendChild(pixelLoader('pagou-pixel-loader-inline'));
if(icon){
slot.style.width=icon.getAttribute('width')+'px';slot.style.height=icon.getAttribute('height')+'px';
icon.parentNode.replaceChild(slot,icon);
}else{active.insertBefore(slot,active.firstChild);}
}
function begin(control,overlay){
if(shell.classList.contains('is-loading'))return;
shell.classList.add('is-loading');shell.setAttribute('aria-busy','true');mark(control);
if(overlay){
startElapsed();overlayTimer=window.setTimeout(function(){
shell.classList.add('is-loading-visible');if(layer)layer.setAttribute('aria-hidden','false');
},1000);
}
fallbackTimer=window.setTimeout(reset,60000);
}
shell.addEventListener('click',function(e){
if(e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
var target=e.target;if(!target||!target.closest)return;var link=target.closest('a');if(!link)return;
var linkTarget=(link.getAttribute('target')||'').toLowerCase();
if(link.hasAttribute('download')||(linkTarget&&linkTarget!=='_self')||link.getAttribute('aria-disabled')==='true'||link.closest('[data-pagou-no-loading]'))return;
var href=link.getAttribute('href');if(!href||href.charAt(0)==='#')return;
var url;try{url=new URL(link.href,window.location.href);}catch(ignore){return;}
if((url.protocol!=='http:'&&url.protocol!=='https:')||url.origin!==window.location.origin)return;
if(url.pathname===window.location.pathname&&url.search===window.location.search&&url.hash)return;
begin(link,false);
});
document.addEventListener('submit',function(e){
if(!shell.contains(e.target)||e.defaultPrevented||e.target.closest('[data-pagou-no-loading]'))return;
if(shell.classList.contains('is-loading')){e.preventDefault();return;}
var submitter=e.submitter||document.activeElement;begin(submitter,e.target.hasAttribute('data-pagou-loading-overlay'));
});
window.addEventListener('pageshow',reset);
}catch(ignore){}
}());

(function () {
    'use strict';
    var shell = document.querySelector('[data-pagou-admin]');
    if (!shell) return;
    var redirect = shell.querySelector('[data-pagou-post-redirect],[data-pagou-auto-open]');
    if (redirect) {
        var destination = new URL(redirect.href, window.location.href);
        if (destination.origin === window.location.origin && destination.pathname === window.location.pathname) {
            window.location.replace(destination.href);
            return;
        }
    }
    shell.querySelectorAll('iframe[data-pagou-client-preview]').forEach(function (frame) {
        var form = document.getElementById(frame.getAttribute('data-pagou-client-preview'));
        if (!form) return;
        function checked(name) {
            var input = form.querySelector('input[type="checkbox"][name="' + name + '"]');
            return input ? input.checked : null;
        }
        // Mirrors the presentation options in the illustrative frame, before saving.
        function sync() {
            var doc = frame.contentDocument;
            if (!doc || !doc.body) return;
            doc.querySelectorAll('[data-preview-text]').forEach(function (node) {
                var field = form.querySelector('[name="' + node.getAttribute('data-preview-text') + '"]');
                if (field) node.textContent = field.value.trim();
            });
            doc.querySelectorAll('[data-preview-when],[data-preview-unless]').forEach(function (node) {
                var when = node.getAttribute('data-preview-when'), unless = node.getAttribute('data-preview-unless');
                var state = checked(when || unless);
                if (state === null) return;
                node.hidden = (when ? !state : state) || (node.hasAttribute('data-preview-text') && node.textContent === '');
            });
            // The body follows its content; the document never reports less than the frame itself.
            frame.style.height = Math.max(200, Math.ceil(doc.body.getBoundingClientRect().height) + 12) + 'px';
        }
        frame.addEventListener('load', sync);
        form.addEventListener('input', sync);
        form.addEventListener('change', sync);
        sync();
    });
    var searchInput = shell.querySelector('#pagou-search-input');
    if (searchInput) {
        // "/" focuses the search, as in most admin tools, unless the operator is typing.
        document.addEventListener('keydown', function (e) {
            if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey || e.defaultPrevented) return;
            var target = e.target;
            if (target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName))) return;
            e.preventDefault();
            searchInput.focus();
            searchInput.select();
        });
    }
    if (/^#attempt-[a-f0-9-]{36}$/.test(window.location.hash)) {
        var focusedAttempt = document.getElementById(window.location.hash.slice(1));
        if (focusedAttempt) {
            // Earlier attempts are collapsed rows; a link to one opens it.
            var history = focusedAttempt.closest('details') || focusedAttempt.querySelector(':scope > details');
            if (history) history.open = true;
            focusedAttempt.scrollIntoView({block:'center'});
        }
    }
    var dirty = false;
    shell.querySelectorAll('.pagou-settings-form').forEach(function (form) {
        var initial = new URLSearchParams(new FormData(form)).toString();
        var notice = document.createElement('p');
        notice.className = 'pagou-notice pagou-notice--warning';
        notice.setAttribute('role', 'status'); notice.hidden = true;
        notice.textContent = 'Há alterações não salvas nesta seção.';
        form.prepend(notice);
        function value(name) { var field = form.elements.namedItem(name); return field ? (field.type === 'checkbox' ? field.checked : field.value) : null; }
        function show(name, visible) {
            var field = form.elements.namedItem(name);
            var label = field && field.closest('label');
            if (label) label.hidden = !visible;
            // Keep values submitted so hiding a dependent field never resets settings.
        }
        function dependencies() {
            var due = value('pix_due_enabled');
            if (due !== null) {
                show('pix_expiration_seconds', !due);
                ['pix_due_expiration_days','pix_due_fine_type','pix_due_interest_type','pix_due_respect_late_fees'].forEach(function (key) { show(key, due); });
                show('pix_due_fine_amount', due && value('pix_due_fine_type') !== 'none');
                show('pix_due_interest_amount', due && value('pix_due_interest_type') !== 'none');
            }
            ['pix','boleto'].forEach(function (method) { var enabled = value(method + '_show_notes'); if (enabled !== null) show(method + '_notes', enabled); });
        }
        var submit = form.id ? document.querySelector('button[form="' + form.id + '"]') : null;
        var footer = submit ? submit.closest('.pagou-form-footer') : null;
        // While something is unsaved, the footer sticks to the bottom, says so and offers to discard.
        var pending = null;
        var discard = null;
        if (footer) {
            pending = document.createElement('strong');
            pending.className = 'pagou-dirty-label'; pending.hidden = true; pending.textContent = 'Alterações não salvas';
            footer.insertBefore(pending, footer.firstChild);
            discard = document.createElement('button');
            discard.type = 'button'; discard.className = 'btn btn-default pagou-button pagou-discard'; discard.hidden = true; discard.textContent = 'Descartar';
            discard.addEventListener('click', function () { form.reset(); changed(); });
            submit.insertAdjacentElement('afterend', discard);
        }
        function changed() {
            dirty = new URLSearchParams(new FormData(form)).toString() !== initial;
            notice.hidden = !dirty; dependencies();
            if (footer) footer.classList.toggle('is-dirty', dirty);
            if (pending) pending.hidden = !dirty;
            if (discard) discard.hidden = !dirty;
        }
        form.addEventListener('input', changed); form.addEventListener('change', changed); dependencies();
    });
    document.addEventListener('submit', function (event) { if (!event.defaultPrevented && shell.contains(event.target)) dirty = false; });
    window.addEventListener('beforeunload', function (event) { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    shell.querySelectorAll('[data-pagou-preference]').forEach(function (details) {
        var key = 'pagou.ui.' + details.getAttribute('data-pagou-preference');
        try { var saved = localStorage.getItem(key); if (saved !== null) details.open = saved === 'open'; } catch (ignore) {}
        details.addEventListener('toggle', function () { try { localStorage.setItem(key, details.open ? 'open' : 'closed'); } catch (ignore) {} });
    });
}());

(function () {
    'use strict';
    var shell = document.querySelector('[data-pagou-admin]');
    if (!shell) return;

    // Daily receipts: pointer and keyboard read the same values; the table view stays available.
    shell.querySelectorAll('[data-pagou-trend]').forEach(function (figure) {
        var plot = figure.querySelector('.pagou-trend-plot');
        var list = figure.querySelector('.pagou-trend-bars');
        var tip = figure.querySelector('.pagou-trend-tooltip');
        var bars = list ? Array.prototype.slice.call(list.children) : [];
        if (!plot || !list || !tip || bars.length === 0) return;
        var active = -1;
        function line(tag, text) { var node = document.createElement(tag); node.textContent = text || ''; tip.appendChild(node); }
        function show(index) {
            index = Math.max(0, Math.min(bars.length - 1, index));
            if (active >= 0 && bars[active]) bars[active].classList.remove('is-active');
            active = index;
            var bar = bars[index];
            bar.classList.add('is-active');
            tip.textContent = '';
            line('strong', bar.getAttribute('data-value'));
            line('span', bar.getAttribute('data-label'));
            line('small', bar.getAttribute('data-count'));
            tip.hidden = false;
            var plotBox = plot.getBoundingClientRect();
            var box = bar.getBoundingClientRect();
            var half = tip.offsetWidth / 2;
            var center = box.left - plotBox.left + box.width / 2;
            tip.style.left = Math.max(half - 58, Math.min(plotBox.width - half, center)) + 'px';
        }
        function hide() {
            if (active >= 0 && bars[active]) bars[active].classList.remove('is-active');
            active = -1; tip.hidden = true;
        }
        plot.addEventListener('pointermove', function (event) {
            var box = list.getBoundingClientRect();
            var index = Math.floor((event.clientX - box.left) / box.width * bars.length);
            if (index >= 0 && index < bars.length && index !== active) show(index);
        });
        plot.addEventListener('pointerleave', function () { if (document.activeElement !== plot) hide(); });
        plot.addEventListener('focus', function () { show(active >= 0 ? active : bars.length - 1); });
        plot.addEventListener('blur', hide);
        plot.addEventListener('keydown', function (event) {
            var keys = { ArrowLeft: active - 1, ArrowRight: active + 1, Home: 0, End: bars.length - 1 };
            if (Object.prototype.hasOwnProperty.call(keys, event.key)) { event.preventDefault(); show(keys[event.key]); }
            else if (event.key === 'Escape') hide();
        });
    });

    // Account card: rendered from the session cache, refreshed after the page is usable.
    function accountCard() { return shell.querySelector('[data-pagou-account]'); }
    function loadAccount(force) {
        var card = accountCard();
        if (!card || card.classList.contains('is-refreshing') || !window.fetch) return;
        var url = card.getAttribute('data-pagou-account-src');
        var options = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        if (force) {
            var token = shell.querySelector('input[name="token"]');
            if (!token) return;
            var body = new FormData();
            body.append('token', token.value);
            body.append('view', 'dashboard');
            body.append('action', 'account-summary');
            options.method = 'POST';
            options.body = body;
            url = 'addonmodules.php?module=pagou_payments&view=dashboard';
        }
        if (!url) return;
        card.classList.add('is-refreshing');
        card.setAttribute('aria-busy', 'true');
        fetch(url, options).then(function (response) {
            var type = response.headers.get('Content-Type') || '';
            if (!response.ok || type.indexOf('text/html') !== 0) throw new Error('unavailable');
            return response.text();
        }).then(function (html) {
            var template = document.createElement('template');
            template.innerHTML = html.trim();
            var next = template.content.querySelector('[data-pagou-account]');
            if (!next) throw new Error('unexpected');
            card.replaceWith(next);
        }).catch(function () {
            card.classList.remove('is-refreshing');
            card.removeAttribute('aria-busy');
            var message = card.querySelector('[data-pagou-account-error]');
            if (!message) {
                message = document.createElement('p');
                message.className = 'pagou-text-warning';
                message.setAttribute('data-pagou-account-error', '');
                card.insertBefore(message, card.querySelector('.pagou-card-caption'));
            }
            message.textContent = 'Não foi possível consultar a conta agora. Tente atualizar em instantes.';
        });
    }
    shell.addEventListener('click', function (event) {
        var button = event.target instanceof Element ? event.target.closest('[data-pagou-account-refresh]') : null;
        if (button) { event.preventDefault(); loadAccount(true); }
    });
    var card = accountCard();
    if (card && card.hasAttribute('data-pagou-account-pending')) loadAccount(false);
}());
