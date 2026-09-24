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
// secondo passaggio in corso (password già giusta, manca il codice dell'app): vale 5 minuti e 5 tentativi
$pending = $_SESSION['pending_2fa'] ?? null;
if ($pending && time() - (int) $pending['at'] > 300) {
    unset($_SESSION['pending_2fa']);
    $pending = null;
    $error = 'Tempo scaduto: rifai l\'accesso.';
}
if (is_post() && ($_POST['do'] ?? '') === 'cancel_2fa') {
    unset($_SESSION['pending_2fa']);
    redirect('login.php');
}
if (is_post() && ($_POST['do'] ?? '') === '2fa' && $pending) {
    $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
    $res = totp_verify_user((int) $pending['uid'], $code);
    if ($res) {
        complete_login((int) $pending['uid'], (string) $pending['username']);
        if ($res === 'recovery') {
            security_log('2fa_codice_recupero', 'usato un codice di recupero');
            flash('err', 'Sei entrato con un codice di recupero: non vale più. Se hai perso il telefono, riconfigura la verifica in due passaggi da Sicurezza.');
        }
        redirect($next);
    }
    security_log('2fa_fallito', (string) $pending['username'], (int) $pending['uid']);
    q('INSERT INTO login_attempts (ip, username) VALUES (?, ?)', [client_ip(), (string) $pending['username']]);   // conta nei blocchi come una password sbagliata
    $_SESSION['pending_2fa']['tries'] = (int) $pending['tries'] + 1;
    if ($_SESSION['pending_2fa']['tries'] >= 5) {
        unset($_SESSION['pending_2fa']);
        $pending = null;
        $error = 'Troppi codici sbagliati: rifai l\'accesso.';
    } else {
        $error = 'Codice sbagliato o scaduto: riprova con quello che vedi adesso nell\'app.';
    }
} elseif (is_post() && !in_array($_POST['do'] ?? '', ['2fa', 'cancel_2fa'], true)) {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    $res = attempt_login(is_string($u) ? $u : '', is_string($p) ? $p : '');
    if ($res === 'ok') {
        redirect($next);
    }
    if ($res === '2fa') {
        redirect('login.php?next=' . urlencode($next));
    }
    if ($res === 'blocked') {
        $error = 'Troppi tentativi falliti: riprova tra ' . LOGIN_WINDOW_MIN . ' minuti.';
    } elseif ($res === 'pending') {
        $error = 'La tua iscrizione è in attesa: l\'admin della lega deve ancora approvarla.';
    } else {
        $error = 'Username o password errati.';
    }
}
$pending = $_SESSION['pending_2fa'] ?? null;

if ($pending):
    layout_start('Verifica in due passaggi'); ?>
<div class="narrow">
  <div class="card login-card">
    <div class="login-ball"><i class="ti ti-shield-lock"></i></div>
    <h1>Verifica in due passaggi</h1>
    <p class="muted">Apri l'app di autenticazione sul telefono e scrivi il codice a 6 cifre di <?= h(APP_NAME) ?>. Hai perso il telefono? Usa uno dei codici di recupero.</p>
    <?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="do" value="2fa"><input type="hidden" name="next" value="<?= h($next) ?>">
      <label class="field"><span>Codice</span>
        <input name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="12" class="code-input" placeholder="123456"></label>
      <button class="btn btn-primary btn-block">Entra</button>
    </form>
    <form method="post" class="login-alt"><?= csrf_field() ?><input type="hidden" name="do" value="cancel_2fa"><button class="link-btn">Annulla e torna al login</button></form>
  </div>
</div>
<?php
    layout_end();
    exit;
endif;

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
