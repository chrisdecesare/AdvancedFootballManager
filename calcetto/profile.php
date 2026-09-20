<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

// chi ha un giocatore collegato modifica tutto dalla pagina del profilo giocatore
if ($pid = my_player_id()) {
    redirect('player_edit.php?id=' . $pid);
}

$u = current_user();
if (is_post()) {
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if ($err = password_error($password)) {
        flash('err', $err);
    } else {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        flash('ok', 'Password aggiornata.');
    }
    redirect('profile.php');
}

layout_start('Il mio account', 'profile');
?>
<div class="narrow">
  <div class="card">
    <h1>Il mio account</h1>
    <p class="muted">Username: <strong><?= h($u['username']) ?></strong> · ruolo <?= h(strtolower(role_label($u['role']))) ?></p>
    <p class="muted small">Questo account non è collegato a un giocatore<?= is_admin() ? ': puoi collegarlo dalla pagina Admin.' : '.' ?></p>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label class="field"><span>Nuova password</span><input type="password" name="password" minlength="8" maxlength="72" required autocomplete="new-password"></label>
      <button class="btn btn-primary">Cambia password</button>
    </form>
  </div>
  <?= push_card() ?>
</div>
<?php
layout_end();
