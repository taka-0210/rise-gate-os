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
    const http5xx = [];
    page.on('response', response => {
        if (response.status() >= 500) http5xx.push({status: response.status(), url: response.url()});
    });
    await page.goto(`${baseUrl}/login`);
    await page.locator('#email').fill('scope7-owner@example.test');
    await page.locator('#password').fill('not-used');
    await Promise.all([page.waitForURL('**/company'), page.locator('button[type="submit"]').click()]);
    for (const path of ['/company', '/account', '/company/organization']) {
        const response = await page.goto(`${baseUrl}${path}`);
        assert(response.status() === 200, `Shared layout regression at ${path}: ${response.status()}`);
        assert(await page.evaluate(() => getComputedStyle(document.body).backgroundColor) === 'rgb(238, 242, 245)', `Shared background is missing at ${path}.`);
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `Shared layout overflows at ${path}.`);
    }
    await page.goto(`${baseUrl}/company/business-domains`);
    assert(await page.getByRole('heading', { name: '事業領域', exact: true }).isVisible(), 'Business Domain index is not visible.');
    assert(await page.getByText('Concurrent Domain', { exact: true }).isVisible(), 'Seed domain is not visible.');
    assert(await page.locator('#domain-q').count() === 0, 'Large search form is still visible.');
    assert(await page.getByRole('link', { name: /利用中 1/ }).isVisible(), 'Active count tab is not visible.');
    assert(await page.getByRole('link', { name: /保管済み 0/ }).isVisible(), 'Archived count tab is not visible.');
    assert(await page.evaluate(() => getComputedStyle(document.body).backgroundColor) === 'rgb(238, 242, 245)', 'Shared page background token is not applied.');
    assert(await page.locator('.company-context-directory__visual img').isVisible(), 'Company Context visual is missing from the directory hero.');
    assert(await page.locator('.company-context-card').first().evaluate(element => {
        const style = getComputedStyle(element);
        return style.backgroundColor === 'rgba(0, 0, 0, 0)' && style.borderRadius === '0px';
    }), 'Domain index still looks like a stack of application cards.');
    await page.screenshot({ path: desktopShot, fullPage: true });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${baseUrl}/company/business-domains`);
    assert(await page.locator('.company-context-directory__hero').isVisible(), '390px directory hero is not visible.');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Business Domain index overflows at 390px.');
    await page.goto(`${baseUrl}/company/business-domains/create`);
    const longText = '地域の中小企業と一緒に、現場で使い続けられる仕組みを育てます。'.repeat(12);
    await page.locator('#domain-name').fill('ブラウザ受入事業');
    await page.locator('#domain-what_summary').fill(longText);
    await page.locator('#domain-who_summary').fill('地域の中小企業');
    await page.locator('#domain-value_proposition').fill('経営と実行をつなぐ伴走価値');
    await page.locator('#domain-geographic_scope_summary').fill('日本国内・オンライン');
    await page.locator('#domain-market_position_summary').fill('実装まで伴走するパートナー');
    await page.locator('#domain-direction').selectOption('growth');
    await page.locator('#domain-direction-memo').fill('地域事業者とのネットワークを広げる。');
    await page.locator('[data-add-item]').first().click();
    const firstItem = page.locator('[data-item]').first();
    await firstItem.locator('select[name$="[kind]"]').selectOption('service');
    await firstItem.locator('input[name$="[name]"]').fill('経営伴走サービス');
    await firstItem.locator('[data-add-attribute]').click();
    await firstItem.locator('[data-attribute] select').selectOption('value');
    await firstItem.locator('[data-attribute] input').fill('支援特徴');
    await firstItem.locator('[data-attribute] textarea').fill('日常業務へ無理なく入る設計');
    await page.locator('[data-add-item]').last().click();
    let secondItem = page.locator('[data-item]').nth(1);
    await page.waitForFunction(() => document.activeElement === document.querySelectorAll('[data-item]')[1]?.querySelector('input[name$="[name]"]'));
    assert(await secondItem.locator('input[name$="[name]"]').evaluate(element => element === document.activeElement), 'New item name did not receive focus.');
    page.once('dialog', dialog => dialog.accept());
    await secondItem.locator('[data-remove-item]').click();
    assert(await page.locator('[data-item]').count() === 1, 'Item removal confirmation did not remove the item.');
    await page.locator('[data-add-item]').last().click();
    secondItem = page.locator('[data-item]').nth(1);
    await secondItem.locator('select[name$="[kind]"]').selectOption('product');
    await secondItem.locator('input[name$="[name]"]').fill('支援ツール');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Create view overflows at 390px.');
    await Promise.all([page.waitForURL('**/company/business-domains/*'), page.locator('button[type="submit"]').last().click()]);
    assert(await page.getByRole('heading', { name: 'ブラウザ受入事業', exact: true }).isVisible(), 'Created domain is not visible.');
    assert(await page.getByText('経営伴走サービス', { exact: true }).isVisible(), 'Created item is not visible.');
    assert(await page.getByText('支援ツール', { exact: true }).isVisible(), 'Continuously added item is not visible.');
    assert(await page.getByText('成長・拡大', { exact: true }).isVisible(), 'Direction is not visible.');
    assert(await page.getByText('地域事業者とのネットワークを広げる。', { exact: true }).isVisible(), 'Direction memo is not visible.');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Show view overflows at 390px.');
    await page.screenshot({ path: mobileShot, fullPage: true });

    const showUrl = page.url();
    await page.locator('.company-context-tools summary').click();
    const editHref = await page.getByRole('link', { name: '内容を編集', exact: true }).getAttribute('href');
    await page.goto(`${baseUrl}/company/business-domains`);
    await page.getByRole('link', { name: '管理する', exact: true }).click();
    await page.getByRole('button', { name: 'ブラウザ受入事業を上へ' }).click();
    const orderedNames = await page.locator('.domain-card h2').allTextContents();
    assert(orderedNames[0] === 'ブラウザ受入事業', `Display order was not persisted: ${JSON.stringify(orderedNames)}`);
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
    await page.locator('.company-context-tools summary').click();
    await page.getByRole('link', { name: '管理・履歴', exact: true }).click();
    await page.locator('#state-reason').fill('ブラウザ保管確認');
    await Promise.all([page.waitForURL('**/company/business-domains/manage/*'), page.getByRole('button', { name: '保管する' }).click()]);
    await page.getByRole('link', { name: '読む画面へ', exact: true }).click();
    assert(await page.getByText(/この事業領域は保管済みです。/).isVisible(), 'Archived state is not visible.');
    await page.goto(`${baseUrl}/company/business-domains`);
    await page.getByRole('link', { name: /保管済み 1/ }).click();
    assert(await page.getByText('ブラウザ受入事業', { exact: true }).isVisible(), 'Archived tab does not show the archived domain.');
    await page.goto(showUrl);
    await page.locator('.company-context-tools summary').click();
    await page.getByRole('link', { name: '管理・履歴', exact: true }).click();
    const historyLink = page.locator('a[href*="/revisions/"]').first();
    await historyLink.click();
    assert(await page.getByText('読み取り専用Snapshot').isVisible(), 'Immutable revision view is not visible.');
    await page.goto(showUrl);
    await page.locator('.company-context-tools summary').click();
    await page.getByRole('link', { name: '管理・履歴', exact: true }).click();
    await page.locator('#state-reason').fill('ブラウザ再開確認');
    await Promise.all([page.waitForURL('**/company/business-domains/manage/*'), page.getByRole('button', { name: '利用中へ戻す' }).click()]);

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
    assert(http5xx.length === 0, `Unexpected HTTP 5xx responses: ${JSON.stringify(http5xx)}`);

    console.log(JSON.stringify({
        status: 'passed',
        desktop: '1280x900',
        mobile: '390x844',
        http5xx: http5xx.length,
        journeys: ['login', 'count-tabs', 'shared-background', 'create-long-content', 'direction', 'continuous-items', 'item-remove-confirm', 'scroll-focus', 'item-attribute', 'reorder', 'stale', 'archive-tab', 'history', 'reopen', 'editor-revoke', 'permission-negative'],
    }));
} finally {
    await browser.close();
}
