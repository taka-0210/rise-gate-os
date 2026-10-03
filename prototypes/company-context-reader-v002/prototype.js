(function () {
    'use strict';

    var body = document.body;
    var motionButtons = Array.from(document.querySelectorAll('[data-motion-choice]'));
    var motionBlockDefinitions = [
        { selector: '.chapter__intro', kind: 'chapter' },
        { selector: '.chapter__opening, .annual-lead', kind: 'statement' },
        { selector: '.context-section, .vision-step, .policy-item, .annual-period, .annual-context > section, .priorities > section, .department-grid > section', kind: 'body' },
        { selector: '.theme > header, .departments > header', kind: 'theme' }
    ];
    Array.from(document.querySelectorAll('.motion-target')).forEach(function (target) {
        target.classList.remove('motion-target', 'motion-target--chapter', 'motion-target--statement', 'motion-target--section', 'motion-target--theme');
    });
    motionBlockDefinitions.forEach(function (definition) {
        Array.from(document.querySelectorAll(definition.selector)).forEach(function (target) {
            target.classList.add('motion-target', 'motion-target--' + definition.kind);
        });
    });
    var tocButtons = Array.from(document.querySelectorAll('[data-toc-choice]'));
    var motionState = document.querySelector('.motion-state');
    var motionTargets = Array.from(document.querySelectorAll('.motion-target'));
    var motionSettingInputs = Array.from(document.querySelectorAll('[data-motion-setting]'));
    var motionApplyButton = document.querySelector('[data-motion-apply]');
    var motionPending = document.querySelector('[data-motion-pending]');
    var motionPreview = document.querySelector('[data-motion-preview]');
    var appliedMotionSettings = { trigger: 75, duration: 1350, distance: 40, blur: 2.5 };
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
        return body.dataset.motion === 'on';
    }

    function settingLabel(name, value) {
        if (name === 'trigger') return '画面上から' + value + '%';
        if (name === 'duration') return value + 'ms';
        return value + 'px';
    }

    function readMotionSettings() {
        var settings = {};
        motionSettingInputs.forEach(function (input) {
            settings[input.dataset.motionSetting] = Number(input.value);
        });
        return settings;
    }

    function updateDraftOutputs() {
        motionSettingInputs.forEach(function (input) {
            var output = document.querySelector('[data-motion-output="' + input.dataset.motionSetting + '"]');
            if (output) output.textContent = settingLabel(input.dataset.motionSetting, input.value);
        });
    }

    function markMotionPending() {
        if (motionApplyButton) motionApplyButton.classList.add('is-pending');
        if (motionPending) {
            motionPending.classList.add('is-pending');
            motionPending.textContent = '未反映の変更があります。「設定を反映」を押してください。';
        }
    }

    function replayMotionPreview() {
        if (!motionPreview || body.dataset.motion !== 'on' || reducedMotion.matches) return;
        motionPreview.classList.remove('is-previewing');
        void motionPreview.offsetWidth;
        motionPreview.classList.add('is-previewing');
        motionPreview.addEventListener('animationend', function () {
            motionPreview.classList.remove('is-previewing');
        }, { once: true });
    }

    function applyMotionSettings(settings, replayVisible) {
        appliedMotionSettings = settings;
        body.style.setProperty('--motion-duration', settings.duration + 'ms');
        body.style.setProperty('--motion-distance', settings.distance + 'px');
        body.style.setProperty('--motion-blur', settings.blur + 'px');
        body.dataset.motionTrigger = String(settings.trigger);
        body.dataset.motionDuration = String(settings.duration);
        body.dataset.motionDistance = String(settings.distance);
        body.dataset.motionBlur = String(settings.blur);
        if (motionApplyButton) motionApplyButton.classList.remove('is-pending');
        if (motionPending) {
            motionPending.classList.remove('is-pending');
            motionPending.textContent = '設定を反映しました。確認プレビューと以降の本文に適用されます。';
        }
        createMotionObserver();
        syncMotionEnhancement(Boolean(replayVisible));
        if (replayVisible) replayMotionPreview();
        updateMotionState();
    }

    function canEnhanceMotion() {
        return !enhancementFailed
            && !directFragmentSession
            && body.dataset.motion === 'on'
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

    function createMotionObserver() {
        if (motionObserver) motionObserver.disconnect();
        if (typeof window.IntersectionObserver !== 'function') {
            showEverythingFailOpen('observer-unsupported-static');
            return;
        }
        var bottomMargin = Math.max(0, 100 - appliedMotionSettings.trigger);
        motionObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting || !canEnhanceMotion()) return;
                revealTarget(entry.target, false);
                motionObserver.unobserve(entry.target);
            });
        }, { threshold: 0.01, rootMargin: '0px 0px -' + bottomMargin + '% 0px' });
        motionTargets.filter(function (target) {
            return !target.classList.contains('is-motion-revealed');
        }).forEach(function (target) { motionObserver.observe(target); });
    }

    function updateMotionState() {
        var selected = body.dataset.motion || 'on';
        motionButtons.forEach(function (button) {
            button.setAttribute('aria-pressed', button.dataset.motionChoice === selected ? 'true' : 'false');
        });
        var runtime = body.dataset.motionContract || 'initializing';
        var summary = '開始' + appliedMotionSettings.trigger + '% / '
            + appliedMotionSettings.duration + 'ms / '
            + appliedMotionSettings.distance + 'px / blur '
            + appliedMotionSettings.blur + 'px';
        motionState.textContent = reducedMotion.matches && selected !== 'off'
            ? '適用値：' + summary + '（OS設定によりMotion OFF）'
            : (selected === 'off' ? 'Motion OFF / 設定保持：' : '適用中：') + summary + ' / Runtime: ' + runtime;
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
                ':scope > .chapter__intro, :scope > .chapter__opening, :scope > .annual-period, :scope > .annual-lead'
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
        createMotionObserver();

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

        motionSettingInputs.forEach(function (input) {
            input.addEventListener('input', function () {
                updateDraftOutputs();
                markMotionPending();
            });
        });

        if (motionApplyButton) {
            motionApplyButton.addEventListener('click', function () {
                applyMotionSettings(readMotionSettings(), true);
                motionApplyButton.textContent = '反映しました';
                window.setTimeout(function () {
                    motionApplyButton.textContent = '設定を反映';
                }, 1200);
            });
        }

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
        updateDraftOutputs();
        applyMotionSettings(readMotionSettings(), false);
        updateMobileTocMode(body.dataset.mobileToc || 'a');
        if (directFragmentSession) revealAnchor(window.location.hash);
        updateMotionState();
    } catch (error) {
        body.dataset.motionFailureSubstage = initializationSubstage;
        showEverythingFailOpen('initialization-failure-static');
    }
})();
