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
    var motionObserver = null;
    var enhancementFailed = false;
    var directFragmentSession = window.location.hash.length > 1;
    var initializationSubstage = 'VARIABLE_BINDING';

    function isInReviewWindow(element) {
        var rect = element.getBoundingClientRect();
        return rect.bottom > 0 && rect.top < window.innerHeight * 0.9;
    }

    function isAnimatedByMode(target) {
        if (body.dataset.motion === 'b') return true;
        if (body.dataset.motion !== 'a') return false;
        return target.classList.contains('motion-target--chapter')
            || target.classList.contains('motion-target--statement')
            || target.classList.contains('motion-target--theme');
    }

    function canEnhanceMotion() {
        return !enhancementFailed
            && !directFragmentSession
            && body.dataset.motion !== 'off'
            && !reducedMotion.matches
            && typeof window.IntersectionObserver === 'function';
    }

    function clearAnimation(target) {
        target.classList.remove('is-motion-animating');
    }

    function animateTarget(target) {
        clearAnimation(target);
        void target.offsetWidth;
        target.classList.add('is-motion-animating');
        target.addEventListener('animationend', function () {
            clearAnimation(target);
        }, { once: true });
    }

    function revealTarget(target, replay) {
        var wasRevealed = target.classList.contains('is-motion-revealed');
        target.classList.add('is-motion-revealed');
        if (isAnimatedByMode(target) && (!wasRevealed || replay)) animateTarget(target);
    }

    function revealVisibleTargets(replay) {
        motionTargets.filter(isInReviewWindow).forEach(function (target) {
            revealTarget(target, replay);
            if (motionObserver) motionObserver.unobserve(target);
        });
    }

    function showEverythingFailOpen(reason) {
        enhancementFailed = true;
        body.classList.remove('is-motion-enhanced');
        body.dataset.motionContract = reason || 'fail-open';
        if (motionState) {
            motionState.textContent = 'Motion無効 / Runtime: ' + body.dataset.motionContract;
        }
        motionTargets.forEach(function (target) {
            clearAnimation(target);
            target.classList.add('is-motion-revealed');
        });
    }

    function syncMotionEnhancement(replayVisible) {
        motionTargets.forEach(clearAnimation);
        if (!canEnhanceMotion()) {
            body.classList.remove('is-motion-enhanced');
            if (enhancementFailed) return;
            body.dataset.motionContract = body.dataset.motion === 'off'
                ? 'off-static'
                : (reducedMotion.matches ? 'reduced-static' : 'fail-open-static');
            return;
        }

        body.classList.add('is-motion-enhanced');
        body.dataset.motionContract = 'enhanced-one-shot';
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                revealVisibleTargets(Boolean(replayVisible));
            });
        });
    }

    function updateMotionState() {
        var labels = {
            a: 'Motion A / EXTREME 60px・1.6秒',
            b: 'Motion B / EXTREME 120px・2.6秒',
            off: 'Motion OFF / 完全静的'
        };
        var selected = body.dataset.motion || 'a';
        motionButtons.forEach(function (button) {
            button.setAttribute('aria-pressed', button.dataset.motionChoice === selected ? 'true' : 'false');
        });
        var runtime = body.dataset.motionContract || 'initializing';
        motionState.textContent = reducedMotion.matches && selected !== 'off'
            ? '現在：' + labels[selected] + '（OS設定によりMotion OFF）'
            : '現在：' + labels[selected] + ' / Runtime: ' + runtime;
    }

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

    function setCurrentChapter(chapter) {
        var key = chapter.dataset.chapter;
        desktopTocLinks.forEach(function (link) {
            if (link.dataset.tocKey === key) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        });
        if (mobileChapter) mobileChapter.textContent = chapter.dataset.chapterLabel;
    }

    function revealAnchor(hash) {
        var id = decodeURIComponent((hash || '').replace(/^#/, ''));
        var target = id ? document.getElementById(id) : null;
        if (!target) return;

        var targets = Array.from(target.querySelectorAll('.motion-target'));
        if (target.classList.contains('motion-target')) targets.unshift(target);
        if (target.classList.contains('chapter')) {
            targets = Array.from(target.querySelectorAll(
                ':scope > .chapter__intro, :scope > .chapter__opening .motion-target, :scope > .annual-lead .motion-target'
            ));
        }
        targets.forEach(function (motionTarget) {
            motionTarget.classList.add('is-motion-revealed');
            clearAnimation(motionTarget);
            if (motionObserver) motionObserver.unobserve(motionTarget);
        });
    }

    try {
        initializationSubstage = 'MOTION_OBSERVER';
        if (typeof window.IntersectionObserver !== 'function') {
            showEverythingFailOpen('observer-unsupported-static');
        } else {
            motionObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting || !canEnhanceMotion()) return;
                    revealTarget(entry.target, false);
                    motionObserver.unobserve(entry.target);
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -50% 0px' });
            motionTargets.forEach(function (target) { motionObserver.observe(target); });
        }

        initializationSubstage = 'CHAPTER_OBSERVER';
        var chapterObserver = typeof window.IntersectionObserver === 'function'
            ? new IntersectionObserver(function (entries) {
                var visible = entries.filter(function (entry) { return entry.isIntersecting; })
                    .sort(function (left, right) {
                        return left.boundingClientRect.top - right.boundingClientRect.top;
                    });
                if (visible[0]) setCurrentChapter(visible[0].target);
            }, { threshold: 0, rootMargin: '-18% 0px -68% 0px' })
            : null;

        if (chapterObserver) {
            chapters.forEach(function (chapter) { chapterObserver.observe(chapter); });
        } else if (chapters[0]) {
            setCurrentChapter(chapters[0]);
        }

        initializationSubstage = 'CONTROL_LISTENERS';
        motionButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                body.dataset.motion = button.dataset.motionChoice;
                syncMotionEnhancement(true);
                updateMotionState();
            });
        });

        tocButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                updateMobileTocMode(button.dataset.tocChoice);
            });
        });

        document.querySelectorAll('.mobile-toc a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (mobileToc) mobileToc.open = false;
            });
        });

        Array.from(document.querySelectorAll('a[href]')).filter(function (link) {
            return (link.getAttribute('href') || '').charAt(0) === '#';
        }).forEach(function (link) {
            link.addEventListener('click', function () {
                revealAnchor(link.getAttribute('href'));
            });
        });

        window.addEventListener('hashchange', function () {
            revealAnchor(window.location.hash);
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
                syncMotionEnhancement(false);
                updateMotionState();
            });
        }

        initializationSubstage = 'INITIAL_STATE';
        updateMobileTocMode(body.dataset.mobileToc || 'a');
        if (directFragmentSession) revealAnchor(window.location.hash);
        syncMotionEnhancement(false);
        updateMotionState();
    } catch (error) {
        body.dataset.motionFailureSubstage = initializationSubstage;
        showEverythingFailOpen('initialization-failure-static');
    }
})();
