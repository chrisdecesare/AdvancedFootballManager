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
});
