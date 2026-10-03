(function () {
    'use strict';

    var frame = document.getElementById('reader-frame');
    var stage = document.querySelector('[data-viewport-stage]');
    var viewportLabel = document.querySelector('[data-viewport-label]');
    var status = document.querySelector('.review-status');
    var viewportButtons = Array.from(document.querySelectorAll('[data-viewport]'));
    var tocButtons = Array.from(document.querySelectorAll('[data-toc-mode]'));
    var viewport = 'desktop';
    var tocMode = 'a';
    var dimensions = {
        desktop: '1440 × 900',
        mobile: '390 × 844',
        narrow: '320 × 800'
    };

    function setPressed(buttons, dataKey, selected) {
        buttons.forEach(function (button) {
            button.setAttribute('aria-pressed', button.dataset[dataKey] === selected ? 'true' : 'false');
        });
    }

    function frameDocument() {
        try { return frame.contentDocument || frame.contentWindow.document; }
        catch (error) { return null; }
    }

    function applyTocMode() {
        var documentInside = frameDocument();
        if (!documentInside) return;
        var toc = documentInside.querySelector('.mobile-toc');
        var action = documentInside.querySelector('.mobile-toc summary span:last-child');
        if (!toc) return;
        documentInside.body.dataset.mobileTocPrototype = tocMode;
        if (tocMode === 'a') {
            toc.style.position = 'static';
            toc.style.top = 'auto';
            toc.style.boxShadow = 'none';
            if (action) action.textContent = '章目次を開く';
        } else {
            toc.style.removeProperty('position');
            toc.style.removeProperty('top');
            toc.style.removeProperty('box-shadow');
            if (action) action.textContent = '目次';
        }
    }

    function renderState() {
        stage.dataset.viewportStage = viewport;
        viewportLabel.textContent = dimensions[viewport];
        setPressed(viewportButtons, 'viewport', viewport);
        setPressed(tocButtons, 'tocMode', tocMode);
        applyTocMode();
        status.textContent = dimensions[viewport] + ' / TOC ' + tocMode.toUpperCase()
            + (viewport === 'desktop' ? 'を保持（Mobileで比較）' : 'を表示');
    }

    viewportButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            viewport = button.dataset.viewport;
            renderState();
        });
    });

    tocButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            tocMode = button.dataset.tocMode;
            renderState();
        });
    });

    frame.addEventListener('load', renderState);
    renderState();
})();
