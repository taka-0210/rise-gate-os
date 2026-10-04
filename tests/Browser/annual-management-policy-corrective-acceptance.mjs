import { chromium } from 'playwright-core';

const [baseUrl, publicId, desktopShot, mobileShot] = process.argv.slice(2);
if (!baseUrl || !publicId || !desktopShot || !mobileShot) {
    throw new Error('Usage: annual-management-policy-corrective-acceptance.mjs <base-url> <public-id> <desktop-shot> <mobile-shot>');
}

const browser = await chromium.launch({
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    headless: true,
});
const assert = (condition, message) => {
    if (!condition) throw new Error(message);
};

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

    await page.goto(`${baseUrl}/login`);
    await page.locator('#email').fill('35a-corrective-owner@example.test');
    await page.locator('#password').fill('not-used');
    await Promise.all([
        page.waitForURL('**/company'),
        page.locator('button[type="submit"]').click(),
    ]);

    const layoutMetrics = [];
    const verifyLayout = async (path, label, mobile = false) => {
        await page.goto(`${baseUrl}${path}`);
        assert(page.url().startsWith(baseUrl), `${label} escaped the isolated server.`);
        const result = await page.evaluate(() => {
            const breadcrumbs = document.querySelector('.breadcrumbs');
            const main = document.querySelector('.main');
            const content = main?.querySelector(':scope > :not(style):not(script)');
            const shell = document.querySelector('.shell');
            const topbar = document.querySelector('.topbar');
            if (!breadcrumbs || !main || !content || !shell || !topbar) return null;
            const breadcrumbBox = breadcrumbs.getBoundingClientRect();
            const contentBox = content.getBoundingClientRect();
            return {
                contentGap: Math.round(contentBox.top - breadcrumbBox.bottom),
                overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                shellDisplay: getComputedStyle(shell).display,
                shellAreas: getComputedStyle(shell).gridTemplateAreas,
                topbarBottom: Math.round(topbar.getBoundingClientRect().bottom),
                contentTop: Math.round(contentBox.top),
            };
        });
        assert(result !== null, `${label} is missing the shared Application Shell contract.`);
        assert(result.shellDisplay === 'grid', `${label} does not use the shared grid shell.`);
        assert(result.shellAreas.includes('header') && result.shellAreas.includes('breadcrumbs') && result.shellAreas.includes('main'), `${label} grid areas are incomplete.`);
        assert(result.contentGap >= 0 && result.contentGap <= (mobile ? 36 : 48), `${label} has abnormal header-to-content spacing: ${JSON.stringify(result)}.`);
        assert(result.overflow <= 0, `${label} overflows horizontally by ${result.overflow}px.`);
        layoutMetrics.push({ label, viewport: mobile ? '390x844' : '1440x1000', ...result });
    };

    for (const [path, label] of [
        ['/company', 'Company Home'],
        ['/company/captures/create', 'Quick Capture'],
        ['/company/captures', 'Capture Inbox'],
        ['/company/annual-management-policy', 'Annual Policy Directory'],
        [`/company/annual-management-policy/${publicId}`, 'Annual Policy Reader'],
        [`/company/annual-management-policy/${publicId}/edit`, 'Annual Policy Editor'],
    ]) {
        await verifyLayout(path, label);
    }

    await page.goto(`${baseUrl}/company/annual-management-policy`);
    assert(await page.getByText('第23期｜2026年度', { exact: true }).first().isVisible(), 'Fiscal term and year are not presented independently.');
    assert(await page.getByText('計画中｜承認済み・開始前', { exact: true }).isVisible(), 'Approved future policy is not shown with planning terminology.');

    await page.goto(`${baseUrl}/company/annual-management-policy/${publicId}`);
    assert(await page.locator('.amp-reader').isVisible(), 'Reader-first article is missing.');
    assert(await page.getByText('計画中｜承認済み・開始前', { exact: true }).first().isVisible(), 'Standalone Reader planning terminology is inconsistent.');
    assert(await page.getByText('承認済みの、次期の方針です。', { exact: true }).isVisible(), 'Standalone Reader planning explanation is missing.');
    assert(await page.getByText('2026/12/01から適用されます。現在は、この方針をもとに次期の計画を検討するための表示です。', { exact: true }).isVisible(), 'Standalone Reader start-date explanation is missing.');
    assert(await page.locator('.amp-management-tools').isVisible(), 'Management tools are missing.');
    const readerOrder = await page.evaluate(() => {
        const reader = document.querySelector('.amp-reader');
        const management = document.querySelector('.amp-management-tools');
        return Boolean(reader && management && (reader.compareDocumentPosition(management) & Node.DOCUMENT_POSITION_FOLLOWING));
    });
    assert(readerOrder, 'Management tools appear before the reader.');
    assert(await page.getByText('強みを仕組みに変え、全員が同じ方向を向いて動ける会社をつくる。', { exact: true }).isVisible(), 'Approved policy is absent from the reader.');
    assert(await page.getByText('第23期｜2026年度', { exact: true }).first().isVisible(), 'Reader period context is incomplete.');
    await page.screenshot({ path: desktopShot, fullPage: true });

    await page.goto(`${baseUrl}/company/annual-management-policy/${publicId}/permissions`);
    assert(await page.getByText('1. 正式版を誰に共有するか', { exact: true }).isVisible(), 'Official sharing language is missing.');
    assert(await page.getByText('2. 方針づくりの担当者', { exact: true }).isVisible(), 'Policy-team language is missing.');

    await page.goto(`${baseUrl}/company/annual-management-policy/${publicId}/edit`);
    for (const label of [
        '今期、何を実現したいのか（任意）',
        'この方針を定める背景（任意）',
        '年度経営方針（承認時は必須）',
        '重点テーマ名',
        '対象部署',
    ]) {
        assert(await page.getByText(label, { exact: true }).first().isVisible(), `Human-language label is missing: ${label}`);
    }
    const themes = page.locator('[data-theme]');
    assert(await themes.count() === 2, 'The reorder fixture does not contain two themes.');
    assert(await themes.nth(0).locator(':scope > .field textarea').first().inputValue() === '顧客価値を高める', 'Initial theme order is unexpected.');
    await themes.nth(1).locator(':scope > .amp-order-actions [data-move-up]').click();
    assert(await themes.nth(0).locator(':scope > .field textarea').first().inputValue() === '組織基盤を整える', 'Theme move-up did not reorder the DOM.');
    assert(await themes.nth(0).locator(':scope > input[type="hidden"]').getAttribute('name') === 'themes[0][public_id]', 'Theme names were not reindexed after reorder.');
    await Promise.all([
        page.waitForURL(`**/company/annual-management-policy/${publicId}`),
        page.locator('[data-amp-form] .amp-form-actions button[type="submit"]').click(),
    ]);
    await page.goto(`${baseUrl}/company/annual-management-policy/${publicId}/edit`);
    assert(await page.locator('[data-theme]').nth(0).locator(':scope > .field textarea').first().inputValue() === '組織基盤を整える', 'Theme order was not persisted.');
    await page.goto(`${baseUrl}/company/annual-management-policy/${publicId}`);
    const approvedThemeOrder = await page.locator('.amp-reader .amp-section > h2.amp-prose').allTextContents();
    assert(approvedThemeOrder[0] === '顧客価値を高める', 'Draft reorder mutated the approved immutable revision.');

    await page.setViewportSize({ width: 390, height: 844 });
    for (const [path, label] of [
        ['/company', 'Company Home mobile'],
        ['/company/captures/create', 'Quick Capture mobile'],
        ['/company/captures', 'Capture Inbox mobile'],
        [`/company/annual-management-policy/${publicId}`, 'Annual Policy Reader mobile'],
        [`/company/annual-management-policy/${publicId}/edit`, 'Annual Policy Editor mobile'],
        [`/company/annual-management-policy/${publicId}/permissions`, 'Annual Policy Permissions mobile'],
    ]) {
        await verifyLayout(path, label, true);
    }
    await page.goto(`${baseUrl}/company/annual-management-policy/${publicId}`);
    assert(await page.locator('.amp-reader').isVisible(), '390px reader is unavailable.');
    await page.screenshot({ path: mobileShot, fullPage: true });

    assert(http5xx.length === 0, `Unexpected HTTP 5xx responses: ${JSON.stringify(http5xx)}`);
    assert(externalRequests.length === 0, `Unexpected external requests: ${JSON.stringify(externalRequests)}`);
    console.log(JSON.stringify({
        status: 'passed',
        desktop: '1440x1000',
        mobile: '390x844',
        timezone: 'Asia/Tokyo',
        http5xx: http5xx.length,
        externalRequests: externalRequests.length,
        layoutMetrics,
        journeys: [
            'shared-shell-cross-page',
            'fiscal-term-context',
            'approved-upcoming-lifecycle',
            'reader-first',
            'permission-language',
            'editor-language',
            'stable-reorder',
            'approved-revision-immutability',
        ],
    }));
} finally {
    await browser.close();
}
