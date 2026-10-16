<?php
/*
 * Ruota della fortuna (logica in lib/wheel.php): un giro ogni 24 ore, si vince KOIN, crediti dell'Avatar, un oggetto esclusivo non
 * acquistabile dal Negozio, un giro extra o un gettone ×2 per le scommesse. Il premio lo estrae il server e la ruota (assets/ruota.js)
 * gira fino a fermarsi con il puntale sullo spicchio estratto. Per ora è visibile solo all'admin e non ha una scheda nel menu: si
 * arriva qui dal bottoncino in Home.
 */
require __DIR__ . '/lib/bootstrap.php';
require_login();

if (!is_admin()) {   // per ora la Ruota è visibile solo all'admin
    flash('err', 'La Ruota della fortuna non è ancora disponibile.');
    redirect('index.php');
}

$me = my_player_id();

if (is_post() && ($_POST['do'] ?? '') === 'spin') {
    $json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if (!$me) {
        [$ok, $msg, $prize] = [false, 'Il tuo account non è collegato a un giocatore: chiedi all\'admin.', null];
    } else {
        [$ok, $msg, $prize] = wheel_spin($me);
    }
    if ($json) {   // la ruota gira senza ricaricare la pagina
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'msg' => $msg, 'prize' => $prize]);
        exit;
    }
    flash($ok ? 'ok' : 'err', $msg);
    redirect('wheel.php');
}

$ready = $me && wheel_ready($me);
$free = $me && wheel_free_ready($me);
$extras = $me ? wheel_extras($me) : ['spins' => 0, 'tokens' => 0];
$slices = wheel_slices();
$kindLabel = ['koin' => 'KOIN', 'credits' => 'Crediti Avatar', 'item' => 'Oggetto esclusivo', 'spin' => 'Giro extra', 'token' => 'Gettone ×2', 'none' => 'Niente'];

layout_start('Ruota della fortuna');
?>
<div class="narrow">
  <div class="card wheel-card" data-wheel-card data-csrf="<?= h(csrf_token()) ?>">
    <h1><?= wheel_icon('ico-wheel-lg') ?> Ruota della fortuna</h1>
    <p class="muted">Un giro gratis ogni <strong>24 ore</strong>: vinci KOIN, crediti per l'Avatar, un oggetto esclusivo che non trovi al Negozio, un giro extra o un gettone ×2 per le scommesse.</p>
    <div class="wheel" data-wheel data-slices="<?= count($slices) ?>"><div class="wheel-pointer" aria-hidden="true"></div><?= wheel_svg() ?></div>
    <?php if (!$me): ?>
      <p><i class="ti ti-alert-triangle"></i> Il tuo account non è collegato a un giocatore: chiedi all'admin.</p>
    <?php else: ?>
      <form method="post" class="form wheel-actions" data-wheel-form>
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="spin">
        <button class="btn btn-primary btn-block" data-wheel-spin<?= $ready ? '' : ' disabled' ?>><i class="ti ti-player-play-filled"></i> <?= $free ? 'Gira la ruota' : ($extras['spins'] > 0 ? 'Usa un giro extra' : 'Già girata') ?></button>
      </form>
      <p class="wheel-status small">
        <?php if (!$free): ?><span class="muted"><i class="ti ti-clock"></i> Prossimo giro gratis tra <strong><?= h(wheel_wait_label($me)) ?></strong>.</span><?php endif; ?>
        <?php if ($extras['spins'] > 0): ?><span class="tag"><i class="ti ti-refresh"></i> <?= $extras['spins'] ?> <?= $extras['spins'] === 1 ? 'giro extra' : 'giri extra' ?></span><?php endif; ?>
        <?php if ($extras['tokens'] > 0): ?><a class="tag" href="bets.php"><i class="ti ti-ticket"></i> <?= $extras['tokens'] ?> <?= $extras['tokens'] === 1 ? 'gettone' : 'gettoni' ?> ×2 (si usano in Scommesse)</a><?php endif; ?>
      </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><i class="ti ti-gift"></i> Cosa puoi vincere</h2>
    <ul class="wheel-prizes">
      <?php foreach ($slices as $i => $s): ?>
        <li>
          <span class="wheel-prize-label"><span class="wheel-dot" style="background:<?= h(wheel_color($i)) ?>"></span><?= h($s['label']) ?><?= $s['type'] === 'item' && $s['koin'] ? ' <span class="muted small">+ ' . (int) $s['koin'] . ' KOIN</span>' : '' ?></span>
          <span class="wheel-prize-right">
            <?php if ($s['type'] === 'item'): ?><span class="wheel-prize-tag">esclusivo</span><?php endif; ?>
            <b class="wheel-pct"><?= h(rtrim(rtrim(number_format(wheel_percent($i), 1, ',', ''), '0'), ',')) ?>%</b>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="muted small">Se l'oggetto esclusivo estratto ce l'hai già, al suo posto ricevi i KOIN dello spicchio. Il <b>gettone ×2</b> si attiva su una partita in Scommesse: le tue puntate singole su quella partita, se vincono, pagano il doppio. Un giro extra non fa ripartire le 24 ore.</p>
  </div>
</div>
<script src="assets/ruota.js?v=<?= h(substr((string) @md5_file(__DIR__ . '/assets/ruota.js'), 0, 10)) ?>" defer></script>
<?php
layout_end();
