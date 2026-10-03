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
    mobileMotionA: path.join(evidenceDirectory, 'mobile-motion-a-v002.png'),
    mobileMotionB: path.join(evidenceDirectory, 'mobile-motion-b-v002.png'),
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
    const desktopMotionTarget = frameLocator.locator('#vision .motion-target--statement');
    const initialMotionState = await desktopMotionTarget.evaluate(element => ({
        enhanced: document.body.classList.contains('is-motion-enhanced'),
        contract: document.body.dataset.motionContract,
        failureSubstage: document.body.dataset.motionFailureSubstage,
        motion: document.body.dataset.motion,
        hash: window.location.hash,
        observer: typeof window.IntersectionObserver,
        reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
        revealed: element.classList.contains('is-motion-revealed'),
        opacity: getComputedStyle(element).opacity,
        transform: getComputedStyle(element).transform,
    }));
    assert(initialMotionState.enhanced, `Motion A did not enable progressive enhancement: ${JSON.stringify(initialMotionState)}`);
    assert(!initialMotionState.revealed, 'Offscreen Motion A target was revealed before entering the viewport.');
    assert(initialMotionState.opacity === '0', `Offscreen Motion A target is visible: ${JSON.stringify(initialMotionState)}`);
    assert(initialMotionState.transform !== 'none', 'Motion A has no pre-reveal translation.');

    await desktopMotionTarget.evaluate(element => element.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(90);
    const motionAState = await desktopMotionTarget.evaluate(element => ({
        name: getComputedStyle(element).animationName,
        duration: getComputedStyle(element).animationDuration,
        revealed: element.classList.contains('is-motion-revealed'),
    }));
    assert(motionAState.name.includes('motion-a-enter'), `Motion A reveal did not run: ${JSON.stringify(motionAState)}`);
    assert(motionAState.duration === '0.36s', `Motion A duration is not 360ms: ${motionAState.duration}`);
    assert(motionAState.revealed, 'Motion A did not persist its revealed state.');
    await page.screenshot({ path: screenshots.desktopA });

    await page.waitForTimeout(420);
    await frameLocator.locator('html').evaluate(element => element.scrollTo(0, 0));
    await page.waitForTimeout(30);
    await desktopMotionTarget.evaluate(element => element.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(90);
    const oneShotState = await desktopMotionTarget.evaluate(element => ({
        revealed: element.classList.contains('is-motion-revealed'),
        animating: element.classList.contains('is-motion-animating'),
        opacity: getComputedStyle(element).opacity,
    }));
    assert(oneShotState.revealed && !oneShotState.animating && oneShotState.opacity === '1',
        `Revealed content replayed or hid during normal re-entry: ${JSON.stringify(oneShotState)}`);

    await frameLocator.getByRole('button', { name: /Motion B/ }).evaluate(button => button.click());
    await page.waitForTimeout(110);
    const motionBState = await desktopMotionTarget.evaluate(element => ({
        name: getComputedStyle(element).animationName,
        duration: getComputedStyle(element).animationDuration,
        distance: getComputedStyle(element).transform,
    }));
    assert(motionBState.name.includes('motion-b-enter'), `Motion B reveal did not run: ${JSON.stringify(motionBState)}`);
    assert(motionBState.duration === '0.54s', `Motion B duration is not 540ms: ${motionBState.duration}`);
    assert(motionBState.name !== motionAState.name && motionBState.duration !== motionAState.duration,
        'Motion A and B are not perceptibly distinct at the CSS contract.');
    await page.screenshot({ path: screenshots.desktopB });
    const textHashB = digest(await frameLocator.locator('.reader').innerText());

    await frameLocator.getByRole('button', { name: /^OFF/ }).evaluate(button => button.click());
    await page.waitForTimeout(40);
    const offStates = await frameLocator.locator('.motion-target').evaluateAll(elements => elements.map(element => ({
        opacity: getComputedStyle(element).opacity,
        transform: getComputedStyle(element).transform,
        animation: getComputedStyle(element).animationName,
    })));
    assert(offStates.every(state => state.opacity === '1' && state.transform === 'none' && state.animation === 'none'),
        'Motion OFF does not expose every target as completely static content.');
    const textHashOff = digest(await frameLocator.locator('.reader').innerText());
    assert(textHashA === textHashB && textHashB === textHashOff, 'Motion variants changed Reader text content.');
    await page.screenshot({ path: screenshots.desktopOff });

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

    const mobileMotionTarget = frameLocator.locator('#theme-three .motion-target--theme');
    await frameLocator.getByRole('button', { name: /Motion A/ }).evaluate(button => button.click());
    await page.waitForTimeout(40);
    const mobileBeforeReveal = await mobileMotionTarget.evaluate(element => ({
        revealed: element.classList.contains('is-motion-revealed'),
        opacity: getComputedStyle(element).opacity,
    }));
    assert(!mobileBeforeReveal.revealed && mobileBeforeReveal.opacity === '0',
        `Offscreen 390px Motion A target is not waiting for reveal: ${JSON.stringify(mobileBeforeReveal)}`);
    await mobileMotionTarget.evaluate(element => element.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(80);
    const mobileAState = await mobileMotionTarget.evaluate(element => ({
        name: getComputedStyle(element).animationName,
        duration: getComputedStyle(element).animationDuration,
    }));
    assert(mobileAState.name === 'motion-a-enter-mobile' && mobileAState.duration === '0.32s',
        `390px Motion A contract is wrong: ${JSON.stringify(mobileAState)}`);
    await page.screenshot({ path: screenshots.mobileMotionA });

    await frameLocator.getByRole('button', { name: /Motion B/ }).evaluate(button => button.click());
    await page.waitForTimeout(100);
    const mobileBState = await mobileMotionTarget.evaluate(element => ({
        name: getComputedStyle(element).animationName,
        duration: getComputedStyle(element).animationDuration,
    }));
    assert(mobileBState.name === 'motion-b-enter-mobile' && mobileBState.duration === '0.46s',
        `390px Motion B contract is wrong: ${JSON.stringify(mobileBState)}`);
    await page.screenshot({ path: screenshots.mobileMotionB });

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
    await frameLocator.getByRole('button', { name: /Motion B/ }).evaluate(button => button.click());
    await page.waitForTimeout(40);
    const reducedStates = await frameLocator.locator('.motion-target').evaluateAll(elements => elements.map(element => ({
        opacity: getComputedStyle(element).opacity,
        transform: getComputedStyle(element).transform,
        animation: getComputedStyle(element).animationName,
    })));
    assert(reducedStates.every(state => state.opacity === '1' && state.transform === 'none' && state.animation === 'none'),
        'Reduced motion does not expose the complete Reader immediately.');
    assert(!await frameLocator.locator('body').evaluate(element => element.classList.contains('is-motion-enhanced')),
        'Reduced motion left the enhancement hiding contract enabled.');
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

    const anchorPage = await context.newPage();
    await anchorPage.setViewportSize({ width: 1440, height: 900 });
    await anchorPage.goto(`${baseUrl}/index.html#annual`);
    const directAnchorState = await anchorPage.locator('#annual .motion-target--statement').evaluate(element => ({
        enhanced: document.body.classList.contains('is-motion-enhanced'),
        opacity: getComputedStyle(element).opacity,
        transform: getComputedStyle(element).transform,
    }));
    assert(!directAnchorState.enhanced && directAnchorState.opacity === '1' && directAnchorState.transform === 'none',
        `Direct fragment navigation hid its destination: ${JSON.stringify(directAnchorState)}`);
    await anchorPage.close();

    const internalAnchorPage = await context.newPage();
    await internalAnchorPage.setViewportSize({ width: 1440, height: 900 });
    await internalAnchorPage.goto(`${baseUrl}/index.html`);
    await internalAnchorPage.locator('.cover-index a').nth(3).click();
    const internalAnchorState = await internalAnchorPage.locator('#annual .motion-target--statement').evaluate(element => ({
        revealed: element.classList.contains('is-motion-revealed'),
        opacity: getComputedStyle(element).opacity,
    }));
    assert(internalAnchorState.revealed && internalAnchorState.opacity === '1',
        `Internal anchor navigation left its destination hidden: ${JSON.stringify(internalAnchorState)}`);
    await internalAnchorPage.close();

    const observerlessContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    await observerlessContext.addInitScript(() => {
        Object.defineProperty(window, 'IntersectionObserver', { configurable: true, value: undefined });
    });
    const observerlessPage = await observerlessContext.newPage();
    await observerlessPage.goto(`${baseUrl}/index.html`);
    const observerlessState = await observerlessPage.locator('#vision .motion-target--statement').evaluate(element => ({
        contract: document.body.dataset.motionContract,
        opacity: getComputedStyle(element).opacity,
    }));
    assert(observerlessState.contract === 'observer-unsupported-static' && observerlessState.opacity === '1',
        `Observer unsupported mode did not fail open: ${JSON.stringify(observerlessState)}`);
    await observerlessContext.close();

    const failureContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    await failureContext.addInitScript(() => {
        Object.defineProperty(window, 'IntersectionObserver', {
            configurable: true,
            value: class BrokenIntersectionObserver {
                constructor() { throw new Error('intentional prototype initialization failure'); }
            },
        });
    });
    const failurePage = await failureContext.newPage();
    await failurePage.goto(`${baseUrl}/index.html`);
    const failureState = await failurePage.locator('#vision .motion-target--statement').evaluate(element => ({
        contract: document.body.dataset.motionContract,
        opacity: getComputedStyle(element).opacity,
        transform: getComputedStyle(element).transform,
    }));
    assert(failureState.contract === 'initialization-failure-static'
        && failureState.opacity === '1'
        && failureState.transform === 'none',
    `Initialization failure did not fail open: ${JSON.stringify(failureState)}`);
    await failureContext.close();

    const zoomContext = await browser.newContext({ viewport: { width: 720, height: 450 } });
    const zoomPage = await zoomContext.newPage();
    await zoomPage.goto(`${baseUrl}/index.html`);
    await zoomPage.getByRole('button', { name: /^OFF/ }).click();
    const zoomOverflow = await zoomPage.locator('html').evaluate(element => element.scrollWidth - element.clientWidth);
    assert(zoomOverflow <= 0, `200% effective viewport overflows horizontally by ${zoomOverflow}px.`);
    await zoomContext.close();

    const noJsContext = await browser.newContext({ viewport: { width: 390, height: 844 }, javaScriptEnabled: false });
    const noJsPage = await noJsContext.newPage();
    await noJsPage.goto(`${baseUrl}/index.html`);
    assert(await noJsPage.getByText('まっすぐな仕事で、', { exact: false }).isVisible(), 'JavaScript-off mode hides Reader content.');
    const noJsStates = await noJsPage.locator('.motion-target').evaluateAll(elements => elements.map(element => ({
        opacity: getComputedStyle(element).opacity,
        transform: getComputedStyle(element).transform,
    })));
    assert(noJsStates.every(state => state.opacity === '1' && state.transform === 'none'),
        'JavaScript-off mode does not expose every Motion target.');
    assert(await noJsPage.locator('.chapter-toc a').count() === 4, 'JavaScript-off mode loses chapter navigation.');
    const noJsOverflow = await noJsPage.locator('html').evaluate(element => element.scrollWidth - element.clientWidth);
    assert(noJsOverflow <= 0, `JavaScript-off 390px Reader overflows by ${noJsOverflow}px.`);
    await noJsContext.close();

    assert(externalRequests.length === 0, `External requests detected: ${JSON.stringify(externalRequests)}`);
    assert(httpErrors.length === 0, `HTTP errors detected: ${JSON.stringify(httpErrors)}`);

    console.log(JSON.stringify({
        status: 'passed',
        prototype: 'isolated-static-v002-motion',
        officialDataConnected: false,
        databaseConnected: false,
        productRouteAdded: false,
        contentHash: textHashA,
        motionVariants: ['A-v002-subtle', 'B-v002-expressive', 'OFF-static'],
        desktopMotionDurations: ['360ms', '540ms'],
        mobileMotionDurations: ['320ms', '460ms'],
        failOpenModes: ['reduced-motion', 'javascript-off', 'observer-unsupported', 'initialization-failure', 'direct-fragment'],
        mobileTocVariants: ['A-normal-flow-disclosure', 'B-current-chapter-sticky'],
        viewports: ['1440x900', '390x844', '320x800', '200%-effective-720x450'],
        externalRequests: externalRequests.length,
        httpErrors: httpErrors.length,
        screenshots,
    }, null, 2));
} finally {
    await browser.close();
}
