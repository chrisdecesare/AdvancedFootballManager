/*
 * Player delle esultanze in pixel art: ogni <svg data-seq> ha i fotogrammi come <g data-f>; ad ogni passo si mostra il fotogramma
 * del passo e si applica al <g data-sprite> lo spostamento (e la rotazione a scatti di 90°) già calcolati in PHP.
 */
(() => {
  const play = (svg, loop) => {
    const seq = JSON.parse(svg.dataset.seq);
    const sprite = svg.querySelector('[data-sprite]');
    const frames = [...sprite.querySelectorAll('[data-f]')];
    const shadow = svg.querySelector('[data-shadow]');
    let i = 0;
    const step = () => {
      const [name, ms, tr] = seq[i];
      frames.forEach(f => f.setAttribute('display', f.dataset.f === name ? 'inline' : 'none'));
      sprite.setAttribute('transform', tr);
      const dy = +(tr.match(/translate\(\S+ (-?\d+)/) || [0, 0])[1];
      if (shadow) shadow.setAttribute('opacity', (0.22 * (1 + dy / 40)).toFixed(3));
      i++;
      if (i < seq.length) setTimeout(step, ms);
      else if (loop) { i = 0; setTimeout(step, ms); }
    };
    step();
  };
  window.PixelAvatar = { play };
  document.querySelectorAll('svg[data-seq][data-autoplay]').forEach(s => play(s, true));
})();
