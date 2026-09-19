<?php
require __DIR__ . '/lib/bootstrap.php';

if (!tables_exist()) {
    redirect('install.php');
}
$next = $_GET['next'] ?? $_POST['next'] ?? null;
$next = safe_back(is_string($next) ? $next : null);
if (current_user()) {
    redirect($next);
}
$error = null;
if (is_post()) {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    $res = attempt_login(is_string($u) ? $u : '', is_string($p) ? $p : '');
    if ($res === 'ok') {
        redirect($next);
    }
    $error = $res === 'blocked'
        ? 'Troppi tentativi falliti: riprova tra ' . LOGIN_WINDOW_MIN . ' minuti.'
        : 'Username o password errati.';
}

layout_start('Accedi');
?>
<div class="narrow">
  <div class="card login-card">
    <div class="login-ball"><i class="ti ti-ball-football"></i></div>
    <h1>Accedi</h1>
    <p class="muted">Le credenziali te le dà l'admin del gruppo.</p>
    <?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <label class="field"><span>Username</span>
        <input name="username" required autofocus autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= h($_POST['username'] ?? '') ?>"></label>
      <label class="field"><span>Password</span>
        <input type="password" name="password" required autocomplete="current-password"></label>
      <button class="btn btn-primary btn-block">Entra</button>
    </form>
  </div>
</div>
<?php
layout_end();
