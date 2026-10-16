<?php
/*
 * Ruota della fortuna (logica in lib/wheel.php): un giro ogni 24 ore, si vince KOIN o un oggetto esclusivo non acquistabile
 * dal Negozio. Per ora è visibile solo all'admin e non ha una scheda nel menu: si arriva qui dal bottoncino in Home.
 */
require __DIR__ . '/lib/bootstrap.php';
require_login();

if (!is_admin()) {   // per ora la Ruota è visibile solo all'admin
    flash('err', 'La Ruota della fortuna non è ancora disponibile.');
    redirect('index.php');
}

$me = my_player_id();

if (is_post() && ($_POST['do'] ?? '') === 'spin') {
    if (!$me) {
        flash('err', 'Il tuo account non è collegato a un giocatore: chiedi all\'admin.');
    } else {
        [$ok, $msg] = wheel_spin($me);
        flash($ok ? 'ok' : 'err', $msg);
    }
    redirect('wheel.php');
}

$ready = $me && wheel_ready($me);
$wait = $ready ? '' : ($me ? wheel_wait_label($me) : '');
$slices = wheel_slices();

layout_start('Ruota della fortuna');
?>
<div class="narrow">
  <div class="card">
    <h1><?= wheel_icon('ico-wheel-lg') ?> Ruota della fortuna</h1>
    <p class="muted">Un giro ogni <strong>24 ore</strong>: vinci KOIN oppure un oggetto esclusivo che non trovi al Negozio.</p>
    <?php if (!$me): ?>
      <p><i class="ti ti-alert-triangle"></i> Il tuo account non è collegato a un giocatore: chiedi all'admin.</p>
    <?php elseif ($ready): ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="spin">
        <button class="btn btn-primary btn-block"><?= wheel_icon() ?> Gira la ruota</button>
      </form>
    <?php else: ?>
      <p><i class="ti ti-clock"></i> Hai già girato: torna tra <strong><?= h($wait) ?></strong> per il prossimo giro.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><i class="ti ti-gift"></i> Cosa puoi vincere</h2>
    <ul class="wheel-prizes">
      <?php foreach ($slices as $s): ?>
        <li>
          <span class="wheel-prize-label"><?= h($s['label']) ?></span>
          <?php if (!empty($s['item'])): ?><span class="wheel-prize-tag">esclusivo</span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="muted small">Se l'oggetto esclusivo estratto ce l'hai già, al suo posto ricevi i KOIN dello spicchio.</p>
  </div>
</div>
<?php
layout_end();
