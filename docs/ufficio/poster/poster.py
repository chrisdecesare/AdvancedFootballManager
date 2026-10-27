#!/usr/bin/env python3
"""Locandina A4 per i centri sportivi: due PDF (casa e tipografia).
Uso: python3 poster.py   (legge poster.json: url del QR e contatti; richiede avatars.json, vedi avatars.php)
 - locandina-A4-casa.pdf          A4 per la stampante di casa/ufficio (margine bianco di sicurezza)
 - locandina-A4-tipografia.pdf    A4 con 3 mm di abbondanza e crocini di taglio (pagina 226 x 313 mm; TrimBox e BleedBox impostati)
Se nel poster.json ci sono ancora i segnaposto, compare il timbro «BOZZA» e il file NON va stampato."""
import json, pathlib, subprocess, html, sys
import segno
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / 'marketing'))
import brand
from pypdf import PdfReader, PdfWriter
from pypdf.generic import RectangleObject

root = pathlib.Path(__file__).parent
cfg = json.loads((root / 'poster.json').read_text(encoding='utf-8'))
avatars = json.loads((root / 'avatars.json').read_text(encoding='utf-8'))
PREVIEW = None
if len(sys.argv) > 2 and sys.argv[1] == '--preview':
    PREVIEW = pathlib.Path(sys.argv[2])
    cfg = {'url': 'https://example.org/calcetto', 'nome': 'Nome Cognome', 'telefono': '000 0000000', 'email': 'nome@example.org'}
draft = any(('[' in str(v)) or ('INSERISCI' in str(v)) for v in cfg.values())
esc = lambda s: html.escape(str(s))
qr = segno.make(cfg['url'], error='h')
qr_svg = qr.svg_inline(scale=1, border=0, dark='#1f1a2e', light='#ffffff', omitsize=True)
url_show = cfg['url'].replace('https://', '').replace('http://', '').rstrip('/')

def icon(kind):
    if kind == 'check':
        return '<svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="19" fill="#38d178" stroke="#1f1a2e" stroke-width="3.5"/><path d="M14 25l7 7 13-15" fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
    if kind == 'teams':
        return '<svg viewBox="0 0 48 48"><circle cx="18" cy="24" r="14" fill="#53c8f5" stroke="#1f1a2e" stroke-width="3.5"/><circle cx="30" cy="24" r="14" fill="#ff8c42" stroke="#1f1a2e" stroke-width="3.5" opacity=".92"/></svg>'
    return '<svg viewBox="0 0 48 48"><path d="M14 8h20v10a10 10 0 0 1-20 0z" fill="#ffd23f" stroke="#1f1a2e" stroke-width="3.5" stroke-linejoin="round"/><path d="M14 11H7v4a8 8 0 0 0 8 8M34 11h7v4a8 8 0 0 1-8 8" fill="none" stroke="#1f1a2e" stroke-width="3.5"/><path d="M24 28v8M16 40h16" stroke="#1f1a2e" stroke-width="4" stroke-linecap="round"/></svg>'

ball = '<svg viewBox="0 0 100 100"><circle cx="51" cy="51" r="44" fill="#1f1a2e"/><circle cx="46" cy="45" r="42" fill="#fff" stroke="#1f1a2e" stroke-width="6"/><circle cx="46" cy="45" r="29" fill="#fff" stroke="#1f1a2e" stroke-width="5"/><g stroke="#1f1a2e" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"><line x1="46.0" y1="32.0" x2="46.0" y2="16.0"/><line x1="58.4" y1="41.0" x2="73.6" y2="36.0"/><line x1="53.6" y1="55.5" x2="63.0" y2="68.5"/><line x1="38.4" y1="55.5" x2="29.0" y2="68.5"/><line x1="33.6" y1="41.0" x2="18.4" y2="36.0"/><polygon points="46.0,32.0 58.4,41.0 53.6,55.5 38.4,55.5 33.6,41.0" fill="#fff"/></g></svg>'

fonts = root / 'fonts'
CSS = """
@font-face { font-family: 'Lilita One'; src: url('fonts/lilita-one-latin-400-normal.woff2') format('woff2'); }
@font-face { font-family: 'Nunito'; font-weight: 700; src: url('fonts/nunito-latin-700-normal.woff2') format('woff2'); }
@font-face { font-family: 'Nunito'; font-weight: 900; src: url('fonts/nunito-latin-900-normal.woff2') format('woff2'); }
@page { size: %(pw)s %(ph)s; margin: 0; }
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { width: %(pw)s; height: calc(%(ph)s - 1mm); overflow: hidden; background: %(pagebg)s; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
:root { --ink: #1f1a2e; --yellow: #ffd23f; --paper: #fff6e0; --grass: #38d178; --sky: #53c8f5; --orange: #ff8c42; --red: #e63946; }
.wrap { position: absolute; left: 0; top: 0; width: %(pw)s; height: calc(%(ph)s - 1mm); overflow: hidden; }
.stage { position: absolute; left: %(ox)s; top: %(oy)s; width: 216mm; height: 303mm; overflow: hidden; transform-origin: 0 0; transform: scale(%(scale)s); font-family: 'Nunito', sans-serif; color: var(--ink); background: var(--paper); }
/* il campo: strisce, linee e cerchio di centrocampo */
.pitch { position: absolute; left: 0; top: 0; width: 216mm; height: 178mm; overflow: hidden; background: repeating-linear-gradient(90deg, #3fcf7a 0 18mm, #36c270 18mm 36mm); border-bottom: 2.4mm solid var(--ink); }
.lines { position: absolute; left: 13mm; top: 13mm; width: 190mm; height: 152mm; border: 1.6mm solid rgba(255,255,255,.85); border-radius: 3mm; }
.circle { position: absolute; left: 50%%; bottom: -40mm; width: 80mm; height: 80mm; margin-left: -40mm; border: 1.6mm solid rgba(255,255,255,.85); border-radius: 50%%; }
.logo { position: absolute; left: 14mm; top: 15mm; display: flex; align-items: center; gap: 5mm; background: var(--yellow); border: 1.2mm solid var(--ink); border-radius: 7mm; padding: 4mm 9mm 4mm 5mm; box-shadow: 2.2mm 2.2mm 0 var(--ink); transform: rotate(-2deg); }
.logo svg { width: 38mm; height: 38mm; flex: none; }
.logo b { display: block; font: 400 42pt/0.95 'Lilita One'; letter-spacing: .3pt; }
h1 { position: absolute; left: 16mm; width: 130mm; top: 67mm; font: 400 45pt/0.98 'Lilita One'; color: #fff; -webkit-text-stroke: 1mm var(--ink); paint-order: stroke fill; text-shadow: 1.3mm 1.3mm 0 var(--ink); letter-spacing: .4pt; }
h1 em { font-style: normal; color: var(--yellow); }
.lead { position: absolute; left: 16mm; width: 86mm; top: 114mm; font-weight: 900; font-size: 12pt; line-height: 1.25; background: #fff; border: 1mm solid var(--ink); border-radius: 4mm; padding: 3.5mm 5mm; box-shadow: 1.6mm 1.6mm 0 var(--ink); }
.conf { position: absolute; left: 0; top: 0; width: 216mm; height: 178mm; }
.conf svg { width: 100%%; height: 100%%; }
.burst2 { position: absolute; left: 150mm; top: 14mm; width: 52mm; height: 52mm; display: grid; place-items: center; transform: rotate(12deg); }
.burst2 .bs { position: absolute; left: 0; top: 0; width: 100%%; height: 100%%; }
.burst2 .bs svg { width: 100%%; height: 100%%; }
.burst2 span { position: relative; font: 400 14pt/0.95 'Lilita One'; text-align: center; transform: rotate(-4deg); }
.team { position: absolute; left: 0; right: 0; top: 0; bottom: 0; }
.team .av { position: absolute; bottom: 6mm; width: 64mm; filter: drop-shadow(0 0 0 transparent); }
.team svg.avf { width: 100%%; height: auto; display: block; overflow: visible; }
.team .ball { position: absolute; width: 13mm; height: 13mm; }
.team .ball svg { width: 100%%; height: 100%%; }
.pad { position: absolute; left: 16mm; right: 16mm; top: 187mm; }
.cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 5mm; }
.card { background: #fff; border: 1mm solid var(--ink); border-radius: 4mm; padding: 3.2mm 4mm 3.8mm; box-shadow: 1.6mm 1.6mm 0 var(--ink); }
.card svg { width: 11mm; height: 11mm; display: block; margin-bottom: 1.5mm; }
.card b { display: block; font: 400 14pt/1.05 'Lilita One'; margin-bottom: 1.5mm; }
.card span { font-weight: 700; font-size: 9.5pt; line-height: 1.25; }
.centre { margin-top: 4.5mm; font-weight: 900; font-size: 11pt; line-height: 1.3; }
.centre u { text-decoration-color: var(--yellow); text-decoration-thickness: 1.2mm; text-underline-offset: 1mm; }
.foot { position: absolute; left: 0; right: 0; bottom: 0; height: 54mm; background: var(--ink); color: #fff; border-top: 2.4mm solid var(--yellow); padding: 4mm 16mm 9mm; display: flex; gap: 8mm; align-items: center; }
.qr { flex: none; width: 36mm; height: 36mm; background: #fff; border: 1mm solid var(--yellow); border-radius: 4mm; padding: 3mm; }
.qr svg { width: 100%%; height: 100%%; display: block; }
.cta { flex: 1; min-width: 0; }
.cta .big { font: 400 24pt/1 'Lilita One'; color: var(--yellow); }
.cta .url { font-weight: 900; font-size: 11pt; margin: 2mm 0 3mm; word-break: break-all; }
.cta .who { font-weight: 700; font-size: 10pt; line-height: 1.45; color: #e9e4f7; }
.cta .who b { color: #fff; font-weight: 900; }
.tiny { position: absolute; right: 16mm; bottom: 4.5mm; font-weight: 700; font-size: 7.5pt; color: #b9b2d0; }
.stamp { position: absolute; left: 22mm; top: 128mm; transform: rotate(-22deg); border: 2mm solid var(--red); color: var(--red); background: rgba(255,255,255,.88); border-radius: 4mm; padding: 4mm 8mm; font: 400 30pt/1.05 'Lilita One'; text-align: center; z-index: 9; }
.stamp small { display: block; font: 900 12pt 'Nunito'; }
.marks { position: absolute; left: 0; top: 0; width: 226mm; height: 313mm; }
"""

AV_POS = [(98, 0), (142, 0)]   # sinistra in mm e altezza da terra: file irregolare per dare vita

ballsvg = '<svg viewBox="0 0 48 48"><ellipse cx="24" cy="43" rx="15" ry="3" fill="#1f1a2e" opacity=".25"/><circle cx="24" cy="23" r="19" fill="#fff" stroke="#1f1a2e" stroke-width="3.2"/><path d="M24 14l8 5.5-3 9.5H19l-3-9.5z" fill="#1f1a2e"/><path d="M24 14V5M32 19.5l9-3M29 29l5 8M19 29l-5 8M16 19.5l-9-3" stroke="#1f1a2e" stroke-width="2.6" stroke-linecap="round"/></svg>'

def stage(pw, ph, ox, oy, scale, pagebg, extra=''):
    avs = ''.join('<div class="av" style="left:%dmm">%s</div>' % (x, svg) for (x, y), svg in zip(AV_POS, avatars))
    avs += '<div class="ball" style="left:131mm;bottom:3mm">' + ballsvg + '</div>'
    css = CSS % dict(pw=pw, ph=ph, ox=ox, oy=oy, scale=scale, pagebg=pagebg)
    body = f'''
<div class="wrap"><div class="stage">
  <div class="pitch"><div class="lines"></div><div class="circle"></div><div class="conf">{brand.confetti(216, 178, 70, 17, avoid=[(14, 10, 128, 150)], unit=1.0)}</div><div class="burst2"><div class="bs">{brand.burst()}</div><span>GRATIS<br>per i<br>giocatori</span></div>
    <div class="logo">{ball}<b>Calcetto<br>Manager</b></div>
    <h1>Chi viene stasera? Lo sa <em>l'app.</em></h1>
    <div class="lead">Presenze con promemoria, squadre equilibrate e classifica per i gruppi che giocano da voi. <u>Gratis</u> per i giocatori.</div>
    <div class="team">{avs}</div>
  </div>
  <div class="pad">
    <div class="cards">
      <div class="card">{icon('check')}<b>Chi c'è, chi no</b><span>Ognuno conferma dal telefono. Chi non risponde riceve il promemoria.</span></div>
      <div class="card">{icon('teams')}<b>Squadre giuste</b><span>L'app forma due squadre equilibrate in base al livello dei giocatori.</span></div>
      <div class="card">{icon('cup')}<b>Classifica e Gazzetta</b><span>Gol, assist, voti, MVP e la Gazzetta della settimana. Ogni partita conta.</span></div>
    </div>
    <p class="centre"><u>Sei il gestore del campo?</u> Parliamone: una pagina del centro e un torneo con i gruppi che giocano da te, da costruire insieme.</p>
  </div>
  <div class="foot">
    <div class="qr">{qr_svg}</div>
    <div class="cta"><div class="big">Inquadra e inizia.</div>
      <div class="url">{esc(url_show)}</div>
      <div class="who"><b>{esc(cfg['nome'])}</b><br>{esc(cfg['telefono'])} · {esc(cfg['email'])}</div></div>
  </div>
  <div class="tiny">Calcetto Manager · gratuito per i giocatori</div>
  {'<div class="stamp">BOZZA<small>dati da inserire: non stampare</small></div>' if draft else ''}
</div></div>{extra}'''
    return f'<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Locandina</title><style>{css}</style></head><body>{body}</body></html>'

def marks():
    # crocini di taglio: pagina 226 x 313 mm, taglio a 8 mm dai bordi, abbondanza 3 mm (da 5 mm); i segni stanno nella fascia esterna
    L = []
    for x in (8, 218):
        for y0, y1 in ((0.8, 4), (309, 312.2)):
            L.append(f'<line x1="{x}" y1="{y0}" x2="{x}" y2="{y1}"/>')
    for y in (8, 305):
        for x0, x1 in ((0.8, 4), (222, 225.2)):
            L.append(f'<line x1="{x0}" y1="{y}" x2="{x1}" y2="{y}"/>')
    return f'<svg class="marks" viewBox="0 0 226 313" fill="none" stroke="#000" stroke-width="0.25">{"".join(L)}</svg>'

def render(htmlText, name, w, h):
    f = root / f'_{name}.html'
    f.write_text(htmlText, encoding='utf-8')
    out = (PREVIEW or root) / f'{name}.pdf'
    subprocess.run(['node', str(root / 'topdf.js'), str(f), str(out), w, h], check=True)
    f.unlink()
    return out

# 1) A4 per la stampante di casa: l'opera tagliata al formato e ridotta del 3,5% con un bordo bianco di sicurezza
casa = render(stage('210mm', '297mm', '4.2mm', '4.2mm', 0.945, '#ffffff'), 'locandina-A4-casa', 'A4', '')
# 2) per la tipografia: 226 x 313 mm, abbondanza 3 mm, crocini
tipo = render(stage('226mm', '313mm', '5mm', '5mm', 1, '#ffffff', marks()), 'locandina-A4-tipografia', '226mm', '313mm')

mm = lambda v: v * 72 / 25.4
r = PdfReader(str(tipo)); w = PdfWriter()
p = r.pages[0]
p.trimbox = RectangleObject([mm(8), mm(313 - 305), mm(218), mm(313 - 8)])
p.bleedbox = RectangleObject([mm(5), mm(313 - 308), mm(221), mm(313 - 5)])
w.add_page(p); w.add_metadata({'/Title': 'Locandina A4 Calcetto Manager (tipografia, 3 mm di abbondanza)'})
with open(tipo, 'wb') as fh:
    w.write(fh)
print('PDF:', casa.name, tipo.name, '(BOZZA)' if draft else '')
