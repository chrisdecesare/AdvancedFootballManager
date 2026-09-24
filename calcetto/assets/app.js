// Piccole interazioni: conferme, select che salvano da sole, voti, calcolo risultato, anteprima foto.
document.addEventListener('DOMContentLoaded', () => {
  // invito ad aggiungere l'email: si può rimandare
  const emailBanner = document.querySelector('[data-email-banner]');
  if (emailBanner) {
    let off = false;
    try { off = localStorage.getItem('email-dismissed') === '1'; } catch (e) { /* memoria del browser non disponibile */ }
    emailBanner.hidden = off;
    emailBanner.querySelector('[data-email-dismiss]').addEventListener('click', () => {
      try { localStorage.setItem('email-dismissed', '1'); } catch (e) { /* pazienza */ }
      emailBanner.hidden = true;
    });
  }

  // conti alla rovescia (partita, fine votazioni): l'ora di riferimento è quella del server
  document.querySelectorAll('[data-countdown]').forEach(el => {
    const target = parseInt(el.dataset.countdown, 10) * 1000;
    const skew = parseInt(el.dataset.now, 10) * 1000 - Date.now();
    const within = parseInt(el.dataset.within || '0', 10) * 1000;
    const out = el.querySelector('[data-cd-out]');
    const two = n => String(n).padStart(2, '0');
    const fmt = ms => {
      const s = Math.ceil(ms / 1000), d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
      return d ? d + 'g ' + h + 'h' : h ? h + 'h ' + two(m) + 'min' : two(m) + ':' + two(s % 60);
    };
    let timer = 0;
    const tick = () => {
      const left = target - (Date.now() + skew);
      if (within && left > within) { el.hidden = true; return; }
      el.hidden = false;
      if (left <= 0) {
        out.textContent = el.dataset.done || '';
        clearInterval(timer);
        const key = 'cd-reload-' + target;
        if (el.dataset.reload && !sessionStorage.getItem(key)) {          // una sola volta, per non ricaricare all'infinito
          try { sessionStorage.setItem(key, '1'); } catch (e) { return; }
          setTimeout(() => location.reload(), 2500);
        }
        return;
      }
      out.textContent = (el.dataset.prefix || '') + fmt(left) + (el.dataset.prefix && el.dataset.prefix.includes('(') ? ')' : '');
    };
    tick();
    timer = setInterval(tick, 1000);
  });

  // conferma dei voti: sparisce da sola, oppure con un tocco
  const voteDone = document.querySelector('[data-vote-done]');
  if (voteDone) {
    voteDone.addEventListener('click', () => voteDone.remove());
    voteDone.addEventListener('animationend', e => { if (e.target === voteDone) voteDone.remove(); });
  }

  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
      if (!confirm(el.dataset.confirm)) e.preventDefault();
    });
  });

  document.querySelectorAll('select[data-autosubmit]').forEach(sel => {
    sel.addEventListener('change', () => sel.form.submit());
  });

  const voteClass = v => v < 5.5 ? 'v-low' : v < 7 ? 'v-mid' : v < 8.5 ? 'v-good' : 'v-top';
  document.querySelectorAll('.vote-range').forEach(r => {
    const out = r.parentElement.querySelector('output');
    const upd = () => {
      const v = parseFloat(r.value);
      out.textContent = v.toFixed(1).replace('.', ',');
      out.className = 'vote ' + voteClass(v);
    };
    r.addEventListener('input', upd);
  });

  // gol della squadra + autogol degli avversari
  const calc = document.getElementById('calc-score');
  if (calc) {
    calc.addEventListener('click', () => {
      const tot = { A: 0, B: 0 };
      document.querySelectorAll('#result-form tr[data-team]').forEach(tr => {
        const t = tr.dataset.team, other = t === 'A' ? 'B' : 'A';
        tot[t] += parseInt(tr.querySelector('.in-goals').value || 0, 10);
        tot[other] += parseInt(tr.querySelector('.in-og').value || 0, 10);
      });
      document.getElementById('score_a').value = tot.A;
      document.getElementById('score_b').value = tot.B;
    });
  }

  // scelta di un'immagine (foto profilo, sfondo): si apre il ritaglio e si carica solo la parte scelta
  document.querySelectorAll('input[type=file][data-crop-w]').forEach(input => {
    input.addEventListener('change', async () => {
      const f = input.files[0];
      if (!f) return;
      const blob = await openCropper(f, {
        w: parseInt(input.dataset.cropW, 10), h: parseInt(input.dataset.cropH, 10),
        round: input.dataset.cropRound === '1', title: input.dataset.cropTitle || 'Ritaglia',
      });
      if (blob === null) { input.value = ''; return; }                  // annullato
      let shown = f;
      if (blob) {
        try {
          const dt = new DataTransfer();
          dt.items.add(new File([blob], (input.name || 'img') + '.jpg', { type: 'image/jpeg' }));
          input.files = dt.files;
          shown = input.files[0];
        } catch (e) { /* il browser non permette di sostituire il file: resta l'originale, ritagliato dal server */ }
      }
      const target = document.getElementById(input.dataset.preview || '');
      if (!target) return;
      const url = URL.createObjectURL(shown);
      if (input.dataset.previewType === 'img') {
        const img = document.createElement('img');
        img.className = 'avatar avatar-xl';
        img.src = url;
        target.innerHTML = '';
        target.appendChild(img);
      } else {
        const box = target.closest('[data-bg]');
        if (box) {
          box.dataset.image = url;
          const image = box.querySelector('input[name=bg_mode][value=image]');
          if (image) image.checked = true;
          box.dispatchEvent(new Event('bg:update'));
        }
      }
    });
  });

  // sfondo del profilo: modalita' (automatico/colore/immagine), anteprime (profilo orizzontale e card verticale) ed editor
  document.querySelectorAll('[data-bg]').forEach(box => {
    const prev = box.querySelector('#bg-preview');
    const prevV = box.querySelector('#bg-preview-v');
    const picker = box.querySelector('input[type=color]');
    const fileIn = box.querySelector('input[type=file][data-bg-editor]');
    const recropBtn = box.querySelector('[data-bg-recrop]');
    const hid = n => box.querySelector('input[name=' + n + ']');
    // foto su cui si stanno scegliendo i riquadri in questa pagina (nuova o l'originale già salvato) e sua proporzione
    const live = { url: '', ar: 0 };
    const lighten = hex => {
      const n = parseInt(hex.slice(1), 16);
      const m = c => Math.round(c + (255 - c) * 0.3).toString(16).padStart(2, '0');
      return '#' + m(n >> 16) + m((n >> 8) & 255) + m(n & 255);
    };
    const paint = (el, css) => {
      el.style.background = '';
      el.style.removeProperty('--pc');
      el.style.removeProperty('--pc2');
      if (css) el.style.background = css;
    };
    const apply = () => {
      const mode = (box.querySelector('input[name=bg_mode]:checked') || {}).value || 'auto';
      box.querySelectorAll('[data-bg-panel]').forEach(p => { p.hidden = p.dataset.bgPanel !== mode; });
      [prev, prevV].forEach(el => paint(el, ''));
      if (mode === 'color') {
        [prev, prevV].forEach(el => { el.style.setProperty('--pc', picker.value); el.style.setProperty('--pc2', lighten(picker.value)); });
      } else if (mode === 'image') {
        if (live.url && live.ar) {          // riquadri appena scelti: si mostrano dalla foto, esattamente come usciranno
          paint(prev, bgCropCss(live.url, parseRect(hid('bg_crop_h').value), live.ar, BGE_AR.h));
          paint(prevV, bgCropCss(live.url, parseRect(hid('bg_crop_v').value), live.ar, BGE_AR.v));
        } else if (box.dataset.image) {     // quelli salvati
          paint(prev, "url('" + box.dataset.image + "') center / cover no-repeat");
          paint(prevV, "url('" + (box.dataset.imageV || box.dataset.image) + "') center / cover no-repeat");
        }
      }
    };
    const edit = async (url, ar, pxW) => {
      const res = await openBgEditor(url, ar, { h: parseRect(hid('bg_crop_h').value), v: parseRect(hid('bg_crop_v').value) }, pxW);
      if (!res) return false;
      hid('bg_crop_h').value = res.h.slice(0, 3).map(n => n.toFixed(4)).join(',');
      hid('bg_crop_v').value = res.v.slice(0, 3).map(n => n.toFixed(4)).join(',');
      live.url = url; live.ar = ar; live.w = pxW;
      const image = box.querySelector('input[name=bg_mode][value=image]');
      if (image) image.checked = true;
      apply();
      return true;
    };
    if (fileIn) fileIn.addEventListener('change', async () => {
      const f = fileIn.files[0];
      if (!f) return;
      // una copia ridotta solo se serve (foto enormi, non JPG, o più pesanti di quanto accetta il server): i riquadri coincidono col server
      const small = await shrinkImage(f, 3200, parseInt(fileIn.dataset.maxBytes, 10) || 2 * 1024 * 1024);
      if (!small) { fileIn.value = ''; return; }
      hid('bg_crop_h').value = ''; hid('bg_crop_v').value = '';   // foto nuova: riquadri di partenza al centro
      const ok = await edit(small.url, small.ar, small.w);
      if (!ok) { fileIn.value = ''; return; }
      if (small.blob) {                                      // copia ridotta: parte quella (altrimenti il file originale, intatto)
        try {
          const dt = new DataTransfer();
          dt.items.add(new File([small.blob], 'sfondo.jpg', { type: 'image/jpeg' }));
          fileIn.files = dt.files;
        } catch (e) { /* il browser non lascia sostituire il file: parte l'originale, il server lo riduce da sé */ }
      }
      hid('bg_recrop').value = '0';
    });
    if (recropBtn) recropBtn.addEventListener('click', async () => {
      if (live.url) { await edit(live.url, live.ar, live.w); return; }   // foto appena scelta in questa pagina
      const url = box.dataset.src;
      if (!url) return;
      const img = await loadImg(url);
      if (!img) return;
      if (await edit(url, img.naturalWidth / img.naturalHeight, img.naturalWidth)) hid('bg_recrop').value = '1';
    });
    box.querySelectorAll('input[name=bg_mode]').forEach(r => r.addEventListener('change', apply));
    picker.addEventListener('input', () => {
      const color = box.querySelector('input[name=bg_mode][value=color]');
      if (color) color.checked = true;
      apply();
    });
    box.querySelectorAll('.swatch').forEach(b => b.addEventListener('click', () => {
      picker.value = b.dataset.color;
      picker.dispatchEvent(new Event('input'));
    }));
    box.addEventListener('bg:update', apply);
    apply();
  });
  // curiosità in Home: cambiano da sole ogni data-seconds secondi (15), ferme se la pagina non si vede
  document.querySelectorAll('[data-facts]').forEach(box => {
    const slides = [...box.querySelectorAll('[data-fact]')];
    const bar = box.querySelector('[data-fact-bar]');
    const secs = parseInt(box.dataset.seconds, 10) || 15;
    if (slides.length < 2) return;
    box.style.setProperty('--fact-s', secs + 's');
    let i = 0;
    setInterval(() => {
      if (document.hidden) return;
      slides[i].classList.remove('is-active');
      i = (i + 1) % slides.length;
      slides[i].classList.add('is-active');
      if (bar) { bar.style.animation = 'none'; void bar.offsetWidth; bar.style.animation = ''; }   // la barra riparte da zero
    }, secs * 1000);
  });
  // slider momenti salienti: frecce, pallini, scorrimento automatico
  document.querySelectorAll('[data-slider]').forEach(slider => {
    const track = slider.querySelector('[data-slides]');
    const slides = [...track.children];
    const dots = [...slider.querySelectorAll('.dot')];
    if (slides.length < 2) return;
    let current = 0, timer = null;
    const go = i => {
      current = (i + slides.length) % slides.length;
      track.scrollTo({ left: slides[current].offsetLeft - track.offsetLeft - 4, behavior: 'smooth' });
    };
    const mark = () => dots.forEach((d, i) => d.classList.toggle('is-active', i === current));
    track.addEventListener('scroll', () => {
      const i = Math.round(track.scrollLeft / (slides[0].offsetWidth + 12));
      if (i !== current) { current = Math.max(0, Math.min(slides.length - 1, i)); }
      mark();
    }, { passive: true });
    slider.querySelector('[data-prev]').addEventListener('click', () => { go(current - 1); restart(); });
    slider.querySelector('[data-next]').addEventListener('click', () => { go(current + 1); restart(); });
    dots.forEach((d, i) => d.addEventListener('click', () => { go(i); restart(); }));
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const restart = () => {
      clearInterval(timer);
      if (!reduced) timer = setInterval(() => go(current + 1), 5000);
    };
    slider.addEventListener('pointerenter', () => clearInterval(timer));
    slider.addEventListener('pointerleave', restart);
    mark();
    restart();
  });

  // campo: l'admin tocca due pedine per scambiarle
  const swapForm = document.getElementById('swap-form');
  if (swapForm) {
    let picked = null;
    document.querySelectorAll('.pitch.is-editable .token').forEach(t => {
      t.addEventListener('click', () => {
        if (!picked) { picked = t; t.classList.add('is-picked'); return; }
        if (picked === t) { t.classList.remove('is-picked'); picked = null; return; }
        swapForm.p1.value = picked.dataset.swap;
        swapForm.p2.value = t.dataset.swap;
        swapForm.submit();
      });
    });
  }

  // se le schede del menu non ci stanno in una riga, il menu passa su una riga sua (di solito ci pensa già il CSS)
  const bar = document.querySelector('.topbar');
  const navEl = bar && bar.querySelector('.nav');
  if (navEl) {
    // Il menu deve stare tutto, senza sovrapporsi al titolo: 1) nomi completi su una riga; 2) se non ci stanno, solo icone
    // (il nome resta sulla scheda attiva e nel tooltip); 3) solo se neanche così, il menu va su una riga sua sotto il titolo,
    // di nuovo prima con i nomi e poi con le sole icone. Con tante schede (admin) si arriva spesso al punto 2 o 3.
    const overflowing = () => navEl.scrollWidth > navEl.clientWidth + 1;
    const fit = () => {
      bar.classList.remove('topbar-wrap', 'nav-compact');
      const burger = bar.querySelector('.nav-toggle');
      if (burger && getComputedStyle(burger).display !== 'none') return;   // menu a panino: niente da controllare
      if (!overflowing()) return;
      bar.classList.add('nav-compact');
      if (!overflowing()) return;
      bar.classList.remove('nav-compact');
      bar.classList.add('topbar-wrap');
      if (overflowing()) bar.classList.add('nav-compact');
    };
    fit();
    window.addEventListener('resize', fit);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
  }

  // menu a panino (telefoni): apre e chiude l'elenco delle schede
  const burger = bar && bar.querySelector('.nav-toggle');
  if (burger) {
    const icon = burger.querySelector('i');
    const setMenu = open => {
      bar.classList.toggle('menu-open', open);
      document.documentElement.classList.toggle('menu-open', open);   // serve a nascondere la schedina delle scommesse sotto il menu
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
      burger.setAttribute('aria-label', open ? 'Chiudi il menu' : 'Apri il menu');
      icon.className = 'ti ' + (open ? 'ti-x' : 'ti-menu-2');
    };
    burger.addEventListener('click', () => setMenu(!bar.classList.contains('menu-open')));
    document.addEventListener('click', e => {
      // un tocco fuori dal menu lo chiude (ma non quelli sul tutorial, che lo apre e chiude da solo)
      if (bar.classList.contains('menu-open') && !e.target.closest('.nav, .nav-toggle, .tour')) setMenu(false);
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') setMenu(false); });
    window.addEventListener('resize', () => { if (getComputedStyle(burger).display === 'none') setMenu(false); });
    document.addEventListener('menu:set', e => setMenu(!!e.detail));
  }

  // tutorial di benvenuto: i passi arrivano dal server (vedi lib/tour.php)
  const tourData = document.getElementById('tour-data');
  if (tourData) {
    let cfg = null;
    try { cfg = JSON.parse(tourData.textContent); } catch (e) { /* dati non validi: niente tutorial */ }
    if (cfg && Array.isArray(cfg.steps) && cfg.steps.length) startTour(cfg);
  }
});

/* Tutorial: evidenzia una scheda alla volta (spot) e la spiega in una card. Finire o saltare lo segna come visto. */
function startTour(cfg) {
  const steps = cfg.steps;
  const el = (tag, cls, txt) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (txt !== undefined) n.textContent = txt;
    return n;
  };
  const root = el('div', 'tour');
  const spot = el('div', 'tour-spot');
  const card = el('div', 'tour-card');
  card.setAttribute('role', 'dialog');
  card.setAttribute('aria-modal', 'true');
  card.setAttribute('aria-labelledby', 'tour-title');
  const icon = el('span', 'tour-icon');
  const iconI = el('i', 'ti');
  icon.appendChild(iconI);
  const title = el('h2', 'tour-title');
  title.id = 'tour-title';
  const head = el('div', 'tour-head');
  head.append(icon, title);
  const text = el('p', 'tour-text');
  const list = el('ul', 'tour-list');
  const count = el('span', 'tour-count');
  const dots = el('span', 'tour-dots');
  steps.forEach(() => dots.appendChild(el('i', 'tour-dot')));
  const skip = el('button', 'tour-skip', 'Salta');
  const back = el('button', 'btn btn-ghost btn-sm', 'Indietro');
  const next = el('button', 'btn btn-primary btn-sm');
  [skip, back, next].forEach(b => { b.type = 'button'; });
  const actions = el('div', 'tour-actions');
  actions.append(back, next);
  const foot = el('div', 'tour-foot');
  foot.append(count, dots, skip, actions);
  card.append(head, text, list, foot);
  root.append(spot, card);
  document.body.appendChild(root);
  document.documentElement.classList.add('tour-open');
  // un ricaricamento non deve far ripartire il tutorial riaperto dal pulsante «?»
  if (/[?&]tour=/.test(location.search)) history.replaceState(null, '', location.pathname + location.hash);

  let i = 0;
  let target = null;

  function place() {
    const vw = window.innerWidth, vh = window.innerHeight, pad = 6;
    let r = null;
    if (target) {
      r = target.getBoundingClientRect();
      if (!r.width || !r.height) r = null;
    }
    if (r) {
      spot.classList.remove('is-center');
      spot.style.left = (r.left - pad) + 'px';
      spot.style.top = (r.top - pad) + 'px';
      spot.style.width = (r.width + pad * 2) + 'px';
      spot.style.height = (r.height + pad * 2) + 'px';
    } else {
      spot.classList.add('is-center');
      spot.style.left = (vw / 2) + 'px';
      spot.style.top = (vh / 2) + 'px';
      spot.style.width = '0px';
      spot.style.height = '0px';
    }
    const cw = card.offsetWidth, ch = card.offsetHeight;
    let left = r ? r.left + r.width / 2 - cw / 2 : (vw - cw) / 2;
    let top = r ? r.bottom + 18 : (vh - ch) / 2;
    left = Math.max(10, Math.min(left, vw - cw - 10));
    top = Math.max(10, Math.min(top, vh - ch - 10));
    card.style.left = left + 'px';
    card.style.top = top + 'px';
  }

  function show(n) {
    i = n;
    const s = steps[i];
    iconI.className = 'ti ti-' + s.icon;
    title.textContent = s.title;
    text.textContent = s.text;
    list.textContent = '';
    (s.bullets || []).forEach(b => list.appendChild(el('li', '', b)));
    count.textContent = (i + 1) + ' / ' + steps.length;
    [...dots.children].forEach((d, k) => d.classList.toggle('is-active', k === i));
    back.hidden = i === 0;
    skip.hidden = i === steps.length - 1;
    next.textContent = i === 0 ? 'Iniziamo' : (i === steps.length - 1 ? 'Ho capito!' : 'Avanti');
    target = s.sel ? document.querySelector(s.sel) : null;
    // sui telefoni le schede stanno nel menu a panino: va aperto per evidenziarle, e richiuso negli altri passi
    const burgerEl = document.querySelector('.nav-toggle');
    const inHiddenMenu = !!(target && target.closest('.nav') && burgerEl && getComputedStyle(burgerEl).display !== 'none');
    document.dispatchEvent(new CustomEvent('menu:set', { detail: inHiddenMenu }));
    if (target && !inHiddenMenu) target.scrollIntoView({ block: 'nearest', inline: 'center' });
    place();
    next.focus({ preventScroll: true });
  }

  function finish() {
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('resize', place);
    document.documentElement.classList.remove('tour-open');
    document.dispatchEvent(new CustomEvent('menu:set', { detail: false }));
    root.remove();
    const fd = new FormData();
    fd.append('do', 'done');
    fd.append('csrf', cfg.csrf);
    fetch(cfg.endpoint, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(() => {});
  }

  function onKey(e) {
    if (e.key === 'Escape') { finish(); return; }
    if (e.key === 'ArrowRight' && i < steps.length - 1) show(i + 1);
    if (e.key === 'ArrowLeft' && i > 0) show(i - 1);
    if (e.key === 'Tab') {   // il focus resta dentro la card
      const items = [skip, back, next].filter(b => !b.hidden);
      const pos = items.indexOf(document.activeElement);
      const to = e.shiftKey ? (pos <= 0 ? items.length - 1 : pos - 1) : (pos === items.length - 1 ? 0 : pos + 1);
      items[to].focus();
      e.preventDefault();
    }
  }

  next.addEventListener('click', () => { if (i === steps.length - 1) finish(); else show(i + 1); });
  back.addEventListener('click', () => { if (i > 0) show(i - 1); });
  skip.addEventListener('click', finish);
  document.addEventListener('keydown', onKey);
  window.addEventListener('resize', place);
  show(0);
}

/*
 * Ritaglio di un'immagine: la persona trascina e ingrandisce, e si ottiene un JPEG w x h con la parte scelta.
 * Ritorna una Promise: Blob (ritagliato), null (annullato) o undefined (il browser non legge l'immagine: si tiene il file).
 */
function openCropper(file, opt) {
  return new Promise(resolve => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onerror = () => { URL.revokeObjectURL(url); resolve(undefined); };
    img.onload = () => {
      const opener = document.activeElement;
      const overlay = document.createElement('div');
      overlay.className = 'crop-modal';
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.setAttribute('aria-label', opt.title);
      overlay.innerHTML =
        '<div class="crop-box">' +
        '<h3 class="crop-title"></h3>' +
        '<div class="crop-stage' + (opt.round ? ' is-round' : '') + '" tabindex="0" style="aspect-ratio:' + opt.w + '/' + opt.h + ';--ar:' + (opt.w / opt.h) + '">' +
        '<img alt="" draggable="false"><span class="crop-mask"></span></div>' +
        '<label class="crop-zoom"><i class="ti ti-zoom-out"></i>' +
        '<input type="range" min="1" max="4" step="0.01" value="1" aria-label="Ingrandimento"><i class="ti ti-zoom-in"></i></label>' +
        '<p class="muted small">Trascina l\'immagine per scegliere la parte da tenere e usa il cursore per ingrandire.</p>' +
        '<div class="btn-row"><button type="button" class="btn btn-ghost" data-crop-cancel>Annulla</button>' +
        '<button type="button" class="btn btn-primary" data-crop-ok>Usa questo ritaglio</button></div></div>';
      overlay.querySelector('.crop-title').textContent = opt.title;
      const stage = overlay.querySelector('.crop-stage');
      const view = stage.querySelector('img');
      const range = overlay.querySelector('input[type=range]');
      view.src = url;
      document.body.appendChild(overlay);
      const prevOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';

      const nw = img.naturalWidth, nh = img.naturalHeight;
      const st = { sw: 1, sh: 1, min: 1, z: 1, x: 0, y: 0 };
      const layout = () => {
        const scale = st.min * st.z;
        const iw = nw * scale, ih = nh * scale;
        st.x = Math.min(0, Math.max(st.sw - iw, st.x));
        st.y = Math.min(0, Math.max(st.sh - ih, st.y));
        view.style.width = iw + 'px';
        view.style.height = ih + 'px';
        view.style.transform = 'translate(' + st.x + 'px,' + st.y + 'px)';
      };
      const zoomTo = z => {                                     // ingrandisce restando sul centro del riquadro
        const old = st.min * st.z;
        const cx = (st.sw / 2 - st.x) / old, cy = (st.sh / 2 - st.y) / old;
        st.z = Math.min(4, Math.max(1, z));
        const now = st.min * st.z;
        st.x = st.sw / 2 - cx * now;
        st.y = st.sh / 2 - cy * now;
        range.value = st.z;
        layout();
      };
      const init = () => {
        st.sw = stage.clientWidth;
        st.sh = stage.clientHeight;
        st.min = Math.max(st.sw / nw, st.sh / nh);
        st.z = 1;
        st.x = (st.sw - nw * st.min) / 2;
        st.y = (st.sh - nh * st.min) * (opt.round ? 0.15 : 0.5);   // le foto in verticale partono dall'alto (la testa)
        range.value = 1;
        layout();
      };
      init();

      // trascinamento con mouse/dito e pizzico con due dita
      const pts = new Map();
      let pinch = 0;
      stage.addEventListener('pointerdown', e => {
        try { stage.setPointerCapture(e.pointerId); } catch (err) { /* puntatore non catturabile: si trascina lo stesso */ }
        pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
        pinch = 0;
        stage.classList.add('is-drag');
      });
      stage.addEventListener('pointermove', e => {
        const p = pts.get(e.pointerId);
        if (!p) return;
        if (pts.size === 1) {
          st.x += e.clientX - p.x;
          st.y += e.clientY - p.y;
          layout();
        }
        p.x = e.clientX; p.y = e.clientY;
        if (pts.size === 2) {
          const [a, b] = [...pts.values()];
          const d = Math.hypot(a.x - b.x, a.y - b.y);
          if (pinch) zoomTo(st.z * d / pinch);
          pinch = d;
        }
      });
      const up = e => { pts.delete(e.pointerId); pinch = 0; if (!pts.size) stage.classList.remove('is-drag'); };
      stage.addEventListener('pointerup', up);
      stage.addEventListener('pointercancel', up);
      stage.addEventListener('wheel', e => { e.preventDefault(); zoomTo(st.z * (e.deltaY < 0 ? 1.08 : 1 / 1.08)); }, { passive: false });
      range.addEventListener('input', () => zoomTo(parseFloat(range.value)));
      stage.addEventListener('keydown', e => {
        const step = 14, k = e.key;
        if (k === 'ArrowLeft') st.x += step; else if (k === 'ArrowRight') st.x -= step;
        else if (k === 'ArrowUp') st.y += step; else if (k === 'ArrowDown') st.y -= step;
        else if (k === '+' || k === '=') zoomTo(st.z * 1.1); else if (k === '-') zoomTo(st.z / 1.1);
        else return;
        e.preventDefault();
        layout();
      });

      const close = result => {
        document.removeEventListener('keydown', onKey, true);
        window.removeEventListener('resize', init);
        overlay.remove();
        document.body.style.overflow = prevOverflow;
        URL.revokeObjectURL(url);
        if (opener && opener.focus) opener.focus();
        resolve(result);
      };
      const confirmCrop = () => {
        const c = document.createElement('canvas');
        c.width = opt.w; c.height = opt.h;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, opt.w, opt.h);
        ctx.imageSmoothingQuality = 'high';
        const scale = st.min * st.z;
        ctx.drawImage(img, -st.x / scale, -st.y / scale, st.sw / scale, st.sh / scale, 0, 0, opt.w, opt.h);
        // toDataURL e' sincrono (toBlob puo' restare in attesa se la scheda e' in secondo piano)
        try {
          const bin = atob(c.toDataURL('image/jpeg', 0.9).split(',')[1]);
          const bytes = new Uint8Array(bin.length);
          for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
          close(new Blob([bytes], { type: 'image/jpeg' }));
        } catch (e) { close(undefined); }
      };
      const onKey = e => {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(null); }
        else if (e.key === 'Tab') {                                // il focus resta dentro la finestra
          const f = [...overlay.querySelectorAll('button, input, [tabindex="0"]')];
          const first = f[0], last = f[f.length - 1];
          if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
          else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
      };
      document.addEventListener('keydown', onKey, true);
      window.addEventListener('resize', init);
      overlay.querySelector('[data-crop-cancel]').addEventListener('click', () => close(null));
      overlay.querySelector('[data-crop-ok]').addEventListener('click', confirmCrop);
      overlay.addEventListener('pointerdown', e => { if (e.target === overlay) close(null); });
      stage.focus();
    };
    img.src = url;
  });
}


/* ---------------------------------------------------------------- editor dello sfondo del profilo
 * Da una sola foto si scelgono due riquadri: orizzontale 4:1 (intestazione del profilo) e verticale 3:4 (card della Rosa).
 * Un riquadro è [x, y, w, h] in frazioni della foto (angolo in alto a sinistra e dimensioni): si sposta trascinandolo e si
 * ridimensiona dall'angolo, con il cursore dello zoom o con due dita / la rotella. Le anteprime mostrano il risultato vero.
 * Il server ricava gli stessi ritagli dalla stessa foto (save_profile_bg_set in lib/helpers.php).
 */
const BGE_AR = { h: 4, v: 3 / 4 };

/** "x,y,w" → [x, y, w] (o null). */
function parseRect(str) {
  const p = String(str || '').split(',').map(Number);
  return p.length === 3 && p.every(Number.isFinite) && p[2] > 0 ? p : null;
}

/** Riquadro valido dentro la foto (proporzione della foto imgAr, del ritaglio ar); null = il più grande possibile, al centro. */
function fitRect(r, imgAr, ar) {
  const maxW = Math.min(1, ar / imgAr);
  let w = r ? Math.max(0.03, Math.min(maxW, r[2])) : maxW;
  const h = w * imgAr / ar;
  const x = r ? Math.max(0, Math.min(1 - w, r[0])) : (1 - w) / 2;
  const y = r ? Math.max(0, Math.min(1 - h, r[1])) : (1 - h) / 2;
  return [x, y, w, h];
}

/** CSS che mostra esattamente il riquadro r della foto in un contenitore con la stessa proporzione. */
function bgCropCss(url, r, imgAr, ar) {
  const [x, y, w, h] = fitRect(r, imgAr, ar);
  const px = w < 1 ? x / (1 - w) * 100 : 0, py = h < 1 ? y / (1 - h) * 100 : 0;
  return "url('" + url + "') " + px.toFixed(3) + '% ' + py.toFixed(3) + '% / ' + (100 / w).toFixed(3) + '% ' + (100 / h).toFixed(3) + '% no-repeat';
}

function loadImg(url) {
  return new Promise(res => { const i = new Image(); i.onload = () => res(i); i.onerror = () => res(null); i.src = url; });
}

/**
 * La foto da usare: se è un JPG non più grande di max px si tiene il file intatto (nessuna perdita di qualità; il server lo
 * raddrizza come il browser), altrimenti una copia JPEG di alta qualità con il lato lungo di max px.
 * Ritorna { blob (null = file originale), url, ar, w (pixel di larghezza) } o null.
 */
async function shrinkImage(file, max, maxBytes) {
  const src = URL.createObjectURL(file);
  const img = await loadImg(src);
  if (!img) { URL.revokeObjectURL(src); alert('Questa immagine non si riesce ad aprire: prova con un JPG o un PNG.'); return null; }
  if (/^image\/jpe?g$/i.test(file.type) && Math.max(img.naturalWidth, img.naturalHeight) <= max && file.size <= maxBytes) {
    return { blob: null, url: src, ar: img.naturalWidth / img.naturalHeight, w: img.naturalWidth };
  }
  URL.revokeObjectURL(src);
  // copia JPEG: si parte da alta qualità e dimensione piena, e si scende solo quanto basta per stare nel limite del server
  let side = Math.min(max, Math.max(img.naturalWidth, img.naturalHeight));
  for (let tries = 0; tries < 12; tries++) {
    const k = side / Math.max(img.naturalWidth, img.naturalHeight);
    const c = document.createElement('canvas');
    c.width = Math.round(img.naturalWidth * k); c.height = Math.round(img.naturalHeight * k);
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(img, 0, 0, c.width, c.height);
    for (const q of [0.93, 0.88, 0.84]) {
      let bytes;
      try {
        const bin = atob(c.toDataURL('image/jpeg', q).split(',')[1]);
        bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
      } catch (e) { return null; }
      if (bytes.length <= maxBytes) {
        const blob = new Blob([bytes], { type: 'image/jpeg' });
        return { blob, url: URL.createObjectURL(blob), ar: c.width / c.height, w: c.width };
      }
    }
    side = Math.round(side * 0.85);                        // ancora troppo pesante: un po' più piccola
  }
  return null;
}

/*
 * Qualità di un riquadro dai pixel veri che contiene (larghezza), rispetto a quanti ne servono sugli schermi ad alta densità:
 * l'intestazione del profilo è larga fino a ~1150 px, la card della Rosa ~220 px.
 */
const BGE_QUALITY = { h: [2000, 1100, 700], v: [650, 440, 300] };
function bgQuality(k, px) {
  const t = BGE_QUALITY[k];
  return px >= t[0] ? ['ottima', 'q-top'] : px >= t[1] ? ['buona', 'q-ok'] : px >= t[2] ? ['discreta', 'q-mid'] : ['bassa: allarga il riquadro o usa una foto più grande', 'q-low'];
}

/** Apre l'editor. Ritorna una Promise: { h: [x,y,w,h], v: [x,y,w,h] } oppure null (annullato). pxW = larghezza vera della foto. */
function openBgEditor(url, imgAr, start, pxW) {
  return new Promise(resolve => {
    const rects = { h: fitRect(start && start.h, imgAr, BGE_AR.h), v: fitRect(start && start.v, imgAr, BGE_AR.v) };
    let cur = 'h';
    const opener = document.activeElement;
    const overlay = document.createElement('div');
    overlay.className = 'crop-modal';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Scegli le parti della foto');
    overlay.innerHTML =
      '<div class="crop-box bge-box">' +
      '<h3 class="crop-title">Scegli le parti della foto</h3>' +
      '<div class="bge-tabs" role="tablist">' +
      '<button type="button" class="slip-tab active" data-k="h" role="tab"><i class="ti ti-rectangle"></i> Orizzontale · profilo</button>' +
      '<button type="button" class="slip-tab" data-k="v" role="tab"><i class="ti ti-rectangle-vertical"></i> Verticale · card nella Rosa</button></div>' +
      '<div class="bge-stage" tabindex="0"><img alt="" draggable="false"><span class="bge-ghost"></span>' +
      '<span class="bge-rect"><span class="bge-handle" aria-hidden="true"></span></span></div>' +
      '<label class="crop-zoom"><i class="ti ti-zoom-out"></i><input type="range" min="1" max="6" step="0.01" value="1" aria-label="Zoom"><i class="ti ti-zoom-in"></i></label>' +
      '<p class="muted small">Trascina il riquadro per scegliere la parte della foto, allargalo o stringilo dall\'angolo giallo (o con lo zoom).' +
      ' Poi passa all\'altro formato: la foto è la stessa, le parti le scegli tu.</p>' +
      '<div class="bge-previews">' +
      '<figure><div class="bge-prev bge-prev-h is-on" data-k="h"></div><figcaption>Così nel profilo <span class="bge-q" data-q="h"></span></figcaption></figure>' +
      '<figure><div class="bge-prev bge-prev-v" data-k="v"></div><figcaption>Così nella Rosa <span class="bge-q" data-q="v"></span></figcaption></figure></div>' +
      '<div class="btn-row"><button type="button" class="btn btn-ghost" data-bge-cancel>Annulla</button>' +
      '<button type="button" class="btn btn-primary" data-bge-ok>Usa queste parti</button></div></div>';
    document.body.appendChild(overlay);
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const stage = overlay.querySelector('.bge-stage');
    const view = stage.querySelector('img');
    const rectEl = stage.querySelector('.bge-rect');
    const ghost = stage.querySelector('.bge-ghost');
    const range = overlay.querySelector('input[type=range]');
    const tabs = [...overlay.querySelectorAll('.bge-tabs [data-k]')];
    const prevs = [...overlay.querySelectorAll('.bge-prev')];
    view.src = url;

    let sw = 0, sh = 0;
    const size = () => {                                     // la foto intera, grande quanto sta nella finestra
      const maxW = overlay.querySelector('.bge-box').clientWidth - 34;
      const maxH = Math.max(160, window.innerHeight * 0.46);
      sw = Math.min(maxW, maxH * imgAr); sh = sw / imgAr;
      stage.style.width = sw + 'px'; stage.style.height = sh + 'px';
      draw();
    };
    const place = (el, r) => {
      el.style.left = r[0] * sw + 'px'; el.style.top = r[1] * sh + 'px';
      el.style.width = r[2] * sw + 'px'; el.style.height = r[3] * sh + 'px';
    };
    const maxWOf = k => Math.min(1, BGE_AR[k] / imgAr);
    const draw = () => {
      place(rectEl, rects[cur]);
      place(ghost, rects[cur === 'h' ? 'v' : 'h']);
      range.value = maxWOf(cur) / rects[cur][2];
      prevs.forEach(p => {
        p.style.background = bgCropCss(url, rects[p.dataset.k], imgAr, BGE_AR[p.dataset.k]);
        p.classList.toggle('is-on', p.dataset.k === cur);
      });
      if (pxW) {                                             // quanto verrà nitido, dai pixel veri dentro il riquadro
        overlay.querySelectorAll('[data-q]').forEach(q => {
          const [label, cls] = bgQuality(q.dataset.q, Math.round(rects[q.dataset.q][2] * pxW));
          q.textContent = '· qualità ' + label;
          q.className = 'bge-q ' + cls;
        });
      }
      tabs.forEach(t => { t.classList.toggle('active', t.dataset.k === cur); t.setAttribute('aria-selected', t.dataset.k === cur); });
    };
    const setRect = (k, x, y, w) => { rects[k] = fitRect([x, y, w], imgAr, BGE_AR[k]); draw(); };
    const zoomTo = z => {                                    // zoom = riquadro più piccolo, restando sullo stesso centro
      const r = rects[cur];
      const w = maxWOf(cur) / Math.min(6, Math.max(1, z));
      const h = w * imgAr / BGE_AR[cur];
      setRect(cur, r[0] + r[2] / 2 - w / 2, r[1] + r[3] / 2 - h / 2, w);
    };
    const select = k => { cur = k; draw(); };
    tabs.forEach(t => t.addEventListener('click', () => select(t.dataset.k)));
    prevs.forEach(p => p.addEventListener('click', () => select(p.dataset.k)));
    range.addEventListener('input', () => zoomTo(parseFloat(range.value)));

    // trascinamento: sul riquadro lo sposta, sull'angolo lo ridimensiona, fuori lo centra lì; due dita = zoom
    const pts = new Map();
    let mode = null, startP = null, startR = null, pinch = 0;
    stage.addEventListener('pointerdown', e => {
      e.preventDefault();
      try { stage.setPointerCapture(e.pointerId); } catch (err) { /* niente */ }
      pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (pts.size === 2) { mode = 'pinch'; pinch = 0; return; }
      const b = stage.getBoundingClientRect();
      const r = rects[cur];
      startP = { x: e.clientX, y: e.clientY };
      if (e.target.classList.contains('bge-handle')) mode = 'resize';
      else if (e.target === rectEl) mode = 'move';
      else {                                                 // tocco fuori dal riquadro: ci si sposta sopra
        mode = 'move';
        setRect(cur, (e.clientX - b.left) / sw - r[2] / 2, (e.clientY - b.top) / sh - r[3] / 2, r[2]);
      }
      startR = rects[cur].slice();
    });
    stage.addEventListener('pointermove', e => {
      const p = pts.get(e.pointerId);
      if (!p) return;
      p.x = e.clientX; p.y = e.clientY;
      if (mode === 'pinch' && pts.size === 2) {
        const [a, c] = [...pts.values()];
        const d = Math.hypot(a.x - c.x, a.y - c.y);
        if (pinch) zoomTo(parseFloat(range.value) * d / pinch);
        pinch = d;
        return;
      }
      if (!startP) return;
      const dx = (e.clientX - startP.x) / sw, dy = (e.clientY - startP.y) / sh;
      if (mode === 'move') setRect(cur, startR[0] + dx, startR[1] + dy, startR[2]);
      else if (mode === 'resize') {
        // l'angolo in alto a sinistra resta fermo; la larghezza segue il dito, l'altezza la proporzione
        const w = Math.max(startR[2] + dx, (startR[3] + dy) * BGE_AR[cur] / imgAr);
        const cap = Math.min(1 - startR[0], (1 - startR[1]) * BGE_AR[cur] / imgAr);
        setRect(cur, startR[0], startR[1], Math.min(w, cap));
      }
    });
    const up = e => { pts.delete(e.pointerId); if (!pts.size) { mode = null; startP = null; } else if (pts.size === 1) { mode = null; startP = null; } };
    stage.addEventListener('pointerup', up);
    stage.addEventListener('pointercancel', up);
    stage.addEventListener('wheel', e => { e.preventDefault(); zoomTo(parseFloat(range.value) * (e.deltaY < 0 ? 1.08 : 1 / 1.08)); }, { passive: false });
    stage.addEventListener('keydown', e => {
      const r = rects[cur], step = 0.02, k = e.key;
      if (k === 'ArrowLeft') setRect(cur, r[0] - step, r[1], r[2]); else if (k === 'ArrowRight') setRect(cur, r[0] + step, r[1], r[2]);
      else if (k === 'ArrowUp') setRect(cur, r[0], r[1] - step, r[2]); else if (k === 'ArrowDown') setRect(cur, r[0], r[1] + step, r[2]);
      else if (k === '+' || k === '=') zoomTo(parseFloat(range.value) * 1.1); else if (k === '-') zoomTo(parseFloat(range.value) / 1.1);
      else return;
      e.preventDefault();
    });

    const close = result => {
      document.removeEventListener('keydown', onKey, true);
      window.removeEventListener('resize', size);
      overlay.remove();
      document.body.style.overflow = prevOverflow;
      if (opener && opener.focus) opener.focus();
      resolve(result);
    };
    const onKey = e => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(null); } };
    document.addEventListener('keydown', onKey, true);
    window.addEventListener('resize', size);
    overlay.querySelector('[data-bge-cancel]').addEventListener('click', () => close(null));
    overlay.querySelector('[data-bge-ok]').addEventListener('click', () => close({ h: rects.h, v: rects.v }));
    overlay.addEventListener('pointerdown', e => { if (e.target === overlay) close(null); });
    size();
    stage.focus();
  });
}
