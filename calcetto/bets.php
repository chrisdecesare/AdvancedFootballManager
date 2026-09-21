<?php
/* Scommesse goliardiche sulle partite (gettoni finti): vedi lib/bets.php per le regole. */
require __DIR__ . '/lib/bootstrap.php';
require_view();

$me = my_player_id();

/* ---------------------------------------------------------------- azioni */
if (is_post()) {
    require_login();
    $do = $_POST['do'] ?? '';
    $match = get_match((int) ($_POST['match_id'] ?? 0));
    $market = (string) ($_POST['market'] ?? '');
    $back = 'bets.php' . ($match ? '#m' . (int) $match['id'] : '');
    if (!$me) {
        flash('err', 'Il tuo account non è collegato a un giocatore: chiedi all\'admin, altrimenti niente scommesse.');
    } elseif (!$match || !match_access($match)) {
        flash('err', 'Partita non trovata.');
    } elseif (!is_admin() && !player_in_group($me, (int) $match['group_id'])) {
        flash('err', 'Puoi scommettere solo sulle partite del tuo gruppo.');
    } elseif ($do === 'bet') {
        wallet_open($me);
        $stake = max(0, (int) ($_POST['stake'] ?? 0));
        $err = bet_place($match, $me, $market, (string) ($_POST['pick'] ?? ''), $stake);
        if ($err) {
            flash('err', $err);
        } else {
            $label = bet_pick_label($match, $market, (string) $_POST['pick']);
            $jokes = ['Che Dio ti assista.', 'Coraggio, o incoscienza.', 'Gli amici ti guardano.', 'Si vedrà chi ride a fine partita.', 'Il banco ringrazia.'];
            flash('ok', "Puntati {$stake} gettoni su «{$label}». " . $jokes[array_rand($jokes)]);
        }
    } elseif ($do === 'cancel') {
        $err = bet_cancel($match, $me, $market);
        flash($err ? 'err' : 'ok', $err ?: 'Puntata ritirata: i gettoni sono tornati nel portafoglio. Vigliacco.');
    }
    redirect($back);
}

/* ---------------------------------------------------------------- dati */
$dole = $me ? wallet_open($me) : null;
if ($dole) {
    flash('ok', $dole);
}
$balance = $me ? wallet_balance($me) : 0;
$inPlay = $me ? wallet_in_play($me) : 0;
$board = bet_leaderboard();

$upcoming = q("SELECT * FROM matches WHERE status = 'programmata' AND " . scope_sql('group_id') . ' ORDER BY match_date ASC')->fetchAll();
$mine = [];        // le mie puntate, per partita e mercato
if ($me) {
    foreach (q('SELECT * FROM bets WHERE player_id = ?', [$me])->fetchAll() as $b) {
        $mine[(int) $b['match_id']][$b['market']] = $b;
    }
}
// scommesse fatte su partite già giocate ma non ancora pagate (aspettano l'MVP) e ultime decise
$waiting = $me ? q("SELECT b.*, m.match_date, m.team_a_name, m.team_b_name, m.score_a, m.score_b FROM bets b JOIN matches m ON m.id = b.match_id
                    WHERE b.player_id = ? AND b.status = 'aperta' AND m.status = 'giocata' AND " . scope_sql('m.group_id') . ' ORDER BY m.match_date DESC', [$me])->fetchAll() : [];
$history = $me ? q("SELECT b.*, m.match_date, m.team_a_name, m.team_b_name FROM bets b JOIN matches m ON m.id = b.match_id
                    WHERE b.player_id = ? AND b.status <> 'aperta' AND " . scope_sql('m.group_id') . ' ORDER BY b.settled_at DESC, b.id DESC LIMIT 12', [$me])->fetchAll() : [];
$net = 0;
foreach ($history as $b) {
    $net += (int) $b['payout'] - (int) $b['stake'];
}
$markets = bet_markets();

layout_start('Scommesse', 'bets');
?>
<div class="page-head"><h1>Scommesse <span class="muted small">a gettoni finti</span></h1></div>
<?= group_bar('bets.php') ?>

<section class="card wallet">
  <?php if ($me): $mp = get_player($me); ?>
    <div class="wallet-who"><?= avatar($mp, 'lg') ?>
      <div><strong class="wallet-name"><?= h($mp['name']) ?></strong>
        <span class="tag tag-mvp"><i class="ti ti-crown"></i> <?= h(bet_title($balance + $inPlay)) ?></span></div></div>
    <div class="wallet-num"><i class="ti ti-coin"></i> <strong><?= $balance ?></strong> <span>gettoni</span></div>
    <?php if ($inPlay): ?><div class="wallet-play muted small"><?= $inPlay ?> in gioco</div><?php endif; ?>
  <?php else: ?>
    <p class="empty">Il tuo account non è collegato a un giocatore: puoi guardare ma non scommettere.</p>
  <?php endif; ?>
  <p class="muted small wallet-rules">Si scommette solo con gettoni finti: nessun euro, solo onore e sfottò. Per ogni mercato il montepremi si divide tra chi indovina, in proporzione a quanto ha puntato
    (se non indovina nessuno, tutti riprendono i propri gettoni). Si punta fino al calcio d'inizio. Chi resta al verde riceve un sussidio di <?= BET_DOLE ?> gettoni a settimana.</p>
</section>

<h2 class="section-title">Da giocare</h2>
<?php if (!$upcoming): ?><p class="empty card">Nessuna partita in programma: appena ne crea una l'admin, si può puntare.</p><?php endif; ?>
<?php foreach ($upcoming as $m):
    $mid = (int) $m['id'];
    $open = bets_open_for($m);
    $cands = bet_candidates($mid);
    $all = match_bets($mid);
    $canBet = $me && $open && (is_admin() || player_in_group($me, (int) $m['group_id'])); ?>
<section class="card bet-match" id="m<?= $mid ?>">
  <div class="card-head">
    <h2><a href="match.php?id=<?= $mid ?>"><?= h(ucfirst(fmt_date_long($m['match_date']))) ?> · <?= fmt_time($m['match_date']) ?></a> <?= group_tag((int) $m['group_id']) ?></h2>
    <?php if ($open): ?><?= countdown_html($m['match_date'], 'Si chiude tra ', 'Chiuso', 0, true, 'hourglass') ?>
    <?php else: ?><span class="tag tag-live"><i class="ti ti-lock"></i> scommesse chiuse</span><?php endif; ?>
  </div>
  <div class="bet-markets">
  <?php foreach ($markets as $mk => $info):
      $list = $all[$mk] ?? [];
      $pool = array_sum(array_column($list, 'stake'));
      $byPick = [];
      foreach ($list as $b) {
          $byPick[$b['pick']] = ($byPick[$b['pick']] ?? 0) + (int) $b['stake'];
      }
      $opts = [];
      if ($mk === 'esito') {
          $opts = ['A' => team_name('A', $m), 'X' => 'Pareggio', 'B' => team_name('B', $m)];
      } else {
          foreach ($cands as $c) {
              $opts[(string) $c['player_id']] = $c['name'];
          }
      }
      $my = $mine[$mid][$mk] ?? null; ?>
    <div class="bet-market">
      <h3><i class="ti ti-<?= $info['icon'] ?>"></i> <?= h($info['label']) ?> <span class="muted small">· <?= h($info['when']) ?></span></h3>
      <?php if ($pool): ?>
        <div class="pool"><span class="pool-tot"><i class="ti ti-coin"></i> <?= $pool ?> nel piatto</span>
        <?php arsort($byPick); foreach (array_slice($byPick, 0, 4, true) as $pick => $sum): ?>
          <span class="pool-opt"><?= h($opts[$pick] ?? '?') ?> <b>×<?= fmt_num($pool / $sum, 1) ?></b></span>
        <?php endforeach; ?></div>
        <p class="bet-friends small muted"><?php foreach ($list as $i => $b): ?><?= $i ? ' · ' : '' ?><?= h($b['name']) ?> <b><?= (int) $b['stake'] ?></b> su <?= h($opts[$b['pick']] ?? '?') ?><?php endforeach; ?></p>
      <?php else: ?><p class="muted small">Ancora nessuna puntata: chi apre le danze?</p><?php endif; ?>
      <?php if ($canBet && $opts): ?>
        <form method="post" class="bet-form">
          <?= csrf_field() ?><input type="hidden" name="do" value="bet"><input type="hidden" name="match_id" value="<?= $mid ?>"><input type="hidden" name="market" value="<?= $mk ?>">
          <select name="pick" required aria-label="Su chi punti">
            <option value="">Scegli…</option>
            <?php foreach ($opts as $val => $label): ?><option value="<?= h((string) $val) ?>" <?= $my && (string) $my['pick'] === (string) $val ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
          </select>
          <input type="number" name="stake" min="1" max="<?= $balance + ($my ? (int) $my['stake'] : 0) ?>" inputmode="numeric" value="<?= $my ? (int) $my['stake'] : min(10, max(1, $balance)) ?>" required aria-label="Gettoni">
          <button class="btn btn-primary btn-sm"><?= $my ? 'Cambia' : 'Punta' ?></button>
        </form>
        <?php if ($my): ?>
          <form method="post" class="bet-mine"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="match_id" value="<?= $mid ?>"><input type="hidden" name="market" value="<?= $mk ?>">
            <span class="tag tag-ok"><i class="ti ti-check"></i> Hai puntato <?= (int) $my['stake'] ?> su <?= h($opts[$my['pick']] ?? '?') ?></span>
            <button class="btn btn-ghost btn-sm" data-confirm="Ritirare la puntata? Vigliacco.">Ritira</button></form>
        <?php endif; ?>
      <?php elseif ($my): ?>
        <p><span class="tag tag-ok"><i class="ti ti-check"></i> Hai puntato <?= (int) $my['stake'] ?> su <?= h($opts[$my['pick']] ?? '?') ?></span></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>

<?php if ($waiting): ?>
<h2 class="section-title">Aspettando il verdetto</h2>
<div class="card table-card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Partita</th><th>Mercato</th><th>La tua scelta</th><th>Puntati</th></tr></thead><tbody>
  <?php foreach ($waiting as $b): ?>
    <tr><td><a href="match.php?id=<?= (int) $b['match_id'] ?>"><?= fmt_date_short($b['match_date']) ?></a></td>
      <td><?= h($markets[$b['market']]['label'] ?? $b['market']) ?></td>
      <td><?= h(bet_pick_label($b, $b['market'], $b['pick'])) ?></td><td><?= (int) $b['stake'] ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <p class="muted small">Si pagano appena le votazioni si chiudono.</p></div>
<?php endif; ?>

<h2 class="section-title">Classifica dei ricchi</h2>
<?php if (!$board): ?><p class="empty card">Nessuno ha ancora aperto il portafoglio.</p><?php else: ?>
<div class="card table-card"><div class="table-wrap"><table class="table">
  <thead><tr><th>#</th><th>Giocatore</th><th class="bet-col-title">Titolo</th><th>Gettoni</th><th title="Puntate vinte su decise">Vinte</th></tr></thead><tbody>
  <?php foreach ($board as $i => $r): $tot = (int) $r['balance'] + (int) $r['in_play']; ?>
    <tr<?= (int) $r['id'] === $me ? ' class="row-mvp"' : '' ?>>
      <td class="rank rank-<?= $i + 1 ?>"><span><?= $i + 1 ?></span></td>
      <td><a class="tname" href="player.php?id=<?= (int) $r['id'] ?>"><?= avatar($r, 'xs') ?> <?= h($r['name']) ?></a></td>
      <td class="muted bet-col-title"><?= h($i === 0 ? 'Il Banco · ' . bet_title($tot) : bet_title($tot)) ?></td>
      <td><strong><?= $tot ?></strong><?php if ((int) $r['in_play']): ?> <span class="muted small">(<?= (int) $r['in_play'] ?> in gioco)</span><?php endif; ?></td>
      <td><?= (int) $r['decided'] ? (int) $r['wins'] . '/' . (int) $r['decided'] : '–' ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<?php if ($history): ?>
<h2 class="section-title">Le tue ultime scommesse <span class="muted small">saldo <?= fmt_signed($net, 0) ?></span></h2>
<div class="card table-card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Partita</th><th>Mercato</th><th>Scelta</th><th>Puntati</th><th>Esito</th></tr></thead><tbody>
  <?php foreach ($history as $b): ?>
    <tr><td><a href="match.php?id=<?= (int) $b['match_id'] ?>"><?= fmt_date_short($b['match_date']) ?></a></td>
      <td><?= h($markets[$b['market']]['label'] ?? $b['market']) ?></td>
      <td><?= h(bet_pick_label($b, $b['market'], $b['pick'])) ?></td><td><?= (int) $b['stake'] ?></td>
      <td><?php if ($b['status'] === 'vinta'): ?><span class="tag tag-ok">vinta +<?= (int) $b['payout'] - (int) $b['stake'] ?></span>
        <?php elseif ($b['status'] === 'persa'): ?><span class="tag tag-live">persa −<?= (int) $b['stake'] ?></span>
        <?php else: ?><span class="tag">rimborsata</span><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>
<?php
layout_end();
