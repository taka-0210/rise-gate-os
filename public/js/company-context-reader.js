(function () {
    'use strict';

    var root = document.querySelector('[data-company-context-reader]');
    if (!root) return;

    var directFragment = window.location.hash.length > 1;
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    var revealTargets = Array.from(root.querySelectorAll('.ccr-reveal'));
    var chapters = Array.from(root.querySelectorAll('[data-chapter]'));
    var tocLinks = Array.from(root.querySelectorAll('[data-toc-key]'));
    var mobileToc = root.querySelector('[data-mobile-toc]');
    var mobileChapter = root.querySelector('[data-current-chapter]');
    var motionButtons = Array.from(root.querySelectorAll('[data-motion-choice]'));
    var motionObserver = null;
    var activeChapterKey = null;
    var brand = root.querySelector('[data-brand-visual]');
    var brandCanvas = root.querySelector('[data-brand-canvas]');
    var brandFallback = root.querySelector('[data-brand-fallback]');
    var brandSvg = null;
    var brandEntered = directFragment || reducedMotion.matches;

    var brandStates = {
        philosophy: { opacity: 16, center: 100, middle: 8, outer: 8, structure: 10, boundary: 10, now: 6 },
        vision: { opacity: 18, center: 80, middle: 75, outer: 0, structure: 10, boundary: 10, now: 18 },
        policy: { opacity: 17, center: 65, middle: 75, outer: 0, structure: 0, boundary: 10, now: 0 },
        annual: { opacity: 17, center: 65, middle: 75, outer: 100, structure: 100, boundary: 85, now: 0 }
    };

    function reveal(target) {
        if (target.classList.contains('is-revealed')) return;
        target.classList.add('is-revealed', 'is-animating');
        target.addEventListener('animationend', function () {
            target.classList.remove('is-animating');
        }, { once: true });
        if (motionObserver) motionObserver.unobserve(target);
    }

    function revealEverything() {
        revealTargets.forEach(function (target) {
            target.classList.add('is-revealed');
            target.classList.remove('is-animating');
        });
    }

    function enableMotion() {
        if (root.dataset.motion !== 'on' || reducedMotion.matches || directFragment || typeof window.IntersectionObserver !== 'function') {
            root.classList.remove('is-motion-enhanced');
            revealEverything();
            return;
        }
        root.classList.add('is-motion-enhanced');
        motionObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) reveal(entry.target);
            });
        }, { threshold: .01, rootMargin: '0px 0px -25% 0px' });
        revealTargets.forEach(function (target) { motionObserver.observe(target); });
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                revealTargets.forEach(function (target) {
                    var rect = target.getBoundingClientRect();
                    if (rect.bottom > 0 && rect.top < window.innerHeight * .75) reveal(target);
                });
            });
        });
    }

    function chooseMotion(value) {
        root.dataset.motion = value;
        motionButtons.forEach(function (button) {
            button.setAttribute('aria-pressed', button.dataset.motionChoice === value ? 'true' : 'false');
        });
        try { window.sessionStorage.setItem('company-context-reader-motion', value); } catch (error) {}
        if (value === 'off') {
            if (motionObserver) motionObserver.disconnect();
            root.classList.remove('is-motion-enhanced');
            revealEverything();
        } else {
            enableMotion();
        }
    }

    function revealAnchor(hash) {
        var id = decodeURIComponent((hash || '').replace(/^#/, ''));
        var target = id ? document.getElementById(id) : null;
        if (!target || !root.contains(target)) return;
        if (target.classList.contains('ccr-reveal')) target.classList.add('is-revealed');
        target.querySelectorAll('.ccr-reveal').forEach(function (item) { item.classList.add('is-revealed'); });
    }

    function setLayer(id, value) {
        if (!brandSvg) return;
        var layer = brandSvg.querySelector('#' + id);
        if (layer) layer.style.opacity = String(Math.max(0, Math.min(1, value / 100)));
    }

    function resetDepth(layer) {
        if (!layer) return;
        layer.querySelectorAll('[data-base-opacity]').forEach(function (element) {
            element.style.opacity = element.dataset.baseOpacity;
        });
    }

    function setManagementDepth(key) {
        if (!brandSvg) return;
        var management = brandSvg.querySelector('#management-layer');
        resetDepth(management);
        if (!management || key !== 'philosophy') return;
        [
            { radius: '360.000', strength: .10 },
            { radius: '305.000', strength: .28 },
            { radius: '255.000', strength: .68 },
            { radius: '205.000', strength: 1 }
        ].forEach(function (ring) {
            management.querySelectorAll('path[d*="A ' + ring.radius + '"]').forEach(function (path) {
                path.style.opacity = String(Number(path.dataset.baseOpacity) * ring.strength);
            });
        });
        var boundary = management.querySelector('circle[r="385"]');
        if (boundary) boundary.style.opacity = String(Number(boundary.dataset.baseOpacity) * .10);
    }

    function setExecutionDepth(key) {
        if (!brandSvg) return;
        var execution = brandSvg.querySelector('#execution-layer');
        resetDepth(execution);
        if (!execution || (key !== 'philosophy' && key !== 'vision')) return;
        ['485.000', '535.000', '585.000'].forEach(function (radius) {
            execution.querySelectorAll('path[d*="A ' + radius + '"]').forEach(function (path) { path.style.opacity = '0'; });
        });
        var boundary = execution.querySelector('circle[r="610"]');
        if (boundary) boundary.style.opacity = '0';
    }

    function applyBrandState(key) {
        var state = brandStates[key] || brandStates.philosophy;
        root.dataset.brandChapter = key;
        if (brand) {
            brand.style.setProperty('--brand-opacity', String((state.opacity * 200) / 10000));
            brand.style.setProperty('--brand-duration', '2000ms');
        }
        setLayer('core-light', state.center);
        setLayer('management-layer', state.center * .82);
        setManagementDepth(key);
        setLayer('execution-layer', state.middle * .72);
        setExecutionDepth(key);
        setLayer('knowledge-layer', state.outer);
        setLayer('outer-structure', state.boundary * .62);
        setLayer('connections', state.structure);
        setLayer('active-flow', state.now);
        setLayer('core-propagation', 0);
    }

    function enterBrand() {
        if (brandEntered) {
            root.dataset.brandEntry = 'entered';
            return;
        }
        brandEntered = true;
        root.dataset.brandEntry = 'entered';
    }

    function checkBrandEntry() {
        if (brandEntered) {
            window.removeEventListener('scroll', checkBrandEntry);
            return;
        }
        if (window.scrollY <= 1) return;
        var philosophy = document.getElementById('philosophy');
        if (philosophy && philosophy.getBoundingClientRect().top <= window.innerHeight * .82) {
            enterBrand();
            window.removeEventListener('scroll', checkBrandEntry);
        }
    }

    function setCurrentChapter(chapter) {
        var key = chapter.dataset.chapter;
        if (activeChapterKey === key) return;
        activeChapterKey = key;
        tocLinks.forEach(function (link) {
            if (link.dataset.tocKey === key) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        });
        if (mobileChapter) mobileChapter.textContent = chapter.dataset.chapterLabel;
        applyBrandState(key);
    }

    function syncChapterFromScroll() {
        if (chapters.length === 0) return;
        var readingLine = window.innerHeight * .35;
        var current = chapters[0];
        chapters.forEach(function (chapter) {
            if (chapter.getBoundingClientRect().top <= readingLine) current = chapter;
        });
        setCurrentChapter(current);
    }

    function loadBrand() {
        if (!brandCanvas || !brandFallback || typeof window.fetch !== 'function') return;
        window.fetch(brandFallback.src, { cache: 'force-cache', credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) throw new Error('brand-http-' + response.status);
                return response.text();
            })
            .then(function (source) {
                var parsed = new DOMParser().parseFromString(source, 'image/svg+xml');
                var svg = parsed.documentElement;
                var required = ['company-core', 'outer-structure', 'knowledge-layer', 'execution-layer', 'management-layer', 'core-light', 'connections', 'active-flow'];
                if (!svg || svg.nodeName.toLowerCase() !== 'svg' || required.some(function (id) { return !svg.querySelector('#' + id); })) {
                    throw new Error('brand-layer-contract');
                }
                brandSvg = document.importNode(svg, true);
                brandSvg.removeAttribute('width');
                brandSvg.removeAttribute('height');
                brandSvg.setAttribute('aria-hidden', 'true');
                brandSvg.setAttribute('focusable', 'false');
                brandSvg.querySelectorAll('#management-layer path, #management-layer circle, #execution-layer path, #execution-layer circle').forEach(function (element) {
                    element.dataset.baseOpacity = element.getAttribute('opacity') || '1';
                });
                brandFallback.remove();
                brandCanvas.appendChild(brandSvg);
                applyBrandState(root.dataset.brandChapter || 'philosophy');
            })
            .catch(function () {
                root.dataset.brandSource = 'fallback-static';
            });
    }

    try {
        var savedMotion = null;
        try { savedMotion = window.sessionStorage.getItem('company-context-reader-motion'); } catch (error) {}
        if (savedMotion === 'off') root.dataset.motion = 'off';
        motionButtons.forEach(function (button) {
            button.addEventListener('click', function () { chooseMotion(button.dataset.motionChoice); });
        });
        root.querySelectorAll('a[href^="#"]').forEach(function (link) {
            link.addEventListener('click', function () {
                revealAnchor(link.getAttribute('href'));
                if (mobileToc) mobileToc.open = false;
            });
        });
        window.addEventListener('hashchange', function () { revealAnchor(window.location.hash); });
        syncChapterFromScroll();
        window.addEventListener('scroll', syncChapterFromScroll, { passive: true });
        if (!brandEntered) {
            window.addEventListener('scroll', checkBrandEntry, { passive: true });
            checkBrandEntry();
        }
        if (reducedMotion.matches) root.dataset.brandEntry = 'entered';
        loadBrand();
        chooseMotion(root.dataset.motion);
        if (directFragment) {
            root.dataset.brandEntry = 'entered';
            revealAnchor(window.location.hash);
        }
        if (typeof reducedMotion.addEventListener === 'function') {
            reducedMotion.addEventListener('change', function () {
                if (reducedMotion.matches) {
                    root.dataset.brandEntry = 'entered';
                    root.classList.remove('is-motion-enhanced');
                    revealEverything();
                }
            });
        }
    } catch (error) {
        window.removeEventListener('scroll', checkBrandEntry);
        root.classList.remove('is-motion-enhanced');
        root.dataset.motion = 'off';
        root.dataset.brandEntry = 'entered';
        revealEverything();
    }
})();
