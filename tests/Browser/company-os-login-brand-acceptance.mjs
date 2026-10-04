import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright-core';

const [baseUrl, evidenceDirectory] = process.argv.slice(2);
if (!baseUrl || !evidenceDirectory) {
    throw new Error('Usage: company-os-login-brand-acceptance.mjs <base-url> <evidence-directory>');
}

fs.mkdirSync(evidenceDirectory, { recursive: true });

const browser = await chromium.launch({
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    headless: true,
});

const assert = (condition, message) => {
    if (!condition) throw new Error(message);
};

const viewports = [
    { name: 'desktop-1920', width: 1920, height: 960 },
    { name: 'desktop-1440', width: 1440, height: 900 },
    { name: 'laptop-1366', width: 1366, height: 720 },
    { name: 'mobile-390', width: 390, height: 844 },
    { name: 'narrow-320', width: 320, height: 800 },
];

const results = [];

try {
    for (const viewport of viewports) {
        const context = await browser.newContext({
            viewport: { width: viewport.width, height: viewport.height },
            reducedMotion: 'reduce',
        });
        const page = await context.newPage();
        const externalRequests = [];
        const httpErrors = [];
        const expectedOrigin = new URL(baseUrl).origin;

        page.on('request', request => {
            if (new URL(request.url()).origin !== expectedOrigin) {
                externalRequests.push(request.url());
            }
        });
        page.on('response', response => {
            if (response.status() >= 400) {
                httpErrors.push({ status: response.status(), url: response.url() });
            }
        });

        const response = await page.goto(baseUrl + '/login', { waitUntil: 'networkidle' });
        assert(response?.status() === 200, viewport.name + ': login response must be 200');
        assert(await page.locator('body.login-brand-page').count() === 1, viewport.name + ': login body class is missing');
        assert(await page.locator('.login-brand__composition').count() === 1, viewport.name + ': composition is missing');
        assert(await page.locator('form[action$="/login"][method="POST"]').count() === 1, viewport.name + ': login form contract changed');
        assert(await page.locator('input[name="_token"]').count() === 1, viewport.name + ': CSRF token is missing');
        assert(await page.locator('input[name="email"][autocomplete="username"]').count() === 1, viewport.name + ': email field contract changed');
        assert(await page.locator('input[name="password"][autocomplete="current-password"]').count() === 1, viewport.name + ': password field contract changed');
        assert(await page.locator('input[name="remember"]').count() === 1, viewport.name + ': remember field is missing');
        assert(await page.locator('a[href$="/forgot-password"]').count() === 1, viewport.name + ': password recovery link changed');

        const state = await page.evaluate(() => {
            const html = document.documentElement;
            const body = document.body;
            const image = document.querySelector('.login-brand__visual-image');
            const rect = image?.getBoundingClientRect();
            const statement = document.querySelector('.login-brand__statement');
            const submit = document.querySelector('.login-brand__submit');
            const outerLeft = rect ? rect.left + rect.width * (1170 / 2880) : null;
            const outerRight = rect ? rect.left + rect.width * (2850 / 2880) : null;
            const outerWidth = outerLeft === null || outerRight === null ? null : outerRight - outerLeft;
            const visibleWidth = outerLeft === null || outerRight === null
                ? null
                : Math.max(0, Math.min(innerWidth, outerRight) - Math.max(0, outerLeft));

            return {
                horizontalOverflow: Math.max(0, Math.max(html.scrollWidth, body.scrollWidth) - html.clientWidth),
                pageHeight: Math.max(html.scrollHeight, body.scrollHeight),
                submitBottom: submit?.getBoundingClientRect().bottom ?? null,
                visualLoaded: Boolean(image && image.complete && image.naturalWidth > 0),
                outerLeft,
                outerRight,
                visibleRatio: outerWidth ? visibleWidth / outerWidth : 0,
                statementFont: statement ? getComputedStyle(statement).fontFamily : '',
            };
        });

        assert(state.horizontalOverflow <= 1, viewport.name + ': horizontal overflow detected');
        assert(state.visualLoaded, viewport.name + ': brand visual did not load');
        assert(!/Mincho/i.test(state.statementFont), viewport.name + ': statement must use gothic/sans typography');
        if (viewport.width > 760) {
            assert(state.submitBottom !== null && state.submitBottom <= viewport.height + 1, viewport.name + ': login button must be visible without scrolling');
            assert(state.pageHeight <= viewport.height + 1, viewport.name + ': login page must fit without vertical scrolling');
            assert(state.outerLeft >= -1, viewport.name + ': brand ring is cut on the left');
            assert(state.outerRight <= viewport.width + 1, viewport.name + ': brand ring is cut on the right');
        } else {
            assert(state.visibleRatio >= .55, viewport.name + ': too little of the mobile brand ring is visible');
            assert(state.visibleRatio <= .8, viewport.name + ': mobile brand ring competes with the content');
        }
        assert(externalRequests.length === 0, viewport.name + ': external request detected');
        assert(httpErrors.length === 0, viewport.name + ': HTTP asset error detected');

        await page.screenshot({
            path: path.join(evidenceDirectory, viewport.name + '.png'),
            fullPage: true,
        });
        results.push({
            viewport: viewport.name,
            horizontalOverflow: state.horizontalOverflow,
            visibleRatio: Number(state.visibleRatio.toFixed(3)),
            pageHeight: state.pageHeight,
            submitBottom: state.submitBottom === null ? null : Number(state.submitBottom.toFixed(1)),
            externalRequests: externalRequests.length,
            httpErrors: httpErrors.length,
        });
        await context.close();
    }
} finally {
    await browser.close();
}

console.log(JSON.stringify({ passed: true, results }, null, 2));
