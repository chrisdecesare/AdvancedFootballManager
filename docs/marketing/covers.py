#!/usr/bin/env python3
"""Genera le copertine di Calcetto Manager: social (Facebook, quadrato, storia), immagine di Google Play e copertina del fascicolo per l'ufficio.
Uso: python3 covers.py   -> cartella copertine/ (PNG esatti nei formati delle piattaforme, PDF della copertina e fascicolo completo)
Richiede: Node con Playwright, pip install pypdf; gli avatar vengono da ../ufficio/poster (avatars.php)."""
import pathlib, subprocess, html, sys
from brand import *

HEAD = 'Chi viene stasera?'
HEAD2 = "Lo sa l'app."
PILL = 'Presenze · Squadre · Classifica · Gazzetta'
CTA = 'Crea la tua lega gratis'
OUT = ROOT / 'copertine'
OUT.mkdir(exist_ok=True)
HERO = avatars('avatars')          # [maglia gialla in salto, maglia azzurra a braccia aperte]
EXTRA = avatars('avatars_extra')   # [rosso, verde, arancio]

BASE = """
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:@W@px;height:@H@px;overflow:hidden}
body{position:relative;font-family:'Nunito',sans-serif;color:#1f1a2e;background:#3fcf7a;-webkit-print-color-adjust:exact}
.stripes{position:absolute;inset:0;background:repeating-linear-gradient(90deg,#3fcf7a 0 @S@px,#36c270 @S@px @S2@px)}
.lines{position:absolute;inset:@M@px;border:@LW@px solid rgba(255,255,255,.85);border-radius:@R@px}
.circle{position:absolute;left:50%;width:@C@px;height:@C@px;margin-left:-@C2@px;border:@LW@px solid rgba(255,255,255,.85);border-radius:50%}
.confetti{position:absolute;inset:0}.confetti svg{width:100%;height:100%}
.sticker{position:absolute;display:flex;align-items:center;gap:@G@px;background:#ffd23f;border:@B@px solid #1f1a2e;border-radius:@R@px;box-shadow:@SH@px @SH@px 0 #1f1a2e;transform:rotate(-2deg)}
.sticker svg{flex:none}
.sticker b{font:400 @LF@px/.95 'Lilita One';letter-spacing:.3px}
.h{position:absolute;font:400 @HF@px/.98 'Lilita One';color:#fff;-webkit-text-stroke:@HS@px #1f1a2e;paint-order:stroke fill;text-shadow:@HT@px @HT@px 0 #1f1a2e;letter-spacing:.5px}
.h em{font-style:normal;color:#ffd23f}
.av{position:absolute}.av svg.avf{width:100%;height:auto;display:block;overflow:visible}
.pill{position:absolute;background:#fff;border:@B@px solid #1f1a2e;border-radius:999px;font-weight:900;box-shadow:@SH2@px @SH2@px 0 #1f1a2e;text-align:center;white-space:nowrap}
.cta{position:absolute;background:#ffd23f;border:@B@px solid #1f1a2e;border-radius:999px;font:400 @CF@px 'Lilita One';box-shadow:@SH@px @SH@px 0 #1f1a2e;text-align:center;white-space:nowrap}
.burst{position:absolute;display:grid;place-items:center;transform:rotate(12deg)}
.burst svg{position:absolute;inset:0;width:100%;height:100%}
.burst span{position:relative;font:400 @BF@px/.95 'Lilita One';text-align:center;transform:rotate(-4deg)}
"""

def fill(s, **kw):
    for k, v in kw.items():
        s = s.replace('@%s@' % k, str(v))
    return s

def css(W, H, S, M, scale, extra=''):
    k = scale
    return font_css() + fill(BASE, W=W, H=H, S=S, S2=S * 2, M=M, LW=round(8 * k, 1), R=round(22 * k, 1), C=round(360 * k), C2=round(180 * k), G=round(26 * k),
                             B=round(7 * k, 1), SH=round(11 * k, 1), SH2=round(7 * k, 1), LF=round(64 * k), HF=round(100 * k), HS=round(10 * k, 1), HT=round(8 * k, 1),
                             CF=round(48 * k), BF=round(40 * k)) + extra

def av(svg, left, bottom, width, extra=''):
    return '<div class="av" style="left:%dpx;bottom:%dpx;width:%dpx;%s">%s</div>' % (left, bottom, width, extra, svg)

def sticker(x, y, badge, wm, pad_v, pad_h):
    return ('<div class="sticker" style="left:%dpx;top:%dpx;padding:%dpx %dpx %dpx %dpx"><div style="width:%dpx;height:%dpx">%s</div><b>Calcetto<br>Manager</b></div>'
            % (x, y, pad_v, pad_h, pad_v, pad_h * 0.6, badge, badge, logo_badge())).replace('Calcetto<br>Manager</b>', 'Calcetto<br>Manager</b>')

def sticker_line(x, y, badge, pad_v, pad_h, font):
    return ('<div class="sticker" style="left:%dpx;top:%dpx;padding:%dpx %dpx"><div style="width:%dpx;height:%dpx">%s</div><b style="font-size:%dpx">Calcetto Manager</b></div>'
            % (x, y, pad_v, pad_h, badge, badge, logo_badge(), font))

def page(W, H, inner, extra_css):
    return '<!doctype html><html lang="it"><head><meta charset="utf-8"><style>%s</style></head><body>%s</body></html>' % (extra_css, inner)

def render_png(name, W, H, htmltext):
    f = OUT / ('_%s.html' % name)
    f.write_text(htmltext, encoding='utf-8')
    out = OUT / ('%s.png' % name)
    subprocess.run(['node', str(ROOT / 'shot.js'), str(f), str(out), str(W), str(H), '1'], check=True)
    f.unlink()
    return out

def burst_el(x, y, d, text, color=YELLOW, fs=None):
    return '<div class="burst" style="left:%dpx;top:%dpx;width:%dpx;height:%dpx"><div style="position:absolute;inset:0">%s</div><span style="%s">%s</span></div>' % (
        x, y, d, d, burst(color), ('font-size:%dpx' % fs) if fs else '', text)

# ------------------------------------------------------------------ Facebook: copertina di pagina 1640 x 624
def facebook():
    W, H = 1640, 624
    c = css(W, H, 104, 28, 1.0, '.h{left:330px;width:980px;text-align:center;top:206px;font-size:96px}')
    conf = '<div class="confetti">%s</div>' % confetti(W, H, 90, 5, avoid=[(300, 20, 1340, 604)], unit=6)
    body = ('<div class="stripes"></div><div class="lines"></div><div class="circle" style="bottom:-190px"></div>' + conf
            + sticker_line(540, 36, 104, 14, 30, 60)
            + '<div class="h">%s<br><em>%s</em></div>' % (html.escape(HEAD), html.escape(HEAD2))
            + '<div class="pill" style="left:430px;width:780px;top:424px;padding:8px 0;font-size:30px">%s</div>' % PILL
            + '<div class="cta" style="left:560px;width:520px;top:514px;padding:4px 0 8px;font-size:44px">%s</div>' % CTA
            + av(EXTRA[0], 6, 24, 200) + av(HERO[0], 140, 6, 215)
            + av(EXTRA[2], 1430, 24, 190) + av(HERO[1], 1260, 6, 215))
    return render_png('facebook-copertina-1640x624', W, H, page(W, H, body, c))

# ------------------------------------------------------------------ Quadrato 1080 x 1080 (Instagram, WhatsApp, Facebook)
def square():
    W = H = 1080
    c = css(W, H, 120, 36, 1.0, '.h{left:40px;width:1000px;text-align:center;top:236px;font-size:106px}.foot{position:absolute;left:0;right:0;bottom:0;height:150px;background:#1f1a2e;border-top:12px solid #ffd23f}')
    conf = '<div class="confetti">%s</div>' % confetti(W, H, 70, 9, avoid=[(60, 220, 1020, 460), (30, 30, 520, 200)], unit=6)
    body = ('<div class="stripes"></div><div class="lines" style="bottom:170px"></div><div class="circle" style="top:300px"></div>' + conf
            + sticker(54, 50, 110, 0, 16, 30)
            + burst_el(790, 36, 220, 'GRATIS<br>per i<br>giocatori', fs=34)
            + '<div class="h">%s<br><em>%s</em></div>' % (html.escape(HEAD), html.escape(HEAD2))
            + av(EXTRA[0], 40, 160, 270) + av(HERO[0], 395, 150, 300) + av(HERO[1], 755, 160, 290)
            + '<div class="foot"><div class="pill" style="left:46px;top:38px;padding:10px 22px;font-size:27px;background:#fff6e0">Presenze · Squadre · Classifica</div>'
              '<div class="cta" style="right:46px;top:30px;padding:8px 34px 12px;font-size:44px;left:auto">%s</div></div>' % CTA)
    return render_png('quadrato-1080x1080', W, H, page(W, H, body, c))

# ------------------------------------------------------------------ Storia 1080 x 1920 (zona sicura: niente testo nei primi e ultimi 250 px)
def story():
    W, H = 1080, 1920
    c = css(W, H, 120, 36, 1.0, '.h{left:40px;width:1000px;text-align:center;top:560px;font-size:150px}')
    conf = '<div class="confetti">%s</div>' % confetti(W, H, 110, 3, avoid=[(60, 540, 1020, 1000), (150, 260, 930, 470)], unit=7)
    body = ('<div class="stripes"></div><div class="lines"></div><div class="circle" style="top:1280px"></div>' + conf
            + sticker(190, 262, 150, 0, 22, 38)
            + '<div class="h">Chi viene<br>stasera?<br><em>Lo sa l\'app.</em></div>'
            + burst_el(770, 1020, 270, 'GRATIS<br>per i<br>giocatori', fs=40)
            + av(EXTRA[0], 20, 380, 300) + av(HERO[0], 370, 380, 340) + av(HERO[1], 760, 380, 300)
            + '<div class="cta" style="left:130px;width:820px;top:1560px;padding:12px 0 18px;font-size:70px">%s</div>' % CTA)
    return render_png('storia-1080x1920', W, H, page(W, H, body, c))

# ------------------------------------------------------------------ Google Play: immagine in primo piano 1024 x 500
def play():
    W, H = 1024, 500
    c = css(W, H, 64, 18, 0.62, '.h{left:40px;width:560px;top:170px;font-size:68px;text-align:left}')
    conf = '<div class="confetti">%s</div>' % confetti(W, H, 50, 14, avoid=[(30, 20, 600, 480)], unit=4)
    body = ('<div class="stripes"></div><div class="lines"></div><div class="circle" style="bottom:-120px"></div>' + conf
            + sticker_line(40, 34, 62, 10, 22, 40)
            + '<div class="h">%s<br><em>%s</em></div>' % (html.escape(HEAD), html.escape(HEAD2))
            + '<div class="pill" style="left:44px;top:400px;padding:7px 22px;font-size:22px">Presenze · Squadre · Classifica</div>'
            + burst_el(884, 26, 120, 'GRATIS', fs=24)
            + av(HERO[0], 596, 18, 215) + av(HERO[1], 780, 18, 205))
    return render_png('google-play-immagine-1024x500', W, H, page(W, H, body, c))

DOCS = [('01', 'Sintesi per la direzione', 'Obiettivo, strade possibili, richiesta di budget'),
        ('02', 'Hosting e Next.js', 'Un solo ambiente Docker per PHP oggi e Next.js domani'),
        ('03', 'App su Google Play e App Store', 'Account, regole, rischio sulle scommesse, notifiche'),
        ('04', 'Conformità legale e privacy', 'GDPR, minori, cookie, giochi, fisco, Altervista'),
        ('05', 'Pubblicità e crescita', 'Ricavi per scenari, strategia, indicatori'),
        ('06', 'Budget e cronoprogramma', 'Costi per fase, tappe e punti di decisione'),
        ('07', 'Fonti e verifiche', 'Link e data di ogni fonte; cosa non è verificato'),
        ('08', 'Pagina per i centri sportivi', 'Bozza di proposta ai gestori dei campi'),
        ('09', 'Piani a pagamento', 'Bozza: gratis, premium per lega, centro sportivo')]

def fascicolo_html():
    items = ''.join('<li><span class="n">%s</span><div><b>%s</b><small>%s</small></div></li>' % (n, html.escape(t), html.escape(d)) for n, t, d in DOCS)
    conf = confetti(216, 130, 60, 21, avoid=[(10, 8, 150, 120)], unit=1.0)
    css_ = font_css() + '''
@page{size:210mm 297mm;margin:0}
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:210mm;height:296mm;overflow:hidden}
body{position:relative;font-family:'Nunito',sans-serif;color:#1f1a2e;background:#fff6e0;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.pitch{position:absolute;left:0;top:0;width:210mm;height:150mm;overflow:hidden;background:repeating-linear-gradient(90deg,#3fcf7a 0 18mm,#36c270 18mm 36mm);border-bottom:2.4mm solid #1f1a2e}
.lines{position:absolute;left:11mm;top:11mm;width:188mm;height:128mm;border:1.6mm solid rgba(255,255,255,.85);border-radius:3mm}
.conf{position:absolute;left:0;top:0;width:210mm;height:130mm}.conf svg{width:100%;height:100%}
.logo{position:absolute;left:18mm;top:16mm;display:flex;align-items:center;gap:4mm;background:#ffd23f;border:1.2mm solid #1f1a2e;border-radius:6mm;padding:3mm 8mm 3mm 4mm;box-shadow:2mm 2mm 0 #1f1a2e;transform:rotate(-2deg)}
.logo svg{width:28mm;height:28mm}.logo b{font:400 30pt/.95 'Lilita One'}
h1{position:absolute;left:18mm;top:58mm;width:130mm;font:400 46pt/.98 'Lilita One';color:#fff;-webkit-text-stroke:1.1mm #1f1a2e;paint-order:stroke fill;text-shadow:1.4mm 1.4mm 0 #1f1a2e}
h1 em{font-style:normal;color:#ffd23f}
.sub{position:absolute;left:18mm;top:98mm;width:80mm;font-weight:900;font-size:10.5pt;line-height:1.3;background:#fff;border:1mm solid #1f1a2e;border-radius:4mm;padding:3.2mm 4.5mm;box-shadow:1.6mm 1.6mm 0 #1f1a2e}
.av{position:absolute;bottom:4mm}.av svg.avf{width:100%;height:auto;display:block;overflow:visible}
.list{position:absolute;left:18mm;right:18mm;top:162mm;list-style:none;display:grid;grid-template-columns:1fr 1fr;grid-template-rows:repeat(5,auto);grid-auto-flow:column;gap:4.2mm 8mm}
.list li{display:flex;gap:3mm;align-items:flex-start}
.n{flex:none;display:grid;place-items:center;width:9mm;height:9mm;background:#ffd23f;border:.8mm solid #1f1a2e;border-radius:2.4mm;box-shadow:.9mm .9mm 0 #1f1a2e;font:400 11pt 'Lilita One'}
.list b{display:block;font:400 13pt/1.05 'Lilita One'}.list small{display:block;font-weight:700;font-size:8.6pt;line-height:1.25;color:#4a4360;margin-top:.6mm}
.box{position:absolute;left:18mm;right:18mm;top:236mm;background:#fff;border:1mm solid #1f1a2e;border-radius:4mm;box-shadow:1.6mm 1.6mm 0 #1f1a2e;padding:4mm 6mm;font-weight:700;font-size:10pt;line-height:1.35}.box b{font:400 13pt 'Lilita One';display:block;margin-bottom:1mm}
.foot{position:absolute;left:0;right:0;bottom:0;height:24mm;background:#1f1a2e;border-top:2.4mm solid #ffd23f;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 18mm;font-weight:900;font-size:9.5pt}
.foot span{color:#ffd23f}
'''
    av_ = ''.join('<div class="av" style="left:%dmm;width:%dmm">%s</div>' % (l, w, a) for (l, w), a in zip(((100, 46), (136, 46), (168, 40)), (HERO[0], HERO[1], EXTRA[1])))
    body = ('<div class="pitch"><div class="lines"></div><div class="conf">%s</div><div class="logo">%s<b>Calcetto<br>Manager</b></div>'
            '<h1>Piano di <em>crescita</em></h1><div class="sub">Server, app negli store, pubblicità e conformità: la richiesta di budget per una prova, in nove documenti.</div>%s</div>'
            '<ul class="list">%s</ul><div class="box"><b>La richiesta in breve</b>Scenario minimo: server proprio e app Android, circa 90–310 € nel primo anno più 25 USD (Google Play), in circa 12–17 giornate di lavoro. Stime da riverificare (documento 06). Next.js è facoltativo e si decide dopo.</div><div class="foot"><div>Documento per la direzione · riservato</div><div><span>Versione 1.0</span> · 18 ottobre 2026</div></div>') % (conf, logo_badge(), av_, items)
    return '<!doctype html><html lang="it"><head><meta charset="utf-8"><style>%s</style></head><body>%s</body></html>' % (css_, body)

def fascicolo():
    from pypdf import PdfReader, PdfWriter
    f = OUT / '_fascicolo.html'
    f.write_text(fascicolo_html(), encoding='utf-8')
    cover = OUT / 'copertina-fascicolo-A4.pdf'
    subprocess.run(['node', str(POSTER / 'topdf.js'), str(f), str(cover), 'A4', ''], check=True)
    f.unlink()
    w = PdfWriter()
    r = PdfReader(str(cover)); w.append(r, outline_item='Copertina')
    base = ROOT.parent / 'ufficio'
    names = {n: t for n, t, d in DOCS}
    for pdf in sorted(base.glob('0[1-9]-*.pdf')):
        w.append(PdfReader(str(pdf)), outline_item='%s · %s' % (pdf.name[:2], names.get(pdf.name[:2], pdf.stem)))
    w.add_metadata({'/Title': 'Calcetto Manager: piano di crescita (fascicolo per la direzione)'})
    out = OUT / 'fascicolo-ufficio-completo.pdf'
    with open(out, 'wb') as fh:
        w.write(fh)
    return cover, out

if __name__ == '__main__':
    for fn in (facebook, square, story, play):
        print('PNG:', fn().name)
    print('PDF:', *[x.name for x in fascicolo()])
