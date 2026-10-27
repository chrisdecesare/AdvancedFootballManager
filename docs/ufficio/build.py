#!/usr/bin/env python3
"""Genera i PDF dai file Markdown in src/ (Markdown -> HTML -> Chromium). Uso: python3 build.py [nome ...]"""
import re, subprocess, sys, pathlib, datetime, json
import markdown

root = pathlib.Path(__file__).parent
src = root / 'src'
out = root
VERSION = 'v1.0'
DATE = '18 ottobre 2026'

CSS = """
@page { size: A4; margin: 22mm 18mm 22mm 18mm; }
* { box-sizing: border-box; }
body { font: 10.5pt/1.5 'Liberation Sans', 'DejaVu Sans', Arial, sans-serif; color: #1f1a2e; }
h1 { font-size: 22pt; margin: 0 0 4pt; color: #1f1a2e; }
h2 { font-size: 15pt; margin: 20pt 0 6pt; padding-bottom: 3pt; border-bottom: 2pt solid #ffd23f; page-break-after: avoid; }
h3 { font-size: 12pt; margin: 14pt 0 4pt; color: #3a3350; page-break-after: avoid; }
p { margin: 5pt 0; }
ul, ol { margin: 4pt 0 6pt 0; padding-left: 18pt; }
li { margin: 2pt 0; }
table { border-collapse: collapse; width: 100%; margin: 8pt 0; font-size: 9pt; page-break-inside: auto; }
th { background: #1f1a2e; color: #fff; text-align: left; padding: 4pt 6pt; }
td { border-bottom: 0.6pt solid #cfc9de; padding: 4pt 6pt; vertical-align: top; }
tr { page-break-inside: avoid; }
tr:nth-child(even) td { background: #faf7ee; }
blockquote { margin: 8pt 0; padding: 6pt 10pt; background: #fff6d6; border-left: 3pt solid #ffd23f; }
code { font: 9pt 'DejaVu Sans Mono', monospace; background: #f1eefa; padding: 0 2pt; }
pre { background: #f1eefa; padding: 6pt; font-size: 8.5pt; white-space: pre-wrap; }
a { color: #1a56c4; text-decoration: none; word-break: break-all; }
.cover { padding: 6pt 0 10pt; border-bottom: 4pt solid #ffd23f; margin-bottom: 12pt; }
.cover .sub { font-size: 12pt; color: #4a4360; }
.cover .meta { font-size: 9pt; color: #6d6585; margin-top: 6pt; }
.toc { background: #faf7ee; border: 0.6pt solid #cfc9de; padding: 6pt 12pt; margin: 10pt 0 14pt; font-size: 9.5pt; }
.toc ul { list-style: none; padding-left: 0; } .toc ul ul { padding-left: 14pt; }
.toc a { color: #1f1a2e; }
.box { border: 1pt solid #1f1a2e; border-radius: 4pt; padding: 6pt 10pt; margin: 8pt 0; background: #fffdf5; }
"""

def build(md_path):
    text = md_path.read_text(encoding='utf-8')
    meta = {}
    m = re.match(r'^---\n(.*?)\n---\n', text, re.S)
    if m:
        for line in m.group(1).splitlines():
            k, _, v = line.partition(':')
            meta[k.strip()] = v.strip()
        text = text[m.end():]
    md = markdown.Markdown(extensions=['tables', 'toc', 'sane_lists', 'attr_list', 'md_in_html'], extension_configs={'toc': {'toc_depth': '2-3'}})
    body = md.convert(text)
    toc = md.toc if meta.get('toc', 'no') == 'yes' else ''
    cover = ('<div class="cover"><h1>%s</h1><div class="sub">%s</div><div class="meta">Calcetto Manager · %s · %s</div></div>'
             % (meta.get('title', md_path.stem), meta.get('subtitle', ''), VERSION, DATE))
    html = '<!doctype html><html lang="it"><head><meta charset="utf-8"><title>%s</title><style>%s</style></head><body>%s%s%s</body></html>' % (
        meta.get('title', md_path.stem), CSS, cover, ('<div class="toc"><b>Indice</b>' + toc + '</div>') if toc else '', body)
    h = out / ('_' + md_path.stem + '.html')
    h.write_text(html, encoding='utf-8')
    pdf = out / (md_path.stem + '.pdf')
    footer = '<div style="font-size:8px;width:100%%;text-align:center;color:#6d6585;font-family:sans-serif">%s · %s · pagina <span class="pageNumber"></span> di <span class="totalPages"></span></div>' % (meta.get('title', md_path.stem), VERSION)
    subprocess.run(['node', str(root / 'topdf.js'), str(h), str(pdf), footer], check=True)
    h.unlink()
    return pdf

if __name__ == '__main__':
    names = sys.argv[1:]
    files = sorted(src.glob('*.md'))
    for f in files:
        if names and f.stem not in names:
            continue
        print('PDF:', build(f))
