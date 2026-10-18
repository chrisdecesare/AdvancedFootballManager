// html -> pdf con Chromium. Uso: node topdf.js in.html out.pdf larghezza altezza (es. 226mm 313mm)
const { chromium } = require('playwright');
(async () => {
  const [, , input, output, w, h] = process.argv;
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage();
  await p.goto('file://' + require('path').resolve(input));
  await p.evaluate(() => document.fonts.ready);
  const size = w === 'A4' ? { format: 'A4' } : { width: w, height: h };
  await p.pdf({ path: output, ...size, printBackground: true, margin: { top: '0', right: '0', bottom: '0', left: '0' }, preferCSSPageSize: false });
  await b.close();
})();
