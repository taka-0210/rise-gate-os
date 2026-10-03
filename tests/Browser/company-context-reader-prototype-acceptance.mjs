import { chromium } from 'playwright-core';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

const [baseUrl, evidenceDirectory] = process.argv.slice(2);
if (!baseUrl || !evidenceDirectory) {
    throw new Error('Usage: company-context-reader-prototype-acceptance.mjs <base-url> <evidence-directory>');
}

fs.mkdirSync(evidenceDirectory, { recursive: true });

const browser = await chromium.launch({
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    headless: true,
});

const assert = (condition, message) => {
    if (!condition) throw new Error(message);
};

const digest = value => crypto.createHash('sha256').update(value).digest('hex');
const screenshots = {
    desktopA: path.join(evidenceDirectory, 'desktop-motion-a.png'),
    desktopB: path.join(evidenceDirectory, 'desktop-motion-b.png'),
    desktopOff: path.join(evidenceDirectory, 'desktop-motion-off.png'),
    mobileTocA: path.join(evidenceDirectory, 'mobile-toc-a.png'),
    mobileTocB: path.join(evidenceDirectory, 'mobile-toc-b.png'),
    narrow: path.join(evidenceDirectory, 'narrow-320.png'),
    reduced: path.join(evidenceDirectory, 'reduced-motion.png'),
};

const externalRequests = [];
const httpErrors = [];

try {
    const context = await browser.newContext({ viewport: { width: 1500, height: 1000 } });
    const page = await context.newPage();
    page.on('request', request => {
        if (!request.url().startsWith(baseUrl)) externalRequests.push(request.url());
    });
    page.on('response', response => {
        if (response.status() >= 400) httpErrors.push({ status: response.status(), url: response.url() });
    });

    await page.goto(`${baseUrl}/review.html`);
    const frameLocator = page.frameLocator('#reader-frame');
    await frameLocator.locator('#reader').waitFor();

    assert(await frameLocator.locator('.prototype-boundary').isVisible(), 'Prototype boundary is not visible.');
    assert(await frameLocator.locator('h1').count() === 1, 'Reader must have exactly one h1.');
    assert(await frameLocator.locator('.chapter > .chapter__intro h2').count() === 4, 'Reader must have exactly four chapter h2 headings.');
    assert(await frameLocator.getByText('架空Data・DB非接続・Permission未実装', { exact: true }).isVisible(), 'Isolation label is missing.');

    for (const text of [
        'まっすぐな仕事で、',
        '技術と対話がめぐり、',
        '強みを仕組みに変え、',
        '第23期｜2026年度',
        '承認済み / 現在有効',
        '現場の知恵をつなぎ、',
        '今期、何を実現したいのか',
        'この方針を定める背景',
        '部署方針',
    ]) {
        assert(await frameLocator.getByText(text, { exact: false }).first().isVisible(), `Required prototype content is missing: ${text}`);
    }

    assert(await frameLocator.locator('.vision-step').count() >= 4, 'Vision does not contain multiple sections.');
    assert(await frameLocator.locator('.policy-item').count() >= 6, 'Policy stress fixture is too short.');
    assert(await frameLocator.locator('.theme').count() >= 3, 'Annual policy must contain multiple themes.');
    assert(await frameLocator.locator('.priorities > section').count() >= 9, 'Annual policy must contain multiple priorities.');
    assert(await frameLocator.locator('.department-grid > section').count() >= 3, 'Annual policy must contain multiple departments.');

    const annualOrder = await frameLocator.locator('#annual').evaluate(section => {
        const selectors = ['.annual-period', '.annual-lead', '.annual-context', '.themes', '.departments'];
        return selectors.map(selector => section.querySelector(selector)?.offsetTop ?? -1);
    });
    assert(annualOrder.every(position => position >= 0), `Annual hierarchy is incomplete: ${annualOrder}`);
    assert(annualOrder.every((position, index) => index === 0 || position > annualOrder[index - 1]), `Annual hierarchy order is wrong: ${annualOrder}`);

    const tocTargetsValid = await frameLocator.locator('.chapter-toc a, .cover-index a, .mobile-toc a').evaluateAll(links =>
        links.every(link => Boolean(document.querySelector(link.getAttribute('href')))),
    );
    assert(tocTargetsValid, 'A TOC link points to a missing anchor.');

    const readerTextA = await frameLocator.locator('.reader').innerText();
    const textHashA = digest(readerTextA);
    await frameLocator.getByRole('button', { name: /Motion B/ }).click();
    await page.waitForTimeout(30);
    const motionBName = await frameLocator.locator('#philosophy .motion-target--statement').evaluate(element => {
        element.classList.add('is-motion-active');
        return getComputedStyle(element).animationName;
    });
    assert(motionBName.includes('motion-b-enter'), `Motion B did not apply to the important Statement: ${motionBName}`);
    const textHashB = digest(await frameLocator.locator('.reader').innerText());

    await frameLocator.getByRole('button', { name: /^OFF/ }).click();
    await page.waitForTimeout(30);
    const motionOffName = await frameLocator.locator('#philosophy .motion-target--statement').evaluate(element => getComputedStyle(element).animationName);
    assert(motionOffName === 'none', `Motion OFF still animates: ${motionOffName}`);
    const textHashOff = digest(await frameLocator.locator('.reader').innerText());
    assert(textHashA === textHashB && textHashB === textHashOff, 'Motion variants changed Reader text content.');

    await frameLocator.getByRole('button', { name: /Motion A/ }).click();
    await page.screenshot({ path: screenshots.desktopA, fullPage: true });
    await frameLocator.getByRole('button', { name: /Motion B/ }).click();
    await page.screenshot({ path: screenshots.desktopB, fullPage: true });
    await frameLocator.getByRole('button', { name: /^OFF/ }).click();
    await page.screenshot({ path: screenshots.desktopOff, fullPage: true });

    await page.getByRole('button', { name: /Mobile/ }).click();
    const frameWidth = await page.locator('#reader-frame').evaluate(element => Math.round(element.getBoundingClientRect().width));
    assert(frameWidth === 390, `Mobile review viewport is not 390px: ${frameWidth}`);

    const mobileReaderTextBefore = digest(await frameLocator.locator('.reader').innerText());
    await page.getByRole('button', { name: /TOC A/ }).click();
    const tocAPosition = await frameLocator.locator('.mobile-toc').evaluate(element => getComputedStyle(element).position);
    assert(tocAPosition === 'static', `TOC A is not normal flow: ${tocAPosition}`);
    await page.screenshot({ path: screenshots.mobileTocA, fullPage: true });

    await page.getByRole('button', { name: /TOC B/ }).click();
    const tocBPosition = await frameLocator.locator('.mobile-toc').evaluate(element => getComputedStyle(element).position);
    assert(tocBPosition === 'sticky', `TOC B is not sticky: ${tocBPosition}`);
    const mobileReaderTextAfter = digest(await frameLocator.locator('.reader').innerText());
    assert(mobileReaderTextBefore === mobileReaderTextAfter, 'TOC A/B changed Reader text content.');
    await page.screenshot({ path: screenshots.mobileTocB, fullPage: true });

    const mobileOverflow = await frameLocator.locator('html').evaluate(element => element.scrollWidth - element.clientWidth);
    assert(mobileOverflow <= 0, `390px Reader overflows horizontally by ${mobileOverflow}px.`);

    await page.getByRole('button', { name: /Narrow/ }).click();
    const narrowWidth = await page.locator('#reader-frame').evaluate(element => Math.round(element.getBoundingClientRect().width));
    assert(narrowWidth === 320, `Narrow review viewport is not 320px: ${narrowWidth}`);
    const narrowOverflow = await frameLocator.locator('html').evaluate(element => element.scrollWidth - element.clientWidth);
    assert(narrowOverflow <= 0, `320px Reader overflows horizontally by ${narrowOverflow}px.`);
    await page.screenshot({ path: screenshots.narrow, fullPage: true });

    await page.getByRole('button', { name: /Desktop/ }).click();
    await frameLocator.locator('[data-management-placeholder]').first().click();
    assert(await frameLocator.getByText('Prototypeのため管理機能は実装していません。', { exact: true }).isVisible(), 'Management placeholder does not disclose its non-functional boundary.');

    await page.emulateMedia({ reducedMotion: 'reduce' });
    await frameLocator.getByRole('button', { name: /Motion B/ }).click();
    const reducedAnimationName = await frameLocator.locator('#philosophy .motion-target--statement').evaluate(element => getComputedStyle(element).animationName);
    assert(reducedAnimationName === 'none', `Reduced motion still animates: ${reducedAnimationName}`);
    assert(await frameLocator.getByText('まっすぐな仕事で、', { exact: false }).isVisible(), 'Reduced motion hides official-style content.');
    await page.screenshot({ path: screenshots.reduced, fullPage: true });
    await page.emulateMedia({ reducedMotion: 'no-preference' });

    const directPage = await context.newPage();
    await directPage.setViewportSize({ width: 1440, height: 900 });
    await directPage.goto(`${baseUrl}/index.html`);
    const desktopOverflow = await directPage.locator('html').evaluate(element => element.scrollWidth - element.clientWidth);
    assert(desktopOverflow <= 0, `1440px Reader overflows horizontally by ${desktopOverflow}px.`);
    await directPage.keyboard.press('Tab');
    assert(await directPage.evaluate(() => document.activeElement?.classList.contains('skip-link')), 'First keyboard focus does not reach the skip link.');
    assert(await directPage.evaluate(() => getComputedStyle(document.activeElement).outlineStyle !== 'none'), 'Keyboard focus is not visible.');
    await directPage.close();

    const noJsContext = await browser.newContext({ viewport: { width: 390, height: 844 }, javaScriptEnabled: false });
    const noJsPage = await noJsContext.newPage();
    await noJsPage.goto(`${baseUrl}/index.html`);
    assert(await noJsPage.getByText('まっすぐな仕事で、', { exact: false }).isVisible(), 'JavaScript-off mode hides Reader content.');
    assert(await noJsPage.locator('.chapter-toc a').count() === 4, 'JavaScript-off mode loses chapter navigation.');
    const noJsOverflow = await noJsPage.locator('html').evaluate(element => element.scrollWidth - element.clientWidth);
    assert(noJsOverflow <= 0, `JavaScript-off 390px Reader overflows by ${noJsOverflow}px.`);
    await noJsContext.close();

    assert(externalRequests.length === 0, `External requests detected: ${JSON.stringify(externalRequests)}`);
    assert(httpErrors.length === 0, `HTTP errors detected: ${JSON.stringify(httpErrors)}`);

    console.log(JSON.stringify({
        status: 'passed',
        prototype: 'isolated-static',
        officialDataConnected: false,
        databaseConnected: false,
        productRouteAdded: false,
        contentHash: textHashA,
        motionVariants: ['A', 'B', 'OFF'],
        mobileTocVariants: ['A-normal-flow-disclosure', 'B-current-chapter-sticky'],
        viewports: ['1440x900', '390x844', '320x800'],
        externalRequests: externalRequests.length,
        httpErrors: httpErrors.length,
        screenshots,
    }, null, 2));
} finally {
    await browser.close();
}
