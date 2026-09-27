<?php
/* Negozio delle personalizzazioni del profilo (copricapi, bordi, sfondi, nickname), pagate con i gettoni delle scommesse: vedi lib/shop.php. */
require __DIR__ . '/lib/bootstrap.php';
require_view();

$me = my_player_id();
$kinds = shop_kinds();
$tab = isset($kinds[$_GET['s'] ?? ''] ) ? $_GET['s'] : 'hat';
$filter = in_array($_GET['f'] ?? '', ['mine', 'buy', 'locked', 'wish'], true) ? $_GET['f'] : 'all';
$page = max(1, (int) ($_GET['p'] ?? 1));
const SHOP_PER_PAGE = 48;

/* ---------------------------------------------------------------- azioni */
if (is_post()) {
    require_login();
    $do = $_POST['do'] ?? '';
    $kind = (string) ($_POST['kind'] ?? '');
    $key = (string) ($_POST['key'] ?? '');
    $item = shop_item($kind, $key);
    if (!isset($kinds[$kind])) {   // gli oggetti del Personaggio si comprano da avatar.php
        redirect('shop.php');
    } elseif (!$me) {
        flash('err', 'Il tuo account non è collegato a un giocatore: chiedi all\'admin.');
    } elseif ($do === 'buy') {
        wallet_open($me);
        $err = shop_buy($me, $kind, $key);
        if (!$err) {
            log_activity('negozio', $kind . ' · ' . ($item['name'] ?? $key));
        }
        flash($err ? 'err' : 'ok', $err ?: 'Comprato: «' . $item['name'] . '»! Ora puoi indossarlo.');
    } elseif ($do === 'wear') {
        $err = shop_equip($me, $kind, $key);
        flash($err ? 'err' : 'ok', $err ?: '«' . $item['name'] . '» indossato: guarda il tuo profilo.');
    } elseif ($do === 'take_off' && isset($kinds[$kind])) {
        shop_equip($me, $kind, null);
        flash('ok', 'Tolto.');
    } elseif ($do === 'wish' && $item) {
        $on = shop_wish_toggle($me, $key);
        if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {   // il cuoricino premuto senza ricaricare la pagina
            header('Content-Type: application/json');
            echo json_encode(['on' => $on, 'n' => shop_market(true)['wish'][$key] ?? 0]);
            exit;
        }
        flash('ok', $on ? '«' . $item['name'] . '» è tra i tuoi obiettivi.' : 'Tolto dagli obiettivi.');
    }
    $back = 'shop.php?s=' . (isset($kinds[$kind]) ? $kind : 'hat');
    if (in_array($_POST['f'] ?? '', ['mine', 'buy', 'locked', 'wish'], true)) {
        $back .= '&f=' . $_POST['f'];
    }
    if ((int) ($_POST['p'] ?? 1) > 1) {
        $back .= '&p=' . (int) $_POST['p'];
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
$wish = $me ? shop_wishlist($me) : [];
$admin = is_admin();
// gli oggetti non ancora usciti (drops.php) non si vedono, tranne all'admin che li vede in anteprima
$catalog = [];
foreach (shop_catalog() as $k => $items) {
    if (!isset($kinds[$k])) {
        continue;
    }
    foreach ($items as $key => $item) {
        $out = !shop_released((string) $key, $item);
        if (!$out || $admin || isset($owned[$key])) {
            $catalog[$k][$key] = $item + ['out' => $out];
        }
    }
}
$worn = $mp ? shop_worn($mp, $tab) : null;

$goalText = function (array $item) use ($progress): string {
    [$stat, $need, $label] = $item['goal'];
    $have = $progress[$stat] ?? 0;
    return $label . ' · ' . (is_float($need) ? fmt_num($have, 1) . ' / ' . fmt_num($need, 1) : min((int) $have, $need) . ' / ' . $need);
};

// quanti oggetti per categoria e quanti ne ho, per le schede
$count = [];
foreach ($catalog as $k => $items) {
    $count[$k] = ['tot' => count(array_filter($items, fn($i) => !$i['out'])), 'mine' => count(array_intersect_key($items, $owned))];
}

// oggetti della scheda scelta: filtrati e in ordine di prezzo (i nickname da sbloccare in fondo, nell'ordine del catalogo)
$items = [];
foreach ($catalog[$tab] as $key => $item) {
    $key = (string) $key;
    $has = isset($owned[$key]);
    $locked = !$has && $item['price'] === null;
    [$price, $why] = shop_price($key, $item, $me);   // il prezzo di adesso (lib/shop.php: shop_price)
    if (($filter === 'mine' && !$has) || ($filter === 'locked' && !$locked) || ($filter === 'wish' && !isset($wish[$key]))
        || ($filter === 'buy' && ($has || $locked || $item['out'] || $price > $balance))) {
        continue;
    }
    $items[$key] = $item + ['has' => $has, 'locked' => $locked, 'now' => $price, 'why' => $why];
}
uasort($items, function ($a, $b) {
    return [$a['out'], $a['now'] ?? PHP_INT_MAX] <=> [$b['out'], $b['now'] ?? PHP_INT_MAX];   // stabile: a parità resta l'ordine del catalogo
});
$pages = max(1, (int) ceil(count($items) / SHOP_PER_PAGE));
$page = min($page, $pages);
$items = array_slice($items, ($page - 1) * SHOP_PER_PAGE, SHOP_PER_PAGE, true);
$wishN = shop_market()['wish'];

$tabUrl = fn(string $t, string $f = 'all', int $p = 1) => 'shop.php?s=' . $t . ($f !== 'all' ? '&f=' . $f : '') . ($p > 1 ? '&p=' . $p : '') . '#oggetti';

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
  <a href="<?= h($tabUrl($tab, 'wish')) ?>" class="<?= $filter === 'wish' ? 'active' : '' ?>"><i class="ti ti-heart"></i> Obiettivi</a>
  <?php if ($tab === 'nick'): ?><a href="<?= h($tabUrl($tab, 'locked')) ?>" class="<?= $filter === 'locked' ? 'active' : '' ?>">Da sbloccare</a><?php endif; ?>
  <?php if ($admin): ?><a href="drops.php" class="shop-drops"><i class="ti ti-rocket"></i> Uscite</a><?php endif; ?>
</div>
<p class="muted small shop-market"><i class="ti ti-chart-line"></i> I prezzi cambiano: salgono se un oggetto è tra gli obiettivi di tanti o ce l'hanno in molti,
  e seguono i gettoni in circolo e quelli che hai tu. Si paga il prezzo del momento.</p>

<?php if ($tab === 'hat' && is_admin()): ?>
<p class="muted small"><i class="ti ti-user-star"></i> I copricapi si vedono anche sul tuo <a class="link" href="avatar.php?c=hat">Personaggio</a>.</p>
<?php endif; ?>
<?php if (!$items): ?><p class="empty card">Niente da mostrare con questo filtro.</p><?php endif; ?>
<div class="shop-grid">
  <?php foreach ($items as $key => $item):
      $has = $item['has'];
      $locked = $item['locked'];
      $isWorn = $worn === $key; ?>
  <article class="card shop-item<?= $isWorn ? ' is-worn' : '' ?><?= $locked ? ' is-locked' : '' ?><?= $item['out'] ? ' is-out' : '' ?>">
    <?php if ($item['out']): ?><span class="tag tag-admin shop-out"><i class="ti ti-eye-off"></i> non ancora uscito</span><?php endif; ?>
    <?php if ($me && !$locked): ?>
    <form method="post" class="wish-form"><?= csrf_field() ?><input type="hidden" name="do" value="wish"><input type="hidden" name="kind" value="<?= $tab ?>">
      <input type="hidden" name="key" value="<?= h($key) ?>"><input type="hidden" name="f" value="<?= h($filter) ?>"><input type="hidden" name="p" value="<?= $page ?>">
      <button class="wish<?= isset($wish[$key]) ? ' is-on' : '' ?>" data-wish aria-pressed="<?= isset($wish[$key]) ? 'true' : 'false' ?>"
        title="<?= isset($wish[$key]) ? 'Togli dagli obiettivi' : 'Aggiungi agli obiettivi' ?>"><i class="ti ti-heart<?= isset($wish[$key]) ? '-filled' : '' ?>"></i>
        <span data-wish-n><?= $wishN[$key] ?? 0 ?></span></button></form>
    <?php endif; ?>
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
      <p class="shop-price<?= $balance < $item['now'] ? ' is-short' : '' ?>" title="<?= h(shop_price_note($item['why'])) ?>"><i class="ti ti-coin"></i> <?= (int) $item['now'] ?>
        <?php if ($item['now'] !== $item['price']): ?><span class="price-move <?= $item['now'] > $item['price'] ? 'is-up' : 'is-down' ?>"><i class="ti ti-trending-<?= $item['now'] > $item['price'] ? 'up' : 'down' ?>"></i> base <?= (int) $item['price'] ?></span><?php endif; ?></p>
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
        <?php elseif (!$locked && !$item['out']): ?>
          <input type="hidden" name="do" value="buy"><input type="hidden" name="p" value="<?= $page ?>">
          <button class="btn btn-primary btn-sm"<?= $balance < $item['now'] ? ' title="Non hai abbastanza gettoni"' : '' ?>>Compra</button>
        <?php endif; ?>
      </form>
    </div>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>
</div>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pagine">
  <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= h($tabUrl($tab, $filter, $page - 1)) ?>"><i class="ti ti-chevron-left"></i> Indietro</a><?php endif; ?>
  <span class="pager-n">Pagina <?= $page ?> di <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= h($tabUrl($tab, $filter, $page + 1)) ?>">Avanti <i class="ti ti-chevron-right"></i></a><?php endif; ?>
</nav>
<?php endif; ?>

<script src="assets/wish.js?v=<?= h(substr((string) @md5_file(__DIR__ . '/assets/wish.js'), 0, 10)) ?>"></script>
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
    // l'anteprima è "sticky" (resta incollata in alto mentre si scorre): è sempre visibile da sola, non va inseguita.
    // scrollIntoView() la considerava sempre un filo fuori vista per via dell'offset e la faceva risalire a ogni click
    // (ripetuto su più oggetti, la pagina saliva tutta): niente scroll automatico, non serve.
  }));
})();
</script>
<?php
layout_end();
