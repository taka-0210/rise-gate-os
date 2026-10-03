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

        await page.locator('[data-brand-chapter-choice=vision]').evaluate(button => button.click());
        const tuning = await page.evaluate(() => {
            const input = document.querySelector('[data-brand-setting=opacity]');
            input.value = '25';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            return {
                chapter: document.body.dataset.brandChapter,
                opacity: document.querySelector('[data-brand-visual]').style.getPropertyValue('--brand-opacity'),
                output: document.querySelector('[data-brand-output=opacity]').textContent,
            };
        });

        if (initial.source !== 'official-svg' || initial.chapter !== 'philosophy' || initial.overflow !== 0 || !initial.readerVisible) {
            throw new Error(viewport.name + ': initial ' + JSON.stringify(initial));
        }
        if (initial.fade.duration !== '1350ms' || initial.fade.distance !== '40px' || initial.fade.blur !== '2.5px' || initial.fade.trigger !== '75') {
            throw new Error(viewport.name + ': fade ' + JSON.stringify(initial.fade));
        }
        if (annual.chapter !== 'annual' || Number(annual.now) <= Number(initial.root.now)) {
            throw new Error(viewport.name + ': annual ' + JSON.stringify(annual));
        }
        if (tuning.chapter !== 'vision' || tuning.opacity !== '0.25' || tuning.output !== '25%') {
            throw new Error(viewport.name + ': tuning ' + JSON.stringify(tuning));
        }
        if (errors.length) throw new Error(viewport.name + ': page errors ' + errors.join(' | '));

        results.push({ viewport: viewport.name, initial, annual, tuning, pageErrors: errors.length });
        await page.close();
    }
} finally {
    await browser.close();
}

console.log(JSON.stringify(results, null, 2));
