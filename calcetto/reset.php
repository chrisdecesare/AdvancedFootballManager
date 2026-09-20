<?php
/* Scelta della nuova password dal link ricevuto per email. */
require __DIR__ . '/lib/bootstrap.php';

if (!tables_exist()) {
    redirect('install.php');
}
$t = $_GET['t'] ?? $_POST['t'] ?? '';
$tok = mail_token_load($t, 'reset');
$user = $tok ? q("SELECT id, username FROM users WHERE id = ? AND status = 'attivo'", [$tok['user_id']])->fetch() : null;
if ($tok && !$user) {
    $tok = null;
}

$errors = [];
if ($tok && is_post()) {
    $pw = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $pw2 = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
    if ($err = password_error($pw, $user['username'])) {
        $errors[] = $err;
    } elseif ($pw !== $pw2) {
        $errors[] = 'Le due password non coincidono.';
    } else {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $user['id']]);
        q("DELETE FROM mail_tokens WHERE user_id = ? AND purpose = 'reset'", [$user['id']]);
        q('DELETE FROM login_attempts WHERE username = ?', [mb_strtolower($user['username'])]);   // niente blocco per i vecchi tentativi
        security_reset_sessions((int) $user['id']);   // tutti i dispositivi devono rifare l'accesso
        notify_password_changed((int) $user['id']);
        flash('ok', 'Password cambiata: ora puoi accedere con quella nuova.');
        redirect('login.php');
    }
}

layout_start('Nuova password');
?>
<div class="narrow">
  <div class="card login-card">
    <div class="login-ball"><i class="ti ti-key"></i></div>
    <?php if (!$tok): ?>
      <h1>Link non valido</h1>
      <p>Questo link non funziona più: è scaduto (dura 60 minuti) oppure è già stato usato.</p>
      <a class="btn btn-primary btn-block" href="forgot.php">Chiedi un nuovo link</a>
    <?php else: ?>
      <h1>Nuova password</h1>
      <p class="muted">Account <strong><?= h($user['username']) ?></strong>. Scegli una password di almeno <?= PASSWORD_MIN ?> caratteri.</p>
      <?php foreach ($errors as $e): ?><div class="flash flash-err"><?= h($e) ?></div><?php endforeach; ?>
      <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="t" value="<?= h($t) ?>">
        <label class="field"><span>Nuova password</span><input type="password" name="password" required autofocus minlength="<?= PASSWORD_MIN ?>" maxlength="72" autocomplete="new-password"></label>
        <label class="field"><span>Ripeti la password</span><input type="password" name="password2" required minlength="<?= PASSWORD_MIN ?>" maxlength="72" autocomplete="new-password"></label>
        <button class="btn btn-primary btn-block">Salva la nuova password</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
