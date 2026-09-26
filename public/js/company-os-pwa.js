(() => {
  if (!('serviceWorker' in navigator)) return;
  const base = document.querySelector('meta[name="company-os-base"]')?.content || '';
  const registration = navigator.serviceWorker.register(base + '/service-worker.js');
  const button = document.querySelector('[data-enable-push]');
  if (!button) return;
  const decodeKey = value => {
    const padding = '='.repeat((4 - value.length % 4) % 4);
    const raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from([...raw].map(character => character.charCodeAt(0)));
  };
  button.addEventListener('click', async () => {
    const status = document.querySelector('[data-push-status]');
    try {
      const vapid = document.querySelector('meta[name="company-os-vapid-public-key"]')?.content;
      if (!vapid) throw new Error('push_not_configured');
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        status.textContent = 'Push通知は許可されませんでした。';
        return;
      }
      const worker = await registration;
      const subscription = await worker.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: decodeKey(vapid)});
      const response = await fetch(base + '/company/push-subscriptions', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
        credentials: 'same-origin',
        body: JSON.stringify(subscription.toJSON())
      });
      if (!response.ok) throw new Error('subscription_rejected');
      status.textContent = 'この端末のPush通知を有効にしました。';
    } catch (_) { status.textContent = 'この端末ではPush通知を利用できません。'; }
  });
})();
