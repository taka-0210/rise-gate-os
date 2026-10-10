import { chromium } from 'playwright-core';
import fs from 'node:fs';

const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
try {
    for (const width of [1440, 1024, 768, 390]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        await page.route('**/*', route => route.abort());
        await page.setContent(fs.readFileSync('prototypes/co-meeting-human-review/index.html', 'utf8'));
        for (const department of ['営業部', '商品管理部', '総務部']) {
            await page.locator('#department').selectOption(department);
            if (await page.locator('#prompts li').count() !== 8) throw new Error('Scenario count mismatch');
        }
        if (await page.locator('#criteria select').count() !== 8) throw new Error('Criteria count mismatch');
        if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)) throw new Error('Horizontal overflow');
        await page.close();
    }
    console.log('PASS: 4 viewports, 3 departments, 8 prompts and 8 criteria; offline, no provider requests');
} finally {
    await browser.close();
}
