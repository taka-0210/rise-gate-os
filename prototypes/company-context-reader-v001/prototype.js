(function () {
    'use strict';

    var body = document.body;
    var motionButtons = Array.from(document.querySelectorAll('[data-motion-choice]'));
    var tocButtons = Array.from(document.querySelectorAll('[data-toc-choice]'));
    var motionState = document.querySelector('.motion-state');
    var motionTargets = Array.from(document.querySelectorAll('.motion-target'));
    var chapters = Array.from(document.querySelectorAll('[data-chapter]'));
    var desktopTocLinks = Array.from(document.querySelectorAll('[data-toc-key]'));
    var mobileToc = document.querySelector('[data-mobile-toc]');
    var mobileChapter = document.querySelector('[data-current-chapter]');
    var mobileTocAction = document.querySelector('[data-mobile-toc-action]');
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    var toast = document.querySelector('.prototype-toast');
    var toastTimer = null;

    function isInReviewWindow(element) {
        var rect = element.getBoundingClientRect();
        return rect.bottom > 0 && rect.top < window.innerHeight * 0.88;
    }

    function replayVisibleMotion() {
        motionTargets.forEach(function (target) {
            target.classList.remove('is-motion-active');
        });
        if (body.dataset.motion === 'off' || reducedMotion.matches) return;
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                motionTargets.filter(isInReviewWindow).forEach(function (target) {
                    target.classList.add('is-motion-active');
                });
            });
        });
    }

    function updateMotionState() {
        var labels = { a: 'Motion A', b: 'Motion B', off: 'Motion OFF' };
        var selected = body.dataset.motion || 'a';
        motionButtons.forEach(function (button) {
            button.setAttribute('aria-pressed', button.dataset.motionChoice === selected ? 'true' : 'false');
        });
        motionState.textContent = reducedMotion.matches && selected !== 'off'
            ? '現在：' + labels[selected] + '（OS設定によりMotion OFF）'
            : '現在：' + labels[selected];
    }

    motionButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            body.dataset.motion = button.dataset.motionChoice;
            updateMotionState();
            replayVisibleMotion();
        });
    });

    function updateMobileTocMode(mode) {
        body.dataset.mobileToc = mode;
        tocButtons.forEach(function (button) {
            button.setAttribute('aria-pressed', button.dataset.tocChoice === mode ? 'true' : 'false');
        });
        if (mobileTocAction) {
            mobileTocAction.textContent = mode === 'a' ? '章目次を開く' : '目次';
        }
        if (mode === 'a' && mobileToc) mobileToc.open = false;
    }

    tocButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            updateMobileTocMode(button.dataset.tocChoice);
        });
    });

    function setCurrentChapter(chapter) {
        var key = chapter.dataset.chapter;
        desktopTocLinks.forEach(function (link) {
            if (link.dataset.tocKey === key) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        });
        if (mobileChapter) mobileChapter.textContent = chapter.dataset.chapterLabel;
    }

    if ('IntersectionObserver' in window) {
        var motionObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting || reducedMotion.matches || body.dataset.motion === 'off') return;
                entry.target.classList.add('is-motion-active');
                motionObserver.unobserve(entry.target);
            });
        }, { threshold: 0.18, rootMargin: '0px 0px -10% 0px' });
        motionTargets.forEach(function (target) { motionObserver.observe(target); });

        var chapterObserver = new IntersectionObserver(function (entries) {
            var visible = entries.filter(function (entry) { return entry.isIntersecting; })
                .sort(function (left, right) { return left.boundingClientRect.top - right.boundingClientRect.top; });
            if (visible[0]) setCurrentChapter(visible[0].target);
        }, { threshold: 0, rootMargin: '-18% 0px -68% 0px' });
        chapters.forEach(function (chapter) { chapterObserver.observe(chapter); });
    } else if (chapters[0]) {
        setCurrentChapter(chapters[0]);
    }

    document.querySelectorAll('.mobile-toc a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (mobileToc) mobileToc.open = false;
        });
    });

    document.querySelectorAll('[data-management-placeholder]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            if (!toast) return;
            toast.hidden = false;
            window.clearTimeout(toastTimer);
            toastTimer = window.setTimeout(function () { toast.hidden = true; }, 2600);
        });
    });

    if (typeof reducedMotion.addEventListener === 'function') {
        reducedMotion.addEventListener('change', function () {
            updateMotionState();
            replayVisibleMotion();
        });
    }

    updateMotionState();
    updateMobileTocMode(body.dataset.mobileToc || 'a');
    replayVisibleMotion();
})();
