(function () {
    'use strict';
    function start() {
        var panel = document.querySelector('.panel[data-widget="PagouOfficialSummary"]');
        if (!panel) return;
        var body = panel.querySelector('.panel-body');
        var refresh = panel.querySelector('.widget-refresh');
        if (refresh) refresh.setAttribute('aria-label', 'Atualizar resumo Pagou');
        var loadingStarted = 0;
        var elapsedTimer = 0;
        function updateElapsed() {
            var elapsed = panel.querySelector('.pagou-widget-loading-elapsed');
            if (!elapsed) return;
            var seconds = Math.max(0, Date.now() - loadingStarted) / 1000;
            elapsed.classList.toggle('is-visible', seconds >= 1);
            elapsed.textContent = seconds < 60 ? seconds.toFixed(1) + 's'
                : Math.floor(seconds / 60) + 'm ' + (seconds % 60).toFixed(1) + 's';
        }
        function updateLoading() {
            var loading = body && body.classList.contains('panel-loading');
            if (body) body.setAttribute('aria-busy', loading ? 'true' : 'false');
            var layer = panel.querySelector('.pagou-widget-loading-layer');
            if (layer) layer.setAttribute('aria-hidden', loading ? 'false' : 'true');
            if (loading && !loadingStarted) {
                loadingStarted = Date.now();
                updateElapsed();
                elapsedTimer = window.setInterval(updateElapsed, 100);
            } else if (!loading && loadingStarted) {
                window.clearInterval(elapsedTimer);
                loadingStarted = 0;
                elapsedTimer = 0;
            }
        }
        updateLoading();
        if (body && typeof MutationObserver !== 'undefined') {
            new MutationObserver(function (changes) {
                updateLoading();
                if (changes.some(function (change) { return change.type === 'childList'; })
                    && window.packery && typeof window.packery.shiftLayout === 'function') {
                    window.packery.shiftLayout();
                }
            }).observe(body, {childList: true, attributes: true, attributeFilter: ['class']});
        }
        if (panel.querySelector('[data-pagou-widget-refresh]') && typeof window.refreshWidget === 'function') {
            window.refreshWidget('PagouOfficialSummary', 'refresh=1');
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
}());
