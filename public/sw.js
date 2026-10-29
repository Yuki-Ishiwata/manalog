self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (_) { data = {}; }
  const target = new URL(data.url || './', self.registration.scope);
  if (target.origin !== self.location.origin || !target.href.startsWith(self.registration.scope)) target.href = self.registration.scope;
  event.waitUntil((async () => {
    await self.registration.showNotification(data.title || 'まなログ', {
      body: data.body || 'タップして、今日の振り返りを始めよう。',
      icon: 'assets/icon-192.png',
      badge: 'assets/icon-192.png',
      tag: data.tag || 'manalog-review',
      data: { url: target.href },
    });
    if ('setAppBadge' in self.navigator) await self.navigator.setAppBadge(1).catch(() => {});
  })());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL(event.notification.data?.url || './', self.registration.scope);
  if (target.origin !== self.location.origin || !target.href.startsWith(self.registration.scope)) target.href = self.registration.scope;
  event.waitUntil((async () => {
    if ('clearAppBadge' in self.navigator) await self.navigator.clearAppBadge().catch(() => {});
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of windows) {
      if (client.url.startsWith(self.registration.scope)) {
        await client.navigate(target.href);
        return client.focus();
      }
    }
    return self.clients.openWindow(target.href);
  })());
});
