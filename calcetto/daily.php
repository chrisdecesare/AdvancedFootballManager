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
    $json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if (!$me) {
        [$ok, $msg, $reward] = [false, 'Il tuo account non è collegato a un giocatore: chiedi all\'admin.', null];
    } else {
        [$ok, $msg, $reward] = daily_claim($me);
    }
    if ($json) {   // il premio compare in una finestra, senza ricaricare la pagina
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'msg' => $msg, 'reward' => $reward]);
        exit;
    }
    flash($ok ? 'ok' : 'err', $msg);
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
<div class="narrow daily-page" data-daily-card data-csrf="<?= h(csrf_token()) ?>">
  <div class="card">
    <h1><i class="ti ti-gift"></i> Daily access</h1>
    <?php if (!$open && $progress === 0): ?>
      <?php $start = date('d/m/Y', strtotime(DAILY_START)); ?>
      <p class="muted">Le ricompense giornaliere <?= strtotime(DAILY_START) > time() ? 'partono il ' . $start : 'sono terminate' ?>.
        Ogni giorno in cui accedi ritiri il regalo successivo: KOIN, personalizzazioni ed esultanze. Salti un giorno? Non perdi
        nulla, ma avanzi più lentamente.</p>
    <?php else: ?>
      <p class="muted">Hai ritirato <strong><?= $progress ?></strong> <?= $progress === 1 ? 'regalo' : 'regali' ?> su <?= DAILY_DAYS ?>.
        <?php if ($progress < DAILY_DAYS): ?>Prossimo: regalo <strong><?= $next ?></strong>.<?php endif; ?></p>
      <?php if ($canClaim): ?>
        <form method="post" class="form" data-daily-form>
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="claim">
          <button class="btn btn-primary btn-block" data-daily-claim><i class="ti ti-gift"></i> Ritira il regalo di oggi: <?= h($cat[$next]['label'] ?? '') ?></button>
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

  <div class="card daily-board">
    <h2><i class="ti ti-calendar-star"></i> I 30 regali</h2>
    <ol class="daily-grid">
      <?php foreach ($cat as $d => $r): ?>
        <?php
          if ($d <= $progress) {
              $cls = 'done'; $state = 'ritirato';
          } elseif ($d === $next && $canClaim) {
              $cls = 'today'; $state = 'oggi';
          } elseif ($d === $next && $claimedToday) {
              $cls = 'next'; $state = 'domani';
          } elseif (!$open) {
              $cls = 'missed'; $state = 'perso';
          } else {
              $cls = 'future'; $state = '';
          }
          $special = !empty($r['item']);
        ?>
        <li class="daily-cell daily-<?= $cls ?><?= $special ? ' is-special' : '' ?>" title="<?= h('Regalo ' . $d . ': ' . ($r['label'] ?? '')) ?>">
          <span class="daily-day"><?= $d ?></span>
          <i class="ti ti-<?= $special ? 'gift' : 'coin' ?> daily-ic" aria-hidden="true"></i>
          <span class="daily-val"><?= (int) ($r['koin'] ?? 0) ?> <small>KOIN</small></span>
          <?php if ($special): ?><span class="daily-item">+ oggetto</span><?php endif; ?>
          <?php if ($cls === 'done'): ?><i class="ti ti-check daily-check" aria-hidden="true"></i><?php endif; ?>
          <?php if ($state !== '' && $cls !== 'done'): ?><span class="daily-state"><?= h($state) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
    <p class="muted small">Le caselle col regalo danno anche un oggetto esclusivo, che non esce al Negozio. Se salti un giorno non perdi niente: il giorno dopo ritiri il regalo che ti spettava e riparti da lì.</p>
  </div>
</div>
<script src="assets/ruota.js?v=<?= h(substr((string) @md5_file(__DIR__ . '/assets/ruota.js'), 0, 10)) ?>" defer></script>
<?php
layout_end();
