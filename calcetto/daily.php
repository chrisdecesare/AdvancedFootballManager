<?php
/*
 * Daily access: 30 regali ritirati in sequenza, uno per giorno di accesso (logica in lib/daily.php, calendario in lib/daily_items.php).
 * Saltare un giorno non fa perdere i regali, ma si avanza di un passo al giorno: chi salta non arriva in fondo entro la campagna.
 */
require __DIR__ . '/lib/bootstrap.php';
require_login();

if (!is_admin()) {   // per ora il Daily access è visibile solo all'admin
    flash('err', 'Il Daily access non è ancora disponibile.');
    redirect('index.php');
}

$me = my_player_id();

if (is_post() && ($_POST['do'] ?? '') === 'claim') {
    if (!$me) {
        flash('err', 'Il tuo account non è collegato a un giocatore: chiedi all\'admin.');
    } else {
        [$ok, $msg] = daily_claim($me);
        flash($ok ? 'ok' : 'err', $msg);
    }
    redirect('daily.php');
}

$open = daily_window_open();
$cat = daily_catalog();
$progress = $me ? daily_progress($me) : 0;      // regali già ritirati
$next = $progress + 1;                           // prossimo regalo della sequenza
$canClaim = $me && daily_claimable($me);
$claimedToday = $me && daily_claimed_today($me);

layout_start('Daily access');
?>
<div class="narrow">
  <div class="card">
    <h1><i class="ti ti-gift"></i> Daily access</h1>
    <?php if (!$open && $progress === 0): ?>
      <?php $start = date('d/m/Y', strtotime(DAILY_START)); ?>
      <p class="muted">Le ricompense giornaliere <?= strtotime(DAILY_START) > time() ? 'partono il ' . $start : 'sono terminate' ?>.
        Ogni giorno in cui accedi ritiri il regalo successivo: KOIN, personalizzazioni ed esultanze. Salti un giorno? Non perdi
        nulla, ma avanzi più lentamente.</p>
    <?php else: ?>
      <p class="muted">Hai ritirato <strong><?= $progress ?></strong> regali su <?= DAILY_DAYS ?>.
        <?php if ($progress < DAILY_DAYS): ?>Prossimo: regalo <strong><?= $next ?></strong>.<?php endif; ?></p>
      <?php if ($canClaim): ?>
        <form method="post" class="form">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="claim">
          <button class="btn btn-primary btn-block"><i class="ti ti-gift"></i> Ritira il regalo di oggi: <?= h($cat[$next]['label'] ?? '') ?></button>
        </form>
      <?php elseif ($progress >= DAILY_DAYS): ?>
        <p><i class="ti ti-trophy"></i> Hai ritirato tutti i regali della campagna. Grande!</p>
      <?php elseif ($claimedToday): ?>
        <p><i class="ti ti-check"></i> Regalo di oggi già ritirato. Torna domani per il regalo <?= $next ?>.</p>
      <?php elseif (!$open): ?>
        <p><i class="ti ti-clock-x"></i> La campagna è finita: ti fermi a <?= $progress ?> regali su <?= DAILY_DAYS ?>.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="daily-grid">
    <?php foreach ($cat as $d => $r): ?>
      <?php
        if ($d <= $progress) {
            $cls = 'done'; $state = '<i class="ti ti-check"></i> ritirato';
        } elseif ($d === $next && $canClaim) {
            $cls = 'today'; $state = '<i class="ti ti-star"></i> da ritirare oggi';
        } elseif ($d === $next && $claimedToday) {
            $cls = 'today'; $state = '<i class="ti ti-clock"></i> domani';
        } elseif (!$open) {
            $cls = 'missed'; $state = '<i class="ti ti-x"></i> perso (campagna finita)';
        } else {
            $cls = 'future'; $state = '';
        }
      ?>
      <div class="daily-cell daily-<?= $cls ?>">
        <div class="daily-day">Regalo <?= $d ?></div>
        <div class="daily-reward"><?= h($r['label'] ?? '') ?></div>
        <div class="daily-state"><?= $state ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php
layout_end();
