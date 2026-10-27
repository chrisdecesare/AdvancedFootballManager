// html -> png esatto. Uso: node shot.js in.html out.png larghezza altezza [scala]
const { chromium } = require('playwright');
(async () => {
  const [, , input, output, w, h, scale] = process.argv;
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: +w, height: +h }, deviceScaleFactor: +(scale || 1) });
  await p.goto('file://' + require('path').resolve(input));
  await p.evaluate(() => document.fonts.ready);
  await p.screenshot({ path: output, clip: { x: 0, y: 0, width: +w, height: +h } });
  await b.close();
})();
