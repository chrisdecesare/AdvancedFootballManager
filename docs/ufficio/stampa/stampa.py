#!/usr/bin/env python3
"""Locandine e biglietti da stampare per i giocatori (con QR code). Uso: python3 stampa.py   (serve: pip install segno==1.6.6 fonttools==4.55.3 brotli==1.1.0, e typst)
Legge stampa.json (indirizzo del sito, link di Google Play quando c'è) e scrive in uscita/:
  locandina-A4.pdf            locandina A4 per la stampante di casa o d'ufficio (nessun margine da tagliare)
  locandina-strappi-A4.pdf    locandina A4 con 8 linguette da strappare in basso, ognuna con il suo QR
  biglietti-foglio-A4.pdf     foglio A4 con 10 biglietti 85 x 55 mm e linee di taglio
  cartoline-A6-tipografia.pdf cartolina A6 orizzontale 148 x 105 mm, 2 pagine (fronte e retro), 3 mm di abbondanza e crocini
  cartoline-foglio-A4-casa.pdf foglio A4 orizzontale con 4 cartoline (solo fronte, ridotte al 94%) e linee di taglio
Ogni formato ha un QR con un parametro diverso (utm_source=locandina|strappo|biglietto|cartolina): si vede nelle statistiche quale funziona di più.
I font (Lilita One, Nunito: licenza OFL) e i due avatar (dell'app) vengono dalla cartella ../poster."""
import json, pathlib, re, subprocess, shutil, sys
import segno
from fontTools.ttLib import TTFont

root = pathlib.Path(__file__).parent
poster = root.parent / 'poster'
cfg = json.loads((root / 'stampa.json').read_text(encoding='utf-8'))
build = root / '_build'
out = root / 'uscita'
if build.exists():
    shutil.rmtree(build)
(build / 'fonts').mkdir(parents=True)
out.mkdir(exist_ok=True)

# font: Typst non legge woff2, si convertono in ttf
for f in (poster / 'fonts').glob('*.woff2'):
    t = TTFont(str(f)); t.flavor = None; t.save(str(build / 'fonts' / (f.stem + '.ttf')))

# QR: uno per formato, correzione d'errore alta (regge piega, macchie e stampa piccola)
base = cfg['url'].rstrip('/')
for name in ('locandina', 'strappo', 'biglietto', 'cartolina'):
    segno.make(f'{base}/?utm_source={name}', error='h').save(
        str(build / f'qr-{name}.svg'), scale=1, border=0, dark='#1f1a2e', light='#ffffff', xmldecl=False, nl=False)

# avatar dell'app (SVG incorporati): servono i namespace per Typst
for i, svg in enumerate(json.loads((poster / 'avatars.json').read_text(encoding='utf-8'))):
    svg = re.sub(r'(\sdata-[\w-]+)(?=[\s/>])', r'\1="1"', svg)   # attributi senza valore (data-shadow): l'SVG di Typst li rifiuta
    (build / f'av{i}.svg').write_text(svg.replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ', 1), encoding='utf-8')

shutil.copy(root / 'stampa.typ', build / 'stampa.typ')
shutil.copy(root / 'logo.svg', build / 'logo.svg')   # il pallone del logo (stesso di ../poster/poster.py)
site = base.replace('https://', '').replace('http://', '')
for doc, name in (('locandina', 'locandina-A4'), ('strappi', 'locandina-strappi-A4'), ('biglietti', 'biglietti-foglio-A4'),
                  ('cartoline-tipografia', 'cartoline-A6-tipografia'), ('cartoline-casa', 'cartoline-foglio-A4-casa')):
    cmd = ['typst', 'compile', '--font-path', str(build / 'fonts'), '--ignore-system-fonts',
           '--input', f'doc={doc}', '--input', f'site={site}', '--input', f'play={cfg.get("google_play", "")}',
           str(build / 'stampa.typ'), str(out / f'{name}.pdf')]
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode:
        sys.exit(r.stderr[:1500])
    print('PDF:', out / f'{name}.pdf')
# immagine in evidenza di Google Play (PNG 1024 x 500, a 72 ppi 1 pt = 1 px)
r = subprocess.run(['typst', 'compile', '--font-path', str(build / 'fonts'), '--ignore-system-fonts', '--input', 'doc=feature', '--input', f'site={site}', '--format', 'png', '--ppi', '72',
                    str(build / 'stampa.typ'), str(out / 'play-immagine-in-evidenza-1024x500.png')], capture_output=True, text=True)
if r.returncode:
    sys.exit(r.stderr[:1500])
print('PNG:', out / 'play-immagine-in-evidenza-1024x500.png')
shutil.rmtree(build)
