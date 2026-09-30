<?php
/*
 * Uscite del negozio (solo admin): gli oggetti del catalogo esteso (lib/shop_items_more.php) sono divisi in pacchetti e restano
 * nascosti finché l'admin non li fa uscire. Si può far uscire un pacchetto intero, un certo numero di oggetti a caso o quelli
 * scelti uno per uno, subito o a una data e ora; e si può ritirare quello che non è ancora stato comprato (chi l'ha già lo tiene).
 * Le date stanno in shop_releases; lib/shop.php (shop_released) decide cosa è in vendita.
 * L'orario delle uscite è uno solo per tutta la pagina (meta 'drops_at', vuoto = subito): lo usano tutti i pulsanti che fanno uscire.
 */
require __DIR__ . '/lib/bootstrap.php';
require_admin();

$drops = shop_more_drops();
$code = isset($drops[$_GET['d'] ?? '']) ? (string) $_GET['d'] : '';
$page = max(1, (int) ($_GET['p'] ?? 1));
const DROPS_PER_PAGE = 48;

/** L'orario scelto per le uscite (timestamp), oppure null = subito (anche quando l'orario scelto è già passato). */
function drops_at(): ?int
{
    $v = (string) (meta_get('drops_at') ?? '');
    $t = $v !== '' ? strtotime($v) : false;
    return $t && $t > time() ? $t : null;
}

/** Gli oggetti di un pacchetto: chiave => oggetto. */
function drops_items(string $code): array
{
    $out = [];
    foreach (shop_catalog()[shop_more_drops()[$code][1]] as $k => $item) {
        if (($item['drop'] ?? null) === $code) {
            $out[(string) $k] = $item;
        }
    }
    return $out;
}

/* ---------------------------------------------------------------- azioni */
if (is_post()) {
    $d = (string) ($_POST['d'] ?? '');
    $do = (string) ($_POST['do'] ?? '');
    $back = (string) ($_POST['back'] ?? '');
    if ($do === 'set_time' || $do === 'reschedule') {
        // l'orario unico delle uscite; «reschedule» ci sposta anche quelle già programmate (quelle già uscite restano come sono)
        $t = ($_POST['mode'] ?? '') === 'at' ? strtotime((string) ($_POST['at'] ?? '')) : false;
        if (($_POST['mode'] ?? '') === 'at' && (!$t || $t <= time())) {
            flash('err', 'Scegli una data e un\'ora future (oppure «Subito»).');
        } else {
            meta_set('drops_at', $t ? date('Y-m-d H:i:s', $t) : '');
            $msg = $t ? 'Da ora le uscite sono programmate per il ' . date('d/m/Y \a\l\l\e H:i', $t) . '.' : 'Da ora gli oggetti escono subito.';
            if ($do === 'reschedule') {
                $n = q('UPDATE shop_releases SET release_at = ? WHERE release_at > ?', [date('Y-m-d H:i:s', $t ?: time()), date('Y-m-d H:i:s')])->rowCount();
                $msg .= ' ' . ($n ? ($n === 1 ? 'Spostata anche 1 uscita già programmata.' : 'Spostate anche ' . $n . ' uscite già programmate.') : 'Non c\'erano uscite programmate da spostare.');
                log_activity('negozio', 'uscite · orario unico · ' . $n . ' spostate');
            }
            flash('ok', $msg);
        }
        redirect('drops.php' . (isset($drops[$d]) && $back === 'detail' ? '?d=' . urlencode($d) : ''));
    }
    if (isset($drops[$d])) {
        $items = drops_items($d);
        $map = shop_release_map(true);
        // quando: l'orario unico delle uscite (drops_at), oppure subito
        $at = drops_at() ?? time();
        $atSql = date('Y-m-d H:i:s', $at);
        $pending = array_keys(array_filter($items, fn($i, $k) => !isset($map[$k]) || $map[$k] > time(), ARRAY_FILTER_USE_BOTH));
        $keys = [];
        if ($do === 'all') {
            $keys = $pending;
        } elseif ($do === 'random') {
            shuffle($pending);
            $keys = array_slice($pending, 0, max(1, min(500, (int) ($_POST['n'] ?? 10))));
        } elseif ($do === 'some' || $do === 'withdraw_some') {
            $keys = array_values(array_intersect(array_map('strval', (array) ($_POST['keys'] ?? [])), array_keys($items)));
        } elseif ($do === 'withdraw') {
            $keys = array_keys($items);
        }
        $n = 0;
        if (in_array($do, ['all', 'random', 'some'], true)) {
            foreach ($keys as $k) {
                q('INSERT INTO shop_releases (item_key, release_at, released_by) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE release_at = VALUES(release_at), released_by = VALUES(released_by)', [$k, $atSql, current_user()['id']]);
                $n++;
            }
            $msg = $n ? ($at > time() + 30 ? $n . ' oggetti usciranno il ' . date('d/m/Y \a\l\l\e H:i', $at) . '.' : $n . ' oggetti sono usciti: ora sono nel negozio.')
                : 'Niente da far uscire.';
        } else {
            foreach ($keys as $k) {
                $n += q('DELETE FROM shop_releases WHERE item_key = ?', [$k])->rowCount();
            }
            $msg = $n ? $n . ' oggetti ritirati dal negozio (chi li ha comprati li tiene).' : 'Niente da ritirare.';
        }
        if ($n) {
            log_activity('negozio', 'uscite · ' . $drops[$d][0] . ' · ' . $do . ' · ' . $n);
        }
        flash('ok', $msg);
        redirect('drops.php' . ((string) ($_POST['back'] ?? '') === 'detail' ? '?d=' . urlencode($d) : '') . '#' . urlencode($d));
    }
    redirect('drops.php');
}

/* ---------------------------------------------------------------- dati */
$map = shop_release_map();
$now = time();
$state = fn(string $k) => !isset($map[$k]) ? 'pending' : ($map[$k] <= $now ? 'out' : 'scheduled');
$kindNames = shop_kinds() + avatar_kinds();

/** Anteprima di un oggetto: il copricapo, il nickname o il personaggio che lo indossa. */
$me = my_player_id();
$look = $me ? avatar_look(get_player($me)) : avatar_defaults() + ['hat' => null];
$preview = function (string $kind, string $key, array $item) use ($look): string {
    if ($kind === 'hat') {
        return '<span class="hat-thumb">' . hat_svg($key) . '</span>';
    }
    if ($kind === 'nick') {
        return '<span class="nick nick-big">«' . h($item['name']) . '»</span>';
    }
    $l = $look;
    $l[$kind] = $key;
    $crop = ['hair_color' => 'head', 'jersey' => 'torso', 'shorts' => 'legs', 'shoes' => 'feet'][$kind] ?? '';
    return avatar_figure($l, ['crop' => $crop] + ($kind === 'celebration' ? ['hint' => $item['anim']] : []));
};

layout_start('Uscite del negozio', 'shop');
?>
<a class="back" href="<?= $code ? 'drops.php' : 'shop.php' ?>"><i class="ti ti-arrow-left"></i> <?= $code ? 'Tutti i pacchetti' : 'Negozio' ?></a>
<div class="page-head"><h1>Uscite del negozio</h1><span class="tag tag-admin"><i class="ti ti-shield"></i> solo admin</span></div>

<?php $dropsAt = drops_at(); $scheduled = (int) q('SELECT COUNT(*) FROM shop_releases WHERE release_at > ?', [date('Y-m-d H:i:s')])->fetchColumn(); ?>
<form method="post" class="card drops-time">
  <?= csrf_field() ?><input type="hidden" name="d" value="<?= h($code) ?>"><input type="hidden" name="back" value="<?= $code ? 'detail' : '' ?>">
  <div class="drops-time-head"><i class="ti ti-clock-hour-4"></i> <strong>Orario delle uscite</strong>
    <span class="tag <?= $dropsAt ? 'tag-admin' : 'tag-ok' ?>"><?= $dropsAt ? 'il ' . date('d/m \a\l\l\e H:i', $dropsAt) : 'subito' ?></span></div>
  <p class="muted small">Vale per tutti i pulsanti qui sotto: quello che fai uscire esce a quest'orario (e parte la notifica a chi le ha attive).</p>
  <div class="drops-time-row">
    <label class="drops-time-opt"><input type="radio" name="mode" value="now"<?= $dropsAt ? '' : ' checked' ?>> Subito</label>
    <label class="drops-time-opt"><input type="radio" name="mode" value="at"<?= $dropsAt ? ' checked' : '' ?>> Il
      <input type="datetime-local" name="at" value="<?= $dropsAt ? h(date('Y-m-d\TH:i', $dropsAt)) : '' ?>" data-focus-mode="at"></label>
    <button class="btn btn-primary btn-sm" name="do" value="set_time"><i class="ti ti-check"></i> Salva orario</button>
    <?php if ($scheduled): ?><button class="btn btn-ghost btn-sm" name="do" value="reschedule" data-confirm="Spostare a quest'orario anche le <?= $scheduled ?> uscite già programmate?"><i class="ti ti-calendar-repeat"></i> Sposta qui anche le <?= $scheduled ?> già programmate</button><?php endif; ?>
  </div>
</form>

<?php if (!$code): ?>
<p class="muted">Gli oggetti nuovi sono divisi in pacchetti e i giocatori non li vedono finché non li fai uscire tu: tutti insieme, alcuni a caso
  o scelti uno per uno, subito o a una data. Puoi anche ritirarli: chi li ha già comprati li tiene.</p>
<div class="drops-grid">
  <?php foreach ($drops as $d => [$name, $kind]):
      $items = drops_items($d);
      $st = ['pending' => 0, 'scheduled' => 0, 'out' => 0];
      foreach ($items as $k => $_) {
          $st[$state($k)]++;
      }
      $sample = array_slice($items, 0, 4, true); ?>
  <section class="card drop" id="<?= h($d) ?>">
    <div class="drop-head">
      <div><span class="drop-kind"><?= h($kindNames[$kind] ?? $kind) ?></span><h2 class="drop-name"><?= h($name) ?></h2></div>
      <a class="btn btn-ghost btn-sm" href="drops.php?d=<?= h(urlencode($d)) ?>">Scegli <i class="ti ti-arrow-right"></i></a>
    </div>
    <div class="drop-bar" title="<?= $st['out'] ?> usciti, <?= $st['scheduled'] ?> programmati, <?= $st['pending'] ?> da far uscire">
      <span class="is-out" style="flex:<?= $st['out'] ?>"></span><span class="is-sched" style="flex:<?= $st['scheduled'] ?>"></span><span class="is-pend" style="flex:<?= $st['pending'] ?>"></span>
    </div>
    <p class="small drop-counts"><b><?= $st['out'] ?></b> usciti · <b><?= $st['scheduled'] ?></b> programmati · <b><?= $st['pending'] ?></b> da far uscire, su <?= count($items) ?></p>
    <div class="drop-sample"><?php foreach ($sample as $k => $it): ?><span class="drop-thumb" title="<?= h($it['name']) ?>"><?= $preview($kind, $k, $it) ?></span><?php endforeach; ?></div>
    <?php if ($st['pending'] + $st['scheduled']): ?>
    <div class="drop-acts">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="d" value="<?= h($d) ?>"><input type="hidden" name="do" value="all">
        <button class="btn btn-primary btn-sm" data-confirm="Far uscire <?= $dropsAt ? 'il ' . date('d/m \a\l\l\e H:i', $dropsAt) : 'adesso' ?> tutti i <?= $st['pending'] + $st['scheduled'] ?> oggetti di «<?= h($name) ?>»?"><i class="ti ti-<?= $dropsAt ? 'calendar-event' : 'rocket' ?>"></i> <?= $dropsAt ? 'Programma tutto' : 'Fai uscire tutto' ?></button></form>
      <form method="post" class="drop-random"><?= csrf_field() ?><input type="hidden" name="d" value="<?= h($d) ?>"><input type="hidden" name="do" value="random">
        <label>N. <input type="number" name="n" min="1" max="<?= $st['pending'] + $st['scheduled'] ?>" value="<?= min(5, $st['pending'] + $st['scheduled']) ?>"></label>
        <button class="btn btn-ghost btn-sm"><i class="ti ti-dice"></i> A caso</button></form>
    </div>
    <?php endif; ?>
    <?php if ($st['out'] + $st['scheduled']): ?>
    <form method="post" class="drop-withdraw"><?= csrf_field() ?><input type="hidden" name="d" value="<?= h($d) ?>"><input type="hidden" name="do" value="withdraw">
      <button class="btn btn-ghost btn-sm" data-confirm="Ritirare tutto «<?= h($name) ?>» dal negozio? Chi l'ha già comprato lo tiene."><i class="ti ti-arrow-back-up"></i> Ritira il pacchetto</button></form>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
</div>

<?php else:
    [$name, $kind] = $drops[$code];
    $items = drops_items($code);
    $pages = max(1, (int) ceil(count($items) / DROPS_PER_PAGE));
    $page = min($page, $pages);
    $pageItems = array_slice($items, ($page - 1) * DROPS_PER_PAGE, DROPS_PER_PAGE, true); ?>
<h2 class="section-title"><?= h($name) ?> <span class="muted small"><?= h($kindNames[$kind] ?? $kind) ?> · <?= count($items) ?> oggetti</span></h2>
<form method="post" class="drop-pick"><?= csrf_field() ?><input type="hidden" name="d" value="<?= h($code) ?>"><input type="hidden" name="back" value="detail">
  <div class="drop-pick-bar card">
    <span class="small">Scegli gli oggetti, poi:</span>
    <button class="btn btn-primary btn-sm" name="do" value="some"><i class="ti ti-<?= $dropsAt ? 'calendar-event' : 'rocket' ?>"></i> <?= $dropsAt ? 'Programma i selezionati' : 'Fai uscire i selezionati' ?></button>
    <button class="btn btn-ghost btn-sm" name="do" value="withdraw_some"><i class="ti ti-arrow-back-up"></i> Ritira i selezionati</button>
    <span class="muted small"><?= $dropsAt ? 'Escono il ' . date('d/m \a\l\l\e H:i', $dropsAt) . ' (orario delle uscite qui sopra).' : 'Escono subito (orario delle uscite qui sopra).' ?></span>
  </div>
  <div class="drop-items">
    <?php foreach ($pageItems as $k => $it): $s = $state($k); ?>
    <label class="card drop-item is-<?= $s ?>">
      <input type="checkbox" name="keys[]" value="<?= h($k) ?>">
      <span class="drop-item-fig"><?= $preview($kind, $k, $it) ?></span>
      <span class="drop-item-name"><?= h($it['name']) ?></span>
      <span class="small drop-item-state"><?= $s === 'out' ? '<i class="ti ti-circle-check"></i> uscito' : ($s === 'scheduled' ? '<i class="ti ti-clock"></i> esce il ' . date('d/m H:i', $map[$k]) : '<i class="ti ti-eye-off"></i> da far uscire') ?>
        · <i class="ti ti-coin"></i> <?= (int) $it['price'] ?></span>
    </label>
    <?php endforeach; ?>
  </div>
</form>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pagine">
  <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="drops.php?d=<?= h(urlencode($code)) ?>&amp;p=<?= $page - 1 ?>"><i class="ti ti-chevron-left"></i> Indietro</a><?php endif; ?>
  <span class="pager-n">Pagina <?= $page ?> di <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="drops.php?d=<?= h(urlencode($code)) ?>&amp;p=<?= $page + 1 ?>">Avanti <i class="ti ti-chevron-right"></i></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php
layout_end();
