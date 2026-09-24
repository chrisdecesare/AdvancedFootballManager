<?php
/*
 * Conferma la password (e il codice dell'app, se c'è la verifica in due passaggi) prima di entrare in un'area delicata:
 * pagine admin dopo un po' di inattività, azioni distruttive. Chi resta collegato con il "resta collegato" non ha digitato
 * la password da tempo: un telefono lasciato sbloccato o una sessione rubata non bastano per le operazioni pericolose.
 * La chiama require_recent_auth() (lib/security.php).
 */
require __DIR__ . '/lib/bootstrap.php';
require_login();

$next = safe_back(is_string($_GET['next'] ?? $_POST['next'] ?? null) ? ($_GET['next'] ?? $_POST['next']) : null);
$uid = (int) current_user()['id'];
$acc = q('SELECT username, password_hash, totp_secret FROM users WHERE id = ?', [$uid])->fetch();
$needCode = !empty($acc['totp_secret']);
$error = null;

if (is_post()) {
    $pw = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if (rate_recent('__confirm_' . $uid, 900) >= 5) {
        $error = 'Troppi tentativi: riprova tra 15 minuti.';
    } elseif (!password_verify($pw, $acc['password_hash'])) {
        rate_record('__confirm_' . $uid);
        security_log('conferma_fallita', 'password sbagliata');
        $error = 'Password sbagliata.';
    } elseif ($needCode && !totp_verify_user($uid, is_string($_POST['code'] ?? null) ? $_POST['code'] : '')) {
        rate_record('__confirm_' . $uid);
        security_log('conferma_fallita', 'codice sbagliato');
        $error = 'Codice sbagliato o scaduto.';
    } else {
        mark_authenticated();
        redirect($next);
    }
}

layout_start('Conferma la password');
?>
<div class="narrow">
  <div class="card login-card">
    <div class="login-ball"><i class="ti ti-lock"></i></div>
    <h1>Conferma che sei tu</h1>
    <p class="muted">Stai per entrare in un'area riservata: per sicurezza riscrivi la password<?= $needCode ? ' e il codice dell\'app' : '' ?>. Non te la chiediamo di nuovo per un po'.</p>
    <?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="next" value="<?= h($next) ?>">
      <input type="text" name="username" value="<?= h($acc['username']) ?>" autocomplete="username" hidden>
      <label class="field"><span>Password</span><input type="password" name="password" required autofocus autocomplete="current-password"></label>
      <?php if ($needCode): ?>
        <label class="field"><span>Codice dell'app (o un codice di recupero)</span><input name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="12" class="code-input"></label>
      <?php endif; ?>
      <button class="btn btn-primary btn-block">Conferma</button>
    </form>
    <p class="login-alt"><a class="link" href="index.php">Torna alla home</a></p>
  </div>
</div>
<?php
layout_end();
