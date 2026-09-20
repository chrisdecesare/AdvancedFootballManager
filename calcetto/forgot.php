<?php
/* Password dimenticata: manda un link per reimpostarla all'email verificata dell'account. */
require __DIR__ . '/lib/bootstrap.php';

if (!tables_exist()) {
    redirect('install.php');
}
if (current_user()) {
    redirect('account.php');
}

$sent = false;
$error = null;
if (is_post()) {
    $who = mb_strtolower(trim(is_string($_POST['who'] ?? null) ? mb_substr($_POST['who'], 0, 190) : ''));
    if ($who === '') {
        $error = 'Scrivi il tuo username o la tua email.';
    } elseif (!rate_hit('__mail_ip__', 8, 3600)) {
        $error = 'Troppe richieste da questa connessione: riprova tra un\'ora.';
    } else {
        $u = q("SELECT id, email FROM users WHERE status = 'attivo' AND email IS NOT NULL AND (LOWER(username) = ? OR email = ?) LIMIT 1", [$who, $who])->fetch();
        if ($u && rate_hit('__mail_reset_' . $u['id'], 3, 3600)) {
            send_password_reset((int) $u['id'], $u['email']);
        }
        usleep(random_int(150000, 400000));    // tempi simili con o senza account
        $sent = true;                           // la risposta è la stessa in ogni caso: non si scopre chi ha un account
    }
}

layout_start('Password dimenticata');
?>
<div class="narrow">
  <div class="card login-card">
    <div class="login-ball"><i class="ti ti-key"></i></div>
    <h1>Password dimenticata</h1>
    <?php if ($sent): ?>
      <p><i class="ti ti-mail-check"></i> Se esiste un account con questo username o questa email e ha un indirizzo verificato, ti abbiamo mandato un link per scegliere una nuova password (vale 60 minuti). Controlla anche la cartella dello spam.</p>
      <p class="muted small">Non arriva nulla? Probabilmente l'account non ha un'email verificata: chiedi all'admin di reimpostare la password.</p>
      <a class="btn btn-primary btn-block" href="login.php">Torna al login</a>
    <?php else: ?>
      <p class="muted">Scrivi il tuo username o l'email del tuo account: ti mandiamo un link per scegliere una nuova password.</p>
      <?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <label class="field"><span>Username o email</span>
          <input name="who" required autofocus maxlength="190" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"></label>
        <button class="btn btn-primary btn-block">Mandami il link</button>
      </form>
      <p class="login-alt"><a class="link" href="login.php">Torna al login</a></p>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
