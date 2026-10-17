// html -> pdf con Chromium (Playwright). Uso: node topdf.js in.html out.pdf "<footer html>"
const { chromium } = require('playwright');
(async () => {
  const [, , input, output, footer] = process.argv;
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage();
  await p.goto('file://' + require('path').resolve(input));
  await p.pdf({ path: output, format: 'A4', printBackground: true, displayHeaderFooter: true,
    headerTemplate: '<span></span>', footerTemplate: footer, margin: { top: '20mm', bottom: '20mm', left: '16mm', right: '16mm' } });
  await b.close();
})();
