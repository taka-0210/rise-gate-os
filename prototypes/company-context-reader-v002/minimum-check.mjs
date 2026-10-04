import { chromium } from 'playwright-core';

const baseUrl = process.argv[2];
if (!baseUrl) throw new Error('Usage: minimum-check.mjs <base-url>');

const browser = await chromium.launch({
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    headless: true,
});

const results = [];

try {
    for (const viewport of [
        { name: 'desktop', width: 1440, height: 900 },
        { name: 'mobile', width: 390, height: 844 },
    ]) {
        const page = await browser.newPage({ viewport });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(baseUrl + '/index.html', { waitUntil: 'networkidle' });
        await page.locator('[data-brand-visual-canvas] svg').waitFor();

        const initial = await page.evaluate(() => ({
            source: document.body.dataset.brandSource,
            chapter: document.body.dataset.brandChapter,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            readerVisible: getComputedStyle(document.querySelector('.reader')).visibility !== 'hidden',
            tuningVisible: (() => {
                const panel = document.querySelector('[data-brand-tuning]');
                const rect = panel.getBoundingClientRect();
                const firstInput = panel.querySelector('[data-brand-setting]');
                return panel.open && rect.top >= 0 && rect.top < window.innerHeight && rect.height > 0
                    && getComputedStyle(firstInput).display !== 'none';
            })(),
            root: {
                core: document.querySelector('#core-light').style.opacity,
                outer: document.querySelector('#knowledge-layer').style.opacity,
                structure: document.querySelector('#connections').style.opacity,
                now: document.querySelector('#active-flow').style.opacity,
                scale: document.querySelector('[data-brand-visual-canvas]').style.getPropertyValue('--brand-scale'),
                innerArc: Number(document.querySelector('#management-layer path[d*="A 205.000"]').style.opacity),
                outerArc: Number(document.querySelector('#management-layer path[d*="A 360.000"]').style.opacity),
                ringMotion: [
                    getComputedStyle(document.querySelector('#knowledge-layer')).animationName,
                    getComputedStyle(document.querySelector('#execution-layer')).animationName,
                    getComputedStyle(document.querySelector('#management-layer')).animationName,
                ],
                outerStroke: getComputedStyle(document.querySelector('#outer-structure path')).stroke,
            },
            fade: {
                duration: document.body.style.getPropertyValue('--motion-duration'),
                distance: document.body.style.getPropertyValue('--motion-distance'),
                blur: document.body.style.getPropertyValue('--motion-blur'),
                trigger: document.body.dataset.motionTrigger,
            },
            layout: {
                x: document.querySelector('[data-brand-visual-canvas]').style.getPropertyValue('--brand-x'),
                y: document.querySelector('[data-brand-visual-canvas]').style.getPropertyValue('--brand-y'),
                blur: document.querySelector('[data-brand-visual]').style.getPropertyValue('--brand-blur'),
                opacity: document.querySelector('[data-brand-visual]').style.getPropertyValue('--brand-opacity'),
                outputs: Array.from(document.querySelectorAll('[data-brand-output]')).map(output => output.textContent),
            },
        }));

        await page.locator('#vision').scrollIntoViewIfNeeded();
        await page.waitForTimeout(250);
        const vision = await page.evaluate(() => ({
            chapter: document.body.dataset.brandChapter,
            middle: document.querySelector('#execution-layer').style.opacity,
            outer: document.querySelector('#knowledge-layer').style.opacity,
            innerExecutionArc: Number(document.querySelector('#execution-layer path[d*="A 430.000"]').style.opacity),
            secondExecutionArc: Number(document.querySelector('#execution-layer path[d*="A 485.000"]').style.opacity),
            outerExecutionArc: Number(document.querySelector('#execution-layer path[d*="A 585.000"]').style.opacity),
        }));

        await page.locator('#policy').scrollIntoViewIfNeeded();
        await page.waitForTimeout(250);
        const policy = await page.evaluate(() => ({
            chapter: document.body.dataset.brandChapter,
            middle: document.querySelector('#execution-layer').style.opacity,
            outer: document.querySelector('#knowledge-layer').style.opacity,
            structure: document.querySelector('#connections').style.opacity,
            secondExecutionArc: Number(document.querySelector('#execution-layer path[d*="A 485.000"]').style.opacity),
            outerExecutionArc: Number(document.querySelector('#execution-layer path[d*="A 585.000"]').style.opacity),
        }));

        await page.locator('#annual').scrollIntoViewIfNeeded();
        await page.waitForTimeout(250);
        const annual = await page.evaluate(() => ({
            chapter: document.body.dataset.brandChapter,
            core: document.querySelector('#core-light').style.opacity,
            outer: document.querySelector('#knowledge-layer').style.opacity,
            structure: document.querySelector('#connections').style.opacity,
            now: document.querySelector('#active-flow').style.opacity,
            scale: document.querySelector('[data-brand-visual-canvas]').style.getPropertyValue('--brand-scale'),
        }));

        const tuning = await page.evaluate(() => {
            const size = document.querySelector('[data-brand-setting=size]');
            const x = document.querySelector('[data-brand-setting=x]');
            const y = document.querySelector('[data-brand-setting=y]');
            const blur = document.querySelector('[data-brand-setting=blur]');
            const density = document.querySelector('[data-brand-setting=density]');
            size.value = '110';
            x.value = '120';
            y.value = '-60';
            blur.value = '3';
            density.value = '60';
            [size, x, y, blur, density].forEach(input => input.dispatchEvent(new Event('input', { bubbles: true })));
            return {
                scale: document.querySelector('[data-brand-visual-canvas]').style.getPropertyValue('--brand-scale'),
                x: document.querySelector('[data-brand-visual-canvas]').style.getPropertyValue('--brand-x'),
                y: document.querySelector('[data-brand-visual-canvas]').style.getPropertyValue('--brand-y'),
                blur: document.querySelector('[data-brand-visual]').style.getPropertyValue('--brand-blur'),
                opacity: document.querySelector('[data-brand-visual]').style.getPropertyValue('--brand-opacity'),
                outputs: Array.from(document.querySelectorAll('[data-brand-output]')).map(output => output.textContent),
            };
        });

        if (initial.source !== 'official-svg' || initial.chapter !== 'philosophy' || initial.overflow !== 0
            || !initial.readerVisible || !initial.tuningVisible) {
            throw new Error(viewport.name + ': initial ' + JSON.stringify(initial));
        }
        if (initial.fade.duration !== '1350ms' || initial.fade.distance !== '40px' || initial.fade.blur !== '2.5px' || initial.fade.trigger !== '75') {
            throw new Error(viewport.name + ': fade ' + JSON.stringify(initial.fade));
        }
        if (initial.root.scale !== '1.008' || initial.layout.x !== '-110px' || initial.layout.y !== '0px'
            || initial.layout.blur !== '0.5px' || initial.layout.opacity !== '0.32'
            || initial.layout.outputs.join('|') !== '120%|-110px|0px|0.5px|200%') {
            throw new Error(viewport.name + ': initial layout ' + JSON.stringify(initial.layout));
        }
        if (vision.chapter !== 'vision' || Number(vision.middle) <= Number(vision.outer) * 8
            || vision.secondExecutionArc !== 0 || vision.outerExecutionArc !== 0) {
            throw new Error(viewport.name + ': vision ring sequence ' + JSON.stringify(vision));
        }
        if (policy.chapter !== 'policy' || Number(policy.outer) <= Number(vision.outer) * 4
            || Number(policy.structure) <= Number(vision.middle)
            || policy.secondExecutionArc <= vision.secondExecutionArc * 10
            || policy.outerExecutionArc <= vision.outerExecutionArc * 10) {
            throw new Error(viewport.name + ': policy ring sequence ' + JSON.stringify(policy));
        }
        if (annual.chapter !== 'annual' || Number(annual.now) <= Number(initial.root.now)
            || annual.scale !== initial.root.scale) {
            throw new Error(viewport.name + ': annual ' + JSON.stringify(annual));
        }
        if (!(initial.root.innerArc > initial.root.outerArc * 6)) {
            throw new Error(viewport.name + ': ROOT emphasis is not concentrated in the inner rings');
        }
        if (initial.root.ringMotion.some(name => name === 'none') || initial.root.outerStroke !== 'rgb(53, 111, 123)') {
            throw new Error(viewport.name + ': arc motion/color ' + JSON.stringify(initial.root));
        }
        if (tuning.scale !== '0.924' || tuning.x !== '120px' || tuning.y !== '-60px' || tuning.blur !== '3px'
            || tuning.opacity !== '0.096' || tuning.outputs.join('|') !== '110%|120px|-60px|3px|60%') {
            throw new Error(viewport.name + ': tuning ' + JSON.stringify(tuning));
        }
        if (errors.length) throw new Error(viewport.name + ': page errors ' + errors.join(' | '));

        results.push({ viewport: viewport.name, initial, vision, policy, annual, tuning, pageErrors: errors.length });
        await page.close();
    }

    const review = await browser.newPage({ viewport: { width: 1440, height: 920 } });
    await review.goto(baseUrl + '/review.html', { waitUntil: 'networkidle' });
    const frame = review.frameLocator('#reader-frame');
    await frame.locator('[data-brand-tuning]').waitFor();
    const iframeRect = await review.locator('#reader-frame').boundingBox();
    const panelRect = await frame.locator('[data-brand-tuning]').evaluate(element => {
        const rect = element.getBoundingClientRect();
        return { top: rect.top, height: rect.height };
    });
    const cacheIdentity = await frame.locator('html').evaluate(() => ({
        css: document.querySelector('link[rel=stylesheet]').getAttribute('href'),
        js: document.querySelector('script[src]').getAttribute('src'),
    }));
    const panelViewportTop = iframeRect.y + panelRect.top;
    if (!Number.isFinite(panelViewportTop) || panelViewportTop < 0 || panelViewportTop >= 920 || panelRect.height <= 0) {
        throw new Error('review: tuning panel is outside the initial outer viewport ' + JSON.stringify({ iframeRect, panelRect }));
    }
    if (!cacheIdentity.css.includes('brand-radius-sequence-2') || !cacheIdentity.js.includes('brand-radius-sequence-2')) {
        throw new Error('review: stale cache identity ' + JSON.stringify(cacheIdentity));
    }
    results.push({ viewport: 'review-wrapper', panelViewportTop, cacheIdentity });
    await review.close();
} finally {
    await browser.close();
}

console.log(JSON.stringify(results, null, 2));
