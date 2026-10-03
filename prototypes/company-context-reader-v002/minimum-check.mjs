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
                const rect = document.querySelector('[data-brand-tuning]').getBoundingClientRect();
                return rect.top >= 0 && rect.top < window.innerHeight && rect.height > 0;
            })(),
            root: {
                core: document.querySelector('#core-light').style.opacity,
                outer: document.querySelector('#knowledge-layer').style.opacity,
                structure: document.querySelector('#connections').style.opacity,
                now: document.querySelector('#active-flow').style.opacity,
            },
            fade: {
                duration: document.body.style.getPropertyValue('--motion-duration'),
                distance: document.body.style.getPropertyValue('--motion-distance'),
                blur: document.body.style.getPropertyValue('--motion-blur'),
                trigger: document.body.dataset.motionTrigger,
            },
        }));

        await page.locator('#annual').scrollIntoViewIfNeeded();
        await page.waitForTimeout(250);
        const annual = await page.evaluate(() => ({
            chapter: document.body.dataset.brandChapter,
            core: document.querySelector('#core-light').style.opacity,
            outer: document.querySelector('#knowledge-layer').style.opacity,
            structure: document.querySelector('#connections').style.opacity,
            now: document.querySelector('#active-flow').style.opacity,
        }));

        const tuning = await page.evaluate(() => {
            const size = document.querySelector('[data-brand-setting=size]');
            const x = document.querySelector('[data-brand-setting=x]');
            const y = document.querySelector('[data-brand-setting=y]');
            const blur = document.querySelector('[data-brand-setting=blur]');
            size.value = '110';
            x.value = '120';
            y.value = '-60';
            blur.value = '3';
            [size, x, y, blur].forEach(input => input.dispatchEvent(new Event('input', { bubbles: true })));
            return {
                scale: document.querySelector('[data-brand-visual-canvas]').style.getPropertyValue('--brand-scale'),
                x: document.querySelector('[data-brand-visual-canvas]').style.getPropertyValue('--brand-x'),
                y: document.querySelector('[data-brand-visual-canvas]').style.getPropertyValue('--brand-y'),
                blur: document.querySelector('[data-brand-visual]').style.getPropertyValue('--brand-blur'),
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
        if (annual.chapter !== 'annual' || Number(annual.now) <= Number(initial.root.now)) {
            throw new Error(viewport.name + ': annual ' + JSON.stringify(annual));
        }
        if (tuning.scale !== '1.067' || tuning.x !== '120px' || tuning.y !== '-60px' || tuning.blur !== '3px'
            || tuning.outputs.join('|') !== '110%|120px|-60px|3px') {
            throw new Error(viewport.name + ': tuning ' + JSON.stringify(tuning));
        }
        if (errors.length) throw new Error(viewport.name + ': page errors ' + errors.join(' | '));

        results.push({ viewport: viewport.name, initial, annual, tuning, pageErrors: errors.length });
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
    if (!cacheIdentity.css.includes('brand-position-controls-2') || !cacheIdentity.js.includes('brand-position-controls-2')) {
        throw new Error('review: stale cache identity ' + JSON.stringify(cacheIdentity));
    }
    results.push({ viewport: 'review-wrapper', panelViewportTop, cacheIdentity });
    await review.close();
} finally {
    await browser.close();
}

console.log(JSON.stringify(results, null, 2));
