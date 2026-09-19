<?php
require __DIR__ . '/lib/bootstrap.php';
require_view();

$sort = $_GET['ordina'] ?? 'points';
$rows = standings($sort);
$cols = [
    'points' => ['Pt', 'Punti (' . POINTS_WIN . ' vittoria, ' . POINTS_DRAW . ' pareggio)'],
    'apps' => ['PG', 'Partite giocate'],
    'win_pct' => ['V%', 'Percentuale vittorie'],
    'goals' => ['Gol', 'Gol'],
    'gpg' => ['G/P', 'Gol a partita'],
    'assists' => ['Ass', 'Assist'],
    'mvp' => ['MVP', 'Premi MVP'],
    'avg_vote' => ['Media', 'Media voto'],
    'own_goals' => ['AG', 'Autogol'],
    'ovr' => ['OVR', 'Rating per il bilanciamento'],
];
if (!isset($cols[$sort])) {
    $sort = 'points';
}

layout_start('Classifica', 'standings');
?>
<div class="page-head"><h1>Classifica</h1></div>
<div class="sortbar">
  Classifica per:
  <?php foreach (['points' => 'Punti', 'goals' => 'Marcatori', 'assists' => 'Assist', 'mvp' => 'MVP', 'avg_vote' => 'Media voto', 'win_pct' => '% Vittorie'] as $k => $l): ?>
    <a href="?ordina=<?= $k ?>" class="<?= $sort === $k ? 'active' : '' ?>"><?= $l ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
  <p class="empty card">La classifica si riempie dopo la prima partita giocata.</p>
<?php else: ?>
<div class="card table-card">
  <div class="table-wrap"><table class="table table-standings">
    <thead><tr>
      <th>#</th><th>Giocatore</th>
      <?php foreach ($cols as $k => [$short, $title]): ?>
        <th class="<?= $sort === $k ? 'sorted' : '' ?>"><a href="?ordina=<?= $k ?>" title="<?= h($title) ?>"><?= $short ?></a></th>
        <?php if ($k === 'apps'): ?><th>V</th><th>N</th><th>S</th><?php endif; ?>
      <?php endforeach; ?>
      <th>Forma</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr>
        <td class="rank rank-<?= $i + 1 ?>"><span><?= $i + 1 ?></span></td>
        <td><a class="tname" href="player.php?id=<?= $r['id'] ?>"><?= avatar($r['player'], 'xs') ?> <?= h($r['player']['name']) ?></a></td>
        <td class="<?= $sort === 'points' ? 'sorted' : '' ?>"><strong><?= $r['points'] ?></strong></td>
        <td class="<?= $sort === 'apps' ? 'sorted' : '' ?>"><?= $r['apps'] ?></td>
        <td><?= $r['wins'] ?></td><td><?= $r['draws'] ?></td><td><?= $r['losses'] ?></td>
        <td class="<?= $sort === 'win_pct' ? 'sorted' : '' ?>"><?= fmt_num($r['win_pct'], 0) ?>%</td>
        <td class="<?= $sort === 'goals' ? 'sorted' : '' ?>"><?= $r['goals'] ?></td>
        <td class="<?= $sort === 'gpg' ? 'sorted' : '' ?>"><?= fmt_num($r['gpg'], 2) ?></td>
        <td class="<?= $sort === 'assists' ? 'sorted' : '' ?>"><?= $r['assists'] ?></td>
        <td class="<?= $sort === 'mvp' ? 'sorted' : '' ?>"><?= $r['mvp'] ?></td>
        <td class="<?= $sort === 'avg_vote' ? 'sorted' : '' ?>"><span class="vote <?= vote_class($r['avg_vote']) ?>"><?= fmt_num($r['avg_vote']) ?></span></td>
        <td class="<?= $sort === 'own_goals' ? 'sorted' : '' ?>"><?= $r['own_goals'] ?></td>
        <td class="<?= $sort === 'ovr' ? 'sorted' : '' ?>"><?= fmt_num($r['ovr'], 1) ?></td>
        <td class="nowrap"><?php foreach ($r['last5'] as $res) echo result_chip($res); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<p class="muted small">Clicca sull'intestazione di una colonna per ordinare. Forma = ultime 5 partite (V vittoria, N pareggio, S sconfitta), la più recente a sinistra.</p>
<?php endif; ?>
<?php
layout_end();
