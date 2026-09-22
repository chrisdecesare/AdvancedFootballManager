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

// Ogni tanto il telefono rinnova da solo l'abbonamento alle notifiche: senza questo il server resterebbe con
// l'indirizzo vecchio e il dispositivo smetterebbe di ricevere. Qui si rifà l'abbonamento e si avvisa il sito,
// dimostrando di conoscere il vecchio indirizzo (è la prova d'identità: push.php non ha una sessione da usare).
self.addEventListener('pushsubscriptionchange', event => {
  event.waitUntil((async () => {
    const old = event.oldSubscription || await self.registration.pushManager.getSubscription().catch(() => null);
    let key = old && old.options && old.options.applicationServerKey;
    if (!key) {
      key = await fetch('push.php?do=key').then(r => r.json()).then(d => d.key).catch(() => null);
      if (!key) return;
      key = Uint8Array.from(atob((key + '='.repeat((4 - key.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
    }
    const sub = event.newSubscription
      || await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }).catch(() => null);
    if (!sub || !old) return;
    const j = sub.toJSON();
    await fetch('push.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ do: 'renew', old: old.endpoint, endpoint: j.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth }),
    }).catch(() => { /* al prossimo avvio del sito ci pensa push.js */ });
  })());
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
