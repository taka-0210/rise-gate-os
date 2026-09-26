import { chromium } from 'playwright-core';

const [baseUrl] = process.argv.slice(2);
if (!baseUrl) throw new Error('usage: node scope10-close-verification.mjs <baseUrl>');
const assert = (value, message) => { if (!value) throw new Error(message); };
const login = async (page, email, expected = '**/company') => {
  await page.locator('#email').fill(email);
  await page.locator('#password').fill('not-used');
  await Promise.all([page.waitForURL(expected), page.locator('button[type=submit]').click()]);
};

const browser = await chromium.launch({
  executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  headless: true,
});
const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
const page = await context.newPage();

try {
  await page.goto(baseUrl + '/login');
  await login(page, 'scope10-owner@example.test');
  await page.goto(baseUrl + '/company/projects/1/manage');
  const create = page.locator('form').filter({ has: page.locator('[name=done_condition]') }).first();
  await create.locator('[name=title]').fill('Expired login recovery');
  await create.locator('[name=done_condition]').fill('Return to the correct Action');
  await create.locator('[name=assigned_to]').selectOption('2');
  await Promise.all([page.waitForLoadState('networkidle'), create.locator('button').click()]);

  await context.clearCookies();
  await page.goto(baseUrl + '/login');
  await login(page, 'scope10-assignee@example.test');
  await page.goto(baseUrl + '/company/notifications');
  const deepLink = await page.locator('article.card a.button').first().getAttribute('href');
  assert(deepLink, 'notification deep link missing');

  await context.clearCookies();
  await page.goto(new URL(deepLink, baseUrl).href);
  await page.waitForURL('**/login');
  await login(page, 'scope10-assignee@example.test', '**/company/projects/1**');
  assert(page.url().includes('/company/projects/1'), 'expired login did not return to the intended deep link');

  await page.goto(baseUrl + '/company/notifications');
  const sw = await page.evaluate(async () => {
    const registration = await navigator.serviceWorker.ready;
    const cacheNames = await caches.keys();
    const entries = [];
    for (const name of cacheNames) {
      const cache = await caches.open(name);
      entries.push(...(await cache.keys()).map(request => request.url));
    }
    const databases = indexedDB.databases ? await indexedDB.databases() : [];
    return {
      scope: registration.scope,
      controller: Boolean(navigator.serviceWorker.controller),
      cacheNames,
      entries,
      databases: databases.map(database => database.name),
    };
  });
  assert(sw.controller, 'service worker is not controlling the page');
  assert(sw.entries.every(url => url.endsWith('/favicon.png') || url.endsWith('/manifest.webmanifest')), 'business data entered Cache Storage');
  assert(sw.databases.length === 0, 'business data entered IndexedDB');

  const field = page.locator('input[name=quiet_starts_at]');
  await field.fill('21:15');
  const urlBeforeUpdate = page.url();
  await page.evaluate(async () => (await navigator.serviceWorker.ready).update());
  await page.waitForTimeout(500);
  assert(await field.inputValue() === '21:15', 'service worker update discarded an in-progress form');
  assert(page.url() === urlBeforeUpdate, 'service worker update forced navigation');

  await context.setOffline(true);
  const offlineNavigationFailed = await page.goto(baseUrl + '/company/notifications', { waitUntil: 'domcontentloaded', timeout: 5000 })
    .then(() => false)
    .catch(() => true);
  assert(offlineNavigationFailed, 'business navigation was unexpectedly served while offline');
  await context.setOffline(false);
  const restored = await page.goto(baseUrl + '/company/notifications');
  assert(restored?.ok(), 'network recovery did not restore the notification center');

  const storageAfterRecovery = await page.evaluate(async () => {
    const entries = [];
    for (const name of await caches.keys()) {
      const cache = await caches.open(name);
      entries.push(...(await cache.keys()).map(request => request.url));
    }
    const databases = indexedDB.databases ? await indexedDB.databases() : [];
    return { entries, databases: databases.map(database => database.name) };
  });
  assert(storageAfterRecovery.entries.every(url => url.endsWith('/favicon.png') || url.endsWith('/manifest.webmanifest')), 'offline/recovery cached business data');
  assert(storageAfterRecovery.databases.length === 0, 'offline/recovery stored business data in IndexedDB');

  await Promise.all([
    page.waitForURL(baseUrl + '/'),
    page.getByRole('button', { name: 'Logout' }).click(),
  ]);
  const protectedResponse = await context.request.get(baseUrl + '/company/notifications', { maxRedirects: 0 });
  assert(protectedResponse.status() === 302, 'logout left the notification center accessible');
  assert((protectedResponse.headers().location || '').includes('/login'), 'logout did not protect the notification center');

  console.log(JSON.stringify({
    expiredLoginDeepLink: true,
    serviceWorkerControlled: true,
    cacheStorageBusinessData: false,
    indexedDbBusinessData: false,
    updatePreservedForm: true,
    offlineNavigationQueued: false,
    networkRecovery: true,
    logoutProtected: true,
    sw,
  }));
} finally {
  await context.close();
  await browser.close();
}
