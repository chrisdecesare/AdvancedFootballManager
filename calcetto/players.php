<?php
require __DIR__ . '/lib/bootstrap.php';
require_view();

$stats = compute_stats();
$staff = is_admin() || (bool) admin_groups();   // admin del sito o di una lega: vede anche i non attivi e aggiunge giocatori
$showInactive = $staff && !empty($_GET['tutti']);
$players = array_filter(all_players(), fn($p) => $showInactive || $p['active']);
$sort = $_GET['ordina'] ?? 'nome';
uasort($players, function ($a, $b) use ($sort, $stats) {
    $sa = $stats[(int) $a['id']];
    $sb = $stats[(int) $b['id']];
    switch ($sort) {
        case 'numero': return ((int) ($a['shirt_number'] ?? 999)) <=> ((int) ($b['shirt_number'] ?? 999));
        case 'ruolo': return array_search($a['position'], positions()) <=> array_search($b['position'], positions()) ?: strcmp($a['name'], $b['name']);
        case 'rating': return $sb['ovr'] <=> $sa['ovr'];
        case 'gol': return $sb['goals'] <=> $sa['goals'];
        default: return strcasecmp($a['name'], $b['name']);
    }
});

layout_start('Rosa', 'players');
?>
<div class="page-head">
  <h1>Rosa <span class="muted small"><?= count($players) ?> giocatori</span></h1>
  <div class="btn-row">
    <?php if ($staff): ?>
      <a class="btn btn-ghost btn-sm" href="?tutti=<?= $showInactive ? 0 : 1 ?>"><?= $showInactive ? 'Nascondi non attivi' : 'Mostra anche non attivi' ?></a>
      <a class="btn btn-primary btn-sm" href="player_edit.php">+ Nuovo giocatore</a>
    <?php endif; ?>
  </div>
</div>
<?= group_bar('players.php') ?>
<div class="sortbar">
  Ordina:
  <?php foreach (['nome' => 'Nome', 'numero' => 'Numero', 'ruolo' => 'Ruolo', 'rating' => 'Rating', 'gol' => 'Gol'] as $k => $l): ?>
    <a href="?ordina=<?= $k ?><?= $showInactive ? '&tutti=1' : '' ?>" class="<?= $sort === $k ? 'active' : '' ?>"><?= $l ?></a>
  <?php endforeach; ?>
</div>

<div class="player-grid">
<?php foreach ($players as $p): $s = $stats[(int) $p['id']]; ?>
  <a class="pcard role-<?= strtolower(position_abbr($p['position'])) ?><?= bg_preset_class($p) ?><?= border_class($p) ?> <?= $p['active'] ? '' : 'is-inactive' ?>" href="player.php?id=<?= (int) $p['id'] ?>"<?= ($bgStyle = profile_bg_style($p, true)) !== '' ? ' style="' . $bgStyle . '"' : '' ?>>
    <?= hat_html($p) ?>
    <div class="pcard-top">
      <span class="pcard-ovr"><?= overall($s['ovr']) ?><small>OVR</small></span>
      <?php if ($p['shirt_number'] !== null): ?><span class="pcard-num"><?= (int) $p['shirt_number'] ?></span><?php endif; ?>
    </div>
    <div class="pcard-photo"><?= avatar($p, 'xl') ?></div>
    <div class="pcard-name"><?= h($p['name']) ?></div>
    <?php if ($nick = nick_html($p)): ?><div class="pcard-nick"><?= $nick ?></div><?php endif; ?>
    <div class="pcard-sub"><span class="pos pos-<?= strtolower(position_abbr($p['position'])) ?>"><?= h($p['position']) ?></span><?php if ($p['position2']): ?> <span class="pos pos-<?= strtolower(position_abbr($p['position2'])) ?>"><?= position_abbr($p['position2']) ?></span><?php endif; ?> · <?= h($p['foot']) ?></div>
    <div class="pcard-stats">
      <div><strong><?= $s['apps'] ?></strong><span>PG</span></div>
      <div><strong><?= $s['goals'] ?></strong><span>Gol</span></div>
      <div><strong><?= $s['assists'] ?></strong><span>Assist</span></div>
      <div><strong class="<?= vote_class($s['avg_vote']) ?>-t"><?= fmt_num($s['avg_vote']) ?></strong><span>Media</span></div>
    </div>
    <?php if (!$p['active']): ?><span class="tag">non attivo</span><?php endif; ?>
  </a>
<?php endforeach; ?>
</div>
<?php if (!$players): ?><p class="empty card">Nessun giocatore. <?= $staff ? 'Aggiungine uno con "+ Nuovo giocatore".' : '' ?></p><?php endif; ?>
<?php
layout_end();
