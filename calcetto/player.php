<?php
require __DIR__ . '/lib/bootstrap.php';
require_view();

$id = int_get('id');
$p = get_player($id);
if (!$p || !player_access($id)) {   // inesistente o di un gruppo che non è il tuo
    http_response_code(404);
    layout_start('Giocatore non trovato', 'players');
    echo '<div class="card"><h2>Giocatore non trovato</h2><a href="players.php"><i class="ti ti-arrow-left"></i> Rosa</a></div>';
    layout_end();
    exit;
}
$s = compute_stats()[$id];
$canEdit = is_admin() || my_player_id() === $id;

// curiosità: le scrive il giocatore stesso o l'admin
if (is_post()) {
    require_login();
    $do = $_POST['do'] ?? '';
    if (!in_array($do, ['add_curiosity', 'del_curiosity'], true)) {
        redirect('player.php?id=' . $id);
    }
    if (!can_edit_curiosities($id)) {
        flash('err', 'Le curiosità di ' . $p['name'] . ' le può scrivere solo lui (o un admin).');
    } elseif ($do === 'add_curiosity') {
        $body = clean_curiosity($_POST['body'] ?? '');
        if ($body === null || mb_strlen($body) > CURIOSITY_MAX) {
            flash('err', 'Scrivi una curiosità di massimo ' . CURIOSITY_MAX . ' caratteri.');
        } elseif ((int) q('SELECT COUNT(*) FROM curiosities WHERE player_id = ?', [$id])->fetchColumn() >= 30) {
            flash('err', 'Ci sono già troppe curiosità: cancellane qualcuna.');
        } elseif (q('SELECT 1 FROM curiosities WHERE player_id = ? AND body = ?', [$id, $body])->fetch()) {
            flash('err', 'Hai già scritto questa curiosità.');
        } else {
            q('INSERT INTO curiosities (player_id, body, created_by) VALUES (?, ?, ?)', [$id, $body, current_user()['id']]);
            flash('ok', 'Curiosità aggiunta.');
        }
    } else {
        q('DELETE FROM curiosities WHERE id = ? AND player_id = ?', [(int) ($_POST['id'] ?? 0), $id]);
        flash('ok', 'Curiosità eliminata.');
    }
    redirect('player.php?id=' . $id . '#curiosita');
}
$curiosities = player_curiosities($id);

// posizione in classifica marcatori e punti
$rankOf = function (string $sort) use ($id): ?int {
    foreach (standings($sort) as $i => $row) {
        if ($row['id'] === $id) {
            return $i + 1;
        }
    }
    return null;
};
$rankPts = $rankOf('points');
$rankGoals = $s['goals'] > 0 ? $rankOf('goals') : null;

/** Andamento voti (dalla più vecchia alla più recente) come linea SVG. */
function vote_sparkline(array $history): string
{
    $pts = array_values(array_filter(array_reverse($history), fn($x) => $x['vote'] !== null));
    $pts = array_slice($pts, -15);
    if (count($pts) < 2) {
        return '<p class="muted small">Il grafico compare dopo almeno 2 partite votate.</p>';
    }
    $w = 600;
    $hgt = 140;
    $pad = 18;
    $n = count($pts);
    $x = fn($i) => $pad + $i * ($w - 2 * $pad) / ($n - 1);
    $y = fn($v) => $hgt - $pad - ($v - 1) / 9 * ($hgt - 2 * $pad);
    $line = '';
    foreach ($pts as $i => $pt) {
        $line .= ($i ? ' L' : 'M') . round($x($i), 1) . ' ' . round($y($pt['vote']), 1);
    }
    $svg = '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $hgt . '" role="img" aria-label="Andamento dei voti">';
    foreach ([6, 8] as $g) {
        $svg .= '<line x1="' . $pad . '" x2="' . ($w - $pad) . '" y1="' . $y($g) . '" y2="' . $y($g) . '" class="spark-grid"/>' .
            '<text x="2" y="' . ($y($g) + 4) . '" class="spark-lbl">' . $g . '</text>';
    }
    $svg .= '<path d="' . $line . '" class="spark-line"/>';
    foreach ($pts as $i => $pt) {
        $svg .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y($pt['vote']), 1) . '" r="5" class="spark-dot ' .
            vote_class($pt['vote']) . '"><title>' . fmt_date_short($pt['date']) . ': ' . fmt_num($pt['vote']) . '</title></circle>';
    }
    return $svg . '</svg>';
}

// intesa con i compagni (partite giocate insieme nella stessa squadra)
$chemAll = chemistry();
$partners = array_values(array_filter(chemistry_partners($chemAll, $id, 200), fn($x) => $x['n'] >= CHEM_MIN_TOGETHER));
$best = array_slice(array_filter($partners, fn($x) => $x['score'] > 0), 0, 5);
$worst = array_slice(array_reverse(array_filter($partners, fn($x) => $x['score'] < 0)), 0, 2);
$partnerOf = function (int $pid): ?array {
    return all_players()[$pid] ?? get_player($pid);
};
$myGroups = array_map('group_name', player_group_ids($id));

layout_start($p['name'], 'players');
?>
<a class="back" href="players.php"><i class="ti ti-arrow-left"></i> Rosa</a>

<section class="card profile role-<?= strtolower(position_abbr($p['position'])) ?><?= !empty($p['bg_image']) ? ' has-bg-image' : '' ?>"<?= ($bgStyle = profile_bg_style($p)) !== '' ? ' style="' . $bgStyle . '"' : '' ?>>
  <div class="profile-photo"><?= avatar($p, 'xxl') ?>
    <?php if ($p['shirt_number'] !== null): ?><span class="profile-num"><?= (int) $p['shirt_number'] ?></span><?php endif; ?></div>
  <div class="profile-info">
    <h1><?= h($p['name']) ?> <?= $p['active'] ? '' : '<span class="tag">non attivo</span>' ?></h1>
    <div class="profile-tags">
      <span class="pos pos-<?= strtolower(position_abbr($p['position'])) ?>" title="Posizione preferita"><?= h($p['position']) ?></span>
      <?php if ($p['position2']): ?><span class="pos pos-<?= strtolower(position_abbr($p['position2'])) ?>" title="Seconda scelta"><?= h($p['position2']) ?></span><?php endif; ?>
      <span class="tag"><i class="ti ti-shoe"></i> <?= h($p['foot']) ?></span>
      <?php if ($rankPts): ?><span class="tag"><i class="ti ti-trophy"></i> <?= $rankPts ?>° in classifica</span><?php endif; ?>
      <?php if ($rankGoals): ?><span class="tag"><i class="ti ti-ball-football"></i> <?= $rankGoals ?>° marcatore</span><?php endif; ?>
      <?= form_badge($s['form']) ?>
      <?php foreach ($myGroups as $gn): ?><span class="tag tag-group"><i class="ti ti-users-group"></i> <?= h($gn) ?></span><?php endforeach; ?>
    </div>
    <div class="ovr-big"><span><?= fmt_num($s['ovr'], 1) ?></span><small>RATING</small></div>
    <?php if ($canEdit): ?><a class="btn btn-ghost btn-sm" href="player_edit.php?id=<?= $id ?>"><i class="ti ti-pencil"></i> Modifica profilo</a><?php endif; ?>
  </div>
</section>

<section class="stat-grid">
  <?php
  $tiles = [
      ['Presenze', $s['apps'], ''],
      ['Gol', $s['goals'], ''],
      ['Assist', $s['assists'], ''],
      ['MVP', $s['mvp'], 'gold'],
      ['Vittorie', $s['wins'], 'green'],
      ['Pareggi', $s['draws'], ''],
      ['Sconfitte', $s['losses'], 'red'],
      ['Autogol', $s['own_goals'], ''],
  ];
  foreach ($tiles as [$l, $v, $c]): ?>
    <div class="stat <?= $c ?>"><strong><?= $v ?></strong><span><?= $l ?></span></div>
  <?php endforeach; ?>
  <div class="stat"><strong class="vote-big <?= vote_class($s['avg_vote']) ?>-t"><?= fmt_num($s['avg_vote']) ?></strong><span>Media voto</span></div>
</section>

<?php if ($curiosities || $canEdit): ?>
<section class="card" id="curiosita">
  <div class="card-head"><h2><i class="ti ti-bulb"></i> Curiosità</h2><a class="link" href="curiosities.php">Tutte <i class="ti ti-arrow-right"></i></a></div>
  <?php if ($curiosities): ?>
    <ul class="fact-list">
    <?php foreach ($curiosities as $c): ?>
      <li>
        <span><?= h($c['body']) ?></span>
        <?php if ($canEdit): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="del_curiosity"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <button class="icon-btn" title="Elimina la curiosità" aria-label="Elimina la curiosità" data-confirm="Eliminare questa curiosità?"><i class="ti ti-trash"></i></button></form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="empty">Ancora nessuna curiosità<?= $canEdit ? ': scrivine una!' : '.' ?></p>
  <?php endif; ?>
  <?php if ($canEdit): ?>
    <form method="post" class="fact-form"><?= csrf_field() ?><input type="hidden" name="do" value="add_curiosity">
      <input name="body" required maxlength="<?= CURIOSITY_MAX ?>" autocomplete="off" aria-label="Nuova curiosità" placeholder="Es. Ha segnato un gol da metà campo, una sola volta in tutta la vita">
      <button class="btn btn-primary btn-sm"><i class="ti ti-plus"></i> Aggiungi</button>
    </form>
    <p class="muted small">Le curiosità compaiono nella pagina «Curiosità» e ogni tanto in Home. Le possono scrivere il giocatore stesso e gli admin.</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
  <h2>Performance</h2>
  <div class="perf">
    <div class="perf-item"><span>Gol a partita</span><strong><?= fmt_num($s['gpg'], 2) ?></strong></div>
    <div class="perf-item"><span>Assist a partita</span><strong><?= fmt_num($s['apg'], 2) ?></strong></div>
    <div class="perf-item"><span>Vittorie</span><strong><?= fmt_num($s['win_pct'], 0) ?>%</strong>
      <div class="bar"><i style="width:<?= min(100, round($s['win_pct'])) ?>%"></i></div></div>
    <div class="perf-item"><span>Partecipazione</span><strong><?= fmt_num($s['participation_pct'], 0) ?>%</strong>
      <div class="bar"><i style="width:<?= min(100, round($s['participation_pct'])) ?>%"></i></div></div>
    <div class="perf-item"><span>Media voto</span><strong class="<?= vote_class($s['avg_vote']) ?>-t"><?= fmt_num($s['avg_vote']) ?></strong>
      <small class="muted"><?= $s['vote_count'] ?> partite votate</small></div>
    <div class="perf-item"><span>Gol ultime 5</span><strong><?= $s['goals_last5'] ?></strong></div>
    <div class="perf-item perf-wide"><span>Forma recente</span>
      <div class="form-row"><?php foreach ($s['last5'] as $r) echo result_chip($r); ?>
        <?php if (!$s['last5']): ?><span class="muted">—</span><?php endif; ?>
        <?php if ($s['avg_vote_last5'] !== null): ?><span class="muted small">media ultime 5: <span class="vote <?= vote_class($s['avg_vote_last5']) ?>"><?= fmt_num($s['avg_vote_last5']) ?></span></span><?php endif; ?>
      </div></div>
  </div>
</section>

<section class="card">
  <h2>Andamento voti</h2>
  <?= vote_sparkline($s['history']) ?>
</section>

<section class="card" id="intesa">
  <h2><i class="ti ti-heart-handshake"></i> Intesa e note</h2>
  <?php if (!$partners): ?>
    <p class="empty">Servono almeno <?= CHEM_MIN_TOGETHER ?> partite giocate insieme a un compagno: poi qui compaiono le sue "intese" (risultati, assist e gol quando giocano insieme).</p>
  <?php else: ?>
    <p class="muted small">Il bonus di intesa (in punti rating) si aggiunge alla forza della squadra quando questi due giocano insieme: pesa di più con molte partite in comune.</p>
    <ul class="chem-list">
    <?php foreach (array_merge($best, $worst) as $c): $o = $partnerOf($c['partner']); if (!$o) continue; ?>
      <li class="chem-item">
        <div class="chem-head">
          <span><a href="player.php?id=<?= (int) $c['partner'] ?>"><strong><?= h($o['name']) ?></strong></a>
            <span class="muted small">· <?= (int) $c['n'] ?> partite insieme (<?= (int) $c['w'] ?>V <?= (int) $c['d'] ?>N <?= (int) $c['l'] ?>S)</span></span>
          <span class="chem-score <?= $c['score'] > 0 ? 'is-pos' : ($c['score'] < 0 ? 'is-neg' : '') ?>" title="Bonus di intesa"><?= fmt_signed($c['score'], 2) ?></span>
        </div>
        <ul class="chem-notes">
          <?php if ($c['base'] !== null): ?>
            <li>Insieme fanno <strong><?= fmt_num($c['ppg'] * 3, 1) ?></strong> punti a partita, contro <?= fmt_num($c['base'] * 3, 1) ?> di media dei due.</li>
          <?php endif; ?>
          <?php if ($c['my_gpg'] !== null && $c['my_gpg_without'] !== null): ?>
            <?php $d = $c['my_gpg'] - $c['my_gpg_without']; ?>
            <li>Gol di <?= h(explode(' ', $p['name'])[0]) ?>: <strong><?= fmt_num($c['my_gpg'], 2) ?></strong> a partita con <?= h(explode(' ', $o['name'])[0]) ?>, <?= fmt_num($c['my_gpg_without'], 2) ?> senza
              <?php if ($c['my_gpg_without'] > 0): ?>(<?= fmt_signed(round($d / $c['my_gpg_without'] * 100), 0) ?>% realizzativo)<?php elseif ($d > 0): ?>(<?= fmt_signed($d, 2) ?> a partita)<?php endif; ?>.</li>
          <?php endif; ?>
          <?php if ($c['assists_to'] || $c['assists_from']): ?>
            <li>Assist registrati: <?= (int) $c['assists_to'] ?> a <?= h(explode(' ', $o['name'])[0]) ?>, <?= (int) $c['assists_from'] ?> da lui<?= $c['assists_to'] && $c['assists_from'] ? ' — <strong>si cercano a vicenda</strong>' : '' ?>.</li>
          <?php endif; ?>
        </ul>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Partite</h2>
  <?php if (!$s['history']): ?>
    <p class="empty">Nessuna partita giocata sul sito.</p>
  <?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Data</th><th>Squadra</th><th>Risultato</th><th></th><th><i class="ti ti-ball-football"></i></th><th><b class="ast">A</b></th><th>AG</th><th>Voto</th></tr></thead>
      <tbody>
      <?php foreach ($s['history'] as $x): ?>
        <tr>
          <td><a href="match.php?id=<?= $x['match_id'] ?>"><?= fmt_date_short($x['date']) ?></a></td>
          <td><span class="team-dot team-<?= strtolower($x['team']) ?>"></span><?= h($x['team_label']) ?></td>
          <td class="nowrap"><?= $x['score_a'] ?> – <?= $x['score_b'] ?></td>
          <td><?= result_chip($x['result']) ?><?= $x['mvp'] ? ' <span class="tag tag-mvp"><i class="ti ti-star-filled"></i></span>' : '' ?></td>
          <td><?= $x['goals'] ?: '' ?></td>
          <td><?= $x['assists'] ?: '' ?></td>
          <td><?= $x['own_goals'] ?: '' ?></td>
          <td><?= $x['voting_open'] ? '<span class="muted small">in corso</span>' : '<span class="vote ' . vote_class($x['vote']) . '">' . fmt_num($x['vote']) . '</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
<?php
layout_end();
