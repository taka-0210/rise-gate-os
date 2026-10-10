import { chromium } from 'playwright-core';
import fs from 'node:fs';
import path from 'node:path';

const directory = process.argv[2];
if (!directory || !path.basename(directory).startsWith('company-os-co-meeting-')) throw new Error('Expected isolated synthetic render directory');
const browser = await chromium.launch({ executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', headless: true });
const results = [];
try {
    for (const screen of ['policy', 'meeting']) {
        for (const width of [1440, 1024, 768, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            // Render actual Blade responses offline; no server, provider, audio or secret access.
            await page.route('**/*', route => route.abort());
            const html = fs.readFileSync(path.join(directory, screen + '.html'), 'utf8')
                .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
            await page.setContent(html);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
            if (overflow) throw new Error(`${screen} horizontal overflow at ${width}`);
            const button = screen === 'policy'
                ? page.getByRole('button', { name: '文書許可を保存' })
                : page.getByRole('button', { name: 'COに論点整理を相談する（テキスト）' });
            if (!await button.isVisible()) throw new Error(`${screen} button missing at ${width}`);
            if (screen === 'meeting') {
                const selected = page.locator('input[name="source_ids[]"]');
                if (!await selected.isChecked()) throw new Error('Selected policy not retained for the next request');
                if (await page.getByText('Revision 1', { exact: false }).count() === 0) throw new Error('Revision missing');
            }
            await page.screenshot({ path: path.join(directory, `${screen}-${width}.png`), fullPage: true });
            results.push({ screen, width, horizontalOverflow: false, formVisible: true });
            await page.close();
        }
    }
    fs.writeFileSync(path.join(directory, 'browser-result.json'), JSON.stringify({ mode: 'offline_actual_blade_synthetic_fixture', results, productionConnections: 0, providerCalls: 0 }, null, 2));
    console.log(JSON.stringify({ checks: results.length, result: 'PASS', mode: 'offline_actual_blade_synthetic_fixture' }));
} finally { await browser.close(); }
