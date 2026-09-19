// Piccole interazioni: conferme, select che salvano da sole, voti, calcolo risultato, anteprima foto.
document.addEventListener('DOMContentLoaded', () => {
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

  const photo = document.getElementById('photo-input');
  if (photo) {
    photo.addEventListener('change', () => {
      const f = photo.files[0];
      if (!f) return;
      const img = document.createElement('img');
      img.className = 'avatar avatar-xl';
      img.src = URL.createObjectURL(f);
      const box = document.getElementById('photo-preview');
      box.innerHTML = '';
      box.appendChild(img);
    });
  }
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
    if (target) target.scrollIntoView({ block: 'nearest', inline: 'center' });
    place();
    next.focus({ preventScroll: true });
  }

  function finish() {
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('resize', place);
    document.documentElement.classList.remove('tour-open');
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
