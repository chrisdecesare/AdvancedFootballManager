<?php
require __DIR__ . '/lib/bootstrap.php';
require_admin();

if (is_post()) {
    $pid = (int) ($_POST['player_id'] ?? 0);
    if (($_POST['do'] ?? '') === 'pay_all') {
        q("UPDATE match_players mp JOIN matches m ON m.id = mp.match_id
           SET mp.paid = 1 WHERE mp.player_id = ? AND mp.team IS NOT NULL AND m.fee > 0", [$pid]);
        flash('ok', 'Tutte le quote del giocatore segnate come pagate.');
    } elseif (($_POST['do'] ?? '') === 'toggle') {
        q('UPDATE match_players SET paid = 1 - paid WHERE match_id = ? AND player_id = ?', [(int) $_POST['match_id'], $pid]);
    }
    redirect('payments.php' . ($pid ? '#p' . $pid : ''));
}

// quote dovute: partite con quota > 0 in cui il giocatore era in squadra
$rows = q("SELECT p.id, p.name, p.photo, m.id AS match_id, m.match_date, m.fee, mp.paid
           FROM match_players mp
           JOIN matches m ON m.id = mp.match_id
           JOIN players p ON p.id = mp.player_id
           WHERE mp.team IS NOT NULL AND m.fee > 0 AND " . scope_sql('m.group_id') . "
           ORDER BY p.name, m.match_date DESC")->fetchAll();
$byPlayer = [];
$totDue = $totPaid = 0.0;
foreach ($rows as $r) {
    $pid = (int) $r['id'];
    $byPlayer[$pid] ??= ['player' => $r, 'due' => 0.0, 'paid' => 0.0, 'unpaid' => []];
    $byPlayer[$pid]['due'] += (float) $r['fee'];
    $totDue += (float) $r['fee'];
    if ($r['paid']) {
        $byPlayer[$pid]['paid'] += (float) $r['fee'];
        $totPaid += (float) $r['fee'];
    } else {
        $byPlayer[$pid]['unpaid'][] = $r;
    }
}
uasort($byPlayer, fn($a, $b) => ($b['due'] - $b['paid']) <=> ($a['due'] - $a['paid']));

layout_start('Pagamenti', 'payments');
?>
<div class="page-head"><h1>Pagamenti</h1></div>
<?= group_bar('payments.php') ?>
<section class="stat-grid stat-grid-3">
  <div class="stat"><strong><?= fmt_money($totDue) ?></strong><span>Totale quote</span></div>
  <div class="stat green"><strong><?= fmt_money($totPaid) ?></strong><span>Incassato</span></div>
  <div class="stat red"><strong><?= fmt_money($totDue - $totPaid) ?></strong><span>Da incassare</span></div>
</section>

<?php if (!$byPlayer): ?>
  <p class="empty card">Nessuna quota: le quote nascono quando un giocatore è in squadra in una partita con quota maggiore di zero.</p>
<?php endif; ?>

<div class="list">
<?php foreach ($byPlayer as $pid => $b): $owed = $b['due'] - $b['paid']; ?>
  <section class="card pay-card" id="p<?= $pid ?>">
    <div class="card-head">
      <a class="tname" href="player.php?id=<?= $pid ?>"><?= avatar($b['player'], 'sm') ?> <strong><?= h($b['player']['name']) ?></strong></a>
      <div class="pay-sum">
        <?php if ($owed > 0): ?>
          <span class="owed">deve <?= fmt_money($owed) ?></span>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="pay_all"><input type="hidden" name="player_id" value="<?= $pid ?>">
            <button class="btn btn-primary btn-sm" data-confirm="Segnare tutte le quote di <?= h($b['player']['name']) ?> come pagate?">Salda tutto</button></form>
        <?php else: ?>
          <span class="tag tag-ok"><i class="ti ti-check"></i> in regola</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($b['unpaid']): ?>
      <div class="pay-grid">
        <?php foreach ($b['unpaid'] as $u): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle">
            <input type="hidden" name="player_id" value="<?= $pid ?>"><input type="hidden" name="match_id" value="<?= (int) $u['match_id'] ?>">
            <button class="pay" title="Segna come pagata"><i class="ti ti-circle"></i> <?= fmt_date_short($u['match_date']) ?> · <?= fmt_money($u['fee']) ?></button></form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
</div>
<?php
layout_end();
