import { chromium } from 'playwright-core';
import fs from 'node:fs';

const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
try {
    const page = await browser.newPage();
    let allow = true;
    let requests = 0;
    await page.route('http://review.test/**', route => {
        requests++;
        return route.fulfill({ status: allow ? 200 : 403, contentType: 'application/json', body: JSON.stringify({ allowed: allow, content_hash: 'fixture' }) });
    });
    await page.goto('http://review.test/');
    await page.setContent('<p>株式会社の方針。\n・論点を整理する。' + '長文'.repeat(100) + '</p><div data-co-voice-reply data-access-url="http://review.test/access" data-content-hash="fixture"><button data-voice-start>start</button><button data-voice-stop>stop</button><button data-voice-replay>replay</button><span data-voice-status></span></div>');
    await page.evaluate(() => {
        window.spoken = []; window.cancelCount = 0;
        window.mockVoices = [{ lang: 'ja-JP', localService: false }, { lang: 'en-US', localService: true }, { lang: 'ja-JP', localService: true }];
        Object.defineProperty(window, 'speechSynthesis', { value: {
            getVoices: () => window.mockVoices, addEventListener() {},
            cancel: () => { window.cancelCount++; },
            speak: u => { window.spoken.push({ text: u.text, local: u.voice.localService, lang: u.lang }); window.lastUtterance = u; }
        } });
        window.SpeechSynthesisUtterance = class { constructor(text) { this.text = text; } };
    });
    await page.addScriptTag({ content: fs.readFileSync('public/js/co-voice-reply.js', 'utf8') });
    if (await page.evaluate(() => window.spoken.length) !== 0) throw Error('Autoplay');
    await page.locator('[data-voice-start]').dblclick();
    await page.waitForFunction(() => window.spoken.length === 1);
    if (!await page.evaluate(() => window.spoken.every(s => s.local && s.lang === 'ja-JP' && Array.from(s.text).length <= 80))) throw Error('Voice selection/chunk');
    allow = false;
    await page.waitForFunction(() => document.querySelector('[data-voice-status]').textContent.includes('停止しました'), { timeout: 6000 });
    allow = true;
    await page.locator('[data-voice-replay]').click();
    await page.waitForFunction(() => window.spoken.length === 2);
    await page.evaluate(() => window.lastUtterance.onend());
    await page.waitForFunction(() => window.spoken.length === 3);
    await page.locator('[data-voice-stop]').click();
    await page.locator('[data-voice-replay]').click();
    await page.waitForFunction(() => window.spoken.length === 4);
    await page.evaluate(() => window.dispatchEvent(new Event('offline')));
    if (!await page.locator('[data-voice-status]').textContent().then(t => t.includes('停止しました'))) throw Error('Offline stop');
    await page.locator('[data-voice-replay]').click();
    await page.waitForFunction(() => window.spoken.length === 5);
    await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, value: true }); document.dispatchEvent(new Event('visibilitychange')); });
    if (!await page.locator('[data-voice-stop]').isDisabled()) throw Error('Hidden stop');
    await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, value: false }); window.mockVoices = [{ lang: 'ja-JP', localService: false }]; });
    await page.locator('[data-voice-start]').click();
    if (!await page.locator('[data-voice-start]').isDisabled()) throw Error('Remote fallback');
    await page.evaluate(() => { window.mockVoices = [{ lang: 'ja-JP', localService: true }]; });
    await page.locator('[data-voice-replay]').evaluate(button => { button.disabled = false; button.click(); });
    await page.waitForFunction(() => window.spoken.length === 6);
    await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
    if (!await page.locator('[data-voice-stop]').isDisabled()) throw Error('Pagehide stop');
    await page.unroute('http://review.test/**');
    await page.route('http://review.test/**', route => route.abort());
    await page.locator('[data-voice-replay]').click();
    await page.waitForFunction(() => document.querySelector('[data-voice-status]').textContent.includes('確認できない'));
    if (await page.evaluate(() => window.spoken.length) !== 6) throw Error('Playback on failed authorization');
    console.log(JSON.stringify({ result: 'PASS', mode: 'mock_speech_offline', requests, checks: ['no autoplay', 'local Japanese only', 'double tap', '80-character chunks', 'revocation', 'replay', 'stop', 'offline', 'hidden', 'no remote fallback'], realAudioQuality: 'HUMAN PENDING' }));
} finally { await browser.close(); }
