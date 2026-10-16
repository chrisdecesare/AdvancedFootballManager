<?php
/* Scommesse goliardiche sulle partite (KOIN finti): vedi lib/bets.php per le regole. */
require __DIR__ . '/lib/bootstrap.php';
require_view();

$me = my_player_id();

/*
 * Quote aggiornate in JSON per la schedina (assets/bets.js le richiede ogni tanto mentre la pagina è aperta):
 * {id partita: {mercato: {scelta: quota}}} per le partite indicate (?quote=1&m[]=...), solo se aperte e visibili.
 */
if (isset($_GET['quote'])) {
    header('Content-Type: application/json; charset=utf-8');
    $out = [];
    foreach (array_slice(array_unique(array_map('intval', (array) ($_GET['m'] ?? []))), 0, 20) as $mid) {
        $m = $mid ? get_match($mid) : null;
        if ($m && match_access($m) && bets_open_for($m)) {
            $out[$mid] = bet_quotes($m, $me);
        } elseif ($m) {
            $out[$mid] = null;   // chiusa (iniziata o finita): dalla schedina va tolta
        }
    }
    echo json_encode($out);
    exit;
}
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
            $seenRaw = is_array($_POST['seen'] ?? null) ? $_POST['seen'] : [];   // quote che la persona vedeva nella schedina
            $moved = [];
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
                    $label = bet_pick_label($m, (string) $market, (string) $pick);
                    log_activity('puntata', $label . ' · ' . $stake . ' KOIN', (int) $m['group_id']);
                    $placed = (float) q("SELECT odds FROM bets WHERE match_id = ? AND player_id = ? AND market = ? AND pick = ? AND status = 'aperta'",
                        [$m['id'], $me, $market, $pick])->fetchColumn();
                    $seen = (float) ($seenRaw[$i] ?? 0);
                    if ($seen > 0 && abs($seen - $placed) >= 0.01) {
                        $moved[] = $label . ' ×' . fmt_num($seen, 2) . ' → ×' . fmt_num($placed, 2);
                    }
                }
            }
            if ($ok) {
                flash('ok', $ok . ($ok > 1 ? ' puntate singole piazzate' : ' puntata singola piazzata') . " ({$total} KOIN in tutto)."
                    . ($moved ? ' Nel frattempo la quota era cambiata: ' . implode(', ', $moved) . '.' : '')
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
                $seen = (float) ($_POST['seen_combo'] ?? 0);
                if ($seen > 0 && abs($seen - $odds) >= 0.01) {
                    flash('ok', 'Nel frattempo la quota della multipla era cambiata: ×' . fmt_num($seen, 2) . ' → ×' . fmt_num($odds, 2) . '.');
                }
                log_activity('multipla', count($legs) . ' scelte · ' . $stake . ' KOIN a ×' . fmt_num($odds, 2), (int) get_match((int) $legs[0]['match_id'])['group_id']);
                flash('ok', 'Multipla da ' . count($legs) . ' su ' . $stake . ' KOIN a ×' . fmt_num($odds, 2)
                    . ': se le indovini tutte vinci ' . bet_payout($stake, $odds, bet_fire_day() ? BET_FIRE_MULT : 1) . (bet_fire_day() ? ' (' . BET_FIRE_NAME . ': doppio!)' : '') . '. Chi non risica...');
            }
        }
        redirect('bets.php?t=mie');
    }
    if ($do === 'combo_cancel') {
        $err = $me ? combo_cancel((int) ($_POST['combo_id'] ?? 0), $me) : 'Il tuo account non è collegato a un giocatore.';
        if (!$err) {
            log_activity('puntata_ritirata', 'multipla');
        }
        flash($err ? 'err' : 'ok', $err ?: 'Multipla ritirata: i KOIN sono tornati nel portafoglio. Vigliacco.');
        redirect('bets.php?t=mie');
    }

    if ($do === 'use_token') {   // gettone ×2 della ruota (lib/wheel.php): le puntate singole su quella partita pagano il doppio
        $tm = (int) ($_POST['match_id'] ?? 0) ? get_match((int) $_POST['match_id']) : null;
        if (!$me || !$tm || !match_access($tm) || (!is_admin() && !player_in_group($me, (int) $tm['group_id']))) {
            flash('err', 'Partita non valida.');
        } elseif ($err = bet_token_use($me, $tm)) {
            flash('err', $err);
        } else {
            flash('ok', 'Gettone ×2 attivato: le tue puntate singole su questa partita, se vincono, pagano il doppio.');
        }
        redirect('bets.php#m' . (int) ($tm['id'] ?? 0));
    }

    if ($do === 'koin_pass') {   // passa KOIN a un compagno (lib/passaggi.php)
        $to = (int) ($_POST['to'] ?? 0);
        $amount = (int) ($_POST['amount'] ?? 0);
        $note = is_string($_POST['note'] ?? null) ? $_POST['note'] : '';
        if (!$me) {
            flash('err', 'Il tuo account non è collegato a un giocatore.');
        } elseif (mb_strlen(trim($note)) > KOIN_PASS_NOTE_MAX) {
            flash('err', 'Il messaggio può avere al massimo ' . KOIN_PASS_NOTE_MAX . ' caratteri.');
        } else {
            $eco = current_eco($me);
            $err = koin_pass($me, $to, $amount, $note, $eco);
            if ($err) {
                flash('err', $err);
            } else {
                $toName = (string) (get_player($to)['name'] ?? '');
                log_activity('gettoni', 'passaggio · ' . $amount . ' KOIN a ' . $toName);
                koin_pass_notify($me, $to, $amount, trim($note));
                flash('ok', 'Passati ' . $amount . ' KOIN a ' . $toName . '. Ora ne hai ' . wallet_balance($me, $eco) . '.');
            }
        }
        redirect('bets.php#passa-koin');
    }

    if ($do === 'bet_stake') {   // chi amministra la lega cambia l'importo di una puntata aperta (la quota resta quella presa)
        $betId = (int) ($_POST['bet_id'] ?? 0);
        $stake = max(0, (int) ($_POST['stake'] ?? 0));
        $b = q('SELECT b.match_id, b.market, b.pick, b.stake, p.name FROM bets b JOIN players p ON p.id = b.player_id WHERE b.id = ?', [$betId])->fetch();
        $bm = $b ? get_match((int) $b['match_id']) : null;
        $err = $bm && match_access($bm) ? bet_admin_set_stake($betId, $stake) : 'Puntata non trovata.';
        if (!$err) {
            log_activity('puntata_modificata', $b['name'] . ' · ' . bet_pick_label($bm, $b['market'], (string) $b['pick']) . ' · ' . $b['stake'] . ' → ' . $stake . ' KOIN', (int) $bm['group_id']);
        }
        flash($err ? 'err' : 'ok', $err ?: 'Puntata di ' . $b['name'] . ' portata a ' . $stake . ' KOIN (la quota resta quella presa).');
        redirect('bets.php' . ($bm ? '#m' . (int) $bm['id'] : ''));
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
            log_activity('puntata', $label . ' · ' . $stake . ' KOIN', (int) $match['group_id']);
            $jokes = ['Che Dio ti assista.', 'Coraggio, o incoscienza.', 'Gli amici ti guardano.', 'Si vedrà chi ride a fine partita.', 'Il banco ringrazia.'];
            flash('ok', "Puntati {$stake} KOIN su «{$label}». " . $jokes[array_rand($jokes)]);
        }
    } elseif ($do === 'cancel') {
        $err = bet_cancel($match, $me, $market, (string) ($_POST['pick'] ?? ''));
        if (!$err) {
            log_activity('puntata_ritirata', bet_pick_label($match, $market, (string) ($_POST['pick'] ?? '')), (int) $match['group_id']);
        }
        flash($err ? 'err' : 'ok', $err ?: 'Puntata ritirata: i KOIN sono tornati nel portafoglio. Vigliacco.');
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
// storico: le ultime (tutte con ?tutte=1), i saldi invece su tutto lo storico
$showAll = !empty($_GET['tutte']);
$histLimit = $showAll ? '' : ' LIMIT 20';
$history = $me ? q("SELECT b.*, m.match_date, m.team_a_name, m.team_b_name FROM bets b JOIN matches m ON m.id = b.match_id
                    WHERE b.player_id = ? AND b.status <> 'aperta' AND " . scope_sql('m.group_id') . ' ORDER BY b.settled_at DESC, b.id DESC' . $histLimit, [$me])->fetchAll() : [];
$histTotal = $me ? (int) q("SELECT COUNT(*) FROM bets b JOIN matches m ON m.id = b.match_id WHERE b.player_id = ? AND b.status <> 'aperta' AND " . scope_sql('m.group_id'), [$me])->fetchColumn() : 0;
$net = $me ? (int) q("SELECT COALESCE(SUM(b.payout - b.stake), 0) FROM bets b JOIN matches m ON m.id = b.match_id WHERE b.player_id = ? AND b.status <> 'aperta' AND " . scope_sql('m.group_id'), [$me])->fetchColumn() : 0;
$markets = bet_markets();
$fireToday = bet_fire_day();

$comboOpen = $me ? combo_open($me) : [];
$comboHist = $me ? combo_history($me, $showAll ? 1000 : 10) : [];
$comboTotal = $me ? (int) q("SELECT COUNT(*) FROM combo_bets WHERE player_id = ? AND status <> 'aperta'", [$me])->fetchColumn() : 0;
$comboNet = $me ? (int) q("SELECT COALESCE(SUM(payout - stake), 0) FROM combo_bets WHERE player_id = ? AND status <> 'aperta'", [$me])->fetchColumn() : 0;

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
            <p class="np-win"><span>Vincita<?= !empty($c['fire']) ? ' <i class="ti ti-flame" title="' . h(BET_FIRE_NAME) . ': doppio"></i>' : '' ?></span><span class="np-dots" aria-hidden="true"></span><b><?= bet_payout((int) $c['stake'], $c['odds'], bet_mult($c)) ?></b></p>
          <?php elseif ($c['status'] === 'vinta'): ?>
            <p class="np-win"><span>Vinto</span><span class="np-dots" aria-hidden="true"></span><b>+<?= (int) $c['payout'] - (int) $c['stake'] ?></b></p>
          <?php elseif ($c['status'] === 'persa'): ?>
            <p class="np-lost"><span>Vincita</span><span class="np-dots" aria-hidden="true"></span><b><s><?= bet_payout((int) $c['stake'], $c['odds'], bet_mult($c)) ?></s></b></p>
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
<div class="page-head"><h1>Scommesse <span class="muted small">a KOIN finti</span></h1></div>
<?= group_bar('bets.php') ?>

<section class="card wallet">
  <?= $me ? eco_switch($me, 'bets.php' . ($tab !== 'partite' ? '?t=' . $tab : '')) : '' ?>
  <?php if ($me): $mp = get_player($me); ?>
    <div class="wallet-who"><?= avatar($mp, 'lg') ?>
      <div><strong class="wallet-name"><?= h($mp['name']) ?></strong>
        <span class="tag tag-mvp"><i class="ti ti-crown"></i> <?= h(bet_title($balance + $inPlay)) ?></span></div></div>
  <?php else: ?>
    <p class="empty">Il tuo account non è collegato a un giocatore: puoi guardare ma non scommettere.</p>
  <?php endif; ?>
  <?php if ($me && ($passTo = koin_pass_recipients($me, current_eco($me)))):
      $passPre = (int) ($_GET['passa'] ?? 0);
      $passLog = koin_passes_of($me, current_eco($me));
      $passLeft = max(0, KOIN_PASS_DAILY_MAX - koin_passed_today($me)); ?>
    <details class="collapsible pass-koin" id="passa-koin" <?= isset($passTo[$passPre]) ? 'open' : '' ?>>
      <summary><strong><i class="ti ti-arrows-exchange"></i> Passa KOIN a un compagno</strong></summary>
      <form method="post" class="form form-grid">
        <?= csrf_field() ?><input type="hidden" name="do" value="koin_pass">
        <label class="field"><span>A chi</span><select name="to" required>
          <option value="">— scegli —</option>
          <?php foreach ($passTo as $pid => $pname): ?><option value="<?= (int) $pid ?>" <?= $pid === $passPre ? 'selected' : '' ?>><?= h($pname) ?></option><?php endforeach; ?>
        </select></label>
        <label class="field"><span>Quanti KOIN (hai <?= (int) $balance ?>, oggi ne puoi passare <?= min($passLeft, (int) $balance) ?>)</span>
          <input type="number" name="amount" required min="1" max="<?= max(1, min($passLeft, (int) $balance)) ?>" inputmode="numeric" value="<?= min(10, max(1, min($passLeft, (int) $balance))) ?>"></label>
        <label class="field span-2"><span>Messaggio (facoltativo, lo vede anche la Gazzetta del mercoledì)</span><input name="note" maxlength="<?= KOIN_PASS_NOTE_MAX ?>" placeholder="Es. per la birra di giovedì"></label>
        <div class="span-2"><button class="btn btn-primary btn-sm" <?= $passLeft < 1 || $balance < 1 ? 'disabled' : '' ?>><i class="ti ti-send"></i> Passa i KOIN</button>
          <span class="muted small">Si passano solo i KOIN disponibili (non quelli puntati), al massimo <?= KOIN_PASS_DAILY_MAX ?> al giorno. Non si possono riprendere.</span></div>
      </form>
      <?php if ($passLog): ?>
        <ul class="pass-log small">
          <?php foreach ($passLog as $x): $in = (int) $x['delta'] > 0; ?>
            <li><span class="<?= $in ? 'is-pos' : 'is-neg' ?>"><?= $in ? '+' : '−' ?><?= abs((int) $x['delta']) ?></span>
              <?= $in ? 'da' : 'a' ?> <a href="player.php?id=<?= (int) $x['peer_id'] ?>"><?= h((string) $x['other']) ?></a>
              <span class="muted">· <?= fmt_date_short($x['created_at']) ?></span><?= $x['note'] ? ' <span class="muted">«' . h($x['note']) . '»</span>' : '' ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </details>
  <?php endif; ?>
  <details class="wallet-rules-box">
    <summary class="small"><i class="ti ti-info-circle"></i> Come funzionano le scommesse</summary>
    <p class="muted small wallet-rules">Si scommette solo con KOIN finti: nessun euro, solo onore e sfottò. Ogni scelta ha la sua <b>quota</b>, calcolata come dai bookmaker (probabilità stimate da gol, forma e voti, più il margine del banco): se indovini vinci puntata × quota, se sbagli perdi la puntata
    (se manca il dato, per esempio nessuno vota l'MVP, tutti riprendono i KOIN). La quota che vedi quando punti è quella che vale, e si abbassa un po' per ogni KOIN già puntato sulla stessa scelta: prima punti su una scelta affollata, meglio è. Nelle prime partite ogni scelta parte alta, circa <b>3,00×</b>, per l'incertezza iniziale: poi scende, partita dopo partita e puntata dopo puntata, fino a un minimo di <b><?= number_format(BET_MIN_ODDS, 2, ',', '') ?>×</b> (nessuna quota scende mai sotto). Oltre a chi vince e all'MVP puoi puntare su chi segna, chi fa doppietta (almeno 2 gol) o tripletta (almeno 3), su chi fa un <b>assist</b>, su chi fa <b>gol + assist</b> nella stessa partita, sul <b>miglior difensore</b> (il premio votato dai giocatori a fine partita, che si paga alla chiusura dei voti), su chi farà un <b>autogol</b> (quote alte: capita di rado), sulla doppietta e la tripletta di assist e sugli <b>over/under</b>: gol totali della partita, gol e assist di una squadra, gol e assist di un giocatore, scegliendo tu la soglia (la quota cambia con lei; quando si punta di più su una soglia si spostano insieme, in proporzione, le quote di tutte le altre: l'over 8,5 non paga mai più dell'over 9,5). Ogni <b><?= h(BET_FIRE_NAME) ?></b> le schedine giocate quel giorno, se vincono, pagano il doppio dei KOIN, e si aprono già le partite fino a venerdì.
    Sui mercati dei giocatori puoi puntare su più giocatori della stessa partita, ognuno la sua scommessa. Si punta fino al calcio d'inizio.
    Tocca una quota per aggiungerla alla <b>schedina</b> (anche da partite diverse): da lì punti ogni scelta da sola, oppure le combini in una <b>multipla</b> dove le quote si moltiplicano (ma basta sbagliarne una per perdere tutto).
    Ogni gol che segni vale <?= BET_REWARD_GOAL ?> KOIN (per le scommesse) più <?= SHOP_CREDIT_GOAL ?> crediti dell'Avatar, ogni assist <?= BET_REWARD_ASSIST ?> KOIN più <?= SHOP_CREDIT_ASSIST ?> crediti, appena viene salvato il risultato. Chi vince l'MVP riceve <?= BET_REWARD_MVP ?> KOIN e chi vince il miglior difensore <?= BET_REWARD_DIF ?> KOIN, alla chiusura dei voti: si spendono in tutto il Negozio e nell'Avatar. Se perdi tutte le puntate su una partita, alla chiusura dei voti ricevi <?= BET_CONSOLATION ?> KOIN di consolazione. Su te stesso (gol, assist, over/under del giocatore, autogol, MVP, miglior difensore) non si scommette.
    I tuoi KOIN si vedono sempre in alto accanto al profilo e servono per il <a class="link" href="shop.php">Negozio</a>, ora una sezione a parte: sfondi, nickname e copricapi per il profilo. Chi resta al verde riceve un sussidio di <?= BET_DOLE ?> KOIN a settimana.
    Ogni lega creata da un utente ha i suoi KOIN: si puntano e si vincono solo sulle sue partite, e non si mescolano con quelli delle altre leghe.</p>
  </details>
</section>

<nav class="shop-tabs">
  <a href="bets.php" class="<?= $tab === 'partite' ? 'active' : '' ?>"><i class="ti ti-calendar-event"></i> Partite</a>
  <a href="bets.php?t=mie" class="<?= $tab === 'mie' ? 'active' : '' ?>"><i class="ti ti-receipt"></i> Scommesse<?php if ($openSingles || $comboOpen): ?> <span class="count"><?= count($openSingles) + count($comboOpen) ?></span><?php endif; ?></a>
  <a href="bets.php?t=classifica" class="<?= $tab === 'classifica' ? 'active' : '' ?>"><i class="ti ti-trophy"></i> Classifica</a>
</nav>

<?php if ($tab === 'partite'): ?>
<?php if ($fireToday): ?>
<section class="card fire-banner">
  <span class="fire-icon" aria-hidden="true"><i class="ti ti-flame"></i></span>
  <div><strong><?= h(BET_FIRE_NAME) ?>!</strong> Solo oggi ogni scommessa vincente (singola o multipla) paga <b>il doppio dei KOIN</b>.
    <span class="small">Le partite fino a venerdì sono già aperte. Vale per le puntate fatte oggi: se domani ne cambi una, torna normale.</span></div>
</section>
<?php endif; ?>
<h2 class="section-title">Da giocare</h2>
<?php if (!$upcoming): ?><p class="empty card">Nessuna partita in programma: appena ne crea una l'admin, si può puntare.</p><?php endif; ?>
<?php foreach ($upcoming as $m):
    $mid = (int) $m['id'];
    $open = bets_open_for($m);
    $cands = bet_candidates($mid);
    $all = can_admin_group((int) $m['group_id']) ? match_bets($mid) : [];   // chi ha puntato su cosa lo vede solo chi amministra la lega: ai giocatori resta la sorpresa
    $quotes = bet_quotes($m, $me);   // come quando si punta: le proprie puntate non abbassano le proprie quote
    $canBet = $me && $open && (is_admin() || player_in_group($me, (int) $m['group_id']));
    $names = [];
    foreach ($cands as $c) {
        $names[(int) $c['player_id']] = $c['name'];
    }
    $myBets = [];   // le mie puntate su questa partita, tutte insieme
    $myPicks = [];  // mercato => scelte su cui ho già puntato (pulsanti segnati)
    foreach ($mine[$mid] ?? [] as $mk => $list) {
        foreach ($list as $b) {
            $myBets[] = $b;
            $myPicks[$mk][] = (string) $b['pick'];
        }
    }
    $dateLbl = fmt_date_short($m['match_date']);
    // pulsante-quota: lo si tocca per mettere la scelta nella schedina
    $qbtn = function (string $mk, string $pick, string $label, ?string $text = null, string $cls = '') use ($quotes, $mid, $dateLbl, $markets, $myPicks): string {
        $qv = $quotes[$mk][$pick] ?? null;
        if ($qv === null) {
            return '<span class="quota-btn is-off ' . $cls . '" aria-hidden="true">–</span>';
        }
        $isMine = in_array($pick, $myPicks[$mk] ?? [], true);
        return '<button type="button" class="quota-btn ' . $cls . ($isMine ? ' is-mine' : '') . '" data-slip-add data-match="' . $mid . '" data-market="' . h($mk) . '"'
            . ' data-pick="' . h($pick) . '" data-label="' . h($label) . '" data-odds="' . h((string) $qv) . '"'
            . ' data-match-label="' . h($dateLbl . ' · ' . rtrim($markets[$mk]['label'], '?')) . '" title="' . ($isMine ? 'Hai già puntato qui' : 'Aggiungi alla schedina') . '">'
            . ($text !== null ? h($text) . ' ' : '') . '<b>×' . fmt_num($qv, 2) . '</b></button>';
    };
    // selettore delle soglie (over/under): soglia con − e +, poi Over o Under
    $picker = function (string $mk, ?string $subject, string $subjectLabel, string $title) use ($quotes, $mid, $dateLbl, $markets, $myPicks): string {
        $table = [];
        foreach ($quotes[$mk] ?? [] as $pk => $qv) {
            $l = bet_line_parse($mk, (string) $pk);
            if ($l && $l['subject'] === $subject) {
                $table[number_format($l['line'], 1, '.', '')][$l['side'] === 'O' ? 0 : 1] = (float) $qv;
            }
        }
        if (!$table) {
            return '';
        }
        // soglia di partenza: quella dove over e under sono più alla pari
        uasort($table, fn($x, $y) => abs(($x[0] ?? 0) - ($x[1] ?? 0)) <=> abs(($y[0] ?? 0) - ($y[1] ?? 0)));
        $start = (float) array_key_first($table);
        ksort($table, SORT_NUMERIC);
        $min = (float) array_key_first($table);
        $max = (float) array_key_last($table);
        $unit = BET_LINE_UNIT[$mk];
        $mine = array_values(array_filter($myPicks[$mk] ?? [], fn($p) => (bet_line_parse($mk, $p)['subject'] ?? false) === $subject));
        $btn = function (string $side) use ($mk, $subject, $subjectLabel, $start, $table, $mid, $dateLbl, $markets, $unit): string {
            $pick = bet_line_pick($subject, $side, $start);
            $o = $table[number_format($start, 1, '.', '')][$side === 'O' ? 0 : 1];
            return '<button type="button" class="quota-btn" data-slip-add data-ou-side="' . $side . '" data-match="' . $mid . '" data-market="' . h($mk) . '"'
                . ' data-pick="' . h($pick) . '" data-label="' . h(($subjectLabel !== '' ? $subjectLabel . ': ' : '') . ($side === 'O' ? 'Over ' : 'Under ') . number_format($start, 1, ',', '') . ' ' . $unit) . '"'
                . ' data-odds="' . h((string) $o) . '" data-match-label="' . h($dateLbl . ' · ' . $markets[$mk]['label']) . '" title="Aggiungi alla schedina">'
                . ($side === 'O' ? 'Over' : 'Under') . ' <b>×' . fmt_num($o, 2) . '</b></button>';
        };
        return '<div class="ou-picker" data-ou="' . h(json_encode($table)) . '" data-mine="' . h(json_encode($mine)) . '" data-prefix="' . h($subject !== null ? $subject . '|' : '') . '"'
            . ' data-unit="' . h($unit) . '" data-subject-label="' . h($subjectLabel) . '">'
            . '<span class="ou-label small">' . h($title) . '</span>'
            . '<span class="ou-line-box"><button type="button" class="ou-step" data-ou-step="-1" aria-label="Soglia più bassa">−</button>'
            . '<input type="number" class="ou-line" min="' . $min . '" max="' . $max . '" step="1" value="' . $start . '" inputmode="decimal" aria-label="Soglia">'
            . '<button type="button" class="ou-step" data-ou-step="1" aria-label="Soglia più alta">+</button></span>'
            . $btn('O') . $btn('U')
            . '<span class="ou-help muted small">Over: <b class="ou-over-txt">' . (int) ceil($start) . ' o più</b> · Under: <b class="ou-under-txt">' . (int) floor($start) . ' o meno</b></span></div>';
    };
    // tabella dei giocatori divisi per squadra: una riga a testa, una colonna per mercato
    $byTeam = [];
    foreach ($cands as $c) {
        $byTeam[in_array($c['team'], ['A', 'B'], true) ? $c['team'] : '-'][] = $c;
    }
    ksort($byTeam);
    $noTeam = count($byTeam) > 1 ? 'Senza squadra' : 'Squadre da fare';
    $grid = function (array $cols) use ($byTeam, $m, $me, $qbtn, $noTeam): string {
        $h = '<div class="qgrid" style="--qcols:' . count($cols) . '"><div class="qgrid-head"><span>Giocatore</span>';
        foreach ($cols as [$mk, $short]) {
            $h .= '<span>' . h($short) . '</span>';
        }
        $h .= '</div>';
        foreach ($byTeam as $t => $list) {
            $h .= '<div class="qgrid-team team-' . strtolower($t === '-' ? 'x' : $t) . '"><span class="team-dot"></span>' . h($t === '-' ? $noTeam : team_name($t, $m)) . '</div>';
            foreach ($list as $c) {
                $pid = (int) $c['player_id'];
                $h .= '<div class="qgrid-row"><span class="qgrid-name">' . h($c['name']) . '</span>';
                foreach ($cols as [$mk, $short]) {
                    $h .= $pid === (int) $me ? '<span class="quota-btn is-off" title="Su te stesso non si scommette">tu</span>' : $qbtn($mk, (string) $pid, $c['name']);
                }
                $h .= '</div>';
            }
        }
        return $h . '</div>';
    };
    $hasTeams = !empty($quotes['ousq']); ?>
<section class="card bet-match" id="m<?= $mid ?>">
  <div class="card-head">
    <h2><a href="match.php?id=<?= $mid ?>"><?= h(ucfirst(fmt_date_long($m['match_date']))) ?> · <?= fmt_time($m['match_date']) ?></a> <?= group_tag((int) $m['group_id']) ?></h2>
    <?php if ($open): ?><?= countdown_html($m['match_date'], 'Si chiude tra ', 'Chiuso', 0, true, 'hourglass') ?>
    <?php elseif (bets_not_yet_open($m)): ?><span class="tag"><i class="ti ti-clock"></i> si aprono <?= h(push_when(date('Y-m-d H:i:s', bets_open_at($m)))) ?></span>
    <?php else: ?><span class="tag tag-live"><i class="ti ti-lock"></i> scommesse chiuse</span><?php endif; ?>
  </div>
  <?php if ($canBet): // gettone ×2 vinto con la ruota (compare solo a chi ne ha o l'ha già attivato) ?>
    <?php if (bet_boosted($me, $mid)): ?>
  <p class="token-note small"><i class="ti ti-ticket"></i> <b>Gettone ×2 attivo</b>: le tue puntate singole su questa partita, se vincono, pagano il doppio.</p>
    <?php elseif (($tokens = wheel_extras($me)['tokens']) > 0): ?>
  <form method="post" class="token-form"><?= csrf_field() ?><input type="hidden" name="do" value="use_token"><input type="hidden" name="match_id" value="<?= $mid ?>">
    <button class="btn btn-ghost btn-sm"><i class="ti ti-ticket"></i> Usa un gettone ×2 su questa partita</button> <span class="muted small">ne hai <?= $tokens ?>: le puntate singole, se vincono, pagano il doppio</span></form>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($canBet): ?>
  <div class="bet-cats" role="tablist" data-bet-cats="<?= $mid ?>">
    <button type="button" class="bet-cat active" data-cat="partita" role="tab"><i class="ti ti-trophy"></i> Partita</button>
    <button type="button" class="bet-cat" data-cat="gol" role="tab"><i class="ti ti-ball-football"></i> Marcatori</button>
    <button type="button" class="bet-cat" data-cat="assist" role="tab"><i class="ti ti-hand-finger"></i> Assist</button>
    <button type="button" class="bet-cat" data-cat="giocatore" role="tab"><i class="ti ti-user"></i> Giocatore</button>
    <button type="button" class="bet-cat" data-cat="premi" role="tab"><i class="ti ti-star"></i> Premi e altro</button>
  </div>
  <p class="muted small bet-tip">Tocca una quota per metterla nella <b>schedina</b> (in basso): da lì la giochi da sola o la combini in una multipla.</p>

  <div class="bet-panel" data-cat-panel="partita">
    <div class="bet-block">
      <h3><i class="ti ti-<?= $markets['esito']['icon'] ?>"></i> Chi vince?</h3>
      <div class="quota-row">
        <?= $qbtn('esito', 'A', team_name('A', $m), team_name('A', $m), 'team-a') ?><?= $qbtn('esito', 'X', 'Pareggio', 'Pareggio') ?><?= $qbtn('esito', 'B', team_name('B', $m), team_name('B', $m), 'team-b') ?>
      </div>
    </div>
    <div class="bet-block">
      <h3><i class="ti ti-arrows-up-down"></i> Over/Under gol <span class="muted small">· entrambe le squadre insieme</span></h3>
      <?= $picker('overunder', null, '', 'Gol totali') ?>
    </div>
    <div class="bet-block">
      <h3><i class="ti ti-shirt-sport"></i> Over/Under gol di una squadra</h3>
      <?php if ($hasTeams): ?>
        <?= $picker('ousq', 'A', team_name('A', $m), team_name('A', $m)) ?><?= $picker('ousq', 'B', team_name('B', $m), team_name('B', $m)) ?>
      <?php else: ?><p class="muted small">Si gioca appena sono fatte le squadre.</p><?php endif; ?>
    </div>
    <div class="bet-block">
      <h3><i class="ti ti-hand-finger"></i> Over/Under assist di una squadra</h3>
      <?php if ($hasTeams): ?>
        <?= $picker('ouassq', 'A', team_name('A', $m), team_name('A', $m)) ?><?= $picker('ouassq', 'B', team_name('B', $m), team_name('B', $m)) ?>
      <?php else: ?><p class="muted small">Si gioca appena sono fatte le squadre.</p><?php endif; ?>
    </div>
  </div>

  <div class="bet-panel" data-cat-panel="gol" hidden>
    <p class="muted small">Chi segna almeno 1 gol, almeno 2 (doppietta) o almeno 3 (tripletta). Puoi metterne più di uno nella schedina.</p>
    <?= $grid([['gol', 'Segna'], ['doppietta', 'Doppietta'], ['tripletta', 'Tripletta']]) ?>
  </div>

  <div class="bet-panel" data-cat-panel="assist" hidden>
    <p class="muted small">Chi fa almeno 1 assist, almeno 2 (doppietta di assist) o almeno 3 (tripletta di assist).</p>
    <?= $grid([['assist', 'Assist'], ['assist2', 'Doppietta'], ['assist3', 'Tripletta']]) ?>
  </div>

  <div class="bet-panel" data-cat-panel="giocatore" hidden>
    <?php $others = array_filter($cands, fn($c) => (int) $c['player_id'] !== (int) $me); ?>
    <?php if (!$others): ?><p class="muted small">Nessun giocatore in lista, per ora.</p><?php else: ?>
    <label class="field bet-player-pick"><span>Scegli un giocatore</span>
      <select data-player-select>
        <?php foreach ($byTeam as $t => $list): ?><optgroup label="<?= h($t === '-' ? $noTeam : team_name($t, $m)) ?>">
          <?php foreach ($list as $c): if ((int) $c['player_id'] === (int) $me) continue; ?><option value="<?= (int) $c['player_id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></optgroup>
        <?php endforeach; ?>
      </select></label>
    <?php $first = true; foreach ($others as $c): $pid = (int) $c['player_id']; $nm = $c['name']; ?>
      <div class="bet-player" data-player-panel="<?= $pid ?>"<?= $first ? '' : ' hidden' ?>>
        <?= $picker('ougol', (string) $pid, $nm, 'Gol di ' . $nm) ?>
        <?= $picker('ouassist', (string) $pid, $nm, 'Assist di ' . $nm) ?>
        <div class="quota-row quota-row-2">
          <?= $qbtn('golassist', (string) $pid, $nm, 'Gol + assist') ?><?= $qbtn('autogol', (string) $pid, $nm, 'Autogol') ?>
        </div>
      </div>
    <?php $first = false; endforeach; endif; ?>
  </div>

  <div class="bet-panel" data-cat-panel="premi" hidden>
    <?php foreach (['mvp', 'difensore', 'golassist', 'autogol'] as $mk): $info = $markets[$mk]; ?>
      <div class="bet-block">
        <h3><i class="ti ti-<?= $info['icon'] ?>"></i> <?= h($info['label']) ?> <span class="muted small">· <?= h($info['when']) ?></span></h3>
        <?php if ($mk === 'difensore' && empty($quotes[$mk])): ?><p class="muted small">Compare quando ci sono almeno due giocatori votabili per il premio.</p>
        <?php else: ?><div class="quota-picks">
          <?php foreach ($cands as $c): $pid = (int) $c['player_id']; if ($pid === (int) $me || !isset($quotes[$mk][$pid])) continue; ?><?= $qbtn($mk, (string) $pid, $c['name'], $c['name']) ?><?php endforeach; ?>
        </div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php elseif ($me && $open): ?>
    <p class="muted small">Puoi scommettere solo sulle partite delle tue leghe.</p>
  <?php endif; ?>

  <?php if ($myBets): ?>
    <div class="bet-mine-list">
      <h3 class="small"><i class="ti ti-receipt"></i> Le tue puntate su questa partita</h3>
      <?php foreach ($myBets as $my): $mk = $my['market']; ?>
        <form method="post" class="bet-mine"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="match_id" value="<?= $mid ?>">
          <input type="hidden" name="market" value="<?= h($mk) ?>"><input type="hidden" name="pick" value="<?= h((string) $my['pick']) ?>">
          <span class="tag tag-ok"><?= !empty($my['fire']) ? '<i class="ti ti-flame"></i>' : '<i class="ti ti-check"></i>' ?> <?= h(rtrim($markets[$mk]['label'] ?? $mk, '?')) ?>: <b><?= h(bet_pick_label($m, $mk, (string) $my['pick'], $names)) ?></b>
            · <?= (int) $my['stake'] ?> a ×<?= fmt_num($my['odds'], 2) ?> = <?= bet_payout((int) $my['stake'], $my['odds'], bet_mult($my)) ?></span>
          <?php if (bets_before_kickoff($m)): ?><button class="btn btn-ghost btn-sm" data-confirm="Ritirare la puntata? Vigliacco.">Ritira</button><?php endif; ?></form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($all): ?>
    <details class="bet-admin small">
      <summary class="muted">Admin: chi ha puntato su cosa (e cambia l'importo)</summary>
      <?php foreach ($all as $mk => $list): foreach ($list as $b): ?>
        <form method="post" class="bet-mine"><?= csrf_field() ?><input type="hidden" name="do" value="bet_stake"><input type="hidden" name="bet_id" value="<?= (int) $b['id'] ?>">
          <span><?= h($b['name']) ?> · <?= h(rtrim($markets[$mk]['label'] ?? $mk, '?')) ?>: <?= h(bet_pick_label($m, $mk, (string) $b['pick'], $names)) ?> a ×<?= fmt_num($b['odds'], 2) ?><?= !empty($b['fire']) ? ' <i class="ti ti-flame" title="' . h(BET_FIRE_NAME) . '"></i>' : '' ?></span>
          <?php if ($b['status'] === 'aperta'): ?>
            <input type="number" name="stake" min="1" value="<?= (int) $b['stake'] ?>" inputmode="numeric" aria-label="KOIN puntati" style="width:5.5em">
            <button class="btn btn-ghost btn-sm" data-confirm="Cambiare l'importo di questa puntata? La quota resta quella presa.">Salva</button>
          <?php else: ?><b><?= (int) $b['stake'] ?></b><?php endif; ?>
        </form>
      <?php endforeach; endforeach; ?>
    </details>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<?php elseif ($tab === 'mie'): ?>

<?php if (!$me): ?><p class="empty card">Il tuo account non è collegato a un giocatore: niente scommesse da mostrare.</p>
<?php elseif (!$openSingles && !$comboOpen && !$history && !$comboHist): ?>
<p class="empty card">Non hai ancora fatto nessuna scommessa: vai su <a class="link" href="bets.php">Partite</a> e tocca una quota.</p>
<?php else: ?>
<p class="muted small">Tutte le tue scommesse, singole e multiple: in gioco <b><?= $inPlay ?></b> KOIN · saldo delle scommesse decise <b><?= fmt_signed($net + $comboNet, 0) ?></b>
  · premi per gol e assist <b>+<?= player_rewards_total($me) ?></b> (<?= BET_REWARD_GOAL ?> a gol e <?= BET_REWARD_ASSIST ?> ad assist, e altrettanti crediti dell'Avatar: <?= SHOP_CREDIT_GOAL ?> a gol e <?= SHOP_CREDIT_ASSIST ?> ad assist)
  · premi MVP e miglior difensore <b>+<?= player_vote_prizes_total($me) ?></b> (<?= BET_REWARD_MVP ?> all'MVP, <?= BET_REWARD_DIF ?> al miglior difensore).</p>

<h2 class="section-title">Singole in corso</h2>
<?php if (!$openSingles): ?><p class="empty card">Nessuna singola in corso.</p><?php else: ?>
<div class="card table-card"><div class="table-wrap"><table class="table table-cards">
  <thead><tr><th>Partita</th><th>Mercato</th><th>La tua scelta</th><th>Puntati</th><th>Quota</th><th>Vinci</th><th></th></tr></thead><tbody>
  <?php foreach ($openSingles as $b):
      $betOpen = bets_open_for(['status' => $b['match_status'], 'match_date' => $b['match_date']]); ?>
    <tr><td class="tc-when"><a href="match.php?id=<?= (int) $b['match_id'] ?>"><?= fmt_date_short($b['match_date']) ?></a></td>
      <td class="tc-market"><?= h($markets[$b['market']]['label'] ?? $b['market']) ?></td>
      <td class="tc-pick"><?= h(bet_pick_label($b, $b['market'], $b['pick'])) ?></td><td data-label="Puntati"><?= (int) $b['stake'] ?></td><td data-label="Quota">×<?= fmt_num($b['odds'], 2) ?></td>
      <td data-label="Vinci"><?= bet_payout((int) $b['stake'], $b['odds'], bet_mult($b)) ?><?= !empty($b['fire']) ? ' <i class="ti ti-flame" title="' . h(BET_FIRE_NAME) . ': vincita doppia"></i>' : '' ?></td>
      <td class="tc-act"><?php if ($betOpen): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="from" value="mie">
            <input type="hidden" name="match_id" value="<?= (int) $b['match_id'] ?>"><input type="hidden" name="market" value="<?= h($b['market']) ?>"><input type="hidden" name="pick" value="<?= h((string) $b['pick']) ?>">
            <button class="btn btn-ghost btn-sm" data-confirm="Ritirare la puntata? Vigliacco.">Ritira</button></form>
        <?php elseif ($b['match_status'] === 'giocata'): ?><span class="tag"><?= in_array($b['market'], BET_VOTE_MARKETS, true) && $b['voting_open'] ? 'aspetta i voti' : 'in attesa del verdetto' ?></span>
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
<div class="card table-card"><div class="table-wrap"><table class="table table-cards">
  <thead><tr><th>Partita</th><th>Mercato</th><th>Scelta</th><th>Puntati</th><th>Quota</th><th>Esito</th></tr></thead><tbody>
  <?php foreach ($history as $b): ?>
    <tr><td class="tc-when"><a href="match.php?id=<?= (int) $b['match_id'] ?>"><?= fmt_date_short($b['match_date']) ?></a></td>
      <td class="tc-market"><?= h($markets[$b['market']]['label'] ?? $b['market']) ?></td>
      <td class="tc-pick"><?= h(bet_pick_label($b, $b['market'], $b['pick'])) ?><?= !empty($b['fire']) ? ' <i class="ti ti-flame" title="' . h(BET_FIRE_NAME) . '"></i>' : '' ?></td><td data-label="Puntati"><?= (int) $b['stake'] ?></td><td data-label="Quota">×<?= fmt_num($b['odds'], 2) ?></td>
      <td class="tc-act"><?php if ($b['status'] === 'vinta'): ?><span class="tag tag-ok">vinta +<?= (int) $b['payout'] - (int) $b['stake'] ?></span>
        <?php elseif ($b['status'] === 'persa'): ?><span class="tag tag-live">persa −<?= (int) $b['stake'] ?></span>
        <?php else: ?><span class="tag">rimborsata</span><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<?php if (!$showAll && ($histTotal > count($history) || $comboTotal > count($comboHist))): ?>
<p class="center"><a class="btn btn-ghost btn-sm" href="bets.php?t=mie&amp;tutte=1">Mostra tutto lo storico (<?= $histTotal ?> singole, <?= $comboTotal ?> multiple)</a></p>
<?php endif; ?>
<?php if ($comboHist): ?>
<h2 class="section-title">Multiple decise <span class="muted small">saldo <?= fmt_signed($comboNet, 0) ?></span></h2>
<div class="notepad-list">
  <?php foreach ($comboHist as $c): ?><?= combo_sheet($c, $markets, false) ?><?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php else: ?>

<h2 class="section-title">Classifica dei ricchi<?php if ($me && count(player_ecos($me)) > 1): ?> <span class="muted small">KOIN di «<?= h(eco_label(current_eco($me))) ?>»</span><?php endif; ?></h2>
<?php if (!$board): ?><p class="empty card">Nessuno ha ancora aperto il portafoglio.</p><?php else: ?>
<div class="card table-card"><div class="table-wrap"><table class="table">
  <thead><tr><th>#</th><th>Giocatore</th><th class="bet-col-title">Titolo</th><th>KOIN</th><th title="Puntate (singole e multiple) vinte su decise">Vinte</th></tr></thead><tbody>
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

<div id="betslip" class="betslip<?= $fireToday ? ' is-fire' : '' ?>" data-balance="<?= (int) $balance ?>" data-mult="<?= $fireToday ? BET_FIRE_MULT : 1 ?>" data-mode="singole" hidden>
  <div class="betslip-head">
    <span class="muted small"><i class="ti ti-stack-2"></i> Schedina (<span id="slip-count">0</span>)</span>
    <div class="slip-tabs" role="tablist">
      <button type="button" class="slip-tab active" data-slip-tab="singole" role="tab" aria-selected="true">Singole</button>
      <button type="button" class="slip-tab" data-slip-tab="multipla" id="slip-tab-multi" role="tab" aria-selected="false" disabled title="Servono almeno 2 selezioni">Multipla</button>
    </div>
  </div>
  <?php if ($fireToday): ?><p class="slip-fire small"><i class="ti ti-flame"></i> <?= h(BET_FIRE_NAME) ?>: le vincite qui sotto sono già raddoppiate.</p><?php endif; ?>
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
    <label class="betslip-stake small">Importo <input type="number" name="stake" id="slip-multi-stake" min="1" max="<?= max(1, $balance) ?>" value="<?= min(10, max(1, $balance)) ?>" inputmode="numeric" aria-label="KOIN sulla multipla"></label>
    <span class="betslip-total small" id="slip-multi-win"></span>
    <button type="submit" class="btn btn-primary btn-sm" id="slip-multi-submit">Punta la multipla</button>
    <button type="button" class="btn btn-ghost btn-sm" id="slip-clear-2">Svuota schedina</button>
    <span class="muted small slip-multi-warn" id="slip-multi-warn" hidden></span>
  </form>
</div>

<script src="assets/bets.js?v=<?= h(substr((string) @md5_file(__DIR__ . '/assets/bets.js'), 0, 10)) ?>" defer></script>
<?php
layout_end();
