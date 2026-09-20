import { chromium } from 'playwright-core';

const [baseUrl, desktopShot, mobileShot] = process.argv.slice(2);
if (!baseUrl || !desktopShot || !mobileShot) {
    throw new Error('Usage: business-domain-acceptance.mjs <base-url> <desktop-shot> <mobile-shot>');
}

const browser = await chromium.launch({
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    headless: true,
});

const assert = (condition, message) => {
    if (!condition) throw new Error(message);
};

try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    await page.goto(`${baseUrl}/login`);
    await page.locator('#email').fill('scope7-owner@example.test');
    await page.locator('#password').fill('not-used');
    await Promise.all([page.waitForURL('**/company'), page.locator('button[type="submit"]').click()]);
    await page.goto(`${baseUrl}/company/business-domains`);
    assert(await page.getByRole('heading', { name: '事業領域', exact: true }).isVisible(), 'Business Domain index is not visible.');
    assert(await page.getByText('Concurrent Domain', { exact: true }).isVisible(), 'Seed domain is not visible.');
    await page.screenshot({ path: desktopShot, fullPage: true });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${baseUrl}/company/business-domains/create`);
    const longText = '地域の中小企業と一緒に、現場で使い続けられる仕組みを育てます。'.repeat(12);
    await page.locator('#domain-name').fill('ブラウザ受入事業');
    await page.locator('#domain-what_summary').fill(longText);
    await page.locator('#domain-who_summary').fill('地域の中小企業');
    await page.locator('#domain-value_proposition').fill('経営と実行をつなぐ伴走価値');
    await page.locator('#domain-geographic_scope_summary').fill('日本国内・オンライン');
    await page.locator('#domain-market_position_summary').fill('実装まで伴走するパートナー');
    await page.locator('[data-add-item]').click();
    await page.locator('[data-item] select[name$="[kind]"]').selectOption('service');
    await page.locator('[data-item] input[name$="[name]"]').fill('経営伴走サービス');
    await page.locator('[data-item] [data-add-attribute]').click();
    await page.locator('[data-attribute] select').selectOption('value');
    await page.locator('[data-attribute] input').fill('支援特徴');
    await page.locator('[data-attribute] textarea').fill('日常業務へ無理なく入る設計');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Create view overflows at 390px.');
    await Promise.all([page.waitForURL('**/company/business-domains/*'), page.locator('button[type="submit"]').last().click()]);
    assert(await page.getByRole('heading', { name: 'ブラウザ受入事業', exact: true }).isVisible(), 'Created domain is not visible.');
    assert(await page.getByText('経営伴走サービス', { exact: true }).isVisible(), 'Created item is not visible.');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Show view overflows at 390px.');
    await page.screenshot({ path: mobileShot, fullPage: true });

    const showUrl = page.url();
    const editHref = await page.getByRole('link', { name: '編集', exact: true }).getAttribute('href');
    const stalePage = await context.newPage();
    await stalePage.goto(editHref);
    await page.goto(editHref);
    await page.locator('#domain-who_summary').fill('更新後の顧客');
    await page.locator('#change-reason').fill('顧客像を更新');
    await Promise.all([page.waitForURL('**/company/business-domains/*'), page.locator('button[type="submit"]').last().click()]);
    await stalePage.locator('#domain-who_summary').fill('古い画面の顧客');
    await stalePage.locator('#change-reason').fill('古い画面から更新');
    await stalePage.locator('button[type="submit"]').last().click();
    assert(await stalePage.getByText('別の変更が保存されています。再読み込みしてください。').isVisible(), 'Stale conflict message is not visible.');
    await stalePage.close();

    await page.goto(showUrl);
    await page.locator('#state-reason').fill('ブラウザ保管確認');
    await Promise.all([page.waitForURL('**/company/business-domains/*'), page.getByRole('button', { name: '保管する' }).click()]);
    assert(await page.getByText('この事業領域は保管済みです。').isVisible(), 'Archived state is not visible.');
    const historyLink = page.locator('a[href*="/revisions/"]').first();
    await historyLink.click();
    assert(await page.getByText('読み取り専用Snapshot').isVisible(), 'Immutable revision view is not visible.');
    await page.goto(showUrl);
    await page.locator('#state-reason').fill('ブラウザ再開確認');
    await Promise.all([page.waitForURL('**/company/business-domains/*'), page.getByRole('button', { name: '利用中へ戻す' }).click()]);

    const editorResponse = await page.goto(`${baseUrl}/company/business-domains/editors`);
    assert(editorResponse.status() === 200, `Editor management returned ${editorResponse.status()}.`);
    const targetRow = page.locator('.editor-row').filter({ hasText: 'ADMIN / ACTIVE' }).first();
    const editorRows = await page.locator('.editor-row').allTextContents();
    assert(await targetRow.count() === 1, `Seed Admin row is not visible: ${JSON.stringify(editorRows)}`);
    assert(await targetRow.getByText('編集担当', { exact: true }).isVisible(), `Seed editor grant is not visible: ${JSON.stringify(editorRows)}`);
    await targetRow.getByRole('button', { name: '解除' }).click();
    await page.locator('form[action$="/logout"] button').click();
    await page.goto(`${baseUrl}/login`);
    await page.locator('#email').fill('scope7-target@example.test');
    await page.locator('#password').fill('not-used');
    await Promise.all([page.waitForURL('**/company'), page.locator('button[type="submit"]').click()]);
    const currentResponse = await page.goto(showUrl);
    assert(currentResponse.status() === 200, 'Active staff cannot view current value after editor revoke.');
    const editResponse = await page.goto(`${showUrl}/edit`);
    assert(editResponse.status() === 403, 'Revoked Admin can still edit Business Domain.');

    console.log(JSON.stringify({
        status: 'passed',
        desktop: '1280x900',
        mobile: '390x844',
        journeys: ['login', 'list', 'create-long-content', 'item-attribute', 'stale', 'archive', 'history', 'reopen', 'editor-revoke', 'permission-negative'],
    }));
} finally {
    await browser.close();
}
