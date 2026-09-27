/*
 * Player del Personaggio in pixel art (lib/avatar.php: avatar_figure). Ogni <svg class="avf"> ha i fotogrammi nei <defs>
 * (<g id="…-nome">) e un <use data-sprite> che mostra quello del passo, con lo spostamento/rotazione già calcolati in PHP.
 *  - data-idle: la posa da fermo, ripetuta (il respiro, il saluto);
 *  - data-seq: l'esultanza, che parte con PixelAvatar.play(); effetti del passo: polvere e oggetti (<g data-fx>), scia dei due passi
 *    precedenti (<use data-ghost>).
 */
(() => {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const players = new Map();   // svg -> { list, i, loop, timer, due, left, done }
  let paused = false;

  const show = (svg, list, i) => {
    const id = svg.dataset.id;
    const [name, , tr, fx = ''] = list[i];
    const sprite = svg.querySelector('[data-sprite]');
    if (!sprite) return;
    sprite.setAttribute('href', `#${id}-${name}`);
    if (tr) sprite.setAttribute('transform', tr); else sprite.removeAttribute('transform');
    const on = fx.split('+');
    svg.querySelectorAll('[data-fx]').forEach(g => g.setAttribute('display', on.includes(g.dataset.fx) ? 'inline' : 'none'));
    svg.querySelectorAll('[data-ghost]').forEach(u => {
      const p = list[i - +u.dataset.ghost];
      if (on.includes('trail') && p) {
        u.setAttribute('href', `#${id}-${p[0]}`);
        u.setAttribute('transform', p[2] || '');
        u.setAttribute('display', 'inline');
      } else u.setAttribute('display', 'none');
    });
    const sh = svg.querySelector('[data-shadow]');
    if (sh) {   // più sale, più l'ombra si stringe e sbiadisce
      const dy = Math.max(0, -(+((tr || '').match(/translate\(\S+ (-?[\d.]+)/) || [0, 0])[1]));
      const k = Math.max(.35, 1 - dy / 45);
      sh.setAttribute('transform', `translate(16 55.6) scale(${k.toFixed(3)}) translate(-16 -55.6)`);
      sh.setAttribute('opacity', (0.22 * k).toFixed(3));
    }
  };

  const schedule = (svg, st, ms) => {
    st.due = performance.now() + ms;
    st.timer = setTimeout(() => step(svg), ms);
  };
  const step = (svg) => {
    const st = players.get(svg);
    if (!st) return;
    if (st.i >= st.list.length) {
      if (st.loop) st.i = 0;
      else { players.delete(svg); idle(svg); if (st.done) st.done(); return; }
    }
    show(svg, st.list, st.i);
    const ms = st.list[st.i][1];
    st.i++;
    if (!paused) schedule(svg, st, ms); else st.left = ms;
  };
  const run = (svg, list, loop, done, start = 0) => {
    stop(svg);
    const st = { list, i: start, loop, done, timer: 0, due: 0, left: 0 };
    players.set(svg, st);
    step(svg);
  };
  const stop = (svg) => { const st = players.get(svg); if (st) clearTimeout(st.timer); players.delete(svg); };

  /** Posa da fermo (con il suo piccolo ciclo, se c'è), ogni figura sfasata dalle altre. */
  const idle = (svg) => {
    const rest = svg.dataset.rest;
    if (!rest) return;
    const list = svg.dataset.idle ? JSON.parse(svg.dataset.idle) : null;
    if (!list || reduce) { stop(svg); show(svg, [[rest, 0, '']], 0); return; }
    run(svg, list, true, null, Math.floor(Math.random() * list.length));
  };

  /** Esultanza: torna alla posa quando finisce. Restituisce la durata in ms (0 se non c'è). */
  const play = (svg, done) => {
    if (!svg || !svg.dataset.seq) return 0;
    const list = JSON.parse(svg.dataset.seq);
    run(svg, list, false, done);
    return list.reduce((t, s) => t + s[1], 0);
  };

  const setPaused = (v) => {
    if (v === paused) return;
    paused = v;
    players.forEach((st, svg) => {
      if (v) { clearTimeout(st.timer); st.left = Math.max(0, st.due - performance.now()); }
      else schedule(svg, st, st.left);
    });
  };

  const start = (root = document) => root.querySelectorAll('svg.avf[data-rest]').forEach(idle);
  window.PixelAvatar = { play, idle, stop, setPaused, start };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => start());
  else start();
})();
