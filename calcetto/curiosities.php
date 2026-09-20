<?php
require __DIR__ . '/lib/bootstrap.php';
require_view();

$items = all_curiosities();
$me = my_player_id();

layout_start('Curiosità', 'curiosities');
?>
<div class="page-head">
  <h1>Curiosità <span class="muted small"><?= count($items) ?></span></h1>
  <div class="btn-row">
    <?php if ($me): ?><a class="btn btn-primary btn-sm" href="player.php?id=<?= $me ?>#curiosita"><i class="ti ti-plus"></i> Scrivi la tua</a><?php endif; ?>
  </div>
</div>
<?= group_bar('curiosities.php') ?>
<p class="muted small">Le curiosità sui giocatori: le scrivono loro (o l'admin) dalla propria scheda, in Rosa.</p>

<?php if (!$items): ?>
  <p class="empty card">Ancora nessuna curiosità. <?= $me ? 'Scrivi la prima dalla tua scheda!' : '' ?></p>
<?php else: ?>
<div class="fact-grid">
  <?php foreach ($items as $c): ?>
    <article class="card fact-card">
      <a class="fact-head" href="player.php?id=<?= (int) $c['player_id'] ?>">
        <?= avatar($c, 'md') ?>
        <span class="fact-who"><?= h($c['name']) ?></span>
      </a>
      <p><?= h($c['body']) ?></p>
    </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php
layout_end();
