<?php
/*
 * Pagine pubbliche con la stessa grafica della landing (benvenuto.php): chi non ha fatto l'accesso le vede con intestazione e piè di pagina propri,
 * chi è dentro le vede dentro il sito con il menu. Così «Trova una lega» è una sezione unica per tutti.
 */

/** Inizio pagina: $title nel <title>. Ritorna true se si è in modalità pubblica (serve a pub_end). */
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
<footer class="pub-foot"><div class="pub-foot-in"><div><b>Calcetto Manager</b><p class="muted small">Il calcetto tra amici, organizzato. Gratis per chi gioca.</p></div></div></footer>
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
  <p class="muted small citymap-key"><span class="dot" style="background:#38d178"></span> lega <span class="dot" style="background:#ff6b9a"></span> lega nuova
    <span class="dot" style="background:#53c8f5"></span> centro sportivo <span class="dot" style="background:#ffd23f"></span> lega del campo</p>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
  var pts = <?= $json ?>, colors = { lega: '#38d178', nuova: '#ff6b9a', centro: '#53c8f5', campo: '#ffd23f' };
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
function league_emblem(array $c): string
{
    $colors = ['#38d178', '#53c8f5', '#ff8c42', '#a67cf2', '#ff6b9a', '#ffd23f'];
    $ini = '';
    foreach (array_slice(preg_split('/\s+/', trim($c['name'])) ?: [], 0, 2) as $w) {
        $ini .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    $inner = $c['logo'] ? '<img src="' . h($c['logo']) . '" alt="">' : '<span class="lg-initials" style="background:' . $colors[$c['id'] % count($colors)] . '">' . h($ini ?: 'L') . '</span>';
    return '<span class="lg-emblem">' . $inner . ($c['isNew'] ? '<span class="lg-new">NUOVO</span>' : '') . '</span>';
}

/** Scheda di una lega trovata sulla mappa; $action = HTML del pulsante. */
function league_card_html(array $c, string $action = ''): string
{
    ob_start();
    ?>
<article class="card lg-card" id="lega-<?= (int) $c['id'] ?>">
  <div class="lg-head"><?= league_emblem($c) ?>
    <div><h3><?= h($c['name']) ?><?= $c['home'] ? ' <span class="tag tag-captain">lega del campo</span>' : '' ?></h3>
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
