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
    (se manca il dato, per esempio nessuno vota l'MVP, tutti riprendono i gettoni). La quota che vedi quando punti è quella che vale. Si punta fino al calcio d'inizio.
    Con «+ Multipla» combini più scelte in una sola giocata: le quote si moltiplicano, ma basta sbagliarne una per perdere tutto.
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
      $fav = $q;
      asort($fav);   // favoriti = quote più basse
      $my = $mine[$mid][$mk] ?? null; ?>
    <div class="bet-market">
      <h3><i class="ti ti-<?= $info['icon'] ?>"></i> <?= h($info['label']) ?> <span class="muted small">· <?= h($info['when']) ?></span></h3>
      <p class="pool-label muted small"><?= $mk === 'esito' ? 'Quote' : 'Quote dei favoriti (i più probabili: quota più bassa = più probabile)' ?></p>
      <div class="pool">
        <?php foreach (array_slice($fav, 0, $mk === 'esito' ? 3 : 4, true) as $pick => $odd): ?>
          <span class="pool-opt"><?= h($opts[$pick] ?? '?') ?> <b>×<?= fmt_num($odd, 2) ?></b></span>
        <?php endforeach; ?>
      </div>
      <?php if ($list): ?><p class="bet-friends small muted"><?php foreach ($list as $i => $b): ?><?= $i ? ' · ' : '' ?><?= h($b['name']) ?> <b><?= (int) $b['stake'] ?></b> su <?= h($opts[$b['pick']] ?? '?') ?><?php endforeach; ?></p><?php endif; ?>
      <?php if ($canBet && $opts): ?>
        <form method="post" class="bet-form">
          <?= csrf_field() ?><input type="hidden" name="do" value="bet"><input type="hidden" name="match_id" value="<?= $mid ?>"><input type="hidden" name="market" value="<?= $mk ?>">
          <select name="pick" required aria-label="Su chi punti">
            <option value="">Scegli…</option>
            <?php foreach ($opts as $val => $label): ?><option value="<?= h((string) $val) ?>" data-label="<?= h($label) ?>" data-odds="<?= h((string) ($q[$val] ?? 0)) ?>" <?= $my && (string) $my['pick'] === (string) $val ? 'selected' : '' ?>><?= h($label) ?> · ×<?= fmt_num($q[$val] ?? 0, 2) ?></option><?php endforeach; ?>
          </select>
          <input type="number" name="stake" min="1" max="<?= $balance + ($my ? (int) $my['stake'] : 0) ?>" inputmode="numeric" value="<?= $my ? (int) $my['stake'] : min(10, max(1, $balance)) ?>" required aria-label="Gettoni">
          <button class="btn btn-primary btn-sm"><?= $my ? 'Cambia' : 'Punta' ?></button>
          <button type="button" class="btn btn-ghost btn-sm" data-combo-add data-match="<?= $mid ?>" data-market="<?= $mk ?>"
            data-match-label="<?= h(fmt_date_short($m['match_date'])) ?> · <?= h($info['label']) ?>"><i class="ti ti-stack-2"></i> Multipla</button>
          <span class="bet-win small" data-bet-win></span>
        </form>
        <?php if ($my): ?>
          <form method="post" class="bet-mine"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="match_id" value="<?= $mid ?>"><input type="hidden" name="market" value="<?= $mk ?>">
            <span class="tag tag-ok"><i class="ti ti-check"></i> <?= (int) $my['stake'] ?> su <?= h($opts[$my['pick']] ?? '?') ?> a ×<?= fmt_num($my['odds'], 2) ?> = <?= bet_payout((int) $my['stake'], $my['odds']) ?></span>
            <button class="btn btn-ghost btn-sm" data-confirm="Ritirare la puntata? Vigliacco.">Ritira</button></form>
        <?php endif; ?>
      <?php elseif ($my): ?>
        <p><span class="tag tag-ok"><i class="ti ti-check"></i> <?= (int) $my['stake'] ?> su <?= h($opts[$my['pick']] ?? '?') ?> a ×<?= fmt_num($my['odds'], 2) ?> = <?= bet_payout((int) $my['stake'], $my['odds']) ?></span></p>
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
<p class="muted small">Costruiscile dalla scheda <a class="link" href="bets.php">Partite</a>: premi «Multipla» sotto ogni scelta per aggiungerla al carrello qui in basso, poi punta quando ne hai almeno due.</p>
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

<form method="post" id="combo-bar" class="combo-cart" hidden>
  <?= csrf_field() ?><input type="hidden" name="do" value="combo_bet">
  <div class="combo-cart-legs" id="combo-cart-legs"></div>
  <div class="combo-cart-actions">
    <span class="combo-cart-odds muted small">Quota <b id="combo-cart-odds">×0</b></span>
    <input type="number" name="stake" id="combo-cart-stake" min="1" max="<?= max(1, $balance) ?>" value="<?= min(10, max(1, $balance)) ?>" inputmode="numeric" aria-label="Gettoni sulla multipla">
    <button type="submit" class="btn btn-primary btn-sm" id="combo-cart-submit" disabled>Punta la multipla</button>
    <button type="button" class="btn btn-ghost btn-sm" id="combo-cart-clear">Svuota</button>
  </div>
</form>

<script>
// vincita potenziale mentre si sceglie e si digita la puntata (scommessa singola)
document.querySelectorAll('.bet-form').forEach(f => {
  const sel = f.querySelector('select'), stake = f.querySelector('input[name=stake]'), out = f.querySelector('[data-bet-win]');
  const update = () => {
    const odds = parseFloat((sel.selectedOptions[0] || {}).dataset ? sel.selectedOptions[0].dataset.odds : 0) || 0;
    const n = parseInt(stake.value, 10) || 0;
    out.textContent = odds && n > 0 ? 'Vinci ' + Math.floor(n * odds + 1e-9) + ' (+' + (Math.floor(n * odds + 1e-9) - n) + ')' : '';
  };
  sel.addEventListener('change', update); stake.addEventListener('input', update); update();
});

// carrello delle multiple: sopravvive alla navigazione tra le schede (sessionStorage), si svuota quando si punta o si preme "Svuota"
(() => {
  const KEY = 'comboCart';
  const bar = document.getElementById('combo-bar');
  if (!bar) return;
  const legsBox = document.getElementById('combo-cart-legs');
  const oddsOut = document.getElementById('combo-cart-odds');
  const submitBtn = document.getElementById('combo-cart-submit');
  const load = () => { try { return JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { return []; } };
  const save = cart => { try { sessionStorage.setItem(KEY, JSON.stringify(cart)); } catch (e) {} };
  let cart = load();

  const render = () => {
    legsBox.innerHTML = '';
    let odds = 1;
    cart.forEach((leg, i) => {
      odds *= leg.odds;
      const chip = document.createElement('span');
      chip.className = 'combo-leg';
      chip.innerHTML = '<span>' + leg.matchLabel + ': <b>' + leg.label + '</b> ×' + leg.odds.toFixed(2) + '</span>';
      const rm = document.createElement('button');
      rm.type = 'button'; rm.textContent = '×'; rm.title = 'Togli dalla multipla';
      rm.addEventListener('click', () => { cart.splice(i, 1); save(cart); render(); });
      chip.appendChild(rm);
      legsBox.appendChild(chip);
      const hid = document.createElement('input');
      hid.type = 'hidden'; hid.name = 'legs[]'; hid.value = leg.matchId + ':' + leg.market + ':' + leg.pick;
      legsBox.appendChild(hid);
    });
    oddsOut.textContent = '×' + (cart.length ? odds.toFixed(2) : '0');
    submitBtn.disabled = cart.length < 2;
    bar.hidden = cart.length === 0;
  };

  document.querySelectorAll('[data-combo-add]').forEach(btn => btn.addEventListener('click', () => {
    const form = btn.closest('form');
    const sel = form.querySelector('select[name=pick]');
    const opt = sel.selectedOptions[0];
    if (!opt || !opt.value) { alert('Scegli prima su chi puntare.'); return; }
    const matchId = btn.dataset.match, market = btn.dataset.market;
    if (cart.some(l => l.matchId === matchId && l.market === market)) { alert('Questa selezione è già nella multipla.'); return; }
    cart.push({ matchId, market, pick: opt.value, label: opt.dataset.label, odds: parseFloat(opt.dataset.odds) || 1, matchLabel: btn.dataset.matchLabel });
    save(cart); render();
  }));
  document.getElementById('combo-cart-clear').addEventListener('click', () => { cart = []; save(cart); render(); });
  bar.addEventListener('submit', () => { sessionStorage.removeItem(KEY); });

  render();
})();
</script>
<?php
layout_end();
