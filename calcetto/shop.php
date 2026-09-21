<?php
/* Negozio delle personalizzazioni del profilo (sfondi, nickname, copricapi), pagate con i gettoni delle scommesse: vedi lib/shop.php. */
require __DIR__ . '/lib/bootstrap.php';
require_view();

$me = my_player_id();
$kinds = shop_kinds();

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
    redirect('shop.php#' . preg_replace('/[^a-z]/', '', $kind));
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

$goalText = function (array $item) use ($progress): string {
    [$stat, $need, $label] = $item['goal'];
    $have = $progress[$stat] ?? 0;
    return $label . ' · ' . (is_float($need) ? fmt_num($have, 1) . ' / ' . fmt_num($need, 1) : min((int) $have, $need) . ' / ' . $need);
};

layout_start('Negozio', 'bets');
?>
<div class="page-head"><h1>Negozio <span class="muted small">personalizza il profilo</span></h1></div>
<div class="sortbar"><a href="bets.php">Scommesse</a><a href="shop.php" class="active">Negozio</a></div>

<section class="card wallet">
  <?php if ($mp): ?>
    <div class="shop-look role-<?= strtolower(position_abbr($mp['position'])) ?><?= bg_preset_class($mp) ?>">
      <?= hat_html($mp) ?>
      <?= avatar($mp, 'lg') ?>
      <div><strong class="wallet-name"><?= h($mp['name']) ?></strong><?= nick_html($mp) ?></div>
    </div>
    <div class="wallet-num"><i class="ti ti-coin"></i> <strong><?= $balance ?></strong> <span>gettoni</span></div>
    <?php if ($inPlay): ?><div class="wallet-play muted small"><?= $inPlay ?> in gioco</div><?php endif; ?>
    <p class="muted small wallet-rules">I gettoni si guadagnano <a class="link" href="bets.php">scommettendo sulle partite</a>. Qui li spendi per sfondi speciali, nickname e copricapi: si vedono sul tuo profilo e nella Rosa.
      Alcuni nickname non si comprano: si sbloccano da soli raggiungendo un obiettivo (gol, assist, MVP...).</p>
  <?php else: ?>
    <p class="empty">Il tuo account non è collegato a un giocatore: puoi guardare il negozio ma non comprare.</p>
  <?php endif; ?>
</section>

<?php foreach ($kinds as $kind => $title):
    $worn = $mp ? shop_worn($mp, $kind) : null; ?>
<h2 class="section-title" id="<?= $kind ?>"><?= h($title) ?></h2>
<div class="shop-grid">
  <?php foreach ($catalog[$kind] as $key => $item):
      $has = isset($owned[$key]);
      $isWorn = $worn === $key;
      $locked = !$has && $item['price'] === null; ?>
  <article class="card shop-item<?= $isWorn ? ' is-worn' : '' ?><?= $locked ? ' is-locked' : '' ?>">
    <div class="shop-thumb">
      <?php if ($kind === 'bg'): ?><span class="shop-swatch bgp-<?= h($key) ?>"></span>
      <?php elseif ($kind === 'hat'): ?><span class="hat-thumb"><?= hat_svg($key) ?></span>
      <?php else: ?><span class="nick nick-big">«<?= h($item['name']) ?>»</span><?php endif; ?>
    </div>
    <div class="shop-name"><?= h($item['name']) ?><?= $isWorn ? ' <span class="tag tag-ok"><i class="ti ti-check"></i> indossato</span>' : '' ?></div>
    <?php if ($locked): ?>
      <p class="muted small shop-goal"><i class="ti ti-lock"></i> Si sblocca con: <?= h($goalText($item)) ?></p>
    <?php elseif (!$has): ?>
      <p class="shop-price"><i class="ti ti-coin"></i> <?= (int) $item['price'] ?></p>
    <?php elseif ($item['price'] === null && !$isWorn): ?>
      <p class="small"><span class="tag tag-mvp"><i class="ti ti-award"></i> sbloccato</span></p>
    <?php endif; ?>
    <?php if ($me): ?>
    <form method="post" class="shop-act"><?= csrf_field() ?><input type="hidden" name="kind" value="<?= $kind ?>"><input type="hidden" name="key" value="<?= h($key) ?>">
      <?php if ($isWorn): ?>
        <input type="hidden" name="do" value="take_off"><button class="btn btn-ghost btn-sm btn-block">Togli</button>
      <?php elseif ($has): ?>
        <input type="hidden" name="do" value="wear">
        <button class="btn btn-primary btn-sm btn-block"<?= $kind === 'bg' && ($mp['bg_image'] || $mp['bg_color']) ? ' data-confirm="Lo sfondo speciale sostituisce quello che hai scelto con colore o immagine. Continuare?"' : '' ?>>Indossa</button>
      <?php elseif (!$locked): ?>
        <input type="hidden" name="do" value="buy">
        <button class="btn btn-primary btn-sm btn-block"<?= $balance < $item['price'] ? ' title="Non hai abbastanza gettoni"' : '' ?>>Compra</button>
      <?php endif; ?>
    </form>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php
layout_end();
