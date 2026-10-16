/*
 * Ruota della fortuna (wheel.php) e Daily access (daily.php): animazione della ruota e finestra col premio.
 * Il premio lo decide il server: qui la ruota gira fino a fermarsi con il puntale (fisso in alto) sullo spicchio che il server ha estratto.
 */
(function () {
  const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function post(form) {
    return fetch(form.getAttribute('action') || location.pathname, {
      method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(form), credentials: 'same-origin'
    }).then(r => r.json());
  }

  // finestra col premio: resta finché non si preme «Ok» (poi la pagina si ricarica per aggiornare saldo e stato)
  function pop(icon, title, sub, reload) {
    const o = document.createElement('div');
    o.className = 'prize-pop';
    o.setAttribute('role', 'dialog');
    o.setAttribute('aria-modal', 'true');
    const c = document.createElement('div');
    c.className = 'prize-card';
    const badge = document.createElement('span');
    badge.className = 'cg-badge';
    const i = document.createElement('i');
    i.className = 'ti ti-' + icon;
    badge.appendChild(i);
    const t = document.createElement('div');
    t.className = 'vote-done-title';
    t.textContent = title;
    const s = document.createElement('p');
    s.className = 'prize-sub';
    s.textContent = sub || '';
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn btn-primary';
    b.textContent = 'Ok';
    b.addEventListener('click', () => { if (reload) location.reload(); else o.remove(); });
    c.append(badge, t, s, b);
    o.appendChild(c);
    document.body.appendChild(o);
    b.focus();
  }

  // ----- ruota
  const wheel = document.querySelector('[data-wheel]');
  const rotEl = document.querySelector('[data-wheel-rot]');
  const form = document.querySelector('[data-wheel-form]');
  const spinBtn = document.querySelector('[data-wheel-spin]');
  let rot = 0;
  if (wheel && rotEl && form && spinBtn) {
    const n = parseInt(wheel.dataset.slices, 10) || 14;
    const step = 360 / n;
    form.addEventListener('submit', e => {
      e.preventDefault();
      if (spinBtn.disabled) return;
      spinBtn.disabled = true;
      post(form).then(res => {
        if (!res.ok || !res.prize) { pop('mood-sad', 'Ops', res.msg || 'Qualcosa non ha funzionato.', true); return; }
        const p = res.prize;
        // angolo finale: giri interi in più, poi lo spicchio estratto (il suo centro sta a slice*step dall'alto) portato sotto il puntale
        const jitter = (Math.random() - .5) * step * .6;
        const base = Math.ceil(rot / 360) * 360;
        rot = base + 360 * 5 + (360 - p.slice * step) + jitter;
        const ms = reduce ? 600 : 5200;
        rotEl.style.transition = 'transform ' + ms + 'ms cubic-bezier(.12,.62,.08,1)';
        rotEl.style.transform = 'rotate(' + rot + 'deg)';
        wheel.classList.add('is-spinning');
        setTimeout(() => {
          wheel.classList.remove('is-spinning');
          const icon = { koin: 'coin', credits: 'diamond', item: 'gift', spin: 'refresh', token: 'ticket', none: 'mood-sad' }[p.type] || 'gift';
          const title = {
            koin: '+' + p.koin + ' KOIN!', credits: '+' + p.credits + ' crediti Avatar!', item: p.itemGiven ? 'Oggetto esclusivo!' : '+' + p.koin + ' KOIN!',
            spin: 'Giro extra!', token: 'Gettone ×2!', none: 'Niente, stavolta…'
          }[p.type];
          let sub = {
            koin: 'Sono già nel tuo portafoglio.',
            credits: 'Si spendono nell\'Avatar, non per le scommesse.',
            item: p.itemGiven ? p.label + ': lo trovi nel tuo Avatar.' : 'Ce l\'avevi già: al suo posto ' + p.koin + ' KOIN.',
            spin: 'Puoi girare subito un\'altra volta.',
            token: 'Attivalo su una partita in Scommesse: le tue puntate singole pagano il doppio.',
            none: 'Riprova alla prossima.'
          }[p.type];
          pop(icon, title, sub, true);
        }, ms + 250);
      }).catch(() => { spinBtn.disabled = false; pop('mood-sad', 'Ops', 'Qualcosa non ha funzionato: riprova.', false); });
    });
  }

  // ----- Daily access: il regalo di oggi
  const dform = document.querySelector('[data-daily-form]');
  if (dform) {
    const claim = dform.querySelector('[data-daily-claim]');
    dform.addEventListener('submit', e => {
      e.preventDefault();
      if (claim.disabled) return;
      claim.disabled = true;
      post(dform).then(res => {
        if (!res.ok) { pop('mood-sad', 'Ops', res.msg || 'Qualcosa non ha funzionato.', true); return; }
        const r = res.reward || {};
        pop(r.item ? 'gift' : 'calendar-check', 'Regalo ritirato!', r.label || res.msg, true);
      }).catch(() => { claim.disabled = false; pop('mood-sad', 'Ops', 'Qualcosa non ha funzionato: riprova.', false); });
    });
  }
})();
