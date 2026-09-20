<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

// chi ha un giocatore collegato modifica tutto dalla pagina del profilo giocatore
if ($pid = my_player_id()) {
    redirect('player_edit.php?id=' . $pid);
}

$u = current_user();

layout_start('Il mio account', 'profile');
?>
<div class="narrow">
  <div class="card">
    <h1>Il mio account</h1>
    <p class="muted">Username: <strong><?= h($u['username']) ?></strong> · ruolo <?= h(strtolower(role_label($u['role']))) ?></p>
    <p class="muted small">Questo account non è collegato a un giocatore<?= is_admin() ? ': puoi collegarlo dalla pagina Admin.' : '.' ?></p>
  </div>
  <?= security_card() ?>
  <?= push_card() ?>
</div>
<?php
layout_end();
