import { chromium } from 'playwright-core';
import fs from 'node:fs';

const [baseUrl, evidenceDirectory] = process.argv.slice(2);
if (!baseUrl || !evidenceDirectory) {
    throw new Error('Usage: company-context-reader-acceptance.mjs <base-url> <evidence-directory>');
}
fs.mkdirSync(evidenceDirectory, { recursive: true });
const assert = (condition, message) => {
    if (!condition) throw new Error(message);
};
const browser = await chromium.launch({
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    headless: true,
});

try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    const http5xx = [];
    const externalRequests = [];
    page.on('response', response => {
        if (response.status() >= 500) http5xx.push({ status: response.status(), url: response.url() });
    });
    page.on('request', request => {
        const hostname = new URL(request.url()).hostname;
        if (!['127.0.0.1', 'localhost'].includes(hostname)) externalRequests.push(request.url());
    });

    await page.goto(baseUrl + '/login');
    await page.locator('#email').fill('context-reader-owner@example.test');
    await page.locator('#password').fill('not-used');
    await Promise.all([
        page.waitForURL('**/company'),
        page.locator('button[type="submit"]').click(),
    ]);
    assert(await page.getByRole('link', { name: /会社の言葉/ }).isVisible(), 'Company Home has no Reader entry.');
    const response = await page.goto(baseUrl + '/company/company-context');
    assert(response.status() === 200, 'Reader returned ' + response.status());
    assert(await page.getByRole('heading', { name: '会社の言葉', exact: true }).isVisible(), 'Reader cover is missing.');
    assert(await page.locator('[data-chapter]').count() === 4, 'Authorized four-chapter story is incomplete.');
    assert(await page.getByText('誠実な仕事で、人と地域の明日を明るくする。', { exact: true }).count() === 1, 'Philosophy snapshot is missing.');
    assert(await page.getByText('現場の知恵をつなぎ、価値が届く速さと確かさを高める。', { exact: true }).count() === 1, 'Annual approved snapshot is missing.');
    assert(await page.getByText('第23期｜2026年度', { exact: true }).count() >= 1, 'Fiscal term label is missing.');
    assert(await page.getByText('承認済み / 現在有効', { exact: true }).count() >= 1, 'Approved/effective lifecycle is missing.');
    assert(await page.locator('[data-reader-width]').count() === 0, 'Canvas comparison control remains after the 1440px decision.');
    assert(await page.locator('[data-brand-setting]').count() === 2, 'Temporary Brand position controls are incomplete.');
    assert(await page.getByText(/VISUAL PROTOTYPE|NOT OFFICIAL DATA/).count() === 0, 'Prototype boundary text leaked into product.');
    assert(await page.locator('.ccr-rail').isVisible(), 'Desktop TOC is missing.');
    assert(await page.locator('.ccr-mobile-toc').isHidden(), 'Mobile TOC is visible on desktop.');
    await page.setViewportSize({ width: 1600, height: 1000 });
    assert(await page.locator('.main').evaluate(element => Math.round(element.getBoundingClientRect().width) === 1440), 'Desktop Reader Canvas is not fixed at 1440px.');
    assert(await page.locator('.ccr-opening').first().evaluate(element => element.getBoundingClientRect().width <= 720), 'Reading measure exceeds 720px.');
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.locator('[data-brand-setting="x"]').fill('-180');
    await page.locator('[data-brand-setting="size"]').fill('115');
    assert(await page.locator('[data-brand-output="x"]').textContent() === '-180px', 'Brand X tuning is not applied immediately.');
    assert(await page.locator('[data-brand-output="size"]').textContent() === '115%', 'Brand size tuning is not applied immediately.');
    await page.locator('[data-brand-setting="x"]').fill('-110');
    await page.locator('[data-brand-setting="size"]').fill('100');
    await page.waitForFunction(() => document.querySelector('[data-brand-canvas] svg #company-core'));
    assert((await page.locator('[data-brand-canvas] svg').count()) === 1, 'Official brand SVG was not loaded as layers.');

    const philosophyIntro = page.locator('#philosophy .ccr-chapter__intro');
    assert(await page.locator('[data-company-context-reader]').getAttribute('data-brand-entry') === 'waiting', 'Brand visual entered before the ROOT transition.');
    await page.waitForFunction(() => document.querySelector('[data-company-context-reader]').classList.contains('is-motion-enhanced'));
    assert(await philosophyIntro.evaluate(element => getComputedStyle(element).opacity === '0'), 'Pending content is visible before its 75% trigger.');
    await philosophyIntro.evaluate(element => element.scrollIntoView({ block: 'center', behavior: 'auto' }));
    await page.waitForFunction(() => document.querySelector('#philosophy .ccr-chapter__intro').classList.contains('is-revealed'));
    await page.waitForTimeout(1400);
    assert(await philosophyIntro.evaluate(element => element.classList.contains('is-revealed') && getComputedStyle(element).opacity === '1'), '1350ms reveal did not complete.');
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'auto' }));
    await page.waitForTimeout(80);
    assert(await philosophyIntro.evaluate(element => element.classList.contains('is-revealed')), 'Revealed content was hidden again.');
    assert(await page.locator('[data-company-context-reader]').getAttribute('data-brand-entry') === 'entered', 'Brand entry did not complete at ROOT.');

    const activate = async key => {
        await page.locator('#' + key).evaluate(element => window.scrollTo({ top: window.scrollY + element.getBoundingClientRect().top - window.innerHeight * .25, behavior: 'auto' }));
        await page.waitForFunction(expected => document.querySelector('[data-company-context-reader]').dataset.brandChapter === expected, key);
        return await page.evaluate(() => ({
            chapter: document.querySelector('[data-company-context-reader]').dataset.brandChapter,
            outer: document.querySelector('[data-brand-canvas] svg #knowledge-layer').style.opacity,
            structure: document.querySelector('[data-brand-canvas] svg #connections').style.opacity,
            boundary: document.querySelector('[data-brand-canvas] svg #outer-structure').style.opacity,
        }));
    };
    const vision = await activate('vision');
    const policy = await activate('policy');
    const annual = await activate('annual');
    assert(vision.chapter === 'vision' && Number(vision.outer) === 0, 'Vision advanced into the outer ring.');
    assert(policy.chapter === 'policy' && Number(policy.structure) === 0 && Number(policy.outer) === 0, 'Policy exposed NOW lines or the outer ring.');
    assert(annual.chapter === 'annual' && Number(annual.structure) === 1 && Number(annual.outer) === 1 && Number(annual.boundary) > .5, 'Annual NOW state is not distinct.');
    await page.screenshot({ path: evidenceDirectory + '/desktop-1440x1000.png', fullPage: true });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(baseUrl + '/company/company-context');
    assert(await page.locator('.ccr-mobile-toc').isVisible(), '390px current-chapter TOC is missing.');
    assert(await page.locator('.ccr-rail').isHidden(), 'Desktop TOC remains at 390px.');
    assert(await page.locator('.ccr-brand-tuner').isHidden(), 'Temporary Brand tuning UI is visible at 390px.');
    assert(await page.locator('.ccr-mobile-toc').evaluate(element => getComputedStyle(element).position === 'sticky'), '390px TOC is not the approved short sticky control.');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), '390px Reader overflows horizontally.');
    assert(await page.locator('[data-brand-visual]').evaluate(element => parseFloat(getComputedStyle(element).right) < 0), 'Mobile Brand Visual is not held on the right.');
    await page.screenshot({ path: evidenceDirectory + '/mobile-390x844.png', fullPage: true });

    await page.setViewportSize({ width: 320, height: 800 });
    await page.goto(baseUrl + '/company/company-context');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), '320px Reader overflows horizontally.');
    await page.screenshot({ path: evidenceDirectory + '/narrow-320x800.png', fullPage: true });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(baseUrl + '/company/company-context');
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), '200% text resize causes horizontal overflow.');
    assert(await page.locator('.ccr-mobile-toc').isVisible(), '200% text resize hides navigation.');

    await page.evaluate(() => { document.documentElement.style.fontSize = ''; });
    await page.getByRole('button', { name: 'OFF', exact: true }).click();
    assert(await page.locator('[data-motion-choice="off"]').getAttribute('aria-pressed') === 'true', 'Motion OFF did not apply.');
    assert(await page.locator('.ccr-reveal').evaluateAll(elements => elements.every(element => getComputedStyle(element).opacity === '1')), 'Motion OFF leaves hidden content.');

    const cookies = await context.cookies();
    const reducedContext = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    await reducedContext.addCookies(cookies);
    const reducedPage = await reducedContext.newPage();
    await reducedPage.goto(baseUrl + '/company/company-context');
    assert(await reducedPage.locator('.ccr-reveal').evaluateAll(elements => elements.every(element => getComputedStyle(element).opacity === '1')), 'Reduced motion leaves hidden content.');
    await reducedContext.close();

    const noJsContext = await browser.newContext({ viewport: { width: 390, height: 844 }, javaScriptEnabled: false });
    await noJsContext.addCookies(cookies);
    const noJsPage = await noJsContext.newPage();
    await noJsPage.goto(baseUrl + '/company/company-context');
    assert(await noJsPage.getByText('お客様の時間を守る', { exact: true }).isVisible(), 'JS OFF hides official content.');
    assert(await noJsPage.locator('.ccr-reveal').evaluateAll(elements => elements.every(element => getComputedStyle(element).opacity === '1')), 'JS OFF leaves hidden content.');
    await noJsContext.close();

    await page.goto(baseUrl + '/company/company-context#annual');
    assert(await page.locator('#annual .ccr-chapter__intro').evaluate(element => getComputedStyle(element).opacity === '1'), 'Direct anchor leaves target invisible.');
    await page.locator('.ccr-mobile-toc summary').focus();
    assert(await page.locator('.ccr-mobile-toc summary').evaluate(element => document.activeElement === element), 'Keyboard focus cannot reach mobile TOC.');
    await page.emulateMedia({ media: 'print' });
    assert(await page.locator('.ccr-toolbar').evaluate(element => getComputedStyle(element).display === 'none'), 'Print retains Reader controls.');
    assert(await page.getByText('正式Revision 1', { exact: false }).count() >= 4, 'Print loses chapter revision identity.');

    assert(http5xx.length === 0, 'Unexpected HTTP 5xx: ' + JSON.stringify(http5xx));
    assert(externalRequests.length === 0, 'Unexpected external requests: ' + JSON.stringify(externalRequests));
    console.log(JSON.stringify({
        status: 'passed',
        desktop: '1440x1000',
        mobile: '390x844',
        narrow: '320x800',
        textResize: '200%',
        http5xx: 0,
        externalRequests: 0,
        checks: ['four-chapter-story', 'desktop-toc', 'mobile-toc-b', 'motion-one-shot', 'motion-off', 'reduced-motion', 'js-off', 'direct-anchor', 'brand-chapter-states', 'print-revision-identity'],
    }));
} finally {
    await browser.close();
}
