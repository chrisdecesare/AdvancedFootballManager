<?php
require __DIR__ . '/lib/bootstrap.php';
require_view();

$id = int_get('id');
$match = get_match($id);
if (!$match || !match_access($match)) {   // partita inesistente o di un gruppo a cui non appartieni
    http_response_code(404);
    layout_start('Partita non trovata', 'matches');
    echo '<div class="card"><h2>Partita non trovata</h2><a href="matches.php"><i class="ti ti-arrow-left"></i> Partite</a></div>';
    layout_end();
    exit;
}
$me = my_player_id();
$self = 'match.php?id=' . $id;

/* ---------------------------------------------------------------- azioni */
if (is_post()) {
    $do = $_POST['do'] ?? '';
    $pid = (int) ($_POST['player_id'] ?? 0);

    if ($do === 'vote') {
        require_login();
        handle_vote($match, $me);
        redirect($self . '#voti');
    }

    require_match_manager();
    $actor = (int) current_user()['id'];
    switch ($do) {
        case 'edit_info':
            $dt = DateTime::createFromFormat('Y-m-d H:i', ($_POST['date'] ?? '') . ' ' . ($_POST['time'] ?? ''));
            if ($dt) {
                $newDate = $dt->format('Y-m-d H:i:s');
                // cambia il giorno di una partita in programma: le vecchie risposte non valgono più
                $reset = $match['status'] === 'programmata' && substr($newDate, 0, 10) !== substr($match['match_date'], 0, 10);
                db()->beginTransaction();
                q('UPDATE matches SET match_date = ?, location = ?, team_a_name = ?, team_b_name = ?, fee = ?, notes = ? WHERE id = ?', [
                    $newDate, trim($_POST['location'] ?? ''),
                    clean_team_name($_POST['team_a'] ?? '', TEAM_A_NAME), clean_team_name($_POST['team_b'] ?? '', TEAM_B_NAME),
                    max(0, (float) str_replace(',', '.', $_POST['fee'] ?? '0')),
                    trim($_POST['notes'] ?? '') ?: null, $id]);
                if ($reset) {
                    sync_match_players($id);   // anche chi non aveva ancora una riga deve poter rispondere
                    reset_match_responses($id);
                }
                db()->commit();
                if ($match['status'] === 'programmata') {
                    push_notify_match_changed($id, $match, $reset, $actor);   // avvisa i giocatori, a pagina già inviata
                }
                flash('ok', 'Dati della partita aggiornati.' . ($reset ? ' Il giorno è cambiato: le risposte dei giocatori sono state azzerate e dovranno rispondere di nuovo.' : ''));
            } else {
                flash('err', 'Data o ora non valide.');
            }
            break;

        case 'set_avail':
            $st = $_POST['status'] ?? '';
            if (in_array($st, ['confermato', 'in_attesa', 'assente'], true)) {
                q("UPDATE match_players SET availability = ?, team = IF(? = 'confermato', team, NULL)
                   WHERE match_id = ? AND player_id = ?", [$st, $st, $id, $pid]);
                assign_formation($id);
            }
            break;

        case 'gen_teams':
            $stats = compute_stats([(int) $match['group_id']]);   // rating calcolati sulle partite del suo gruppo
            $pool = [];
            foreach (match_roster($id) as $r) {
                if ($r['availability'] === 'confermato') {
                    $pool[] = ['id' => (int) $r['player_id'], 'prefs' => player_prefs($r),
                        'rating' => $stats[(int) $r['player_id']]['ovr'] ?? 6];
                }
            }
            if (count($pool) < 2) {
                flash('err', 'Servono almeno 2 giocatori confermati.');
                break;
            }
            @set_time_limit(90);   // con 22 giocatori e molte intese il calcolo può richiedere qualche secondo sull'hosting
            $chem = chemistry([(int) $match['group_id']]);   // intesa dalle partite già giocate insieme, nel gruppo
            $res = balance_teams($pool, 6, $match, chemistry_pairs_for($chem, array_column($pool, 'id')));
            db()->beginTransaction();
            q('UPDATE match_players SET team = NULL WHERE match_id = ?', [$id]);
            foreach (['A', 'B'] as $t) {
                foreach ($res[$t] as $p) {
                    q('UPDATE match_players SET team = ? WHERE match_id = ? AND player_id = ?', [$t, $id, $p]);
                }
            }
            db()->commit();
            assign_formation($id);
            $hasChem = abs($res['chemA'] ?? 0) + abs($res['chemB'] ?? 0) > 0;
            $fa = $res['sumA'] + ($res['chemA'] ?? 0);
            $fb = $res['sumB'] + ($res['chemB'] ?? 0);
            flash('ok', sprintf('Squadre generate: forza %s vs %s (differenza %s)%s.',
                fmt_num($fa, 1), fmt_num($fb, 1), fmt_num(abs($fa - $fb), 2),
                $hasChem ? ', compresa l\'intesa ' . fmt_signed($res['chemA'], 2) . ' / ' . fmt_signed($res['chemB'], 2) : ''));
            redirect($self . '#squadre');

        case 'move_team':
            q("UPDATE match_players SET team = IF(team = 'A', 'B', 'A'), availability = 'confermato'
               WHERE match_id = ? AND player_id = ?", [$id, $pid]);
            assign_formation($id);
            redirect($self . '#squadre');

        case 'swap_slots':
            // scambia squadra e posto di due pedine (senza ricalcolare il resto)
            $a = q('SELECT player_id, team, slot FROM match_players WHERE match_id = ? AND player_id = ?', [$id, (int) ($_POST['p1'] ?? 0)])->fetch();
            $b = q('SELECT player_id, team, slot FROM match_players WHERE match_id = ? AND player_id = ?', [$id, (int) ($_POST['p2'] ?? 0)])->fetch();
            if ($a && $b && $a['team'] && $b['team']) {
                q('UPDATE match_players SET team = ?, slot = ? WHERE match_id = ? AND player_id = ?', [$b['team'], $b['slot'], $id, $a['player_id']]);
                q('UPDATE match_players SET team = ?, slot = ? WHERE match_id = ? AND player_id = ?', [$a['team'], $a['slot'], $id, $b['player_id']]);
            }
            redirect($self . '#squadre');

        case 'set_formation':
            $t = ($_POST['team'] ?? '') === 'B' ? 'b' : 'a';
            $f = trim($_POST['formation'] ?? '');
            q("UPDATE matches SET formation_$t = ? WHERE id = ?", [parse_formation($f) ? $f : '', $id]);
            assign_formation($id);
            flash('ok', $f ? "Modulo $f impostato." : 'Modulo automatico.');
            redirect($self . '#squadre');

        case 'reset_formation':
            assign_formation($id);
            flash('ok', 'Posizioni ricalcolate in base alle preferenze.');
            redirect($self . '#squadre');

        case 'add_to_team':
            $t = ($_POST['team'] ?? '') === 'B' ? 'B' : 'A';
            q("UPDATE match_players SET team = ?, availability = 'confermato' WHERE match_id = ? AND player_id = ?", [$t, $id, $pid]);
            assign_formation($id);
            redirect($self . '#squadre');

        case 'remove_from_team':
            q('UPDATE match_players SET team = NULL, slot = NULL WHERE match_id = ? AND player_id = ?', [$id, $pid]);
            assign_formation($id);
            redirect($self . '#squadre');

        case 'clear_teams':
            q('UPDATE match_players SET team = NULL, slot = NULL WHERE match_id = ?', [$id]);
            flash('ok', 'Squadre azzerate.');
            break;

        case 'save_result':
            db()->beginTransaction();
            foreach (['goals', 'assists', 'own_goals'] as $k) {
                foreach ((array) ($_POST[$k] ?? []) as $p => $v) {
                    q("UPDATE match_players SET $k = ? WHERE match_id = ? AND player_id = ?",
                        [max(0, min(99, (int) $v)), $id, (int) $p]);
                }
            }
            $sa = $_POST['score_a'] ?? '';
            $sb = $_POST['score_b'] ?? '';
            q('UPDATE matches SET score_a = ?, score_b = ? WHERE id = ?', [
                $sa === '' ? null : max(0, (int) $sa), $sb === '' ? null : max(0, (int) $sb), $id]);
            if (!empty($_POST['finish'])) {
                if ($sa === '' || $sb === '') {
                    db()->rollBack();
                    flash('err', 'Inserisci il risultato prima di chiudere la partita.');
                    redirect($self . '#risultato');
                }
                $ends = default_voting_end();
                q("UPDATE matches SET status = 'giocata', voting_open = 1, voting_ends_at = ? WHERE id = ?", [$ends, $id]);
                push_notify_voting($id, true, $actor);   // parte dopo che la pagina è stata inviata
                flash('ok', 'Partita conclusa: votazioni aperte per chi ha giocato, fino a ' . push_when($ends) . '.');
            } else {
                flash('ok', 'Risultato salvato.');
            }
            db()->commit();
            if ($match['status'] === 'giocata' || !empty($_POST['finish'])) {
                bets_resettle_result($id);   // risultato salvato o corretto: si pagano (o si rifanno) le scommesse su esito e marcatori
            }
            redirect($self . '#risultato');

        case 'add_link':
            $x = (int) ($_POST['assister_id'] ?? 0);
            $y = (int) ($_POST['scorer_id'] ?? 0);
            $times = max(1, min(20, (int) ($_POST['n'] ?? 1)));
            $tx = q('SELECT team FROM match_players WHERE match_id = ? AND player_id = ? AND team IS NOT NULL', [$id, $x])->fetchColumn();
            $ty = q('SELECT team FROM match_players WHERE match_id = ? AND player_id = ? AND team IS NOT NULL', [$id, $y])->fetchColumn();
            if (!$tx || !$ty) {
                flash('err', 'Scegli due giocatori che hanno giocato la partita.');
            } elseif ($x === $y) {
                flash('err', 'Chi fa assist e chi segna devono essere due giocatori diversi.');
            } elseif ($tx !== $ty) {
                flash('err', 'Devono essere della stessa squadra.');
            } else {
                q('INSERT INTO match_links (match_id, assister_id, scorer_id, n) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE n = VALUES(n)', [$id, $x, $y, $times]);
                flash('ok', 'Assist registrato.');
            }
            redirect($self . '#assist');

        case 'del_link':
            q('DELETE FROM match_links WHERE match_id = ? AND assister_id = ? AND scorer_id = ?',
                [$id, (int) ($_POST['assister_id'] ?? 0), (int) ($_POST['scorer_id'] ?? 0)]);
            redirect($self . '#assist');

        case 'close_voting':
            $late = close_voting_now($id, $actor);   // voti d'ufficio a chi non ha votato + notifica
            if ($late === null) {
                flash('err', 'Le votazioni erano già chiuse.');
                break;
            }
            flash('ok', 'Votazioni chiuse: voti e MVP ora contano nelle statistiche.'
                . ($late ? ' A chi non ha votato (' . implode(', ', $late) . ') è stato dato ' . default_vote_label() . ' d\'ufficio a tutti gli altri.' : ''));
            break;

        case 'open_voting':
            q('UPDATE matches SET voting_open = 1, voting_ends_at = ? WHERE id = ?', [default_voting_end(), $id]);
            q('DELETE FROM ratings WHERE match_id = ? AND is_auto = 1', [$id]);   // i voti d'ufficio si rifanno alla prossima chiusura
            bets_unsettle($id, ['mvp']);   // l'MVP torna in gioco: le scommesse si ripagano alla prossima chiusura
            push_notify_voting($id, true, $actor);
            flash('ok', 'Votazioni riaperte.');
            break;

        case 'set_voting_end':
            if ($match['status'] !== 'giocata' || !$match['voting_open']) {
                flash('err', 'Le votazioni non sono aperte.');
                break;
            }
            if (!empty($_POST['clear'])) {
                q('UPDATE matches SET voting_ends_at = NULL WHERE id = ?', [$id]);
                flash('ok', 'Nessuna scadenza: le votazioni si chiudono solo quando le chiudi tu.');
                break;
            }
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', (string) ($_POST['ends'] ?? ''));
            if (!$dt || $dt->getTimestamp() <= time()) {
                flash('err', 'Scegli un orario di fine votazioni che non sia già passato.');
                break;
            }
            q('UPDATE matches SET voting_ends_at = ? WHERE id = ?', [$dt->format('Y-m-d H:i:s'), $id]);
            flash('ok', 'Le votazioni terminano ' . push_when($dt->format('Y-m-d H:i:s')) . '.');
            break;

        case 'reopen':
            q("UPDATE matches SET status = 'programmata', voting_open = 0, voting_ends_at = NULL WHERE id = ?", [$id]);
            bets_unsettle($id);   // le scommesse tornano aperte e si ripagano quando la partita viene richiusa
            flash('ok', 'Partita riportata a "programmata".');
            break;

        case 'toggle_paid':
            if (!is_admin()) {
                flash('err', 'I pagamenti li gestisce solo un admin.');
                break;
            }
            q('UPDATE match_players SET paid = 1 - paid WHERE match_id = ? AND player_id = ?', [$id, $pid]);
            redirect($self . '#pagamenti');

        case 'delete':
            if (!is_admin()) {
                flash('err', 'Solo un admin può eliminare una partita.');
                break;
            }
            push_notify_match_cancelled($match, $actor);   // prima di cancellare: dopo non si saprebbe più a chi mandarla
            q('DELETE FROM matches WHERE id = ?', [$id]);
            flash('ok', 'Partita eliminata.');
            redirect('matches.php');
    }
    redirect($self);
}

function handle_vote(array $match, ?int $me): void
{
    $id = (int) $match['id'];
    if ($match['status'] !== 'giocata' || !$match['voting_open']) {
        flash('err', 'Le votazioni per questa partita sono chiuse.');
        return;
    }
    $mates = [];
    foreach (match_roster($id) as $r) {
        if ($r['team']) {
            $mates[(int) $r['player_id']] = true;
        }
    }
    if (!$me || !isset($mates[$me])) {
        flash('err', 'Può votare solo chi ha giocato la partita.');
        return;
    }
    $votes = (array) ($_POST['vote'] ?? []);
    $mvp = (int) ($_POST['mvp'] ?? 0);
    if (!$mvp || $mvp === $me || !isset($mates[$mvp])) {
        flash('err', 'Scegli l\'MVP tra gli altri giocatori della partita.');
        return;
    }
    db()->beginTransaction();
    q('DELETE FROM ratings WHERE match_id = ? AND voter_id = ?', [$id, $me]);
    foreach ($mates as $pid => $_) {
        if ($pid === $me || !isset($votes[$pid])) {
            continue;
        }
        $v = round(max(1, min(10, (float) $votes[$pid])) * 2) / 2; // passi da 0,5
        q('INSERT INTO ratings (match_id, voter_id, rated_id, vote) VALUES (?, ?, ?, ?)', [$id, $me, $pid, $v]);
    }
    q('DELETE FROM mvp_votes WHERE match_id = ? AND voter_id = ?', [$id, $me]);
    q('INSERT INTO mvp_votes (match_id, voter_id, voted_id) VALUES (?, ?, ?)', [$id, $me, $mvp]);
    db()->commit();
    flash('ok', 'Voti registrati, grazie! Puoi modificarli finché le votazioni sono aperte.');
    $_SESSION['vote_done'] = 1;   // fa comparire il segno di spunta grande nella pagina successiva
}

/* ---------------------------------------------------------------- dati */
if ($match['status'] === 'programmata') {
    sync_match_players($id);
}
$roster = match_roster($id);
$stats = compute_stats([(int) $match['group_id']]);
$byStatus = ['confermato' => [], 'in_attesa' => [], 'assente' => []];
$teams = ['A' => [], 'B' => []];
$myRow = null;
foreach ($roster as $r) {
    $byStatus[$r['availability']][] = $r;
    if ($r['team']) {
        $teams[$r['team']][] = $r;
    }
    if ((int) $r['player_id'] === $me) {
        $myRow = $r;
    }
}
$hasTeams = $teams['A'] || $teams['B'];
$played = $match['status'] === 'giocata';
$votingOpen = $played && (int) $match['voting_open'];
$avgs = match_vote_averages()[$id] ?? [];
$mvpCounts = match_mvp_counts()[$id] ?? [];
$mvp = $played ? match_mvp($id) : null;
$iPlayed = $myRow && $myRow['team'];
$showVotes = $played && (!$votingOpen || is_admin());

// voti già dati dal giocatore collegato (per precompilare il modulo)
$myVotes = [];
$myMvp = null;
if ($votingOpen && $iPlayed) {
    foreach (q('SELECT rated_id, vote FROM ratings WHERE match_id = ? AND voter_id = ?', [$id, $me])->fetchAll() as $r) {
        $myVotes[(int) $r['rated_id']] = (float) $r['vote'];
    }
    $myMvp = q('SELECT voted_id FROM mvp_votes WHERE match_id = ? AND voter_id = ?', [$id, $me])->fetchColumn() ?: null;
}
$voters = $played ? array_map('intval', q('SELECT voter_id FROM mvp_votes WHERE match_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN)) : [];
$participants = array_merge($teams['A'], $teams['B']);
$chem = !$played && $hasTeams ? chemistry([(int) $match['group_id']]) : ['pairs' => [], 'players' => []];
$nameOf = short_names($roster);
$links = $hasTeams ? q('SELECT ml.assister_id, ml.scorer_id, ml.n, a.name AS an, s.name AS sn FROM match_links ml
                        JOIN players a ON a.id = ml.assister_id JOIN players s ON s.id = ml.scorer_id
                        WHERE ml.match_id = ? ORDER BY a.name, s.name', [$id])->fetchAll() : [];

layout_start('Partita del ' . fmt_date_short($match['match_date']), 'matches');
if (!empty($_SESSION['vote_done'])):
    unset($_SESSION['vote_done']); ?>
<div class="vote-done" data-vote-done role="status" aria-live="polite">
  <div class="vote-done-card">
    <svg class="vote-done-badge" viewBox="0 0 120 120" aria-hidden="true">
      <circle class="vd-shadow" cx="66" cy="66" r="52"/>
      <circle class="vd-disc" cx="60" cy="60" r="52"/>
      <path class="vd-check" d="M34 62 L53 81 L88 40"/>
    </svg>
    <div class="vote-done-title">Voti inviati!</div>
    <div class="vote-done-sub">Puoi cambiarli finché le votazioni sono aperte.</div>
  </div>
</div>
<?php endif; ?>
<a class="back" href="matches.php"><i class="ti ti-arrow-left"></i> Partite</a>

<section class="card match-head">
  <div class="match-when">
    <span class="eyebrow"><?= $played ? 'Partita giocata' : '<i class="ti ti-calendar-event"></i> In programma' ?></span>
    <h1><?= h(ucfirst(fmt_date_long($match['match_date']))) ?></h1>
    <div class="hero-meta">
      <span><i class="ti ti-clock"></i> <?= fmt_time($match['match_date']) ?></span>
      <span><i class="ti ti-map-pin"></i> <?= h($match['location'] ?: 'Campo da definire') ?></span>
      <?= group_tag((int) $match['group_id']) ?>
      <?php if ((float) $match['fee'] > 0): ?><span><i class="ti ti-currency-euro"></i> <?= fmt_money($match['fee']) ?> a testa</span><?php endif; ?>
    </div>
    <?php if ($match['notes']): ?><p class="muted"><?= nl2br(h($match['notes'])) ?></p><?php endif; ?>
    <?php if (!$played && strtotime($match['match_date']) > time() - 3 * 3600): ?>
      <div class="hero-count"><?= countdown_html($match['match_date'], 'Mancano ', 'Si gioca!', 86400, false, 'hourglass-high', 'countdown-big') ?></div>
    <?php endif; ?>
    <?php if (!$played): ?><div class="match-cal"><?= gcal_button($match) ?></div><?php endif; ?>
  </div>
  <?php if ($played || $match['score_a'] !== null): ?>
    <div class="score">
      <div class="score-team team-a"><?= h(team_name('A', $match)) ?></div>
      <div class="score-num"><?= $match['score_a'] ?? '-' ?> <span>–</span> <?= $match['score_b'] ?? '-' ?></div>
      <div class="score-team team-b"><?= h(team_name('B', $match)) ?></div>
    </div>
  <?php endif; ?>
  <?= availability_buttons($match, $myRow['availability'] ?? null, $self) ?>
</section>

<?php if (!$played): ?>
<section class="card">
  <h2>Presenze</h2>
  <div class="avail-cols">
    <?php foreach (['confermato' => '<i class="ti ti-user-check"></i> Confermati', 'in_attesa' => '<i class="ti ti-user-question"></i> Da confermare', 'assente' => '<i class="ti ti-user-x"></i> Assenti'] as $st => $label): ?>
      <div class="avail-col">
        <h3><?= $label ?> <span class="count <?= $st === 'confermato' ? 'count-yes' : ($st === 'assente' ? 'count-no' : '') ?>"><?= count($byStatus[$st]) ?></span></h3>
        <?php foreach ($byStatus[$st] as $r): ?>
          <div class="pline-row">
            <?= player_line($r) ?>
            <?php if (can_manage_matches()): ?>
              <form method="post" class="inline">
                <?= csrf_field() ?><input type="hidden" name="do" value="set_avail"><input type="hidden" name="player_id" value="<?= (int) $r['player_id'] ?>">
                <select name="status" class="mini-select" data-autosubmit aria-label="Cambia stato">
                  <option value="confermato" <?= $st === 'confermato' ? 'selected' : '' ?>>Ci sono</option>
                  <option value="in_attesa" <?= $st === 'in_attesa' ? 'selected' : '' ?>>In attesa</option>
                  <option value="assente" <?= $st === 'assente' ? 'selected' : '' ?>>Assente</option>
                </select>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="card" id="squadre">
  <div class="card-head">
    <h2>Squadre</h2>
    <?php if (can_manage_matches() && !$played): ?>
      <div class="btn-row">
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="gen_teams">
          <button class="btn btn-primary btn-sm"><i class="ti ti-scale"></i> <?= $hasTeams ? 'Rigenera' : 'Genera squadre bilanciate' ?></button></form>
        <?php if ($hasTeams): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="clear_teams">
            <button class="btn btn-ghost btn-sm" data-confirm="Azzerare le squadre?">Azzera</button></form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php if (!$hasTeams): ?>
    <p class="empty"><?= can_manage_matches() ? 'Quando ci sono abbastanza confermati, premi "Genera squadre bilanciate": l\'algoritmo divide i giocatori in due squadre con forza complessiva simile.' : 'Le squadre non sono ancora state fatte.' ?></p>
  <?php else: ?>
    <div class="squad-grid">
    <div class="squad-pitch">
      <?= render_pitch($match, $roster, can_manage_matches() && !$played) ?>
      <?php if (can_manage_matches() && !$played): ?>
        <form method="post" class="center"><?= csrf_field() ?><input type="hidden" name="do" value="reset_formation">
          <button class="btn btn-ghost btn-sm"><i class="ti ti-refresh"></i> Ricalcola posizioni dalle preferenze</button></form>
      <?php endif; ?>
    </div>
    <div class="teams">
      <?php foreach (['A', 'B'] as $t): $str = team_strength($roster, $t, $stats); ?>
        <div class="team team-<?= strtolower($t) ?>">
          <div class="team-head">
            <strong><?= h(team_name($t, $match)) ?></strong>
            <?php if ($played): ?><span class="team-score"><?= (int) $match['score_' . strtolower($t)] ?></span><?php endif; ?>
            <span class="team-str" title="Somma dei rating"><i class="ti ti-scale"></i> <?= fmt_num($str['sum'], 1) ?> <small>(media <?= fmt_num($str['avg'], 2) ?>)</small></span>
          </div>
          <?php $roles = team_roles($roster, $t); ?>
          <span class="team-roles" title="Giocatori per ruolo (1ª scelta) e con Jolly come 2ª scelta">
            POR <?= $roles['POR'] ?> · DIF <?= $roles['DIF'] ?> · CEN <?= $roles['CEN'] ?> · ATT <?= $roles['ATT'] ?><?= $roles['JOL'] ? ' · Jolly ' . $roles['JOL'] : '' ?></span>
          <?php $tch = !$played ? team_chemistry($chem, array_column($teams[$t], 'player_id')) : ['pairs' => []]; ?>
          <?php if ($tch['pairs']): ?>
            <span class="team-roles team-chem" title="Intesa: bonus o malus di forza per chi ha già giocato insieme (risultati, assist, gol)">
              <i class="ti ti-heart-handshake"></i> Intesa <strong><?= fmt_signed($tch['sum'], 2) ?></strong>
              <?php foreach (array_slice($tch['pairs'], 0, 2) as $cp): ?>
                · <?= h($nameOf[$cp['a']] ?? '?') ?>+<?= h($nameOf[$cp['b']] ?? '?') ?> <?= fmt_signed($cp['score'], 2) ?>
              <?php endforeach; ?></span>
          <?php endif; ?>
          <?php foreach ($teams[$t] as $r): $pid = (int) $r['player_id'];
            $extra = '';
            if ($r['goals']) $extra .= '<span class="ev"><i class="ti ti-ball-football"></i>' . ($r['goals'] > 1 ? '×' . $r['goals'] : '') . '</span>';
            if ($r['assists']) $extra .= '<span class="ev"><b class="ast">A</b>' . ($r['assists'] > 1 ? '×' . $r['assists'] : '') . '</span>';
            if ($r['own_goals']) $extra .= '<span class="ev ev-og">AG' . ($r['own_goals'] > 1 ? '×' . $r['own_goals'] : '') . '</span>';
            if ($showVotes && isset($avgs[$pid])) $extra .= '<span class="vote ' . vote_class($avgs[$pid]['avg']) . '">' . fmt_num($avgs[$pid]['avg']) . '</span>';
            if (!$votingOpen && $mvp === $pid) $extra .= '<span class="tag tag-mvp"><i class="ti ti-star-filled"></i> MVP</span>';
            if (!$played) $extra .= '<span class="ovr" title="Rating">' . fmt_num($stats[$pid]['ovr'] ?? 6, 1) . '</span>';
          ?>
            <div class="pline-row">
              <?= player_line($r, $extra) ?>
              <?php if (can_manage_matches() && !$played): ?>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="move_team"><input type="hidden" name="player_id" value="<?= $pid ?>">
                  <button class="icon-btn" title="Sposta nell'altra squadra"><i class="ti ti-arrows-exchange"></i></button></form>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="remove_from_team"><input type="hidden" name="player_id" value="<?= $pid ?>">
                  <button class="icon-btn" title="Togli dalla squadra"><i class="ti ti-x"></i></button></form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
    </div>
    <?php
    $benched = array_filter($byStatus['confermato'], fn($r) => !$r['team']);
    if (can_manage_matches() && !$played && $benched): ?>
      <div class="bench">
        <span class="muted small">Confermati senza squadra:</span>
        <?php foreach ($benched as $r): ?>
          <span class="bench-item"><?= h($r['name']) ?>
            <?php foreach (['A', 'B'] as $t): ?>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="add_to_team"><input type="hidden" name="player_id" value="<?= (int) $r['player_id'] ?>"><input type="hidden" name="team" value="<?= $t ?>">
                <button class="icon-btn team-<?= strtolower($t) ?>" title="Metti in <?= h(team_name($t, $match)) ?>">+<?= h(mb_substr(team_name($t, $match), 0, 1)) ?></button></form>
            <?php endforeach; ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php if (can_manage_matches() && $hasTeams): ?>
<section class="card" id="risultato">
  <h2>Risultato e marcatori</h2>
  <form method="post" class="form" id="result-form">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_result">
    <div class="result-inputs">
      <label class="team-a"><?= h(team_name('A', $match)) ?> <input type="number" min="0" max="99" name="score_a" id="score_a" value="<?= h($match['score_a']) ?>"></label>
      <span>–</span>
      <label class="team-b"><input type="number" min="0" max="99" name="score_b" id="score_b" value="<?= h($match['score_b']) ?>"> <?= h(team_name('B', $match)) ?></label>
      <button type="button" class="btn btn-ghost btn-sm" id="calc-score" title="Gol della squadra + autogol degli avversari">Calcola dai gol</button>
    </div>
    <div class="table-wrap"><table class="table table-inputs">
      <thead><tr><th>Giocatore</th><th>Squadra</th><th><i class="ti ti-ball-football"></i> Gol</th><th><b class="ast">A</b> Assist</th><th>Autogol</th></tr></thead>
      <tbody>
      <?php foreach ($participants as $r): $pid = (int) $r['player_id']; ?>
        <tr data-team="<?= $r['team'] ?>">
          <td><?= h($r['name']) ?></td>
          <td><span class="team-dot team-<?= strtolower($r['team']) ?>"></span><?= h(team_name($r['team'], $match)) ?></td>
          <td><input type="number" min="0" max="99" name="goals[<?= $pid ?>]" value="<?= (int) $r['goals'] ?>" class="in-goals"></td>
          <td><input type="number" min="0" max="99" name="assists[<?= $pid ?>]" value="<?= (int) $r['assists'] ?>"></td>
          <td><input type="number" min="0" max="99" name="own_goals[<?= $pid ?>]" value="<?= (int) $r['own_goals'] ?>" class="in-og"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="btn-row">
      <button class="btn btn-ghost">Salva</button>
      <?php if (!$played): ?>
        <button class="btn btn-primary" name="finish" value="1" data-confirm="Chiudere la partita e aprire le votazioni?"><i class="ti ti-flag-2"></i> Salva e concludi partita</button>
      <?php endif; ?>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if (can_manage_matches() && $hasTeams): ?>
<section class="card" id="assist">
  <h2><i class="ti ti-heart-handshake"></i> Chi ha fatto assist a chi <span class="muted small">(facoltativo)</span></h2>
  <p class="muted small">Serve a misurare l'<strong>intesa</strong>: quando uno serve spesso l'altro (o si servono a vicenda) rendono meglio insieme e le squadre bilanciate ne tengono conto.
    Non deve coincidere con la tabella qui sopra: registra solo le combinazioni che ricordi.</p>
  <?php if ($links): ?>
    <div class="list">
      <?php foreach ($links as $lk): ?>
        <div class="link-row">
          <span><strong><?= h($lk['an']) ?></strong> <i class="ti ti-arrow-right"></i> <strong><?= h($lk['sn']) ?></strong> <span class="muted">· <?= (int) $lk['n'] ?> gol</span></span>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="del_link">
            <input type="hidden" name="assister_id" value="<?= (int) $lk['assister_id'] ?>"><input type="hidden" name="scorer_id" value="<?= (int) $lk['scorer_id'] ?>">
            <button class="icon-btn" title="Togli"><i class="ti ti-x"></i></button></form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <form method="post" class="form form-grid form-grid-4">
    <?= csrf_field() ?><input type="hidden" name="do" value="add_link">
    <label class="field"><span>Assist di</span><select name="assister_id">
      <?php foreach ($participants as $r): ?><option value="<?= (int) $r['player_id'] ?>"><?= h($r['name']) ?> (<?= h(team_name($r['team'], $match)) ?>)</option><?php endforeach; ?></select></label>
    <label class="field"><span>per il gol di</span><select name="scorer_id">
      <?php foreach ($participants as $r): ?><option value="<?= (int) $r['player_id'] ?>"><?= h($r['name']) ?> (<?= h(team_name($r['team'], $match)) ?>)</option><?php endforeach; ?></select></label>
    <label class="field"><span>Quante volte</span><input type="number" name="n" min="1" max="20" value="1"></label>
    <div><button class="btn btn-primary btn-sm">Aggiungi</button></div>
  </form>
</section>
<?php endif; ?>

<?php if ($played): ?>
<section class="card" id="voti">
  <div class="card-head">
    <h2>Voti <?= $votingOpen ? '<span class="tag tag-live">aperti</span>' : '<span class="tag">chiusi</span>' ?></h2>
    <span class="muted small">Hanno votato <?= count($voters) ?>/<?= count($participants) ?></span>
  </div>

  <?php if ($votingOpen && $match['voting_ends_at']): ?>
    <p class="vote-deadline"><i class="ti ti-alarm"></i> Le votazioni terminano <strong><?= h(push_when($match['voting_ends_at'])) ?></strong>
      <?= countdown_html($match['voting_ends_at'], '· mancano ', 'chiusura in corso…', 0, true, 'hourglass', 'countdown-small') ?></p>
  <?php elseif ($votingOpen): ?>
    <p class="vote-deadline muted"><i class="ti ti-alarm"></i> Nessun orario di fine: le votazioni si chiudono quando le chiude chi gestisce la partita.</p>
  <?php endif; ?>

  <?php if ($votingOpen && $iPlayed): ?>
    <form method="post" class="vote-form">
      <?= csrf_field() ?><input type="hidden" name="do" value="vote">
      <p class="muted">Dai un voto da 1 a 10 a ogni compagno e avversario, poi scegli l'MVP. <?= $myMvp ? '<strong>Hai già votato:</strong> puoi modificare.' : '' ?> <span class="small">Se non voti entro la chiusura, a tutti gli altri viene dato <?= default_vote_label() ?> d'ufficio.</span></p>
      <?php foreach ($participants as $r): $pid = (int) $r['player_id']; if ($pid === $me) continue;
        $val = $myVotes[$pid] ?? 6; ?>
        <div class="vote-row">
          <div class="vote-who"><?= avatar($r, 'sm') ?><span><?= h($r['name']) ?></span><span class="team-dot team-<?= strtolower($r['team']) ?>"></span></div>
          <input type="range" min="1" max="10" step="0.5" name="vote[<?= $pid ?>]" value="<?= h($val) ?>" class="vote-range" aria-label="Voto a <?= h($r['name']) ?>">
          <output class="vote <?= vote_class($val) ?>"><?= fmt_num($val) ?></output>
          <label class="mvp-pick" title="MVP"><input type="radio" name="mvp" value="<?= $pid ?>" <?= (int) $myMvp === $pid ? 'checked' : '' ?> required><span><i class="ti ti-star-filled"></i></span></label>
        </div>
      <?php endforeach; ?>
      <button class="btn btn-primary btn-block">Invia voti</button>
    </form>
  <?php elseif ($votingOpen): ?>
    <p class="muted">Votano solo i giocatori che hanno partecipato. I risultati si vedono alla chiusura delle votazioni.</p>
  <?php endif; ?>

  <?php $missing = array_filter($participants, fn($r) => !in_array((int) $r['player_id'], $voters, true)); ?>
  <?php if ($missing && $votingOpen): ?><p class="small muted">Mancano: <?= h(implode(', ', array_column($missing, 'name'))) ?></p><?php endif; ?>
  <?php if ($missing && !$votingOpen): ?><p class="small muted"><i class="ti ti-info-circle"></i> Non hanno votato: <?= h(implode(', ', array_column($missing, 'name'))) ?> (a tutti gli altri è stato dato <?= default_vote_label() ?> d'ufficio).</p><?php endif; ?>

  <?php if ($showVotes): ?>
    <?php if ($votingOpen): ?><p class="small muted"><i class="ti ti-eye"></i> Anteprima visibile solo agli admin.</p><?php endif; ?>
    <?php
    $rank = $participants;
    usort($rank, fn($a, $b) => ($avgs[(int) $b['player_id']]['avg'] ?? 0) <=> ($avgs[(int) $a['player_id']]['avg'] ?? 0));
    ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Giocatore</th><th>Media</th><th>N. voti</th><th><i class="ti ti-star-filled"></i> voti MVP</th></tr></thead>
      <tbody>
      <?php foreach ($rank as $r): $pid = (int) $r['player_id']; $a = $avgs[$pid] ?? null; ?>
        <tr class="<?= $mvp === $pid ? 'row-mvp' : '' ?>">
          <td><a class="tname" href="player.php?id=<?= $pid ?>"><?= avatar($r, 'xs') ?> <?= h($r['name']) ?></a> <?= $mvp === $pid ? '<span class="tag tag-mvp">MVP</span>' : '' ?></td>
          <td><span class="vote <?= vote_class($a['avg'] ?? null) ?>"><?= fmt_num($a['avg'] ?? null) ?></span></td>
          <td><?= $a['n'] ?? 0 ?></td>
          <td><?= $mvpCounts[$pid] ?? 0 ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <?php if ($votingOpen && can_manage_matches()): ?>
    <form method="post" class="deadline-form"><?= csrf_field() ?><input type="hidden" name="do" value="set_voting_end">
      <label class="field"><span>Fine votazioni</span>
        <input type="datetime-local" name="ends" value="<?= $match['voting_ends_at'] ? h(date('Y-m-d\TH:i', strtotime($match['voting_ends_at']))) : '' ?>"></label>
      <button class="btn btn-ghost btn-sm">Salva orario</button>
      <?php if ($match['voting_ends_at']): ?><button class="btn btn-ghost btn-sm" name="clear" value="1">Nessuna scadenza</button><?php endif; ?>
    </form>
  <?php endif; ?>

  <?php if (can_manage_matches()): ?>
    <div class="btn-row">
      <form method="post" class="inline"><?= csrf_field() ?>
        <?php if ($votingOpen): ?>
          <input type="hidden" name="do" value="close_voting"><button class="btn btn-primary" data-confirm="Chiudere le votazioni? Voti e MVP entreranno nelle statistiche. A chi non ha votato verrà dato <?= default_vote_label() ?> d'ufficio a tutti gli altri."><i class="ti ti-lock"></i> Chiudi votazioni</button>
        <?php else: ?>
          <input type="hidden" name="do" value="open_voting"><button class="btn btn-ghost"><i class="ti ti-lock-open"></i> Riapri votazioni</button>
        <?php endif; ?>
      </form>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (is_admin() && (float) $match['fee'] > 0 && $participants): ?>
<section class="card" id="pagamenti">
  <?php $paidN = count(array_filter($participants, fn($r) => $r['paid'])); ?>
  <div class="card-head"><h2>Pagamenti</h2>
    <span class="muted small">Incassati <?= fmt_money($paidN * $match['fee']) ?> su <?= fmt_money(count($participants) * $match['fee']) ?></span></div>
  <div class="pay-grid">
    <?php foreach ($participants as $r): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle_paid"><input type="hidden" name="player_id" value="<?= (int) $r['player_id'] ?>">
        <button class="pay <?= $r['paid'] ? 'is-paid' : '' ?>"><?= $r['paid'] ? '<i class="ti ti-check"></i>' : '<i class="ti ti-circle"></i>' ?> <?= h($r['name']) ?></button></form>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if (can_manage_matches()): ?>
<details class="card collapsible">
  <summary><strong><i class="ti ti-settings"></i> Gestione partita</strong></summary>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?><input type="hidden" name="do" value="edit_info">
    <label class="field"><span>Data</span><input type="date" name="date" value="<?= date('Y-m-d', strtotime($match['match_date'])) ?>" required></label>
    <label class="field"><span>Ora</span><input type="time" name="time" value="<?= fmt_time($match['match_date']) ?>" required></label>
    <label class="field span-2"><span>Campo</span><input name="location" value="<?= h($match['location']) ?>"></label>
    <label class="field"><span><span class="team-dot team-a"></span>Nome squadra 1</span><input name="team_a" maxlength="40" value="<?= h(team_name('A', $match)) ?>"></label>
    <label class="field"><span><span class="team-dot team-b"></span>Nome squadra 2</span><input name="team_b" maxlength="40" value="<?= h(team_name('B', $match)) ?>"></label>
    <label class="field"><span>Quota a testa (€)</span><input name="fee" inputmode="decimal" value="<?= h(number_format((float) $match['fee'], 2, ',', '')) ?>"></label>
    <label class="field span-2"><span>Note</span><input name="notes" value="<?= h($match['notes']) ?>"></label>
    <?php if (!$played): ?><p class="muted small span-2">Se cambi il giorno, le risposte «Ci sono / Non ci sono» si azzerano. I giocatori ricevono una notifica per ogni modifica.</p><?php endif; ?>
    <div class="span-2"><button class="btn btn-ghost">Salva modifiche</button></div>
  </form>
  <div class="btn-row danger-zone">
    <?php if ($played): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="reopen">
        <button class="btn btn-ghost" data-confirm="Riportare la partita a 'programmata'? Voti e MVP restano salvati ma non contano finché non la richiudi.">↩️ Riporta a programmata</button></form>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="delete">
      <button class="btn btn-danger" data-confirm="Eliminare definitivamente la partita con presenze, gol e voti?"><i class="ti ti-trash"></i> Elimina partita</button></form>
    <?php endif; ?>
  </div>
</details>
<?php endif; ?>
<?php
layout_end();
