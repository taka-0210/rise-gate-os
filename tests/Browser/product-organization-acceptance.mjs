import { chromium } from 'playwright-core';

const [baseUrl, desktopShot, mobileShot] = process.argv.slice(2);
if (!baseUrl || !desktopShot || !mobileShot) {
    throw new Error('Usage: product-organization-acceptance.mjs <base-url> <desktop-shot> <mobile-shot>');
}

const browser = await chromium.launch({
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    headless: true,
});

const assert = (condition, message) => {
    if (!condition) throw new Error(message);
};

const login = async (page, email) => {
    await page.goto(`${baseUrl}/login`);
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('not-used');
    await page.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
};

const logout = async page => {
    await page.locator('form[action$="/logout"] button').last().click();
    await page.waitForURL(`${baseUrl}/`);
};

try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    const serverErrors = [];
    page.on('response', response => {
        if (response.status() >= 500) serverErrors.push({ status: response.status(), url: response.url() });
    });

    await login(page, 'pux-single@example.test');
    await page.waitForURL('**/company');
    assert(await page.getByRole('heading', { name: 'PUX Single Company', exact: true }).isVisible(), 'Single-company user did not reach Company Home.');
    assert(await page.getByText('会社切替', { exact: true }).count() === 0, 'Single-company user was offered a company switch.');
    await page.goto(`${baseUrl}/companies`);
    await page.waitForURL('**/company');
    await logout(page);

    await login(page, 'pux-review@example.test');
    await page.waitForURL('**/companies');
    assert(await page.getByRole('heading', { name: '利用会社', exact: true }).isVisible(), 'Review-required selector is not visible.');
    assert(await page.getByText('PUX Active Alpha', { exact: true }).isVisible(), 'First active membership is missing.');
    assert(await page.getByText('PUX Active Beta', { exact: true }).isVisible(), 'Second active membership is missing.');
    assert(await page.getByText('PUX Suspended Hidden', { exact: true }).count() === 0, 'Suspended membership leaked into the selector.');
    assert(await page.getByText('既存の複数会社利用です。会社は自動選択されません。', { exact: true }).isVisible(), 'Explicit-selection guidance is missing.');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Company selector overflows on desktop.');
    await page.screenshot({ path: desktopShot, fullPage: true });
    const alphaCard = page.locator('article').filter({ hasText: 'PUX Active Alpha' });
    await alphaCard.getByRole('button', { name: 'この会社を利用する' }).click();
    await page.waitForURL('**/company');
    assert(await page.getByRole('heading', { name: 'PUX Active Alpha', exact: true }).isVisible(), 'Explicit company selection did not reach the selected Company Home.');
    await logout(page);

    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, 'pux-unstarted@example.test');
    await page.waitForURL('**/companies');
    assert(await page.getByText('利用する会社がまだ設定されていません', { exact: true }).isVisible(), 'Unstarted guidance is missing.');
    assert(await page.getByRole('main').getByRole('link', { name: 'Account', exact: true }).isVisible(), 'Account recovery path is missing.');
    assert(await page.getByRole('main').getByRole('button', { name: 'Logout', exact: true }).isVisible(), 'Logout path is missing.');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Unstarted state overflows at 390px.');
    await page.screenshot({ path: mobileShot, fullPage: true });

    assert(serverErrors.length === 0, `Laravel returned 5xx responses: ${JSON.stringify(serverErrors)}`);
    console.log(JSON.stringify({
        singleCompanyAutoEntry: true,
        reviewRequiredExplicitSelection: true,
        inactiveMembershipExcluded: true,
        unstartedGuidanceAt390px: true,
        serverErrors,
    }, null, 2));
} finally {
    await browser.close();
}
