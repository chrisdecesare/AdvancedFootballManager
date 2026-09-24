<?php
/* Scommesse goliardiche sulle partite (gettoni finti): vedi lib/bets.php per le regole. */
require __DIR__ . '/lib/bootstrap.php';
require_view();

$me = my_player_id();
$tab = match ($_GET['t'] ?? '') { 'mie', 'multiple' => 'mie', 'classifica' => 'classifica', default => 'partite' };   // 'multiple': vecchi link

/* ---------------------------------------------------------------- azioni */
if (is_post()) {
    require_login();
    $do = $_POST['do'] ?? '';

    if ($do === 'bet_multi') {
        if (!$me) {
            flash('err', 'Il tuo account non è collegato a un giocatore: chiedi all\'admin, altrimenti niente scommesse.');
        } else {
            wallet_open($me);
            $legsRaw = is_array($_POST['legs'] ?? null) ? $_POST['legs'] : [];
            $stakesRaw = is_array($_POST['stakes'] ?? null) ? $_POST['stakes'] : [];
            $ok = 0;
            $total = 0;
            $firstErr = null;
            foreach ($legsRaw as $i => $r) {
                [$matchId, $market, $pick] = array_pad(explode(':', (string) $r, 3), 3, null);
                $m = ((int) $matchId) ? get_match((int) $matchId) : null;
                $stake = max(0, (int) ($stakesRaw[$i] ?? 0));
                if (!$m || !match_access($m)) {
                    $firstErr ??= 'Una delle partite della schedina non è più valida.';
                    continue;
                }
                if (!is_admin() && !player_in_group($me, (int) $m['group_id'])) {
                    $firstErr ??= 'Puoi scommettere solo sulle partite del tuo gruppo.';
                    continue;
                }
                $err = bet_place($m, $me, (string) $market, (string) $pick, $stake);
                if ($err) {
                    $firstErr ??= $err;
                } else {
                    $ok++;
                    $total += $stake;
                }
            }
            if ($ok) {
                flash('ok', $ok . ($ok > 1 ? ' puntate singole piazzate' : ' puntata singola piazzata') . " ({$total} gettoni in tutto)."
                    . ($firstErr ? ' Una non è andata a buon fine: ' . $firstErr : ''));
            } else {
                flash('err', $firstErr ?: 'Nessuna puntata piazzata.');
            }
        }
        redirect('bets.php');
    }
    if ($do === 'combo_bet') {
        if (!$me) {
            flash('err', 'Il tuo account non è collegato a un giocatore: chiedi all\'admin, altrimenti niente scommesse.');
        } else {
            wallet_open($me);
            $raw = is_array($_POST['legs'] ?? null) ? $_POST['legs'] : [];
            [$legs, $err] = combo_prepare($raw, $me);
            if (!$err) {
                $stake = max(0, (int) ($_POST['stake'] ?? 0));
                $err = combo_place($me, $legs, $stake);
            }
            if ($err) {
                flash('err', $err);
            } else {
                $odds = combo_odds($legs);
                flash('ok', 'Multipla da ' . count($legs) . ' su ' . $stake . ' gettoni a ×' . fmt_num($odds, 2)
                    . ': se le indovini tutte vinci ' . bet_payout($stake, $odds) . '. Chi non risica...');
            }
        }
        redirect('bets.php?t=mie');
    }
    if ($do === 'combo_cancel') {
        $err = $me ? combo_cancel((int) ($_POST['combo_id'] ?? 0), $me) : 'Il tuo account non è collegato a un giocatore.';
        flash($err ? 'err' : 'ok', $err ?: 'Multipla ritirata: i gettoni sono tornati nel portafoglio. Vigliacco.');
        redirect('bets.php?t=mie');
    }

    $match = get_match((int) ($_POST['match_id'] ?? 0));
    $market = (string) ($_POST['market'] ?? '');
    $back = ($_POST['from'] ?? '') === 'mie' ? 'bets.php?t=mie' : 'bets.php' . ($match ? '#m' . (int) $match['id'] : '');
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
        $err = bet_cancel($match, $me, $market, (string) ($_POST['pick'] ?? ''));
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
$mine = [];        // le mie puntate aperte, per partita e mercato: può essercene più di una (scelte diverse dello stesso mercato)
if ($me) {
    foreach (q('SELECT * FROM bets WHERE player_id = ?', [$me])->fetchAll() as $b) {
        $mine[(int) $b['match_id']][$b['market']][] = $b;
    }
}
// scheda «Scommesse»: tutte le mie singole, quelle ancora in gioco (partita da giocare o in attesa del verdetto) e quelle decise
$openSingles = $me ? q("SELECT b.*, m.match_date, m.team_a_name, m.team_b_name, m.status AS match_status, m.voting_open FROM bets b JOIN matches m ON m.id = b.match_id
                    WHERE b.player_id = ? AND b.status = 'aperta' AND " . scope_sql('m.group_id') . ' ORDER BY m.match_date ASC, b.id', [$me])->fetchAll() : [];
$history = $me ? q("SELECT b.*, m.match_date, m.team_a_name, m.team_b_name FROM bets b JOIN matches m ON m.id = b.match_id
                    WHERE b.player_id = ? AND b.status <> 'aperta' AND " . scope_sql('m.group_id') . ' ORDER BY b.settled_at DESC, b.id DESC', [$me])->fetchAll() : [];
$net = 0;
foreach ($history as $b) {
    $net += (int) $b['payout'] - (int) $b['stake'];
}
$markets = bet_markets();

$comboOpen = $me ? combo_open($me) : [];
$comboHist = $me ? combo_history($me, 1000) : [];
$comboNet = 0;
foreach ($comboHist as $c) {
    $comboNet += (int) $c['payout'] - (int) $c['stake'];
}

/** Una multipla disegnata come un foglio di bloc-notes scritto a mano: sopra le scelte con le loro quote, sotto la vincita. */
function combo_sheet(array $c, array $markets, bool $open): string
{
    $marks = ['vinta' => ['ok', 'check'], 'persa' => ['ko', 'x'], 'rimborsata' => ['void', 'arrow-back-up']];
    // si ritira solo finché nessuna delle sue partite è iniziata (stessa regola di combo_cancel)
    $canCancel = $open && !array_filter($c['legs'], fn($l) => strtotime($l['match_date']) <= time());
    ob_start(); ?>
    <article class="notepad">
      <div class="notepad-rings" aria-hidden="true"></div>
      <div class="notepad-sheet">
        <p class="notepad-title">Multipla da <?= count($c['legs']) ?> <span><?= fmt_date_short($c['created_at']) ?></span></p>
        <ol class="notepad-legs">
          <?php foreach ($c['legs'] as $l): $mk = $marks[$l['status']] ?? null; ?>
            <li class="<?= $mk ? 'leg-' . $mk[0] : '' ?>">
              <span class="np-what"><span class="np-ctx"><?= date('d/m', strtotime($l['match_date'])) ?> · <?= h(rtrim($markets[$l['market']]['label'] ?? $l['market'], '?')) ?>:</span> <b><?= h($l['label']) ?></b></span>
              <span class="np-dots" aria-hidden="true"></span>
              <span class="np-odds"><?= fmt_num($l['odds'], 2) ?></span>
              <?php if ($mk): ?><i class="ti ti-<?= $mk[1] ?> np-mark" title="<?= h($l['status']) ?>"></i><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
        <div class="notepad-sum">
          <p><span>Quota totale</span><span class="np-dots" aria-hidden="true"></span><b>×<?= fmt_num($c['odds'], 2) ?></b></p>
          <p><span>Puntata</span><span class="np-dots" aria-hidden="true"></span><b><?= (int) $c['stake'] ?></b></p>
          <?php if ($open): ?>
            <p class="np-win"><span>Vincita</span><span class="np-dots" aria-hidden="true"></span><b><?= bet_payout((int) $c['stake'], $c['odds']) ?></b></p>
          <?php elseif ($c['status'] === 'vinta'): ?>
            <p class="np-win"><span>Vinto</span><span class="np-dots" aria-hidden="true"></span><b>+<?= (int) $c['payout'] - (int) $c['stake'] ?></b></p>
          <?php elseif ($c['status'] === 'persa'): ?>
            <p class="np-lost"><span>Vincita</span><span class="np-dots" aria-hidden="true"></span><b><s><?= bet_payout((int) $c['stake'], $c['odds']) ?></s></b></p>
          <?php endif; ?>
        </div>
        <?php if (!$open): ?><span class="np-stamp np-stamp-<?= h($c['status']) ?>"><?= h($c['status']) ?></span><?php endif; ?>
        <?php if ($open): ?>
          <form method="post" class="notepad-foot"><?= csrf_field() ?><input type="hidden" name="do" value="combo_cancel"><input type="hidden" name="combo_id" value="<?= (int) $c['id'] ?>">
            <span class="muted small">solo se sono giuste tutte le <?= count($c['legs']) ?> scelte</span>
            <?php if ($canCancel): ?><button class="btn btn-ghost btn-sm" data-confirm="Ritirare la multipla? Vigliacco.">Ritira</button><?php endif; ?></form>
        <?php endif; ?>
      </div>
    </article>
    <?php return ob_get_clean();
}

layout_start('Scommesse', 'bets');
?>
<div class="page-head"><h1>Scommesse <span class="muted small">a gettoni finti</span></h1></div>
<?= group_bar('bets.php') ?>

<section class="card wallet">
  <?php if ($me): $mp = get_player($me); ?>
    <div class="wallet-who"><?= avatar($mp, 'lg') ?>
      <div><strong class="wallet-name"><?= h($mp['name']) ?></strong>
        <span class="tag tag-mvp"><i class="ti ti-crown"></i> <?= h(bet_title($balance + $inPlay)) ?></span></div></div>
  <?php else: ?>
    <p class="empty">Il tuo account non è collegato a un giocatore: puoi guardare ma non scommettere.</p>
  <?php endif; ?>
  <p class="muted small wallet-rules">Si scommette solo con gettoni finti: nessun euro, solo onore e sfottò. Ogni scelta ha la sua <b>quota</b>, calcolata come dai bookmaker (probabilità stimate da gol, forma e voti, più il margine del banco): se indovini vinci puntata × quota, se sbagli perdi la puntata
    (se manca il dato, per esempio nessuno vota l'MVP, tutti riprendono i gettoni). La quota che vedi quando punti è quella che vale, e si abbassa un po' per ogni gettone già puntato sulla stessa scelta: prima punti su una scelta affollata, meglio è. Oltre a chi vince e all'MVP puoi puntare su chi segna, chi fa doppietta (almeno 2 gol) o tripletta (almeno 3) e sull'over/under dei gol totali della partita, scegliendo tu la soglia (la quota cambia con lei).
    Sui mercati dei giocatori puoi puntare su più giocatori della stessa partita, ognuno la sua scommessa. Si punta fino al calcio d'inizio.
    Tocca una quota per aggiungerla alla <b>schedina</b> (anche da partite diverse): da lì punti ogni scelta da sola, oppure le combini in una <b>multipla</b> dove le quote si moltiplicano (ma basta sbagliarne una per perdere tutto).
    I tuoi gettoni si vedono sempre in alto accanto al profilo e servono per il <a class="link" href="shop.php">Negozio</a>, ora una sezione a parte: sfondi, nickname e copricapi per il profilo. Chi resta al verde riceve un sussidio di <?= BET_DOLE ?> gettoni a settimana.</p>
</section>

<nav class="shop-tabs">
  <a href="bets.php" class="<?= $tab === 'partite' ? 'active' : '' ?>"><i class="ti ti-calendar-event"></i> Partite</a>
  <a href="bets.php?t=mie" class="<?= $tab === 'mie' ? 'active' : '' ?>"><i class="ti ti-receipt"></i> Scommesse<?php if ($openSingles || $comboOpen): ?> <span class="count"><?= count($openSingles) + count($comboOpen) ?></span><?php endif; ?></a>
  <a href="bets.php?t=classifica" class="<?= $tab === 'classifica' ? 'active' : '' ?>"><i class="ti ti-trophy"></i> Classifica</a>
</nav>

<?php if ($tab === 'partite'): ?>
<h2 class="section-title">Da giocare</h2>
<?php if (!$upcoming): ?><p class="empty card">Nessuna partita in programma: appena ne crea una l'admin, si può puntare.</p><?php endif; ?>
<?php foreach ($upcoming as $m):
    $mid = (int) $m['id'];
    $open = bets_open_for($m);
    $cands = bet_candidates($mid);
    $all = is_admin() ? match_bets($mid) : [];   // chi ha puntato su cosa lo vede solo l'admin: ai giocatori resta la sorpresa
    $quotes = bet_quotes($m);
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
      $opts = [];
      if ($mk === 'esito') {
          $opts = ['A' => team_name('A', $m), 'X' => 'Pareggio', 'B' => team_name('B', $m)];
      } elseif ($mk === 'overunder') {
          // la soglia la sceglie chi punta: niente elenco di pulsanti, c'è il selettore qui sotto (quote per ogni soglia)
      } else {
          foreach ($cands as $c) {
              $opts[(string) $c['player_id']] = $c['name'];
          }
      }
      $q = $quotes[$mk] ?? [];
      $myList = $mine[$mid][$mk] ?? [];
      $myPicks = array_column($myList, 'pick'); ?>
    <div class="bet-market">
      <h3><i class="ti ti-<?= $info['icon'] ?>"></i> <?= h($info['label']) ?> <span class="muted small">· <?= h($info['when']) ?></span></h3>
      <?php if ($list): ?><p class="bet-friends small muted"><?php foreach ($list as $i => $b): ?><?= $i ? ' · ' : '' ?><?= h($b['name']) ?> <b><?= (int) $b['stake'] ?></b> su <?= h($opts[$b['pick']] ?? bet_pick_label($m, $mk, (string) $b['pick'])) ?><?php endforeach; ?></p><?php endif; ?>
      <?php if ($canBet && $mk === 'overunder' && $q):
          $ouTable = [];
          foreach ($q as $pk => $qv) {
              if ($ou = bet_ou_parse((string) $pk)) {
                  $ouTable[number_format($ou[1], 1, '.', '')][$ou[0] === 'O' ? 0 : 1] = (float) $qv;
              }
          }
          // soglia di partenza: quella dove over e under sono più alla pari
          uasort($ouTable, fn($x, $y) => abs($x[0] - $x[1]) <=> abs($y[0] - $y[1]));
          $ouStart = (float) array_key_first($ouTable);
          ksort($ouTable, SORT_NUMERIC);
          $ouMax = (float) array_key_last($ouTable);
          $ouBtn = fn(string $side) => '<button type="button" class="quota-btn" data-slip-add data-ou-side="' . $side . '" data-match="' . $mid . '" data-market="overunder"'
              . ' data-pick="' . h(bet_ou_pick($side, $ouStart)) . '" data-label="' . h(bet_pick_label($m, 'overunder', bet_ou_pick($side, $ouStart))) . '"'
              . ' data-odds="' . h((string) $ouTable[number_format($ouStart, 1, '.', '')][$side === 'O' ? 0 : 1]) . '"'
              . ' data-match-label="' . h(fmt_date_short($m['match_date'])) . ' · ' . h($info['label']) . '" title="Aggiungi alla schedina">'
              . ($side === 'O' ? 'Over' : 'Under') . ' <b>×' . fmt_num($ouTable[number_format($ouStart, 1, '.', '')][$side === 'O' ? 0 : 1], 2) . '</b></button>'; ?>
        <p class="muted small" style="margin:0">Scegli quanti gol devono essere superati (o no), poi tocca Over o Under: la quota cambia con la soglia.</p>
        <div class="ou-picker" data-ou="<?= h(json_encode($ouTable)) ?>" data-mine="<?= h(json_encode(array_values($myPicks))) ?>">
          <span class="ou-label small">Gol totali</span>
          <button type="button" class="ou-step" data-ou-step="-1" aria-label="Soglia più bassa">−</button>
          <input type="number" class="ou-line" min="0.5" max="<?= $ouMax ?>" step="1" value="<?= $ouStart ?>" inputmode="decimal" aria-label="Soglia dei gol totali">
          <button type="button" class="ou-step" data-ou-step="1" aria-label="Soglia più alta">+</button>
          <?= $ouBtn('O') ?><?= $ouBtn('U') ?>
          <span class="ou-help muted small">Over: <b class="ou-over-txt"><?= (int) ceil($ouStart) ?> o più gol</b> · Under: <b class="ou-under-txt"><?= (int) floor($ouStart) ?> o meno</b></span>
        </div>
      <?php elseif ($canBet && $opts): ?>
        <p class="muted small" style="margin:0">Tocca una quota per aggiungerla alla schedina<?= in_array($mk, BET_PLAYER_MARKETS, true) ? ' (anche più di una: es. due marcatori diversi)' : '' ?>:</p>
        <div class="quota-picks">
          <?php foreach ($opts as $val => $label): if (!isset($q[$val])) { continue; } $qv = $q[$val];
              $isMine = in_array((string) $val, $myPicks, true); ?>
            <button type="button" class="quota-btn<?= $isMine ? ' is-mine' : '' ?>" data-slip-add
              data-match="<?= $mid ?>" data-market="<?= $mk ?>" data-pick="<?= h((string) $val) ?>" data-label="<?= h($label) ?>" data-odds="<?= h((string) $qv) ?>"
              data-match-label="<?= h(fmt_date_short($m['match_date'])) ?> · <?= h($info['label']) ?>"
              title="<?= $isMine ? 'Hai già puntato qui' : 'Aggiungi alla schedina' ?>"><?= h($label) ?> <b>×<?= fmt_num($qv, 2) ?></b></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($myList): ?>
        <div class="bet-mine-list">
        <?php foreach ($myList as $my): ?>
          <form method="post" class="bet-mine"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="match_id" value="<?= $mid ?>">
            <input type="hidden" name="market" value="<?= $mk ?>"><input type="hidden" name="pick" value="<?= h((string) $my['pick']) ?>">
            <span class="tag tag-ok"><i class="ti ti-check"></i> <?= (int) $my['stake'] ?> su <?= h($opts[$my['pick']] ?? bet_pick_label($m, $mk, (string) $my['pick'])) ?> a ×<?= fmt_num($my['odds'], 2) ?> = <?= bet_payout((int) $my['stake'], $my['odds']) ?></span>
            <?php if ($canBet): ?><button class="btn btn-ghost btn-sm" data-confirm="Ritirare la puntata? Vigliacco.">Ritira</button><?php endif; ?></form>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>

<?php elseif ($tab === 'mie'): ?>

<?php if (!$me): ?><p class="empty card">Il tuo account non è collegato a un giocatore: niente scommesse da mostrare.</p>
<?php elseif (!$openSingles && !$comboOpen && !$history && !$comboHist): ?>
<p class="empty card">Non hai ancora fatto nessuna scommessa: vai su <a class="link" href="bets.php">Partite</a> e tocca una quota.</p>
<?php else: ?>
<p class="muted small">Tutte le tue scommesse, singole e multiple: in gioco <b><?= $inPlay ?></b> gettoni · saldo delle scommesse decise <b><?= fmt_signed($net + $comboNet, 0) ?></b>.</p>

<h2 class="section-title">Singole in corso</h2>
<?php if (!$openSingles): ?><p class="empty card">Nessuna singola in corso.</p><?php else: ?>
<div class="card table-card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Partita</th><th>Mercato</th><th>La tua scelta</th><th>Puntati</th><th>Quota</th><th>Vinci</th><th></th></tr></thead><tbody>
  <?php foreach ($openSingles as $b):
      $betOpen = bets_open_for(['status' => $b['match_status'], 'match_date' => $b['match_date']]); ?>
    <tr><td><a href="match.php?id=<?= (int) $b['match_id'] ?>"><?= fmt_date_short($b['match_date']) ?></a></td>
      <td><?= h($markets[$b['market']]['label'] ?? $b['market']) ?></td>
      <td><?= h(bet_pick_label($b, $b['market'], $b['pick'])) ?></td><td><?= (int) $b['stake'] ?></td><td>×<?= fmt_num($b['odds'], 2) ?></td>
      <td><?= bet_payout((int) $b['stake'], $b['odds']) ?></td>
      <td><?php if ($betOpen): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="from" value="mie">
            <input type="hidden" name="match_id" value="<?= (int) $b['match_id'] ?>"><input type="hidden" name="market" value="<?= h($b['market']) ?>"><input type="hidden" name="pick" value="<?= h((string) $b['pick']) ?>">
            <button class="btn btn-ghost btn-sm" data-confirm="Ritirare la puntata? Vigliacco.">Ritira</button></form>
        <?php elseif ($b['match_status'] === 'giocata'): ?><span class="tag"><?= $b['market'] === 'mvp' && $b['voting_open'] ? 'aspetta l\'MVP' : 'in attesa del verdetto' ?></span>
        <?php else: ?><span class="tag tag-live">si gioca</span><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<h2 class="section-title">Multiple in corso</h2>
<?php if (!$comboOpen): ?><p class="empty card">Nessuna multipla in corso: tocca almeno due quote nella scheda <a class="link" href="bets.php">Partite</a> e combinale nella scheda «Multipla» della schedina.</p><?php else: ?>
<div class="notepad-list">
  <?php foreach ($comboOpen as $c): ?><?= combo_sheet($c, $markets, true) ?><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($history): ?>
<h2 class="section-title">Singole decise <span class="muted small">saldo <?= fmt_signed($net, 0) ?></span></h2>
<div class="card table-card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Partita</th><th>Mercato</th><th>Scelta</th><th>Puntati</th><th>Quota</th><th>Esito</th></tr></thead><tbody>
  <?php foreach ($history as $b): ?>
    <tr><td><a href="match.php?id=<?= (int) $b['match_id'] ?>"><?= fmt_date_short($b['match_date']) ?></a></td>
      <td><?= h($markets[$b['market']]['label'] ?? $b['market']) ?></td>
      <td><?= h(bet_pick_label($b, $b['market'], $b['pick'])) ?></td><td><?= (int) $b['stake'] ?></td><td>×<?= fmt_num($b['odds'], 2) ?></td>
      <td><?php if ($b['status'] === 'vinta'): ?><span class="tag tag-ok">vinta +<?= (int) $b['payout'] - (int) $b['stake'] ?></span>
        <?php elseif ($b['status'] === 'persa'): ?><span class="tag tag-live">persa −<?= (int) $b['stake'] ?></span>
        <?php else: ?><span class="tag">rimborsata</span><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<?php if ($comboHist): ?>
<h2 class="section-title">Multiple decise <span class="muted small">saldo <?= fmt_signed($comboNet, 0) ?></span></h2>
<div class="notepad-list">
  <?php foreach ($comboHist as $c): ?><?= combo_sheet($c, $markets, false) ?><?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php else: ?>

<h2 class="section-title">Classifica dei ricchi</h2>
<?php if (!$board): ?><p class="empty card">Nessuno ha ancora aperto il portafoglio.</p><?php else: ?>
<div class="card table-card"><div class="table-wrap"><table class="table">
  <thead><tr><th>#</th><th>Giocatore</th><th class="bet-col-title">Titolo</th><th>Gettoni</th><th title="Puntate (singole e multiple) vinte su decise">Vinte</th></tr></thead><tbody>
  <?php foreach ($board as $i => $r): $tot = (int) $r['balance'] + (int) $r['in_play']; ?>
    <tr<?= (int) $r['id'] === $me ? ' class="row-mvp"' : '' ?>>
      <td class="rank rank-<?= $i + 1 ?>"><span><?= $i + 1 ?></span></td>
      <td><a class="tname" href="player.php?id=<?= (int) $r['id'] ?>"><?= avatar($r, 'xs') ?> <?= h($r['name']) ?></a></td>
      <td class="muted bet-col-title"><?= h($i === 0 ? 'Il Banco · ' . bet_title($tot) : bet_title($tot)) ?></td>
      <td><strong><?= $tot ?></strong><?php if ((int) $r['in_play']): ?> <span class="muted small">(<?= (int) $r['in_play'] ?> in gioco)</span><?php endif; ?></td>
      <td><?= (int) $r['decided'] ? (int) $r['wins'] . '/' . (int) $r['decided'] : '–' ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<?php endif; ?>

<div id="betslip" class="betslip" data-balance="<?= (int) $balance ?>" data-mode="singole" hidden>
  <div class="betslip-head">
    <span class="muted small"><i class="ti ti-stack-2"></i> Schedina (<span id="slip-count">0</span>)</span>
    <div class="slip-tabs" role="tablist">
      <button type="button" class="slip-tab active" data-slip-tab="singole" role="tab" aria-selected="true">Singole</button>
      <button type="button" class="slip-tab" data-slip-tab="multipla" id="slip-tab-multi" role="tab" aria-selected="false" disabled title="Servono almeno 2 selezioni">Multipla</button>
    </div>
  </div>
  <p class="slip-hint muted small" id="slip-hint"></p>
  <div class="betslip-legs" id="slip-legs"></div>

  <form method="post" class="betslip-actions" id="slip-singles-form" data-slip-panel="singole">
    <?= csrf_field() ?><input type="hidden" name="do" value="bet_multi">
    <span class="betslip-total small" id="slip-singles-total"></span>
    <button type="submit" class="btn btn-primary btn-sm" id="slip-singles-submit" disabled>Punta le singole</button>
    <button type="button" class="btn btn-ghost btn-sm" id="slip-clear">Svuota schedina</button>
  </form>

  <form method="post" class="betslip-actions" id="slip-multi-form" data-slip-panel="multipla" hidden>
    <?= csrf_field() ?><input type="hidden" name="do" value="combo_bet">
    <div id="slip-multi-legs"></div>
    <span class="betslip-odds small">Quota multipla <b id="slip-multi-odds">×0</b></span>
    <label class="betslip-stake small">Importo <input type="number" name="stake" id="slip-multi-stake" min="1" max="<?= max(1, $balance) ?>" value="<?= min(10, max(1, $balance)) ?>" inputmode="numeric" aria-label="Gettoni sulla multipla"></label>
    <span class="betslip-total small" id="slip-multi-win"></span>
    <button type="submit" class="btn btn-primary btn-sm" id="slip-multi-submit">Punta la multipla</button>
    <button type="button" class="btn btn-ghost btn-sm" id="slip-clear-2">Svuota schedina</button>
    <span class="muted small slip-multi-warn" id="slip-multi-warn" hidden></span>
  </form>
</div>

<script>
// Schedina: si clicca su una quota per aggiungerla (come in un'app di scommesse vera). Da qui si punta ogni selezione
// da sola (scheda «Singole», una puntata indipendente ciascuna) oppure tutte insieme in una sola multipla (scheda «Multipla»,
// quota = prodotto delle quote). Sopravvive alla navigazione tra le schede della pagina (sessionStorage).
(() => {
  const KEY = 'betslipCart';
  const slip = document.getElementById('betslip');
  if (!slip) return;
  const balance = parseInt(slip.dataset.balance, 10) || 0;
  const legsBox = document.getElementById('slip-legs');
  const countOut = document.getElementById('slip-count');
  const tabSingole = document.querySelector('[data-slip-tab="singole"]');
  const tabMulti = document.getElementById('slip-tab-multi');
  const panelSingole = document.getElementById('slip-singles-form');
  const panelMulti = document.getElementById('slip-multi-form');
  const multiLegsBox = document.getElementById('slip-multi-legs');
  const multiOddsOut = document.getElementById('slip-multi-odds');
  const singlesSubmit = document.getElementById('slip-singles-submit');

  const load = () => { try { return JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { return []; } };
  const save = cart => { try { sessionStorage.setItem(KEY, JSON.stringify(cart)); } catch (e) {} };
  let cart = load();
  let tab = 'singole';

  const hidden = (name, value) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = name; i.value = value; return i; };
  const win = (stake, odds) => Math.floor(stake * odds + 1e-9);

  const hint = document.getElementById('slip-hint');
  const singlesTotal = document.getElementById('slip-singles-total');
  const multiStake = document.getElementById('slip-multi-stake');
  const multiWin = document.getElementById('slip-multi-win');

  // Singole: ogni selezione ha il suo importo ed è una scommessa a sé. Multipla: un solo importo per tutte le selezioni,
  // la quota è il prodotto delle quote e si viene pagati solo se sono giuste TUTTE (basta un errore e si perde tutto).
  const showTab = t => {
    tab = t;
    slip.dataset.mode = t;
    tabSingole.classList.toggle('active', t === 'singole');
    tabMulti.classList.toggle('active', t === 'multipla');
    tabSingole.setAttribute('aria-selected', t === 'singole');
    tabMulti.setAttribute('aria-selected', t === 'multipla');
    panelSingole.hidden = t !== 'singole';
    panelMulti.hidden = t !== 'multipla';
    updateTotals();
  };

  const multiOddsNow = () => cart.reduce((o, l) => o * l.odds, 1);
  const updateTotals = () => {
    let staked = 0, maxWin = 0;
    cart.forEach(l => { const n = l.stake || 0; staked += n; maxWin += n > 0 ? win(n, l.odds) : 0; });
    singlesTotal.textContent = cart.length ? 'Totale puntato ' + staked + ' · se vincono tutte incassi ' + maxWin : '';
    const n = parseInt(multiStake.value, 10) || 0;
    multiWin.textContent = cart.length >= 2 && n > 0
      ? 'Vinci ' + win(n, multiOddsNow()) + ' solo se sono giuste tutte le ' + cart.length + ' scelte'
      : '';
    hint.textContent = tab === 'singole'
      ? 'Singole: ogni scelta è una scommessa separata con il suo importo, si paga ognuna per conto suo.'
      : 'Multipla: un solo importo su tutte le scelte insieme, le quote si moltiplicano. Si viene pagati solo se sono giuste tutte: basta un errore e si perde la puntata.';
  };
  multiStake.addEventListener('input', updateTotals);

  const render = () => {
    legsBox.innerHTML = '';
    multiLegsBox.innerHTML = '';
    countOut.textContent = cart.length;
    let multiOdds = 1;

    cart.forEach((leg, i) => {
      multiOdds *= leg.odds;

      const row = document.createElement('div');
      row.className = 'slip-leg';
      row.innerHTML = '<span class="slip-leg-txt">' + leg.matchLabel + ': <b>' + leg.label + '</b> ×' + leg.odds.toFixed(2) + '</span>';

      // scheda «Singole»: uno stake per selezione, aggiornato in tempo reale. La lista è fuori dal <form> (resta visibile
      // anche nella scheda Multipla), quindi questi campi si legano al form con l'attributo form="..." invece che con il nesting,
      // altrimenti il browser non li invia e "Punta le singole" risulta come se non si fosse puntato nulla.
      const legIn = hidden('legs[]', leg.matchId + ':' + leg.market + ':' + leg.pick);
      legIn.setAttribute('form', 'slip-singles-form');
      const stakeIn = document.createElement('input');
      stakeIn.type = 'number'; stakeIn.name = 'stakes[]'; stakeIn.className = 'slip-leg-stake';
      stakeIn.min = 1; stakeIn.max = Math.max(1, balance); stakeIn.inputMode = 'numeric';
      stakeIn.value = leg.stake || Math.min(10, Math.max(1, balance));
      stakeIn.setAttribute('aria-label', 'Gettoni su questa selezione');
      stakeIn.setAttribute('form', 'slip-singles-form');
      row.appendChild(legIn);
      row.appendChild(stakeIn);
      const winOut = document.createElement('span');
      winOut.className = 'slip-leg-win small';
      row.appendChild(winOut);
      const updateWin = () => {
        const n = parseInt(stakeIn.value, 10) || 0;
        leg.stake = n; save(cart);
        winOut.textContent = n > 0 ? 'vinci ' + win(n, leg.odds) : '';
        updateTotals();
      };
      stakeIn.addEventListener('input', updateWin);
      updateWin();

      const rm = document.createElement('button');
      rm.type = 'button'; rm.className = 'slip-leg-rm'; rm.textContent = '×'; rm.title = 'Togli dalla schedina';
      rm.addEventListener('click', () => { cart.splice(i, 1); save(cart); render(); });
      row.appendChild(rm);
      legsBox.appendChild(row);

      multiLegsBox.appendChild(hidden('legs[]', leg.matchId + ':' + leg.market + ':' + leg.pick));
    });

    multiOddsOut.textContent = '×' + (cart.length ? multiOdds.toFixed(2) : '0');
    // «chi vince», «over/under» e «MVP»: una sola scelta per partita nella multipla (si escludono a vicenda); i marcatori invece
    // si sommano, ma lo stesso giocatore su «segna», «doppietta» e «tripletta» no (una comprende l'altra). Stesse regole del server.
    const SCORER = ['gol', 'doppietta', 'tripletta'];
    const seen = {};
    let clash = null;
    cart.forEach(l => {
      const scorer = SCORER.includes(l.market);
      const k = l.matchId + '|' + (scorer ? 'scorer|' + l.pick : l.market);
      if (seen[k] && !clash) clash = scorer ? 'scorer' : 'excl';
      seen[k] = true;
    });
    const multiWarn = document.getElementById('slip-multi-warn');
    multiWarn.hidden = !clash;
    multiWarn.textContent = clash === 'excl' ? 'Due scelte di «chi vince», «over/under» o «MVP» della stessa partita si escludono: togline una per fare la multipla (restano valide come singole).'
      : clash === 'scorer' ? 'Lo stesso giocatore può stare in uno solo tra «segna», «doppietta» e «tripletta» nella multipla (una comprende l\'altra): togline uno (restano valide come singole).' : '';
    document.getElementById('slip-multi-submit').disabled = clash || cart.length < 2;
    singlesSubmit.disabled = cart.length === 0;
    tabMulti.disabled = cart.length < 2;
    tabMulti.title = cart.length < 2 ? 'Servono almeno 2 selezioni' : '';
    if (cart.length < 2 && tab === 'multipla') showTab('singole');
    slip.hidden = cart.length === 0;
    updateTotals();

    refreshPicked();
  };

  // evidenzia i pulsanti-quota già nella schedina (e, per l'over/under, quelli su cui ho già puntato alla soglia mostrata)
  const refreshPicked = () => {
    document.querySelectorAll('[data-slip-add]').forEach(btn => {
      btn.classList.toggle('is-picked', cart.some(l => l.matchId === btn.dataset.match && l.market === btn.dataset.market && l.pick === btn.dataset.pick));
    });
    document.querySelectorAll('.ou-picker').forEach(box => {
      const mine = JSON.parse(box.dataset.mine || '[]');
      box.querySelectorAll('[data-ou-side]').forEach(b => b.classList.toggle('is-mine', mine.includes(b.dataset.pick)));
    });
  };

  // over/under: chi punta sceglie la soglia (0,5 / 1,5 / ...; se scrive un intero N vale "più di N gol", cioè N,5);
  // la quota di ogni soglia l'ha già calcolata il server, qui si mostra quella giusta e si aggiornano i pulsanti Over/Under
  document.querySelectorAll('.ou-picker').forEach(box => {
    const table = JSON.parse(box.dataset.ou);
    const lines = Object.keys(table).map(Number).sort((a, b) => a - b);
    const input = box.querySelector('.ou-line');
    const fmt = n => n.toFixed(2).replace('.', ',');
    const set = v => {
      let line = Math.floor(Number.isFinite(v) ? v : lines[0]) + 0.5;
      line = Math.min(lines[lines.length - 1], Math.max(lines[0], line));
      input.value = line;
      const odds = table[line.toFixed(1)];
      box.querySelectorAll('[data-ou-side]').forEach(b => {
        const over = b.dataset.ouSide === 'O';
        const o = odds[over ? 0 : 1];
        b.dataset.pick = b.dataset.ouSide + line.toFixed(1);
        b.dataset.label = (over ? 'Over ' : 'Under ') + String(line).replace('.', ',') + ' gol';
        b.dataset.odds = o;
        b.innerHTML = (over ? 'Over' : 'Under') + ' <b>×' + fmt(o) + '</b>';
      });
      box.querySelector('.ou-over-txt').textContent = Math.ceil(line) + ' o più gol';
      box.querySelector('.ou-under-txt').textContent = Math.floor(line) + ' o meno';
      refreshPicked();
    };
    box.querySelectorAll('[data-ou-step]').forEach(b => b.addEventListener('click', () => set(parseFloat(input.value) + parseInt(b.dataset.ouStep, 10))));
    input.addEventListener('change', () => set(parseFloat(String(input.value).replace(',', '.'))));
  });

  document.querySelectorAll('[data-slip-add]').forEach(btn => btn.addEventListener('click', () => {
    // si può puntare su più scelte dello stesso mercato (es. due marcatori diversi): si toglie solo ri-toccando la STESSA quota.
    const matchId = btn.dataset.match, market = btn.dataset.market, pick = btn.dataset.pick;
    const already = cart.findIndex(l => l.matchId === matchId && l.market === market && l.pick === pick);
    if (already !== -1) { cart.splice(already, 1); save(cart); render(); return; }
    cart.push({ matchId, market, pick, label: btn.dataset.label, odds: parseFloat(btn.dataset.odds) || 1, matchLabel: btn.dataset.matchLabel });
    save(cart); render();
  }));
  tabSingole.addEventListener('click', () => showTab('singole'));
  tabMulti.addEventListener('click', () => { if (!tabMulti.disabled) showTab('multipla'); });
  const clearAll = () => { cart = []; save(cart); render(); };
  document.getElementById('slip-clear').addEventListener('click', clearAll);
  document.getElementById('slip-clear-2').addEventListener('click', clearAll);
  panelSingole.addEventListener('submit', () => { sessionStorage.removeItem(KEY); });
  panelMulti.addEventListener('submit', () => { sessionStorage.removeItem(KEY); });

  render();
})();
</script>
<?php
layout_end();
