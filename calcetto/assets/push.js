// Notifiche push: attivazione e disattivazione su questo dispositivo (il server è push.php, il service worker è sw.js).
document.addEventListener('DOMContentLoaded', () => {
  const keyMeta = document.querySelector('meta[name="push-key"]');
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const uid = (document.querySelector('meta[name="push-user"]') || {}).content || '0';
  if (!keyMeta || !csrfMeta) return;

  const cards = [...document.querySelectorAll('[data-push-card]')];
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  const supported = window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  const store = {
    get: k => { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* memoria del browser non disponibile: pazienza */ } },
    del: k => { try { localStorage.removeItem(k); } catch (e) { /* idem */ } },
  };

  const post = data => fetch('push.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(Object.assign({ csrf: csrfMeta.content }, data)),
  }).then(r => r.json().catch(() => ({ ok: false, error: 'Risposta non valida dal server.' })));

  const keyBytes = s => Uint8Array.from(atob((s + '='.repeat((4 - s.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));

  const currentSub = async () => {
    const reg = await navigator.serviceWorker.getRegistration();
    return reg ? reg.pushManager.getSubscription() : null;
  };

  // manda al server l'abbonamento di questo dispositivo (associandolo all'account con cui si è entrati adesso)
  const sendSub = sub => {
    const j = sub.toJSON();
    return post({ do: 'subscribe', endpoint: j.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth });
  };

  const bells = [...document.querySelectorAll('[data-push-bell]')];
  const setText = (card, sel, text) => { const el = card.querySelector(sel); if (el) el.textContent = text; };
  const show = (card, sel, on) => { const el = card.querySelector(sel); if (el) el.hidden = !on; };

  const render = async () => {
    let state, sub = null;
    if (!supported) state = ios && !standalone ? 'ios-install' : 'unsupported';
    else if (Notification.permission === 'denied') state = 'denied';
    else {
      sub = Notification.permission === 'granted' ? await currentSub().catch(() => null) : null;
      state = sub ? 'on' : 'off';
    }
    const dismissed = store.get('push-dismissed') === '1';
    bells.forEach(bell => {
      const icon = bell.querySelector('.ti');
      const label = state === 'on' ? 'Notifiche attive: tocca per disattivarle' : state === 'off' ? 'Notifiche disattivate: tocca per attivarle' : 'Notifiche';
      if (icon) icon.className = 'ti ' + (state === 'on' ? 'ti-bell-ringing' : state === 'off' ? 'ti-bell-off' : 'ti-bell');
      bell.dataset.state = state;
      bell.title = label;
      bell.setAttribute('aria-label', label);
    });
    cards.forEach(card => {
      const banner = card.hasAttribute('data-push-banner');
      const toggle = card.querySelector('[data-push-toggle]');
      card.hidden = banner ? !(state === 'off' && !dismissed) : false;
      if (banner) return;
      const msg = {
        'ios-install': 'Su iPhone e iPad le notifiche funzionano solo se aggiungi prima il sito alla schermata Home: in Safari tocca «Condividi», poi «Aggiungi alla schermata Home», apri Calcetto dall\'icona che compare e torna qui.',
        'unsupported': 'Questo browser non supporta le notifiche (serve una connessione sicura https e un browser recente).',
        'denied': 'Le notifiche sono bloccate per questo sito: riattivale dalle impostazioni del browser (icona del lucchetto accanto all\'indirizzo), poi ricarica la pagina.',
        'off': 'Le notifiche non sono attive su questo dispositivo.',
        'on': 'Le notifiche sono attive su questo dispositivo.',
      }[state];
      const lastErr = state === 'off' ? store.get('push-error') : null;
      if (lastErr) store.del('push-error');
      setText(card, '[data-push-status]', lastErr ? 'Non è stato possibile attivare le notifiche: ' + lastErr : msg);
      show(card, '[data-push-toggle]', state === 'off' || state === 'on');
      show(card, '[data-push-test]', state === 'on');
      if (toggle) {
        toggle.dataset.state = state;
        toggle.innerHTML = state === 'on' ? '<i class="ti ti-bell-off"></i> Disattiva' : '<i class="ti ti-bell-ringing"></i> Attiva le notifiche';
        toggle.classList.toggle('btn-primary', state !== 'on');
        toggle.classList.toggle('btn-ghost', state === 'on');
      }
    });
    return state;
  };

  const busy = (card, on) => card.querySelectorAll('button').forEach(b => { b.disabled = on; });
  const notice = (card, text) => setText(card, '[data-push-status]', text);

  const pause = ms => new Promise(r => setTimeout(r, ms));

  // l'abbonamento è stato fatto con la chiave del sito attuale? (se le chiavi cambiano, le notifiche vecchie non arrivano più)
  const sameKey = (sub, key) => {
    const cur = sub.options && sub.options.applicationServerKey;
    if (!cur) return true;   // il browser non lo dice: non si può confrontare
    const a = new Uint8Array(cur);
    return a.length === key.length && a.every((b, i) => b === key[i]);
  };

  // aspetta che il service worker appena registrato sia attivo (navigator.serviceWorker.ready non va bene dopo una nuova registrazione)
  const activated = async reg => {
    const sw = reg.installing || reg.waiting || reg.active;
    if (!sw || sw.state === 'activated') return reg;
    await new Promise((ok, no) => sw.addEventListener('statechange', () => {
      if (sw.state === 'activated') ok(); else if (sw.state === 'redundant') no(new Error('service worker non installato'));
    }));
    return reg;
  };

  const withTimeout = (p, ms) => Promise.race([p, new Promise((_, no) => setTimeout(() => no(Object.assign(new Error('timeout'), { name: 'AbortError' })), ms))]);

  const pushHelp = 'il telefono non riesce a registrarsi presso il servizio notifiche di Google (errore «push service error»). '
    + 'Prova così: controlla di avere internet, disattiva VPN, «DNS privato» e blocca-pubblicità (AdGuard ecc.), aggiorna Chrome e Google Play Services, '
    + 'controlla che data e ora del telefono siano automatiche, poi riprova. Se usi Brave, attiva «Usa Google Services per le notifiche push» nelle impostazioni.';

  // La registrazione presso il servizio notifiche del telefono (FCM) ogni tanto fallisce con «push service error»:
  // si riprova fino a 3 volte, ripulendo prima l'abbonamento e poi anche il service worker.
  const subscribe = async () => {
    const key = keyBytes(keyMeta.content);
    let reg = await navigator.serviceWorker.register('sw.js').then(activated);
    let lastErr;
    for (let attempt = 1; attempt <= 3; attempt++) {
      try {
        if (attempt > 1) {
          const old = await reg.pushManager.getSubscription().catch(() => null);
          if (old) await old.unsubscribe().catch(() => {});
          if (attempt === 3) {
            await reg.unregister().catch(() => {});
            reg = await navigator.serviceWorker.register('sw.js').then(activated);
          }
          await pause(1500 * (attempt - 1));
        }
        let sub = await reg.pushManager.getSubscription();
        if (sub && !sameKey(sub, key)) {
          await sub.unsubscribe().catch(() => {});
          sub = null;
        }
        return sub || await withTimeout(reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }), 20000);
      } catch (err) {
        lastErr = err;
        if (err && (err.name === 'NotAllowedError' || err.name === 'InvalidAccessError' || err.name === 'NotSupportedError')) break;   // riprovare non serve
      }
    }
    console.warn('Notifiche push: registrazione non riuscita', lastErr);
    throw new Error(lastErr && lastErr.name === 'NotAllowedError'
      ? 'le notifiche sono bloccate per questo sito nelle impostazioni del telefono o del browser.'
      : pushHelp + (lastErr && lastErr.message ? ' (dettaglio: ' + lastErr.message + ')' : ''));
  };

  const enable = async card => {
    // il permesso va chiesto subito, mentre è ancora valido il tocco dell'utente (Safari lo pretende)
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') return;
    notice(card, 'Attivazione in corso…');
    const sub = await subscribe();
    const res = await sendSub(sub);
    if (!res.ok) {
      await sub.unsubscribe().catch(() => {});
      throw new Error(res.error || 'Attivazione non riuscita.');
    }
    store.set('push-sync-' + uid, String(Date.now()));
  };

  const disable = async () => {
    const sub = await currentSub();
    if (!sub) return;
    await post({ do: 'unsubscribe', endpoint: sub.endpoint });
    await sub.unsubscribe();
  };

  // campanella in alto: con un tocco attiva o disattiva le notifiche di questo dispositivo; se non si può
  // (bloccate, browser non adatto, iPhone senza app) porta alla scheda che spiega cosa fare
  bells.forEach(bell => bell.addEventListener('click', async e => {
    const state = bell.dataset.state;
    if (!supported || (state !== 'on' && state !== 'off')) return;
    e.preventDefault();
    bell.classList.add('is-busy');
    try {
      if (state === 'on') await disable(); else await enable(document.createElement('div'));
      await render();
    } catch (err) {
      store.set('push-error', err && err.message ? err.message : 'errore sconosciuto');   // la scheda lo mostra
      window.location.href = bell.href;
    } finally {
      bell.classList.remove('is-busy');
    }
    if (Notification.permission === 'denied') window.location.href = bell.href;   // ha bloccato la richiesta: la scheda spiega come sbloccarla
  }));

  document.addEventListener('click', async e => {
    const btn = e.target.closest('[data-push-toggle], [data-push-test], [data-push-dismiss]');
    if (!btn) return;
    const card = btn.closest('[data-push-card]');
    if (btn.hasAttribute('data-push-dismiss')) { store.set('push-dismissed', '1'); render(); return; }
    if (!supported) return;
    busy(card, true);
    try {
      if (btn.hasAttribute('data-push-test')) {
        notice(card, 'Invio della notifica di prova…');
        const res = await post({ do: 'test' });
        notice(card, res.ok ? 'Notifica inviata: dovrebbe comparire tra un attimo.' : (res.error || 'Invio non riuscito.'));
      } else if (btn.dataset.state === 'on') {
        await disable();
        await render();
      } else {
        await enable(card);
        await render();
      }
    } catch (err) {
      await render();
      notice(card, 'Non è stato possibile completare l\'operazione: ' + (err && err.message ? err.message : err));
    } finally {
      busy(card, false);
    }
  });

  // chi esce dall'account toglie il dispositivo dalle sue notifiche (al prossimo accesso, di chiunque, si riabbina da solo)
  document.querySelectorAll('a[href="logout.php"]').forEach(a => a.addEventListener('click', async e => {
    if (!supported || Notification.permission !== 'granted') return;
    e.preventDefault();
    store.del('push-sync-' + uid);
    try {
      const sub = await Promise.race([currentSub(), new Promise(r => setTimeout(() => r(null), 1500))]);
      if (sub) await Promise.race([post({ do: 'unsubscribe', endpoint: sub.endpoint }), new Promise(r => setTimeout(r, 2000))]);
    } catch (err) { /* si esce lo stesso */ }
    window.location.href = a.href;
  }));

  (async () => {
    if (supported && Notification.permission === 'granted') {
      // tiene aggiornato il service worker e riassocia il dispositivo all'account in uso (al massimo ogni 12 ore)
      try {
        await navigator.serviceWorker.register('sw.js');
        let sub = await currentSub();
        if (sub && !sameKey(sub, keyBytes(keyMeta.content))) {
          // abbonamento fatto con una chiave vecchia del sito: non riceverebbe più nulla, si rifà da solo
          await sub.unsubscribe().catch(() => {});
          sub = await subscribe();
          store.del('push-sync-' + uid);
        }
        const last = parseInt(store.get('push-sync-' + uid) || '0', 10);
        if (sub && Date.now() - last > 12 * 3600 * 1000) {
          const res = await sendSub(sub);
          if (res.ok) store.set('push-sync-' + uid, String(Date.now()));
        }
      } catch (err) { /* al prossimo caricamento riprova */ }
    }
    render();
  })();
});
