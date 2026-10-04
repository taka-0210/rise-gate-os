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
    var brandVisual = document.querySelector('[data-brand-visual]');
    var brandVisualCanvas = document.querySelector('[data-brand-visual-canvas]');
    var brandTuning = document.querySelector('[data-brand-tuning]');
    var prototypeControls = document.querySelector('.prototype-controls');
    var brandFallback = document.querySelector('[data-brand-visual-fallback]');
    var brandStatus = document.querySelector('[data-brand-status]');
    var brandCurrentLabel = document.querySelector('[data-brand-current-label]');
    var brandTuningFields = document.querySelector('.brand-tuning__fields');
    if (brandTuningFields) {
        brandTuningFields.insertAdjacentHTML('beforeend', '<label>&#20840;&#20307;&#12398;&#28611;&#12373; <output data-brand-output="density">100%</output><input type="range" min="10" max="200" step="5" value="100" data-brand-setting="density"></label>');
    }
    var brandSettingInputs = Array.from(document.querySelectorAll('[data-brand-setting]'));
    var brandSvg = null;
    var brandLayoutState = { size: 100, x: 0, y: 0, blur: 1.5, density: 100 };
    var brandChapterLabels = {
        philosophy: '01 理念 / ROOT',
        vision: '02 Vision / FUTURE',
        policy: '03 方針 / DIRECTION',
        annual: '04 年度経営方針 / NOW'
    };
    var brandFixedScale = 84;
    var brandChapterStates = {
        philosophy: { opacity: 16, center: 100, outer: 8, structure: 10, now: 6, blur: 2, duration: 1600 },
        vision: { opacity: 18, center: 80, outer: 75, structure: 35, now: 18, blur: 1, duration: 1800 },
        policy: { opacity: 17, center: 65, outer: 75, structure: 100, now: 30, blur: .5, duration: 1600 },
        annual: { opacity: 16, center: 60, outer: 55, structure: 50, now: 100, blur: 0, duration: 1500 }
    };

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

    function brandSettingLabel(name, value) {
        if (name === 'blur') return value + 'px';
        if (name === 'size' || name === 'density') return value + '%';
        return value + 'px';
    }

    function setBrandLayerOpacity(id, value) {
        if (!brandSvg) return;
        var layer = brandSvg.querySelector('#' + id);
        if (layer) layer.style.opacity = String(Math.max(0, Math.min(1, value / 100)));
    }

    function setManagementDepth(key) {
        if (!brandSvg) return;
        var management = brandSvg.querySelector('#management-layer');
        if (!management) return;
        Array.from(management.querySelectorAll('[data-base-opacity]')).forEach(function (element) {
            element.style.opacity = element.dataset.baseOpacity;
        });
        if (key !== 'philosophy') return;
        [
            { radius: '360.000', strength: .10 },
            { radius: '305.000', strength: .28 },
            { radius: '255.000', strength: .68 },
            { radius: '205.000', strength: 1 }
        ].forEach(function (ring) {
            Array.from(management.querySelectorAll('path[d*="A ' + ring.radius + '"]')).forEach(function (path) {
                path.style.opacity = String(Number(path.dataset.baseOpacity) * ring.strength);
            });
        });
        var boundary = management.querySelector('circle[r="385"]');
        if (boundary) boundary.style.opacity = String(Number(boundary.dataset.baseOpacity) * .10);
    }

    function syncBrandControls(key) {
        brandSettingInputs.forEach(function (input) {
            var name = input.dataset.brandSetting;
            input.value = brandLayoutState[name];
            var output = document.querySelector('[data-brand-output=' + name + ']');
            if (output) output.textContent = brandSettingLabel(name, brandLayoutState[name]);
        });
        if (brandCurrentLabel) brandCurrentLabel.textContent = brandChapterLabels[key];
    }

    function applyBrandChapter(key, syncControls) {
        var state = brandChapterStates[key] || brandChapterStates.philosophy;
        body.dataset.brandChapter = key;
        if (brandVisual) {
            brandVisual.style.setProperty('--brand-opacity', String((state.opacity * brandLayoutState.density) / 10000));
            brandVisual.style.setProperty('--brand-blur', brandLayoutState.blur + 'px');
            brandVisual.style.setProperty('--brand-duration', state.duration + 'ms');
        }
        if (brandVisualCanvas) {
            brandVisualCanvas.style.setProperty('--brand-scale', String((brandFixedScale * brandLayoutState.size) / 10000));
            brandVisualCanvas.style.setProperty('--brand-x', brandLayoutState.x + 'px');
            brandVisualCanvas.style.setProperty('--brand-y', brandLayoutState.y + 'px');
            brandVisualCanvas.style.setProperty('--brand-duration', state.duration + 'ms');
        }
        setBrandLayerOpacity('core-light', state.center);
        setBrandLayerOpacity('management-layer', state.center * .82);
        setManagementDepth(key);
        setBrandLayerOpacity('execution-layer', state.outer * .72);
        setBrandLayerOpacity('knowledge-layer', state.outer);
        setBrandLayerOpacity('outer-structure', state.structure * .62);
        setBrandLayerOpacity('connections', state.structure);
        setBrandLayerOpacity('active-flow', state.now);
        setBrandLayerOpacity('core-propagation', 0);
        if (syncControls) syncBrandControls(key);
        if (brandStatus) {
            brandStatus.textContent = brandSvg
                ? brandChapterLabels[key] + ' / 公式SVG layer stateを即時反映'
                : brandChapterLabels[key] + ' / Source読込待ち（fallback表示）';
        }
    }

    function bindBrandTuning() {
        if (brandTuning && prototypeControls) {
            prototypeControls.insertAdjacentElement('afterend', brandTuning);
        }
        brandSettingInputs.forEach(function (input) {
            input.addEventListener('input', function () {
                var name = input.dataset.brandSetting;
                brandLayoutState[name] = Number(input.value);
                var output = document.querySelector('[data-brand-output=' + name + ']');
                if (output) output.textContent = brandSettingLabel(name, input.value);
                applyBrandChapter(body.dataset.brandChapter || 'philosophy', false);
            });
        });
    }

    function loadBrandVisualSource() {
        if (!brandVisualCanvas || typeof window.fetch !== 'function') {
            if (brandStatus) brandStatus.textContent = '公式SVGはfallback画像として表示中';
            return;
        }
        window.fetch('brand-visual-source.php', { cache: 'no-store', credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) throw new Error('source-http-' + response.status);
                return response.text();
            })
            .then(function (source) {
                var parsed = new DOMParser().parseFromString(source, 'image/svg+xml');
                var svg = parsed.documentElement;
                var required = ['company-core', 'outer-structure', 'knowledge-layer', 'execution-layer', 'management-layer', 'core-light', 'connections', 'active-flow'];
                if (!svg || svg.nodeName.toLowerCase() !== 'svg' || required.some(function (id) { return !svg.querySelector('#' + id); })) {
                    throw new Error('source-layer-contract');
                }
                brandSvg = document.importNode(svg, true);
                brandSvg.removeAttribute('width');
                brandSvg.removeAttribute('height');
                brandSvg.setAttribute('aria-hidden', 'true');
                brandSvg.setAttribute('focusable', 'false');
                Array.from(brandSvg.querySelectorAll('#management-layer path, #management-layer circle')).forEach(function (element) {
                    element.dataset.baseOpacity = element.getAttribute('opacity') || '1';
                });
                if (brandFallback) brandFallback.remove();
                brandVisualCanvas.appendChild(brandSvg);
                body.dataset.brandSource = 'official-svg';
                applyBrandChapter(body.dataset.brandChapter || 'philosophy', true);
            })
            .catch(function () {
                body.dataset.brandSource = 'fallback-static';
                if (brandStatus) brandStatus.textContent = '公式SVGのlayer読込に失敗 / fallback静的表示';
            });
    }

    function setCurrentChapter(chapter) {
        var key = chapter.dataset.chapter;
        desktopTocLinks.forEach(function (link) {
            if (link.dataset.tocKey === key) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        });
        if (mobileChapter) mobileChapter.textContent = chapter.dataset.chapterLabel;
        applyBrandChapter(key, true);
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
        bindBrandTuning();
        applyBrandChapter('philosophy', true);
        loadBrandVisualSource();
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
