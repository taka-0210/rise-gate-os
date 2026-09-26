const CACHE_NAME = 'company-os-shell-v2';
const SAFE_ASSETS = ['./favicon.png', './manifest.webmanifest'];
self.addEventListener('install', event => event.waitUntil(
  caches.open(CACHE_NAME).then(cache => cache.addAll(SAFE_ASSETS)).then(() => self.skipWaiting())
));
self.addEventListener('activate', event => event.waitUntil(
  caches.keys()
    .then(keys => Promise.all(keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))))
    .then(() => self.clients.claim())
));
self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET' || event.request.mode === 'navigate') return;
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin || !SAFE_ASSETS.some(asset => url.pathname.endsWith(asset.replace('./','/')))) return;
  event.respondWith(caches.match(event.request).then(cached => cached || fetch(event.request)));
});
self.addEventListener('push', event => {
  const data = event.data ? event.data.json() : {};
  event.waitUntil(self.registration.showNotification(data.title || 'Company OSに新しい通知があります', {
    body: 'Company OSを開いて内容を確認してください。', icon: './favicon.png', data: {url: data.url || './company/notifications'}
  }));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil((async () => {
    const fallback = new URL('./company/notifications', self.location.href);
    const requested = new URL(event.notification.data.url || fallback.href, self.location.href);
    const target = requested.origin === self.location.origin ? requested.href : fallback.href;
    const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    const existing = windows.find(client => new URL(client.url).origin === self.location.origin);
    if (existing) {
      await existing.navigate(target);
      return existing.focus();
    }
    return clients.openWindow(target);
  })());
});
