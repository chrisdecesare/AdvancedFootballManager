"""Pezzi grafici comuni di Calcetto Manager per locandina, copertine social, immagine dello store e fascicolo."""
import json, math, pathlib, random

ROOT = pathlib.Path(__file__).resolve().parent
POSTER = ROOT.parent / 'ufficio' / 'poster'
FONTS = POSTER / 'fonts'
YELLOW, INK, PAPER, GREEN, SKY, ORANGE, RED = '#ffd23f', '#1f1a2e', '#fff6e0', '#38d178', '#53c8f5', '#ff8c42', '#e63946'

def font_css():
    f = lambda n: (FONTS / n).as_uri()
    return ("@font-face{font-family:'Lilita One';src:url('%s') format('woff2');}"
            "@font-face{font-family:'Nunito';font-weight:700;src:url('%s') format('woff2');}"
            "@font-face{font-family:'Nunito';font-weight:900;src:url('%s') format('woff2');}"
            % (f('lilita-one-latin-400-normal.woff2'), f('nunito-latin-700-normal.woff2'), f('nunito-latin-900-normal.woff2')))

def avatars(name='avatars'):
    return json.loads((POSTER / (name + '.json')).read_text(encoding='utf-8'))

def logo_badge():
    """Il logo dell'app (icona 512): disco bianco con bordo scuro, ombra e pallone, in vettoriale."""
    cx, cy, R = 46, 45, 13
    pts = [(cx + R * math.cos(math.radians(-90 + 72 * k)), cy + R * math.sin(math.radians(-90 + 72 * k))) for k in range(5)]
    spokes = ''.join('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>' % (x, y, cx + 29 * math.cos(math.radians(-90 + 72 * k)), cy + 29 * math.sin(math.radians(-90 + 72 * k))) for k, (x, y) in enumerate(pts))
    poly = ' '.join('%.1f,%.1f' % q for q in pts)
    return ('<svg viewBox="0 0 100 100"><circle cx="51" cy="51" r="44" fill="%s"/><circle cx="46" cy="45" r="42" fill="#fff" stroke="%s" stroke-width="6"/>'
            '<circle cx="46" cy="45" r="29" fill="#fff" stroke="%s" stroke-width="5"/><g stroke="%s" stroke-width="5" stroke-linecap="round" stroke-linejoin="round">%s'
            '<polygon points="%s" fill="#fff"/></g></svg>') % (INK, INK, INK, INK, spokes, poly)

def ball():
    return ('<svg viewBox="0 0 48 48"><ellipse cx="24" cy="43" rx="15" ry="3" fill="#1f1a2e" opacity=".25"/><circle cx="24" cy="23" r="19" fill="#fff" stroke="#1f1a2e" stroke-width="3.2"/>'
            '<path d="M24 14l8 5.5-3 9.5H19l-3-9.5z" fill="#1f1a2e"/><path d="M24 14V5M32 19.5l9-3M29 29l5 8M19 29l-5 8M16 19.5l-9-3" stroke="#1f1a2e" stroke-width="2.6" stroke-linecap="round"/></svg>')

def burst(color=YELLOW):
    """Stella adesiva (starburst) 100x100, da riempire con un testo sopra."""
    def poly(r1, r2, n=18, dx=0, dy=0):
        out = []
        for i in range(n * 2):
            r = r1 if i % 2 == 0 else r2
            a = math.radians(-90 + i * 180 / n)
            out.append('%.1f,%.1f' % (50 + dx + r * math.cos(a), 50 + dy + r * math.sin(a)))
        return ' '.join(out)
    return ('<svg viewBox="0 0 100 100"><polygon points="%s" fill="%s"/><polygon points="%s" fill="%s" stroke="%s" stroke-width="3" stroke-linejoin="round"/></svg>'
            % (poly(47, 38, dx=3, dy=3), INK, poly(47, 38), color, INK))

def confetti(w, h, n=70, seed=11, avoid=(), unit=1.0):
    """Coriandoli sparsi (svg w x h nelle stesse unità); avoid = rettangoli (x0, y0, x1, y1) da lasciare liberi."""
    rnd = random.Random(seed)
    cols = [YELLOW, '#fff', SKY, ORANGE, '#ff6b9a', '#a67cf2']
    out = []
    tries = 0
    while len(out) < n and tries < n * 20:
        tries += 1
        x, y = rnd.uniform(0, w), rnd.uniform(0, h)
        if any(a <= x <= c and b <= y <= d for a, b, c, d in avoid):
            continue
        sw, sh = rnd.uniform(1.6, 3.4) * unit, rnd.uniform(0.7, 1.4) * unit
        out.append('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="%.2f" fill="%s" stroke="%s" stroke-width="%.2f" transform="rotate(%d %.1f %.1f)"/>'
                   % (x, y, sw, sh, 0.2 * unit, rnd.choice(cols), INK, 0.25 * unit, rnd.randint(0, 179), x + sw / 2, y + sh / 2))
    return '<svg viewBox="0 0 %s %s" xmlns="http://www.w3.org/2000/svg">%s</svg>' % (w, h, ''.join(out))
