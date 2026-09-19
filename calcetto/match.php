<?php
require __DIR__ . '/lib/bootstrap.php';
require_view();

$id = int_get('id');
$match = get_match($id);
if (!$match) {
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

    require_admin();
    switch ($do) {
        case 'edit_info':
            $dt = DateTime::createFromFormat('Y-m-d H:i', ($_POST['date'] ?? '') . ' ' . ($_POST['time'] ?? ''));
            if ($dt) {
                q('UPDATE matches SET match_date = ?, location = ?, team_a_name = ?, team_b_name = ?, fee = ?, notes = ? WHERE id = ?', [
                    $dt->format('Y-m-d H:i:s'), trim($_POST['location'] ?? ''),
                    clean_team_name($_POST['team_a'] ?? '', TEAM_A_NAME), clean_team_name($_POST['team_b'] ?? '', TEAM_B_NAME),
                    max(0, (float) str_replace(',', '.', $_POST['fee'] ?? '0')),
                    trim($_POST['notes'] ?? '') ?: null, $id]);
                flash('ok', 'Dati della partita aggiornati.');
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
            $stats = compute_stats();
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
            $res = balance_teams($pool, 6, $match);
            db()->beginTransaction();
            q('UPDATE match_players SET team = NULL WHERE match_id = ?', [$id]);
            foreach (['A', 'B'] as $t) {
                foreach ($res[$t] as $p) {
                    q('UPDATE match_players SET team = ? WHERE match_id = ? AND player_id = ?', [$t, $id, $p]);
                }
            }
            db()->commit();
            assign_formation($id);
            flash('ok', sprintf('Squadre generate: forza %s vs %s (differenza %s).',
                fmt_num($res['sumA'], 1), fmt_num($res['sumB'], 1), fmt_num(abs($res['sumA'] - $res['sumB']), 2)));
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
                q("UPDATE matches SET status = 'giocata', voting_open = 1 WHERE id = ?", [$id]);
                flash('ok', 'Partita conclusa: votazioni aperte per chi ha giocato.');
            } else {
                flash('ok', 'Risultato salvato.');
            }
            db()->commit();
            redirect($self . '#risultato');

        case 'close_voting':
            q('UPDATE matches SET voting_open = 0 WHERE id = ?', [$id]);
            flash('ok', 'Votazioni chiuse: voti e MVP ora contano nelle statistiche.');
            break;

        case 'open_voting':
            q('UPDATE matches SET voting_open = 1 WHERE id = ?', [$id]);
            flash('ok', 'Votazioni riaperte.');
            break;

        case 'reopen':
            q("UPDATE matches SET status = 'programmata', voting_open = 0 WHERE id = ?", [$id]);
            flash('ok', 'Partita riportata a "programmata".');
            break;

        case 'toggle_paid':
            q('UPDATE match_players SET paid = 1 - paid WHERE match_id = ? AND player_id = ?', [$id, $pid]);
            redirect($self . '#pagamenti');

        case 'delete':
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
}

/* ---------------------------------------------------------------- dati */
if ($match['status'] === 'programmata') {
    sync_match_players($id);
}
$roster = match_roster($id);
$stats = compute_stats();
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

layout_start('Partita del ' . fmt_date_short($match['match_date']), 'matches');
?>
<a class="back" href="matches.php"><i class="ti ti-arrow-left"></i> Partite</a>

<section class="card match-head">
  <div class="match-when">
    <span class="eyebrow"><?= $played ? 'Partita giocata' : '<i class="ti ti-calendar-event"></i> In programma' ?></span>
    <h1><?= h(ucfirst(fmt_date_long($match['match_date']))) ?></h1>
    <div class="hero-meta">
      <span><i class="ti ti-clock"></i> <?= fmt_time($match['match_date']) ?></span>
      <span><i class="ti ti-map-pin"></i> <?= h($match['location'] ?: 'Campo da definire') ?></span>
      <?php if ((float) $match['fee'] > 0): ?><span><i class="ti ti-currency-euro"></i> <?= fmt_money($match['fee']) ?> a testa</span><?php endif; ?>
    </div>
    <?php if ($match['notes']): ?><p class="muted"><?= nl2br(h($match['notes'])) ?></p><?php endif; ?>
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
            <?php if (is_admin()): ?>
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
    <?php if (is_admin() && !$played): ?>
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
    <p class="empty"><?= is_admin() ? 'Quando ci sono abbastanza confermati, premi "Genera squadre bilanciate": l\'algoritmo divide i giocatori in due squadre con forza complessiva simile.' : 'Le squadre non sono ancora state fatte.' ?></p>
  <?php else: ?>
    <div class="squad-grid">
    <div class="squad-pitch">
      <?= render_pitch($match, $roster, is_admin() && !$played) ?>
      <?php if (is_admin() && !$played): ?>
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
              <?php if (is_admin() && !$played): ?>
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
    if (is_admin() && !$played && $benched): ?>
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

<?php if (is_admin() && $hasTeams): ?>
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

<?php if ($played): ?>
<section class="card" id="voti">
  <div class="card-head">
    <h2>Voti <?= $votingOpen ? '<span class="tag tag-live">aperti</span>' : '<span class="tag">chiusi</span>' ?></h2>
    <span class="muted small">Hanno votato <?= count($voters) ?>/<?= count($participants) ?></span>
  </div>

  <?php if ($votingOpen && $iPlayed): ?>
    <form method="post" class="vote-form">
      <?= csrf_field() ?><input type="hidden" name="do" value="vote">
      <p class="muted">Dai un voto da 1 a 10 a ogni compagno e avversario, poi scegli l'MVP. <?= $myMvp ? '<strong>Hai già votato:</strong> puoi modificare.' : '' ?></p>
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

  <?php if ($votingOpen): ?>
    <?php $missing = array_filter($participants, fn($r) => !in_array((int) $r['player_id'], $voters, true)); ?>
    <?php if ($missing): ?><p class="small muted">Mancano: <?= h(implode(', ', array_column($missing, 'name'))) ?></p><?php endif; ?>
  <?php endif; ?>

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

  <?php if (is_admin()): ?>
    <div class="btn-row">
      <form method="post" class="inline"><?= csrf_field() ?>
        <?php if ($votingOpen): ?>
          <input type="hidden" name="do" value="close_voting"><button class="btn btn-primary" data-confirm="Chiudere le votazioni? Voti e MVP entreranno nelle statistiche."><i class="ti ti-lock"></i> Chiudi votazioni</button>
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

<?php if (is_admin()): ?>
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
    <div class="span-2"><button class="btn btn-ghost">Salva modifiche</button></div>
  </form>
  <div class="btn-row danger-zone">
    <?php if ($played): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="reopen">
        <button class="btn btn-ghost" data-confirm="Riportare la partita a 'programmata'? Voti e MVP restano salvati ma non contano finché non la richiudi.">↩️ Riporta a programmata</button></form>
    <?php endif; ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="delete">
      <button class="btn btn-danger" data-confirm="Eliminare definitivamente la partita con presenze, gol e voti?"><i class="ti ti-trash"></i> Elimina partita</button></form>
  </div>
</details>
<?php endif; ?>
<?php
layout_end();
