<?php
/* Scommesse goliardiche sulle partite (gettoni finti): vedi lib/bets.php per le regole. */
require __DIR__ . '/lib/bootstrap.php';
require_view();

$me = my_player_id();
$tab = in_array($_GET['t'] ?? '', ['multiple', 'classifica'], true) ? $_GET['t'] : 'partite';

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
        redirect('bets.php?t=multiple');
    }
    if ($do === 'combo_cancel') {
        $err = $me ? combo_cancel((int) ($_POST['combo_id'] ?? 0), $me) : 'Il tuo account non è collegato a un giocatore.';
        flash($err ? 'err' : 'ok', $err ?: 'Multipla ritirata: i gettoni sono tornati nel portafoglio. Vigliacco.');
        redirect('bets.php?t=multiple');
    }

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

$comboOpen = $me ? combo_open($me) : [];
$comboHist = $me ? combo_history($me) : [];
$comboNet = 0;
foreach ($comboHist as $c) {
    $comboNet += (int) $c['payout'] - (int) $c['stake'];
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
    (se manca il dato, per esempio nessuno vota l'MVP, tutti riprendono i gettoni). La quota che vedi quando punti è quella che vale, e si abbassa un po' per ogni gettone già puntato sulla stessa scelta: prima punti su una scelta affollata, meglio è. Su «chi segna» e «MVP» puoi puntare su più giocatori della stessa partita, ognuno la sua scommessa. Si punta fino al calcio d'inizio.
    Tocca una quota per aggiungerla alla <b>schedina</b> (anche da partite diverse): da lì punti ogni scelta da sola, oppure le combini in una <b>multipla</b> dove le quote si moltiplicano (ma basta sbagliarne una per perdere tutto).
    I tuoi gettoni si vedono sempre in alto accanto al profilo e servono per il <a class="link" href="shop.php">Negozio</a>, ora una sezione a parte: sfondi, nickname e copricapi per il profilo. Chi resta al verde riceve un sussidio di <?= BET_DOLE ?> gettoni a settimana.</p>
</section>

<nav class="shop-tabs">
  <a href="bets.php" class="<?= $tab === 'partite' ? 'active' : '' ?>"><i class="ti ti-calendar-event"></i> Partite</a>
  <a href="bets.php?t=multiple" class="<?= $tab === 'multiple' ? 'active' : '' ?>"><i class="ti ti-stack-2"></i> Multiple<?php if ($comboOpen): ?> <span class="count"><?= count($comboOpen) ?></span><?php endif; ?></a>
  <a href="bets.php?t=classifica" class="<?= $tab === 'classifica' ? 'active' : '' ?>"><i class="ti ti-trophy"></i> Classifica</a>
</nav>

<?php if ($tab === 'partite'): ?>
<h2 class="section-title">Da giocare</h2>
<?php if (!$upcoming): ?><p class="empty card">Nessuna partita in programma: appena ne crea una l'admin, si può puntare.</p><?php endif; ?>
<?php foreach ($upcoming as $m):
    $mid = (int) $m['id'];
    $open = bets_open_for($m);
    $cands = bet_candidates($mid);
    $all = match_bets($mid);
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
      <?php if ($list): ?><p class="bet-friends small muted"><?php foreach ($list as $i => $b): ?><?= $i ? ' · ' : '' ?><?= h($b['name']) ?> <b><?= (int) $b['stake'] ?></b> su <?= h($opts[$b['pick']] ?? '?') ?><?php endforeach; ?></p><?php endif; ?>
      <?php if ($canBet && $opts): ?>
        <p class="muted small" style="margin:0">Tocca una quota per aggiungerla alla schedina<?= $mk !== 'esito' ? ' (anche più di una: es. due marcatori diversi)' : '' ?>:</p>
        <div class="quota-picks">
          <?php foreach ($opts as $val => $label): $qv = $q[$val] ?? 0;
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
            <span class="tag tag-ok"><i class="ti ti-check"></i> <?= (int) $my['stake'] ?> su <?= h($opts[$my['pick']] ?? '?') ?> a ×<?= fmt_num($my['odds'], 2) ?> = <?= bet_payout((int) $my['stake'], $my['odds']) ?></span>
            <?php if ($canBet): ?><button class="btn btn-ghost btn-sm" data-confirm="Ritirare la puntata? Vigliacco.">Ritira</button><?php endif; ?></form>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>

<?php if ($waiting): ?>
<h2 class="section-title">Aspettando il verdetto</h2>
<div class="card table-card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Partita</th><th>Mercato</th><th>La tua scelta</th><th>Puntati</th><th>Quota</th></tr></thead><tbody>
  <?php foreach ($waiting as $b): ?>
    <tr><td><a href="match.php?id=<?= (int) $b['match_id'] ?>"><?= fmt_date_short($b['match_date']) ?></a></td>
      <td><?= h($markets[$b['market']]['label'] ?? $b['market']) ?></td>
      <td><?= h(bet_pick_label($b, $b['market'], $b['pick'])) ?></td><td><?= (int) $b['stake'] ?></td><td>×<?= fmt_num($b['odds'], 2) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <p class="muted small">Si pagano appena le votazioni si chiudono.</p></div>
<?php endif; ?>

<?php if ($history): ?>
<h2 class="section-title">Le tue ultime scommesse singole <span class="muted small">saldo <?= fmt_signed($net, 0) ?></span></h2>
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

<?php elseif ($tab === 'multiple'): ?>

<h2 class="section-title">Le tue multiple aperte</h2>
<p class="muted small">Costruiscile dalla scheda <a class="link" href="bets.php">Partite</a>: tocca una quota per aggiungerla alla schedina in basso, poi nella scheda «Multipla» della schedina combinane almeno due in un'unica giocata.</p>
<?php if (!$comboOpen): ?><p class="empty card">Nessuna multipla in corso.</p><?php else: ?>
<div class="list">
  <?php foreach ($comboOpen as $c): ?>
    <div class="card combo-card">
      <div class="combo-legs-view">
        <?php foreach ($c['legs'] as $l): ?>
          <span class="tag<?= $l['status'] !== 'aperta' ? ' tag-' . ($l['status'] === 'vinta' ? 'ok' : ($l['status'] === 'persa' ? 'live' : '')) : '' ?>">
            <?= fmt_date_short($l['match_date']) ?> · <?= h($markets[$l['market']]['label'] ?? $l['market']) ?>: <b><?= h($l['label']) ?></b> ×<?= fmt_num($l['odds'], 2) ?></span>
        <?php endforeach; ?>
      </div>
      <div class="combo-card-foot">
        <span><?= (int) $c['stake'] ?> gettoni a ×<?= fmt_num($c['odds'], 2) ?> = vinci <?= bet_payout((int) $c['stake'], $c['odds']) ?></span>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="combo_cancel"><input type="hidden" name="combo_id" value="<?= (int) $c['id'] ?>">
          <button class="btn btn-ghost btn-sm" data-confirm="Ritirare la multipla? Vigliacco.">Ritira</button></form>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($comboHist): ?>
<h2 class="section-title">Storico multiple <span class="muted small">saldo <?= fmt_signed($comboNet, 0) ?></span></h2>
<div class="list">
  <?php foreach ($comboHist as $c): ?>
    <div class="card combo-card">
      <div class="combo-legs-view">
        <?php foreach ($c['legs'] as $l): ?>
          <span class="tag<?= $l['status'] !== 'aperta' ? ' tag-' . ($l['status'] === 'vinta' ? 'ok' : ($l['status'] === 'persa' ? 'live' : '')) : '' ?>">
            <?= fmt_date_short($l['match_date']) ?> · <?= h($markets[$l['market']]['label'] ?? $l['market']) ?>: <b><?= h($l['label']) ?></b> ×<?= fmt_num($l['odds'], 2) ?></span>
        <?php endforeach; ?>
      </div>
      <div class="combo-card-foot">
        <span><?= (int) $c['stake'] ?> gettoni a ×<?= fmt_num($c['odds'], 2) ?></span>
        <?php if ($c['status'] === 'vinta'): ?><span class="tag tag-ok">vinta +<?= (int) $c['payout'] - (int) $c['stake'] ?></span>
        <?php elseif ($c['status'] === 'persa'): ?><span class="tag tag-live">persa −<?= (int) $c['stake'] ?></span>
        <?php else: ?><span class="tag">rimborsata</span><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
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

<div id="betslip" class="betslip" data-balance="<?= (int) $balance ?>" hidden>
  <div class="betslip-head">
    <span class="muted small"><i class="ti ti-stack-2"></i> Schedina (<span id="slip-count">0</span>)</span>
    <div class="slip-tabs">
      <button type="button" class="slip-tab active" data-slip-tab="singole">Singole</button>
      <button type="button" class="slip-tab" data-slip-tab="multipla" id="slip-tab-multi" disabled title="Servono almeno 2 selezioni">Multipla</button>
    </div>
  </div>
  <div class="betslip-legs" id="slip-legs"></div>

  <form method="post" class="betslip-actions" id="slip-singles-form" data-slip-panel="singole">
    <?= csrf_field() ?><input type="hidden" name="do" value="bet_multi">
    <button type="submit" class="btn btn-primary btn-sm" id="slip-singles-submit" disabled>Punta le singole</button>
    <button type="button" class="btn btn-ghost btn-sm" id="slip-clear">Svuota schedina</button>
  </form>

  <form method="post" class="betslip-actions" id="slip-multi-form" data-slip-panel="multipla" hidden>
    <?= csrf_field() ?><input type="hidden" name="do" value="combo_bet">
    <div id="slip-multi-legs"></div>
    <span class="betslip-odds muted small">Quota multipla <b id="slip-multi-odds">×0</b></span>
    <input type="number" name="stake" id="slip-multi-stake" min="1" max="<?= max(1, $balance) ?>" value="<?= min(10, max(1, $balance)) ?>" inputmode="numeric" aria-label="Gettoni sulla multipla">
    <button type="submit" class="btn btn-primary btn-sm" id="slip-multi-submit">Punta la multipla</button>
    <button type="button" class="btn btn-ghost btn-sm" id="slip-clear-2">Svuota schedina</button>
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

  const showTab = t => {
    tab = t;
    tabSingole.classList.toggle('active', t === 'singole');
    tabMulti.classList.toggle('active', t === 'multipla');
    panelSingole.hidden = t !== 'singole';
    panelMulti.hidden = t !== 'multipla';
  };

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
    singlesSubmit.disabled = cart.length === 0;
    tabMulti.disabled = cart.length < 2;
    if (cart.length < 2 && tab === 'multipla') showTab('singole');
    slip.hidden = cart.length === 0;

    // aggiorna anche i pulsanti-quota già scelti (evidenziati) e le quote in tutte le partite, in caso una scelta sia sparita
    document.querySelectorAll('[data-slip-add]').forEach(btn => {
      btn.classList.toggle('is-picked', cart.some(l => l.matchId === btn.dataset.match && l.market === btn.dataset.market && l.pick === btn.dataset.pick));
    });
  };

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
