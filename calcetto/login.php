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
    if ($res === 'blocked') {
        $error = 'Troppi tentativi falliti: riprova tra ' . LOGIN_WINDOW_MIN . ' minuti.';
    } elseif ($res === 'pending') {
        $error = 'La tua iscrizione è in attesa: l\'admin della lega deve ancora approvarla.';
    } else {
        $error = 'Username o password errati.';
    }
}

layout_start('Accedi');
?>
<div class="narrow">
  <div class="card login-card">
    <div class="login-ball"><i class="ti ti-ball-football"></i></div>
    <h1>Accedi</h1>
    <p class="muted"><?= REGISTRATION ? 'Entra con il tuo account.' : 'Le credenziali te le dà l\'admin del gruppo.' ?></p>
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
    <p class="login-alt"><a class="link" href="forgot.php">Password dimenticata?</a></p>
    <?php if (REGISTRATION): ?>
      <p class="login-alt">Non hai un account? <a class="link" href="register.php">Iscriviti</a></p>
    <?php endif; ?>
    <?php if (LEAGUE_CREATION): ?>
      <p class="login-alt">Organizzi un calcetto? <a class="link" href="create_league.php">Crea la tua lega</a></p>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
