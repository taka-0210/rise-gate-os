import { chromium } from 'playwright-core';

const [baseUrl, desktopShot, mobileShot] = process.argv.slice(2);
if (!baseUrl || !desktopShot || !mobileShot) {
    throw new Error('Usage: management-design-p1-acceptance.mjs <base-url> <desktop-shot> <mobile-shot>');
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
    await page.locator('#email').fill('mdc-p1-owner@example.test');
    await page.locator('#password').fill('not-used');
    await Promise.all([
        page.waitForURL('**/company'),
        page.locator('button[type="submit"]').click(),
    ]);

    await page.goto(`${baseUrl}/company`);
    assert(await page.getByRole('link', { name: /理念・Vision・方針/ }).isVisible(), 'Company Home does not expose the MDC entry.');
    await page.getByRole('link', { name: /理念・Vision・方針/ }).click();
    assert(await page.getByRole('heading', { name: /会社の言葉を/ }).isVisible(), 'MDC directory is unavailable.');
    for (const label of ['理念', 'Vision', '方針']) {
        assert(await page.getByRole('heading', { name: label, exact: true }).isVisible(), `${label} is absent from the fixed type directory.`);
    }
    assert(await page.locator('.mdc-directory img').count() === 0, 'P1 added an image despite the scope exclusion.');

    const create = async (type, statement, sections, horizon = null, statementExplanation = null) => {
        await page.goto(`${baseUrl}/company/management-design/${type}/edit`);
        await page.locator('#mdc-statement').fill(statement);
        if (statementExplanation !== null) await page.locator('#mdc-statement-explanation').fill(statementExplanation);
        if (horizon !== null) await page.locator('#mdc-horizon').fill(horizon);
        for (const section of sections) {
            await page.locator('[data-add-section]').click();
            const row = page.locator('[data-section]').last();
            await row.locator('input[name$="[title]"]').fill(section.title);
            await row.locator('textarea[name$="[body]"]').fill(section.body);
            if (section.explanation) await row.locator('textarea[name$="[explanation]"]').fill(section.explanation);
            if (section.horizon) await row.locator('input[name$="[horizon]"]').fill(section.horizon);
        }
        await Promise.all([
            page.waitForURL(`**/company/management-design/${type}`),
            page.getByRole('button', { name: '正本として保存' }).click(),
        ]);
        assert(await page.getByText(statement, { exact: true }).isVisible(), `${type} statement was not saved.`);
    };

    const longJapanese = '理念は日々の判断に立ち返る根です。'.repeat(80);
    await create('philosophy', longJapanese, [
        { title: '存在理由', body: '人と会社の可能性を、誠実な判断によってひらきます。', explanation: 'この言葉を日々の判断へつなげるためのSection説明です。' },
        { title: '大切にする姿勢', body: '短期の便利さより、長期の信頼を選びます。' },
    ], null, '抽象度の高い理念に込めた会社固有の意味を説明します。');
    assert(await page.locator('.mdc-read--philosophy').isVisible(), 'ROOT composition is missing.');
    assert(await page.getByText('抽象度の高い理念に込めた会社固有の意味を説明します。', { exact: true }).isVisible(), 'Statement explanation is missing.');
    assert(await page.getByText('この言葉を日々の判断へつなげるためのSection説明です。', { exact: true }).isVisible(), 'Section explanation is missing.');
    assert(await page.locator('.mdc-hero--long').isVisible(), 'Long statement presentation was not selected.');
    assert(await page.locator('.mdc-hero__statement').evaluate(element => parseInt(getComputedStyle(element).fontWeight, 10) >= 700), 'Philosophy statement is not visually strong enough.');
    assert(await page.locator('.mdc-section__body').first().evaluate(element => parseFloat(getComputedStyle(element).fontSize) >= 20), 'Section body remains too small on desktop.');
    assert(await page.locator('.mdc-hero__statement').evaluate(element => parseFloat(getComputedStyle(element).fontSize) <= 26), 'Long statement remains oversized on desktop.');
    assert(await page.locator('.mdc-hero__statement').evaluate(element => parseFloat(getComputedStyle(element).lineHeight) >= 45), 'Long statement line height is not readable.');
    await page.screenshot({ path: desktopShot, fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    assert(await page.locator('.mdc-hero__statement').evaluate(element => parseFloat(getComputedStyle(element).fontSize) <= 18), 'Long statement remains oversized at 390px.');
    assert(await page.locator('.mdc-section__body').first().evaluate(element => parseFloat(getComputedStyle(element).fontSize) >= 18), 'Section body remains too small at 390px.');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), 'Long statement overflows at 390px.');
    await page.setViewportSize({ width: 1440, height: 1000 });

    await create('vision', '私たちは、対話から未来を共につくります。', [
        { title: '顧客との未来', body: '問いとEvidenceを共有し、次の一歩を選べる状態をつくります。', horizon: '中長期' },
    ], '2030年代を見据えた方向');
    assert(await page.locator('.mdc-read--vision').isVisible(), 'FUTURE composition is missing.');
    assert(await page.getByText(/2030年代を見据えた方向/).isVisible(), 'Vision horizon is missing.');

    await create('policy', '判断に迷ったときは、事実と対話を優先します。', [
        { title: '判断の順序', body: '安全、顧客価値、持続性の順に確認します。' },
    ]);
    assert(await page.locator('.mdc-read--policy').isVisible(), 'DIRECTION composition is missing.');

    await page.goto(`${baseUrl}/company/management-design/philosophy/history`);
    assert(await page.getByText('Revision 1', { exact: false }).isVisible(), 'Immutable Revision 1 is absent.');
    const revisionResponse = await page.locator('a[href*="/revisions/"]').first().click();
    assert(await page.getByText('存在理由', { exact: true }).isVisible(), 'Revision snapshot is unreadable.');
    assert(await page.getByText('抽象度の高い理念に込めた会社固有の意味を説明します。', { exact: true }).isVisible(), 'Statement explanation is missing from immutable history.');
    assert(await page.getByText('この言葉を日々の判断へつなげるためのSection説明です。', { exact: true }).isVisible(), 'Section explanation is missing from immutable history.');

    const stalePage = await context.newPage();
    await page.goto(`${baseUrl}/company/management-design/philosophy/edit`);
    await stalePage.goto(`${baseUrl}/company/management-design/philosophy/edit`);
    await page.locator('#mdc-statement').fill('先に保存された理念');
    await Promise.all([
        page.waitForURL('**/company/management-design/philosophy'),
        page.getByRole('button', { name: '正本として保存' }).click(),
    ]);
    await stalePage.locator('#mdc-statement').fill('古い画面からの理念');
    await stalePage.getByRole('button', { name: '正本として保存' }).click();
    assert(await stalePage.getByRole('alert').isVisible(), 'Stale update did not fail closed.');
    assert(await stalePage.getByText(/別の変更/).isVisible(), 'Stale conflict guidance is missing.');
    await stalePage.close();

    await page.goto(`${baseUrl}/company/management-design/policy/history`);
    await page.locator('#state-reason').fill('Browser archive verification');
    await page.getByRole('button', { name: '保管する' }).click();
    await page.getByRole('button', { name: '再開する' }).waitFor();
    assert(await page.getByRole('button', { name: '再開する' }).isVisible(), 'Archived state is not reflected.');
    await page.locator('#state-reason').fill('Browser reopen verification');
    await page.getByRole('button', { name: '再開する' }).click();
    await page.getByRole('button', { name: '保管する' }).waitFor();
    assert(await page.getByRole('button', { name: '保管する' }).isVisible(), 'Reopened state is not reflected.');

    await page.setViewportSize({ width: 3200, height: 1000 });
    await page.goto(`${baseUrl}/company/management-design/philosophy`);
    const widePhilosophy = await page.evaluate(() => {
        const hero = document.querySelector('.mdc-read--philosophy .mdc-hero').getBoundingClientRect();
        const heading = document.querySelector('.mdc-read--philosophy .mdc-hero h1').getBoundingClientRect();
        const statement = document.querySelector('.mdc-read--philosophy .mdc-hero__statement').getBoundingClientRect();

        return {
            heroCenter: hero.left + (hero.width / 2),
            headingHeight: heading.height,
            statementCenter: statement.left + (statement.width / 2),
            statementWidth: statement.width,
        };
    });
    assert(widePhilosophy.headingHeight < 150, 'Philosophy heading collapsed vertically at a zoomed-out equivalent viewport.');
    assert(widePhilosophy.statementWidth >= 700, 'Philosophy statement collapsed into a narrow column at a zoomed-out equivalent viewport.');
    assert(Math.abs(widePhilosophy.statementCenter - widePhilosophy.heroCenter) <= 2, 'Philosophy statement drifted away from center at a zoomed-out equivalent viewport.');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), 'Zoomed-out equivalent ROOT view overflows horizontally.');

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${baseUrl}/company/management-design/philosophy`);
    assert(await page.locator('.mdc-read--philosophy').isVisible(), '390px ROOT view is unavailable.');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), '390px read view overflows horizontally.');
    await page.screenshot({ path: mobileShot, fullPage: true });

    await page.goto(`${baseUrl}/company/management-design/vision/edit`);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), '390px edit view overflows horizontally.');
    await page.locator('[data-add-section]').focus();
    assert(await page.locator('[data-add-section]').evaluate(element => document.activeElement === element), 'Keyboard focus cannot reach Add Section.');
    assert(http5xx.length === 0, `Unexpected HTTP 5xx responses: ${JSON.stringify(http5xx)}`);
    assert(externalRequests.length === 0, `Unexpected external requests: ${JSON.stringify(externalRequests)}`);

    console.log(JSON.stringify({
        status: 'passed',
        desktop: '1440x1000',
        mobile: '390x844',
        http5xx: http5xx.length,
        externalRequests: externalRequests.length,
        journeys: [
            'login', 'company-entry', 'fixed-types', 'philosophy-root', 'vision-future',
            'policy-direction', 'official-explanations', 'long-japanese', 'continuous-sections', 'history-revision',
            'stale-fail-closed', 'archive-reopen', 'mobile-390', 'keyboard-focus',
        ],
    }));
} finally {
    await browser.close();
}
