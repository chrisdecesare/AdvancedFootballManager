<?php
require __DIR__ . '/lib/bootstrap.php';
require_view();

if (is_post() && ($_POST['do'] ?? '') === 'create') {
    require_admin();
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    $dt = DateTime::createFromFormat('Y-m-d H:i', "$date $time");
    if (!$dt) {
        flash('err', 'Data o ora non valide.');
    } else {
        q('INSERT INTO matches (match_date, location, team_a_name, team_b_name, fee, notes) VALUES (?, ?, ?, ?, ?, ?)', [
            $dt->format('Y-m-d H:i:s'),
            trim($_POST['location'] ?? ''),
            clean_team_name($_POST['team_a'] ?? '', TEAM_A_NAME),
            clean_team_name($_POST['team_b'] ?? '', TEAM_B_NAME),
            max(0, (float) str_replace(',', '.', $_POST['fee'] ?? '0')),
            trim($_POST['notes'] ?? '') ?: null,
        ]);
        $id = (int) db()->lastInsertId();
        sync_match_players($id);
        flash('ok', 'Partita creata: ora i giocatori possono confermare.');
        redirect('match.php?id=' . $id);
    }
    redirect('matches.php');
}

$upcoming = q("SELECT m.*,
                 SUM(mp.availability = 'confermato') AS yes_n,
                 SUM(mp.availability = 'assente') AS no_n
               FROM matches m LEFT JOIN match_players mp ON mp.match_id = m.id
               WHERE m.status = 'programmata' GROUP BY m.id ORDER BY m.match_date ASC")->fetchAll();
$played = played_matches();
$me = my_player_id();

layout_start('Partite', 'matches');
?>
<div class="page-head"><h1>Partite</h1></div>

<?php if (is_admin()): ?>
<details class="card collapsible" id="nuova" <?= $upcoming ? '' : 'open' ?>>
  <summary><strong>+ Nuova partita</strong></summary>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?><input type="hidden" name="do" value="create">
    <label class="field"><span>Data</span><input type="date" name="date" required value="<?= date('Y-m-d', strtotime('next thursday')) ?>"></label>
    <label class="field"><span>Ora</span><input type="time" name="time" required value="21:00"></label>
    <label class="field span-2"><span>Campo</span><input name="location" value="<?= h(DEFAULT_LOCATION) ?>" placeholder="Es. Centro sportivo, campo 2"></label>
    <label class="field"><span><span class="team-dot team-a"></span>Nome squadra 1</span><input name="team_a" maxlength="40" value="<?= h(TEAM_A_NAME) ?>" placeholder="Es. Scapoli"></label>
    <label class="field"><span><span class="team-dot team-b"></span>Nome squadra 2</span><input name="team_b" maxlength="40" value="<?= h(TEAM_B_NAME) ?>" placeholder="Es. Ammogliati"></label>
    <label class="field"><span>Quota a testa (€)</span><input name="fee" inputmode="decimal" value="<?= h(number_format(DEFAULT_FEE, 2, ',', '')) ?>"></label>
    <label class="field span-2"><span>Note</span><input name="notes" placeholder="Facoltative"></label>
    <div class="span-2"><button class="btn btn-primary">Crea partita</button></div>
  </form>
</details>
<?php endif; ?>

<h2 class="section-title">In programma</h2>
<?php if (!$upcoming): ?><p class="empty card">Nessuna partita in programma.</p><?php endif; ?>
<div class="list">
<?php foreach ($upcoming as $m): ?>
  <a class="card match-row" href="match.php?id=<?= (int) $m['id'] ?>">
    <div class="mdate"><span class="d"><?= date('j', strtotime($m['match_date'])) ?></span><span class="m"><?= mb_substr(MESI[(int) date('n', strtotime($m['match_date']))], 0, 3) ?></span></div>
    <div class="minfo"><strong><?= h(ucfirst(fmt_date_long($m['match_date']))) ?> · <?= fmt_time($m['match_date']) ?></strong>
      <span class="muted"><?= h($m['location'] ?: 'Campo da definire') ?></span></div>
    <div class="mside"><span class="count count-yes"><?= (int) $m['yes_n'] ?></span> <span class="muted small">confermati</span></div>
  </a>
<?php endforeach; ?>
</div>

<h2 class="section-title">Giocate</h2>
<?php if (!$played): ?><p class="empty card">Ancora nessuna partita giocata.</p><?php endif; ?>
<div class="list">
<?php foreach ($played as $m):
    $mvp = !$m['voting_open'] ? match_mvp((int) $m['id']) : null;
    $mvpP = $mvp ? get_player($mvp) : null; ?>
  <a class="card match-row" href="match.php?id=<?= (int) $m['id'] ?>">
    <div class="mdate"><span class="d"><?= date('j', strtotime($m['match_date'])) ?></span><span class="m"><?= mb_substr(MESI[(int) date('n', strtotime($m['match_date']))], 0, 3) ?></span></div>
    <div class="minfo"><strong class="mini-score"><span class="team-a"><?= h(team_name('A', $m)) ?></span> <?= (int) $m['score_a'] ?> – <?= (int) $m['score_b'] ?> <span class="team-b"><?= h(team_name('B', $m)) ?></span></strong>
      <span class="muted"><?= fmt_date_short($m['match_date']) ?> · <?= h($m['location']) ?></span></div>
    <div class="mside">
      <?php if ($m['voting_open']): ?><span class="tag tag-live"><i class="ti ti-writing"></i> voti aperti</span>
      <?php elseif ($mvpP): ?><span class="tag tag-mvp"><i class="ti ti-star-filled"></i> <?= h($mvpP['name']) ?></span><?php endif; ?>
    </div>
  </a>
<?php endforeach; ?>
</div>
<?php
layout_end();
