<?php
/* Negozio delle personalizzazioni del profilo (copricapi, bordi, sfondi, nickname), pagate con i gettoni delle scommesse: vedi lib/shop.php. */
require __DIR__ . '/lib/bootstrap.php';
require_view();

$me = my_player_id();
$kinds = shop_kinds();
$tab = isset($kinds[$_GET['s'] ?? ''] ) ? $_GET['s'] : 'hat';
$filter = in_array($_GET['f'] ?? '', ['mine', 'buy', 'locked'], true) ? $_GET['f'] : 'all';

/* ---------------------------------------------------------------- azioni */
if (is_post()) {
    require_login();
    $do = $_POST['do'] ?? '';
    $kind = (string) ($_POST['kind'] ?? '');
    $key = (string) ($_POST['key'] ?? '');
    $item = shop_item($kind, $key);
    if (!$me) {
        flash('err', 'Il tuo account non è collegato a un giocatore: chiedi all\'admin.');
    } elseif ($do === 'buy') {
        wallet_open($me);
        $err = shop_buy($me, $kind, $key);
        flash($err ? 'err' : 'ok', $err ?: 'Comprato: «' . $item['name'] . '»! Ora puoi indossarlo.');
    } elseif ($do === 'wear') {
        $err = shop_equip($me, $kind, $key);
        flash($err ? 'err' : 'ok', $err ?: '«' . $item['name'] . '» indossato: guarda il tuo profilo.');
    } elseif ($do === 'take_off' && isset($kinds[$kind])) {
        shop_equip($me, $kind, null);
        flash('ok', 'Tolto.');
    }
    $back = 'shop.php?s=' . (isset($kinds[$kind]) ? $kind : 'hat');
    if (in_array($_POST['f'] ?? '', ['mine', 'buy', 'locked'], true)) {
        $back .= '&f=' . $_POST['f'];
    }
    redirect($back . '#oggetti');
}

/* ---------------------------------------------------------------- dati */
$dole = $me ? wallet_open($me) : null;
if ($dole) {
    flash('ok', $dole);
}
$mp = $me ? get_player($me) : null;
$balance = $me ? wallet_balance($me) : 0;
$inPlay = $me ? wallet_in_play($me) : 0;
$owned = $me ? shop_owned($me) : [];
$progress = $me ? shop_progress($me) : [];
$catalog = shop_catalog();
$worn = $mp ? shop_worn($mp, $tab) : null;

$goalText = function (array $item) use ($progress): string {
    [$stat, $need, $label] = $item['goal'];
    $have = $progress[$stat] ?? 0;
    return $label . ' · ' . (is_float($need) ? fmt_num($have, 1) . ' / ' . fmt_num($need, 1) : min((int) $have, $need) . ' / ' . $need);
};

// quanti oggetti per categoria e quanti ne ho, per le schede
$count = [];
foreach ($catalog as $k => $items) {
    $count[$k] = ['tot' => count($items), 'mine' => count(array_intersect_key($items, $owned))];
}

// oggetti della scheda scelta: filtrati e in ordine di prezzo (i nickname da sbloccare in fondo, nell'ordine del catalogo)
$items = [];
foreach ($catalog[$tab] as $key => $item) {
    $has = isset($owned[$key]);
    $locked = !$has && $item['price'] === null;
    if (($filter === 'mine' && !$has) || ($filter === 'locked' && !$locked)
        || ($filter === 'buy' && ($has || $locked || $item['price'] > $balance))) {
        continue;
    }
    $items[$key] = $item + ['has' => $has, 'locked' => $locked];
}
uasort($items, function ($a, $b) {
    $pa = $a['price'] ?? PHP_INT_MAX;
    $pb = $b['price'] ?? PHP_INT_MAX;
    return $pa <=> $pb;   // uasort è stabile: a parità (i nickname da sbloccare) resta l'ordine del catalogo
});

$tabUrl = fn(string $t, string $f = 'all') => 'shop.php?s=' . $t . ($f !== 'all' ? '&f=' . $f : '') . '#oggetti';

layout_start('Negozio', 'shop');
?>
<div class="page-head"><h1>Negozio <span class="muted small">personalizza il profilo</span></h1></div>

<?php if ($mp): ?>
<section class="card shop-bar" id="anteprima">
  <div class="shop-look role-<?= strtolower(position_abbr($mp['position'])) ?><?= bg_preset_class($mp) ?><?= border_class($mp) ?>" data-look>
    <?= hat_html($mp) ?>
    <?= avatar($mp, 'lg') ?>
    <div data-look-name><strong class="wallet-name"><?= h($mp['name']) ?></strong><?= nick_html($mp) ?></div>
  </div>
  <div class="shop-bar-info">
    <div class="wallet-num"><i class="ti ti-coin"></i> <strong><?= $balance ?></strong> <span>gettoni</span><?php if ($inPlay): ?> <small class="muted">(+<?= $inPlay ?> in gioco)</small><?php endif; ?></div>
    <p class="muted small">Premi <b>Prova</b> su un oggetto per vederlo qui addosso a te prima di comprarlo.</p>
  </div>
</section>
<p class="muted small">I gettoni si guadagnano <a class="link" href="bets.php">scommettendo sulle partite</a>. Quello che indossi si vede sul tuo profilo e nella Rosa. Alcuni nickname non si comprano:
  si sbloccano da soli raggiungendo un obiettivo (gol, assist, MVP, presenze...).</p>
<?php else: ?>
<p class="empty card">Il tuo account non è collegato a un giocatore: puoi guardare il negozio ma non comprare.</p>
<?php endif; ?>

<nav class="shop-tabs" id="oggetti">
  <?php foreach ($kinds as $k => $title): ?>
    <a href="<?= h($tabUrl($k)) ?>" class="<?= $k === $tab ? 'active' : '' ?>"><?= h($title) ?> <span class="count"><?= $count[$k]['mine'] ?>/<?= $count[$k]['tot'] ?></span></a>
  <?php endforeach; ?>
</nav>
<div class="sortbar shop-filters">
  Mostra:
  <a href="<?= h($tabUrl($tab)) ?>" class="<?= $filter === 'all' ? 'active' : '' ?>">Tutto</a>
  <a href="<?= h($tabUrl($tab, 'buy')) ?>" class="<?= $filter === 'buy' ? 'active' : '' ?>">Che posso comprare</a>
  <a href="<?= h($tabUrl($tab, 'mine')) ?>" class="<?= $filter === 'mine' ? 'active' : '' ?>">Miei</a>
  <?php if ($tab === 'nick'): ?><a href="<?= h($tabUrl($tab, 'locked')) ?>" class="<?= $filter === 'locked' ? 'active' : '' ?>">Da sbloccare</a><?php endif; ?>
</div>

<?php if (!$items): ?><p class="empty card">Niente da mostrare con questo filtro.</p><?php endif; ?>
<div class="shop-grid">
  <?php foreach ($items as $key => $item):
      $has = $item['has'];
      $locked = $item['locked'];
      $isWorn = $worn === $key; ?>
  <article class="card shop-item<?= $isWorn ? ' is-worn' : '' ?><?= $locked ? ' is-locked' : '' ?>">
    <div class="shop-thumb">
      <?php if ($tab === 'bg'): ?><span class="shop-swatch bgp-<?= h($key) ?>"></span>
      <?php elseif ($tab === 'border'): ?><span class="shop-brdthumb brd-<?= h($key) ?>"></span>
      <?php elseif ($tab === 'hat'): ?><span class="hat-thumb"><?= hat_svg($key) ?></span>
      <?php else: ?><span class="nick nick-big">«<?= h($item['name']) ?>»</span><?php endif; ?>
    </div>
    <div class="shop-name"><?= h($item['name']) ?><?= $isWorn ? ' <span class="tag tag-ok"><i class="ti ti-check"></i> indossato</span>' : '' ?></div>
    <?php if ($locked): ?>
      <p class="muted small shop-goal"><i class="ti ti-lock"></i> Si sblocca con: <?= h($goalText($item)) ?></p>
    <?php elseif (!$has): ?>
      <p class="shop-price<?= $balance < $item['price'] ? ' is-short' : '' ?>"><i class="ti ti-coin"></i> <?= (int) $item['price'] ?></p>
    <?php elseif ($item['price'] === null && !$isWorn): ?>
      <p class="small"><span class="tag tag-mvp"><i class="ti ti-award"></i> sbloccato</span></p>
    <?php endif; ?>
    <?php if ($me): ?>
    <div class="shop-act">
      <button type="button" class="btn btn-ghost btn-sm" data-try="<?= $tab ?>"
        <?= $tab === 'bg' ? 'data-cls="bgp-' . h($key) . '"' : ($tab === 'border' ? 'data-cls="brd-' . h($key) . '"' : ($tab === 'nick' ? 'data-text="«' . h($item['name']) . '»"' : '')) ?>><i class="ti ti-eye"></i> Prova</button>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="kind" value="<?= $tab ?>"><input type="hidden" name="key" value="<?= h($key) ?>"><input type="hidden" name="f" value="<?= h($filter) ?>">
        <?php if ($isWorn): ?>
          <input type="hidden" name="do" value="take_off"><button class="btn btn-ghost btn-sm">Togli</button>
        <?php elseif ($has): ?>
          <input type="hidden" name="do" value="wear">
          <button class="btn btn-primary btn-sm"<?= $tab === 'bg' && ($mp['bg_image'] || $mp['bg_color']) ? ' data-confirm="Lo sfondo speciale sostituisce quello che hai scelto con colore o immagine. Continuare?"' : '' ?>>Indossa</button>
        <?php elseif (!$locked): ?>
          <input type="hidden" name="do" value="buy">
          <button class="btn btn-primary btn-sm"<?= $balance < $item['price'] ? ' title="Non hai abbastanza gettoni"' : '' ?>>Compra</button>
        <?php endif; ?>
      </form>
    </div>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>
</div>

<script>
// "Prova": mette l'oggetto addosso all'anteprima, senza comprarlo
(() => {
  const look = document.querySelector('[data-look]');
  if (!look) return;
  const dropClass = prefix => [...look.classList].filter(c => c.startsWith(prefix)).forEach(c => look.classList.remove(c));
  document.querySelectorAll('[data-try]').forEach(btn => btn.addEventListener('click', () => {
    const kind = btn.dataset.try;
    if (kind === 'bg') { dropClass('bgp-'); look.classList.add(btn.dataset.cls); }
    else if (kind === 'border') { dropClass('brd-'); look.classList.add(btn.dataset.cls); }
    else if (kind === 'hat') {
      let hat = look.querySelector('.hat');
      if (!hat) { hat = document.createElement('span'); hat.className = 'hat'; hat.setAttribute('aria-hidden', 'true'); look.prepend(hat); }
      hat.innerHTML = btn.closest('.shop-item').querySelector('.hat-thumb').innerHTML;
    } else if (kind === 'nick') {
      const box = look.querySelector('[data-look-name]');
      let nick = box.querySelector('.nick');
      if (!nick) { nick = document.createElement('span'); nick.className = 'nick'; box.append(nick); }
      nick.textContent = btn.dataset.text;
    }
    document.getElementById('anteprima').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }));
})();
</script>
<?php
layout_end();
