import { chromium } from 'playwright-core';
import fs from 'node:fs';

const [baseUrl, desktopShot, mobileShot, printShot, printPdf] = process.argv.slice(2);
if (!baseUrl || !desktopShot || !mobileShot || !printShot || !printPdf) {
    throw new Error('Usage: company-context-presentation-acceptance.mjs <base-url> <desktop-shot> <mobile-shot> <print-shot> <print-pdf>');
}

const browser = await chromium.launch({
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    headless: true,
});
const assert = (condition, message) => {
    if (!condition) throw new Error(message);
};

try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
    const page = await context.newPage();
    const http5xx = [];
    page.on('response', response => {
        if (response.status() >= 500) http5xx.push({ status: response.status(), url: response.url() });
    });

    const login = async email => {
        await page.goto(baseUrl + '/login');
        await page.locator('#email').fill(email);
        await page.locator('#password').fill('not-used');
        await Promise.all([
            page.waitForURL('**/company'),
            page.locator('button[type="submit"]').click(),
        ]);
    };

    await login('pux-b-owner@example.test');
    let response = await page.goto(baseUrl + '/company/business-domains');
    assert(response.status() === 200, 'Read index returned ' + response.status());
    assert(await page.getByRole('heading', { name: '事業領域', exact: true }).isVisible(), 'Read title is missing.');
    assert(await page.getByText('地域共創事業', { exact: true }).isVisible(), 'Seed domain is missing.');
    assert(await page.locator('form[action*="/move"]').count() === 0, 'Read index still contains reorder forms.');
    assert(await page.locator('.domain-order-actions').count() === 0, 'Read index still contains management controls.');

    await page.getByText('地域共創事業', { exact: true }).click();
    assert(await page.locator('.company-context-read form').count() === 0, 'Read detail contains a management form.');
    assert(await page.getByText('Revision履歴').count() === 0, 'Read detail contains history.');
    const editHref = await page.getByRole('link', { name: '内容を編集', exact: true }).getAttribute('href');
    const showUrl = page.url();
    await page.goto(editHref);

    const longText = '地域企業の現場に入り、経営の考えと日々の実行をつなぐ仕組みを一緒につくります。'.repeat(14);
    await page.locator('#domain-description').fill('地域企業が次の一歩を選び、実行し、学びを会社の資産として残すための伴走事業です。');
    await page.locator('#domain-what_summary').fill(longText);
    await page.locator('#domain-who_summary').fill('地域で事業を営む中小企業と、その会社で働く人たち');
    await page.locator('#domain-value_proposition').fill('経営判断を、現場で続けられる具体的な行動へ変えること');
    await page.locator('#domain-geographic_scope_summary').fill('日本国内 / 対面とオンライン');
    await page.locator('#domain-market_position_summary').fill('外部の助言者ではなく、実装まで並走する経営パートナー');
    await page.locator('#domain-self_recognized_strengths').fill('経営とシステムの両方を理解し、日常業務へ落とし込めること');
    await page.locator('#domain-direction').selectOption('strengthen');
    await page.locator('#domain-direction-memo').fill('提供品質と再現性を高め、地域企業が自走できる支援へ深化する。');
    await page.locator('[data-add-item]').first().click();
    const item = page.locator('[data-item]').first();
    await item.locator('select[name$="[kind]"]').selectOption('service');
    await item.locator('input[name$="[name]"]').fill('Company OS伴走支援');
    await item.locator('textarea[name$="[description]"]').fill('https://example.test/' + 'very-long-segment-'.repeat(18));
    await item.locator('[data-add-attribute]').click();
    await item.locator('[data-attribute] select').selectOption('value');
    await item.locator('[data-attribute] input').fill('提供価値');
    await item.locator('[data-attribute] textarea').fill('気づく、残す、考える、実行する、学びを残す、という循環を支える。');
    await page.locator('#change-reason').fill('Company Context PresentationのBrowser確認用に内容を更新');
    await Promise.all([
        page.waitForURL(url => !url.pathname.endsWith('/edit')),
        page.getByRole('button', { name: '新しいRevisionとして保存' }).click(),
    ]);

    assert(page.url() === showUrl, 'Edit did not return to Read.');
    assert(await page.getByText('事業の輪郭', { exact: true }).isVisible(), 'Editorial outline is missing.');
    assert(await page.getByText('自社認識の強み', { exact: true }).isVisible(), 'Strength section is missing.');
    assert(await page.getByText('強化・深化', { exact: true }).isVisible(), 'Direction is missing.');
    assert(await page.getByText('Company OS伴走支援', { exact: true }).isVisible(), 'Item is missing.');
    assert(await page.locator('.company-context-read form').count() === 0, 'Read contains management form after save.');
    assert(await page.locator('.company-context-title h1').evaluate(element => parseFloat(getComputedStyle(element).fontSize)) >= 50, 'Desktop editorial title is too small.');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Desktop Read overflows.');

    let keyboardReachedContext = false;
    for (let index = 0; index < 30; index++) {
        await page.keyboard.press('Tab');
        keyboardReachedContext = await page.evaluate(() => Boolean(document.activeElement?.closest('.company-context-read')));
        if (keyboardReachedContext) break;
    }
    assert(keyboardReachedContext, 'Keyboard focus did not reach Company Context links.');
    assert(await page.evaluate(() => getComputedStyle(document.activeElement).outlineStyle !== 'none'), 'Focused Read link has no visible outline.');
    await page.screenshot({ path: desktopShot, fullPage: true });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(showUrl);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), '390px Read overflows.');
    assert(await page.locator('.company-context-axis-grid').evaluate(element => getComputedStyle(element).gridTemplateColumns.split(' ').length === 1), '390px outline is not one column.');
    await page.screenshot({ path: mobileShot, fullPage: true });

    await page.setViewportSize({ width: 1280, height: 1000 });
    await page.goto(showUrl);
    await page.getByRole('link', { name: '管理', exact: true }).click();
    assert(await page.getByText('Revision履歴', { exact: true }).isVisible(), 'Manage detail has no history.');
    assert(await page.locator('#state-reason').isVisible(), 'Manage detail has no state form.');
    await page.locator('#state-reason').fill('Browser Printで保管表示を確認');
    await Promise.all([
        page.waitForURL('**/company/business-domains/manage/*'),
        page.getByRole('button', { name: '保管する', exact: true }).click(),
    ]);
    await page.getByRole('link', { name: '読む画面へ', exact: true }).click();
    assert(await page.getByText('この事業領域は保管済みです。以下は保管時点の現在値です。').isVisible(), 'Archived Read notice is missing.');

    await page.emulateMedia({ media: 'print' });
    assert(await page.locator('.topbar').evaluate(element => getComputedStyle(element).display === 'none'), 'Navigation remains in print.');
    assert(await page.locator('.company-context-print-hidden').first().evaluate(element => getComputedStyle(element).display === 'none'), 'Read actions remain in print.');
    assert(await page.getByRole('heading', { name: '地域共創事業', exact: true }).isVisible(), 'Print hides the business title.');
    assert(await page.getByText('Company OS伴走支援', { exact: true }).isVisible(), 'Print hides business content.');
    assert(await page.getByText('この事業領域は保管済みです。以下は保管時点の現在値です。').isVisible(), 'Print hides archived notice.');
    assert(await page.getByText('Revision履歴').count() === 0, 'Print DOM contains hidden history.');
    await page.screenshot({ path: printShot, fullPage: true });
    await page.pdf({ path: printPdf, format: 'A4', printBackground: true, preferCSSPageSize: true });
    assert(fs.statSync(printPdf).size > 5000, 'Generated print PDF is unexpectedly small.');
    await page.emulateMedia({ media: 'screen' });

    await page.getByRole('link', { name: '管理', exact: true }).click();
    await page.locator('#state-reason').fill('Browser確認後に再開');
    await Promise.all([
        page.waitForURL('**/company/business-domains/manage/*'),
        page.getByRole('button', { name: '利用中へ戻す', exact: true }).click(),
    ]);
    const editorResponse = await page.goto(baseUrl + '/company/business-domains/editors');
    assert(editorResponse.status() === 200, 'Owner cannot open editor management.');
    const adminRow = page.locator('.editor-row').filter({ hasText: 'PUX-B Admin' });
    await adminRow.getByRole('button', { name: '解除', exact: true }).click();
    await page.locator('form[action$="/logout"] button').click();
    await login('pux-b-admin@example.test');
    response = await page.goto(showUrl);
    assert(response.status() === 200, 'Active non-editor cannot Read.');
    response = await page.goto(baseUrl + '/company/business-domains/manage');
    assert(response.status() === 403, 'Revoked editor can still Manage.');

    assert(http5xx.length === 0, 'Unexpected HTTP 5xx responses: ' + JSON.stringify(http5xx));
    console.log(JSON.stringify({
        status: 'passed',
        desktop: '1280x1000',
        mobile: '390x844',
        print: 'A4 PDF',
        pdfBytes: fs.statSync(printPdf).size,
        http5xx: http5xx.length,
        journeys: [
            'owner-login', 'read-index', 'read-detail', 'edit-save-read',
            'desktop-editorial', 'mobile-one-column', 'keyboard-focus',
            'manage-history', 'archive-read', 'browser-print', 'reopen',
            'editor-revoke', 'permission-negative',
        ],
    }));
} finally {
    await browser.close();
}
