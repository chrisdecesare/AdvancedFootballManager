/* Service worker di Calcetto Manager: riceve le notifiche push e apre il sito quando ci si tocca sopra. */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
  const at = path => new URL(path, self.registration.scope).href;
  event.waitUntil(self.registration.showNotification(data.title || 'Calcetto Manager', {
    body: data.body || '',
    icon: at('assets/icons/icon-192.png'),     // il logo del sito (come nell'intestazione)
    badge: at('assets/icons/badge-96.png'),    // versione monocromatica per la barra di stato di Android
    tag: data.tag || undefined,
    renotify: !!data.tag,
    data: { url: data.url || 'index.php' },
  }));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const target = new URL((event.notification.data && event.notification.data.url) || 'index.php', self.registration.scope).href;
  event.waitUntil((async () => {
    const open = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of open) {
      if (c.url.startsWith(self.registration.scope) && 'focus' in c) {
        await c.focus();
        if ('navigate' in c) { try { await c.navigate(target); } catch (e) { /* resta dov'è */ } }
        return;
      }
    }
    if (self.clients.openWindow) await self.clients.openWindow(target);
  })());
});
