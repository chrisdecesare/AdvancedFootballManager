/*
 * Animazioni del Personaggio (avatar.php). Lo scheletro, le pose e le esultanze arrivano da lib/avatar_rig.php (JSON in #av-rig):
 * qui si interpolano gli angoli delle articolazioni tra una posa chiave e l'altra e si aggiornano i <g data-j="..."> dell'SVG.
 * Quando il personaggio non è in volo i piedi restano a terra (stesso calcolo di avatar_ground() in PHP).
 */
(() => {
  const el = document.getElementById('av-rig');
  if (!el) return;
  const RIG = JSON.parse(el.textContent);
  const JOINTS = ['waist', 'neck', 'shL', 'elL', 'wrL', 'shR', 'elR', 'wrR', 'hipL', 'knL', 'anL', 'hipR', 'knR', 'anR'];
  const NUM = JOINTS.concat(['x', 'y', 'r', 'sx']);
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const rot = (pt, c, deg) => {
    const a = deg * Math.PI / 180, dx = pt[0] - c[0], dy = pt[1] - c[1];
    return [c[0] + dx * Math.cos(a) - dy * Math.sin(a), c[1] + dx * Math.sin(a) + dy * Math.cos(a)];
  };
  const ground = (p) => {
    let low = -Infinity;
    for (const [s, m] of [['L', 1], ['R', -1]]) {
      [[38, 160], [58, 160], [50, 137]].forEach((pt, i) => {
        if (i < 2) {
          pt = rot(pt, RIG.pivots['an' + s], p['an' + s] * m);
          pt = rot(pt, RIG.pivots['kn' + s], p['kn' + s] * m);
        }
        pt = rot(pt, RIG.pivots['hip' + s], p['hip' + s] * m);
        low = Math.max(low, pt[1]);
      });
    }
    return RIG.ground - low;
  };
  const baseY = (p) => p.y + (p.air ? 0 : ground(p));

  const EASE = {
    l: t => t,
    i: t => t * t,
    o: t => 1 - (1 - t) * (1 - t),
    io: t => t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2,
    s: t => (t < 1 ? 0 : 1),
  };

  /** Posa intermedia tra a e b (e = avanzamento già "ammorbidito"); mani, bocca e accessori cambiano a metà strada. */
  const mix = (a, b, e) => {
    const p = {};
    for (const k of NUM) p[k] = a[k] + (b[k] - a[k]) * e;
    const late = e >= .5 ? b : a;
    p.hL = late.hL; p.hR = late.hR; p.m = late.m; p.p = late.p;
    p.air = a.air || b.air;
    // quota: tra due pose a terra i piedi restano a terra; se una delle due è in volo si passa da una quota all'altra
    p._y = a.air || b.air ? baseY(a) + (baseY(b) - baseY(a)) * e : p.y + ground(p);
    return p;
  };

  const apply = (svg, p) => {
    const y = p._y !== undefined ? p._y : baseY(p);
    svg.querySelectorAll('[data-j]').forEach(g => {
      const j = g.dataset.j;
      if (j === 'root') {
        g.setAttribute('transform', `translate(${p.x.toFixed(2)} ${y.toFixed(2)}) rotate(${p.r.toFixed(2)} ${RIG.root[0]} ${RIG.root[1]}) translate(60 0) scale(${p.sx.toFixed(3)} 1) translate(-60 0)`);
        return;
      }
      const a = (j === 'waist' || j === 'neck') ? p[j] : p[j] * (j.endsWith('R') ? -1 : 1);
      g.setAttribute('transform', `rotate(${a.toFixed(2)} ${RIG.pivots[j][0]} ${RIG.pivots[j][1]})`);
    });
    for (const s of ['L', 'R']) {
      svg.querySelectorAll(`[data-hand${s}]`).forEach(g => g.setAttribute('display', g.getAttribute(`data-hand${s}`) === p['h' + s] ? 'inline' : 'none'));
    }
    svg.querySelectorAll('[data-mouth]').forEach(g => g.setAttribute('display', g.dataset.mouth === p.m ? 'inline' : 'none'));
    svg.querySelectorAll('[data-prop]').forEach(g => g.setAttribute('display', p.p.includes(g.dataset.prop) ? 'inline' : 'none'));
    const sh = svg.querySelector('[data-shadow]');
    if (sh) {
      const up = Math.max(0, -y) / 80;   // più in alto è, più l'ombra si stringe e sbiadisce
      sh.setAttribute('transform', `translate(60 162) scale(${(1 - up * .55).toFixed(3)}) translate(-60 -162)`);
      sh.setAttribute('opacity', (1 - up * .6).toFixed(2));
    }
  };

  /** Una sequenza di pose chiave nel tempo, da ms 0 all'ultima chiave. */
  const poseAt = (keys, t) => {
    if (t <= keys[0][0]) return keys[0][1];
    for (let i = 1; i < keys.length; i++) {
      const [t1, p1, ease] = keys[i];
      if (t <= t1) {
        const [t0, p0] = keys[i - 1];
        return mix(p0, p1, (EASE[ease] || EASE.io)((t - t0) / (t1 - t0 || 1)));
      }
    }
    return keys[keys.length - 1][1];
  };

  const active = new Map();   // svg -> { keys, start, loop, done }
  let pausedAt = 0;
  let running = false;
  const tick = (now) => {
    running = false;
    if (pausedAt) return;
    active.forEach((st, svg) => {
      const len = st.keys[st.keys.length - 1][0];
      let t = now - st.start;
      if (st.loop) t %= len;
      apply(svg, poseAt(st.keys, Math.min(t, len)));
      if (!st.loop && t >= len) {
        active.delete(svg);
        rest(svg);
        if (st.done) st.done();
      }
    });
    if (active.size) { running = true; requestAnimationFrame(tick); }
  };
  const run = (svg, keys, opts = {}) => {
    active.set(svg, { keys, start: performance.now(), loop: !!opts.loop, done: opts.done });
    if (!running && !pausedAt) { running = true; requestAnimationFrame(tick); }
  };

  /** Posa di riposo del personaggio (quella scelta nel catalogo); il saluto continua a salutare. */
  const rest = (svg) => {
    const name = svg.dataset.pose || 'rest';
    const base = RIG.poses[name] || RIG.poses.rest;
    apply(svg, base);
    if (name === 'wave' && !reduce) {
      const a = base, b = Object.assign({}, base, { elR: base.elR - 30, wrR: base.wrR - 20 });
      run(svg, [[0, a], [450, b, 'io'], [900, a, 'io']], { loop: true });
    }
  };

  window.AvatarRig = {
    play(svg, done) {
      const keys = RIG.anims[svg.dataset.anim];
      if (!keys) return 0;
      run(svg, keys, { done });
      return keys[keys.length - 1][0];
    },
    rest,
    setPaused(v) {
      if (v && !pausedAt) pausedAt = performance.now();
      if (!v && pausedAt) {   // riprende da dove era: si sposta in avanti l'inizio di ogni animazione del tempo passato in pausa
        const d = performance.now() - pausedAt;
        pausedAt = 0;
        active.forEach(st => { st.start += d; });
        if (active.size && !running) { running = true; requestAnimationFrame(tick); }
      }
    },
    stop(svg) { active.delete(svg); },
  };
})();
