/*
 * Player delle esultanze in pixel art. Ogni <svg data-seq> ha i fotogrammi nei <defs> (<g id="…-nome">) e un <use data-sprite>:
 * a ogni passo il <use> punta al fotogramma del passo con lo spostamento/rotazione già calcolati in PHP. Effetti del passo:
 * polvere (<g data-fx>) e scia (i due passi precedenti, semitrasparenti, nei <use data-ghost>).
 */
(() => {
  const players = new Map();
  const show = (svg, seq, i) => {
    const id = svg.dataset.id;
    const [name, , tr, fx] = seq[i];
    const sprite = svg.querySelector('[data-sprite]');
    sprite.setAttribute('href', `#${id}-${name}`);
    sprite.setAttribute('transform', tr);
    svg.querySelectorAll('[data-fx]').forEach(g => g.setAttribute('display', g.dataset.fx === fx ? 'inline' : 'none'));
    svg.querySelectorAll('[data-ghost]').forEach(u => {
      const k = +u.dataset.ghost, p = seq[i - k];
      if (fx === 'trail' && p) {
        u.setAttribute('href', `#${id}-${p[0]}`);
        u.setAttribute('transform', p[2]);
        u.setAttribute('display', 'inline');
      } else u.setAttribute('display', 'none');
    });
    const sh = svg.querySelector('[data-shadow]');
    if (sh) {
      const dy = Math.max(0, -(+((tr.match(/translate\(\S+ (-?[\d.]+)/) || [0, 0])[1])));
      const k = 1 - dy / 45;
      sh.setAttribute('transform', `translate(16.5 55.5) scale(${k.toFixed(3)}) translate(-16.5 -55.5)`);
      sh.setAttribute('opacity', (0.22 * k).toFixed(3));
    }
  };
  /** Suona la sequenza di un svg; opts: loop, speed (moltiplicatore delle durate), done. */
  const play = (svg, opts = {}) => {
    stop(svg);
    const seq = JSON.parse(svg.dataset.seq);
    const st = { i: 0, t: 0 };
    players.set(svg, st);
    const step = () => {
      show(svg, seq, st.i);
      const ms = seq[st.i][1] * (opts.speed ? opts.speed() : 1);
      st.i++;
      if (st.i >= seq.length) {
        if (!opts.loop) { players.delete(svg); if (opts.done) st.t = setTimeout(opts.done, ms); return; }
        st.i = 0;
      }
      st.t = setTimeout(step, ms);
    };
    step();
  };
  const stop = (svg) => { const st = players.get(svg); if (st) clearTimeout(st.t); players.delete(svg); };
  const goto = (svg, i) => { stop(svg); const seq = JSON.parse(svg.dataset.seq); show(svg, seq, (i + seq.length) % seq.length); };
  window.PixelAvatar = { play, stop, goto };
  if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.querySelectorAll('svg[data-seq][data-autoplay]').forEach(s => play(s, { loop: true }));
  }
})();
