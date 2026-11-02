<?php
/*
 * Pagine pubbliche con la stessa grafica della landing (benvenuto.php): chi non ha fatto l'accesso le vede con intestazione e piè di pagina propri,
 * chi è dentro le vede dentro il sito con il menu. Così «Trova una lega» è una sezione unica per tutti.
 */

/** Inizio pagina: $title nel <title>. Ritorna true se si è in modalità pubblica (serve a pub_end). */
/**
 * Il piè di pagina di tutte le pagine: identico a SiteFooter del sito Next (presentazione, tre social solo come icone senza link, link utili,
 * «Sostienici» e copyright). Prezzi, guide, privacy, termini e contatti esistono solo sul sito Next: i link portano lì.
 */
function site_footer(): string
{
    $n = NEXT_SITE;
    $links = [['/prezzi', 'Prezzi'], ['/guide', 'Guide'], ['/centri-sportivi', 'Per i centri sportivi'], ['/chi-siamo', 'Chi siamo'], ['/privacy', 'Privacy e cookie'],
        ['/privacy#cookie', 'Gestisci i cookie'], ['/termini', 'Termini'], ['/contatti', 'Contatti']];
    $nav = '';
    foreach ($links as [$href, $label]) {
        $nav .= '<a href="' . h($n . $href) . '">' . h($label) . '</a>';
    }
    if (kofi_username()) {   // «Sostienici» resta nascosto finché l'admin non mette il nome della pagina Ko-fi
        $nav .= '<a href="sostienici"><i class="ti ti-cup" aria-hidden="true"></i> Sostienici</a>';
    }
    return '<footer class="site-foot"><div class="site-foot-in"><div class="site-foot-brand"><div class="site-foot-id"><b>' . h(APP_NAME) . '</b>'
        . '<p class="site-foot-social" aria-label="I nostri social, in arrivo">'
        . '<span title="X" aria-label="X"><i class="ti ti-brand-x"></i></span>'
        . '<span title="Instagram" aria-label="Instagram"><i class="ti ti-brand-instagram"></i></span>'
        . '<span title="TikTok" aria-label="TikTok"><i class="ti ti-brand-tiktok"></i></span></p></div>'
        . '<p class="site-foot-tag muted small">Il calcetto tra amici, organizzato. Gratis per chi gioca.</p></div>'
        . '<nav class="site-foot-links" aria-label="Informazioni">' . $nav . '</nav></div>'
        . '<p class="site-foot-copy small muted">© ' . date('Y') . ' ' . h(APP_NAME) . ' · Tutti i diritti riservati</p></footer>';
}

function pub_start(string $title, string $description = ''): bool
{
    if (current_user() && !is_guest()) {
        layout_start($title, 'find');
        return false;
    }
    $ver = fn (string $f) => substr((string) @md5_file(__DIR__ . '/../assets/' . $f), 0, 10);
    ?><!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="theme-color" content="#ffd23f">
<title><?= h($title) ?> · Calcetto Manager</title>
<?php if ($description !== ''): ?><meta name="description" content="<?= h($description) ?>"><?php endif; ?>
<link rel="icon" type="image/png" href="assets/icons/icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Caveat:wght@500;700&family=Lilita+One&family=Nunito:wght@500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
<link rel="stylesheet" href="assets/style.css?v=<?= h($ver('style.css')) ?>"><link rel="stylesheet" href="assets/landing.css?v=<?= h($ver('landing.css')) ?>">
</head><body>
<header class="pub-top"><div class="pub-top-in">
  <a href="benvenuto.php" class="brand"><span class="brand-ball">⚽</span> Calcetto Manager</a>
  <nav class="pub-nav" aria-label="Sito">
    <a href="calcetto.php">Trova una lega</a>
    <a href="login.php" class="btn btn-ghost btn-sm">Accedi</a>
    <a href="create_league.php" class="btn btn-primary btn-sm pub-nav-cta">Crea la lega</a>
  </nav>
</div></header>
<main class="pub">
<?php
    return true;
}

function pub_end(bool $public): void
{
    if (!$public) {
        layout_end();
        return;
    }
    ?></main>
<?= site_footer() ?>
</body></html>
<?php
}

/** Mappa della città (Leaflet + OpenStreetMap da cdn.jsdelivr.net): $points = [{lat, lng, label, kind, href, sub}]. Senza JavaScript resta l'elenco sotto. */
function city_map(array $points, int $height = 380): void
{
    if (!$points) {
        return;
    }
    $json = json_encode($points, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    ?>
<div class="citymap-wrap">
  <div id="citymap" class="citymap" style="height:<?= (int) $height ?>px" role="region" aria-label="Mappa delle leghe e dei campi"></div>
  <p class="muted small citymap-key"><span class="dot" style="background:#ffffff"></span> lega senza campo <span class="dot" style="background:#38d178"></span> lega con un campo
    <span class="dot" style="background:#53c8f5"></span> centro sportivo</p>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
  var pts = <?= $json ?>, colors = { lega: '#ffffff', campo: '#38d178', centro: '#53c8f5' };
  if (!window.L || !pts.length) return;
  var m = L.map('citymap', { scrollWheelZoom: false });
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '© OpenStreetMap', referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(m);
  pts.forEach(function (p) {
    var d = document.createElement('div'), a = document.createElement('a');
    a.href = p.href; a.textContent = p.label; a.style.fontWeight = '800'; d.appendChild(a);
    if (p.sub) { var s = document.createElement('div'); s.textContent = p.sub; s.style.fontSize = '.85em'; d.appendChild(s); }
    L.circleMarker([p.lat, p.lng], { radius: p.kind === 'centro' ? 11 : 9, color: '#1f1a2e', weight: 2, fillColor: colors[p.kind], fillOpacity: .95 }).addTo(m).bindPopup(d);
  });
  if (pts.length === 1) m.setView([pts[0].lat, pts[0].lng], 14);
  else m.fitBounds(pts.map(function (p) { return [p.lat, p.lng]; }), { padding: [30, 30], maxZoom: 15 });
})();
</script>
<?php
}

/** Stemma della lega: logo o iniziali su un colore; bollino NUOVO in alto a destra. */
function league_emblem(array $c, bool $zoom = false): string
{
    $colors = ['#38d178', '#53c8f5', '#ff8c42', '#a67cf2', '#ff6b9a', '#ffd23f'];
    $ini = '';
    foreach (array_slice(preg_split('/\s+/', trim($c['name'])) ?: [], 0, 2) as $w) {
        $ini .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    $inner = $c['logo'] ? '<img src="' . h($c['logo']) . '" alt="">' : '<span class="lg-initials" style="background:' . $colors[$c['id'] % count($colors)] . '">' . h($ini ?: 'L') . '</span>';
    if ($zoom && $c['logo']) {   // il logo si ingrandisce con un tocco: una finestra (<dialog>) che si chiude con un tocco, «Chiudi» o Esc (assets/app.js)
        $inner = '<button type="button" class="lg-zoom-btn" data-lg-zoom aria-label="' . h('Ingrandisci il logo di ' . $c['name']) . '">' . $inner . '</button>'
            . '<dialog class="lg-zoom" aria-label="' . h('Logo di ' . $c['name']) . '"><img src="' . h($c['logo']) . '" alt="' . h('Logo di ' . $c['name']) . '">'
            . '<button type="button" class="btn btn-primary btn-sm" data-lg-zoom-close>Chiudi</button></dialog>';
    }
    return '<span class="lg-emblem">' . $inner . ($c['isNew'] ? '<span class="lg-new">NUOVO</span>' : '') . '</span>';
}

/** Scheda di una lega trovata sulla mappa; $action = HTML del pulsante. */
function league_card_html(array $c, string $action = ''): string
{
    ob_start();
    ?>
<article class="card lg-card" id="lega-<?= (int) $c['id'] ?>">
  <div class="lg-head"><?= league_emblem($c, true) ?>
    <div><h3><?= h($c['name']) ?></h3>
      <p class="muted small"><i class="ti ti-map-pin"></i> <?= h(($c['area'] ? $c['area'] . ', ' : '') . $c['city']) ?></p></div></div>
  <?php if ($c['about']): ?><p class="small"><?= h($c['about']) ?></p><?php endif; ?>
  <dl class="lg-stats">
    <div><dt>Amministratore</dt><dd><?= h($c['admin']) ?></dd></div>
    <div><dt>Componenti</dt><dd><?= (int) $c['players'] ?></dd></div>
    <div><dt>Partite al mese</dt><dd><?= $c['played6'] ? h(str_replace('.', ',', (string) $c['perMonth'])) : '—' ?> <span class="muted small">(<?= (int) $c['played6'] ?> negli ultimi 6 mesi)</span></dd></div>
  </dl>
  <?php if ($c['center']): ?><p class="small"><i class="ti ti-building-stadium"></i> Gioca a <?= h($c['center']['name']) ?></p><?php endif; ?>
  <?php if ($action): ?><div class="btn-row"><?= $action ?></div><?php endif; ?>
</article>
<?php
    return (string) ob_get_clean();
}
