import { chromium } from 'playwright-core';

const [baseUrl, desktopShot, mobileShot] = process.argv.slice(2);
if (!baseUrl || !desktopShot || !mobileShot) throw new Error('Usage: scope8-project-action-acceptance.mjs <base-url> <desktop-shot> <mobile-shot>');
const assert = (condition, message) => { if (!condition) throw new Error(message); };
const browser = await chromium.launch({ executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', headless: true });

try {
    const login = async (page, email) => {
        await page.goto(baseUrl + '/login');
        await page.locator('#email').fill(email);
        await page.locator('#password').fill('not-used');
        await Promise.all([page.waitForURL('**/company'), page.locator('button[type="submit"]').click()]);
    };
    const desktop = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await desktop.newPage();
    await login(page, 'scope8-owner@example.test');
    let response = await page.goto(baseUrl + '/company/projects');
    assert(response.status() === 200, 'Project index failed');
    const projectLink = page.locator('a[href$="/company/projects/1"]');
    assert(await projectLink.isVisible(), 'Project missing');
    await projectLink.click();
    assert(await page.getByText('Confirm launch checklist', { exact: true }).isVisible(), 'Direct Action missing');
    assert(await page.getByText('Customer Readiness', { exact: true }).isVisible(), 'Theme missing');
    await page.screenshot({ path: desktopShot, fullPage: true });
    await page.locator('a[href$="/manage"]').click();
    assert(await page.getByText('PARTICIPANTS', { exact: true }).isVisible(), 'Member UI missing');
    assert(await page.getByText('VISIBILITY', { exact: true }).isVisible(), 'Visibility UI missing');
    assert(await page.getByText('PROJECT COMPLETION', { exact: true }).isVisible(), 'Completion UI missing');

    const reader = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const mobile = await reader.newPage();
    await login(mobile, 'scope8-reader@example.test');
    response = await mobile.goto(baseUrl + '/company/projects');
    assert(response.status() === 200, 'Mobile index failed');
    await mobile.locator('a[href$="/company/projects/1"]').click();
    assert(await mobile.locator('a[href$="/manage"]').count() === 0, 'Non-member manage link leaked');
    assert(await mobile.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), '390px horizontal overflow');
    await mobile.screenshot({ path: mobileShot, fullPage: true });
    const manage = await mobile.goto(baseUrl + '/company/projects/1/manage');
    assert(manage.status() === 403, 'Non-member manage did not deny');
    await desktop.close();
    await reader.close();
    console.log(JSON.stringify({ desktop: desktopShot, mobile: mobileShot, status: 'pass' }));
} finally {
    await browser.close();
}
