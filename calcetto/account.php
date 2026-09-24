<?php
/* Sicurezza dell'account: email (per recuperare la password), cambio password, uscita dagli altri dispositivi. */
require __DIR__ . '/lib/bootstrap.php';
require_login();

$uid = (int) current_user()['id'];
$acc = q('SELECT id, username, password_hash, email, pending_email FROM users WHERE id = ?', [$uid])->fetch();

if (is_post()) {
    $do = $_POST['do'] ?? '';
    $cur = is_string($_POST['current'] ?? null) ? $_POST['current'] : '';
    if (in_array($do, ['set_email', 'remove_email', 'change_password'], true)) {
        // queste azioni chiedono la password attuale (con un limite di tentativi: potrebbe essere una sessione rubata)
        if (rate_recent('__pwcheck_' . $uid, 900) >= 5) {
            flash('err', 'Troppi tentativi con la password attuale: riprova tra 15 minuti.');
            redirect('account.php');
        }
        if (!password_verify($cur, $acc['password_hash'])) {
            rate_record('__pwcheck_' . $uid);
            flash('err', 'La password attuale non è corretta.');
            redirect('account.php');
        }
    }
    if (in_array($do, ['set_email', 'remove_email', 'change_password', 'logout_all'], true)) {
        log_activity('account', ['set_email' => 'email cambiata', 'remove_email' => 'email tolta', 'change_password' => 'password cambiata', 'logout_all' => 'uscita dagli altri dispositivi'][$do]);
    }
    switch ($do) {
        case 'set_email':
            $email = mb_strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                flash('err', 'Scrivi un indirizzo email valido.');
            } elseif ($email === $acc['email']) {
                flash('ok', 'Questo indirizzo è già confermato.');
            } elseif (q('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $uid])->fetch()) {
                flash('err', 'Questo indirizzo è già collegato a un altro account.');
            } elseif ($err = start_email_verification($uid, $email)) {
                flash('err', $err);
            } else {
                flash('ok', 'Ti ho mandato un\'email a ' . $email . ': apri il link per confermare l\'indirizzo (controlla anche lo spam).');
            }
            break;
        case 'resend':
            if (!$acc['pending_email']) {
                break;
            }
            if ($err = start_email_verification($uid, $acc['pending_email'])) {
                flash('err', $err);
            } else {
                flash('ok', 'Email di conferma rimandata a ' . $acc['pending_email'] . '.');
            }
            break;
        case 'remove_email':
            q('UPDATE users SET email = NULL, pending_email = NULL WHERE id = ?', [$uid]);
            q("DELETE FROM mail_tokens WHERE user_id = ? AND purpose = 'verify'", [$uid]);
            if ($acc['email']) {
                notify_email_changed($acc['email'], $uid, null);
            }
            flash('ok', 'Email tolta dall\'account: senza, la password si recupera solo dall\'admin.');
            break;
        case 'change_password':
            $new = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $new2 = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
            if ($err = password_error($new, $acc['username'])) {
                flash('err', $err);
            } elseif ($new !== $new2) {
                flash('err', 'Le due password nuove non coincidono.');
            } elseif ($new === $cur) {
                flash('err', 'La nuova password deve essere diversa da quella attuale.');
            } else {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $uid]);
                security_reset_sessions($uid);   // gli altri dispositivi devono rifare l'accesso, questo resta collegato
                notify_password_changed($uid);
                flash('ok', 'Password cambiata. Gli altri dispositivi dovranno rifare l\'accesso.');
            }
            break;
        case 'logout_all':
            security_reset_sessions($uid);
            flash('ok', 'Tutti gli altri dispositivi sono stati scollegati.');
            break;
    }
    redirect('account.php');
}

$devices = (int) q('SELECT COUNT(*) FROM auth_tokens WHERE user_id = ? AND expires_at > ?', [$uid, date('Y-m-d H:i:s')])->fetchColumn();

layout_start('Sicurezza', 'profile');
?>
<div class="narrow">
  <?php if (my_player_id() && !is_guest()): ?><a class="back" href="player_edit.php?id=<?= (int) my_player_id() ?>"><i class="ti ti-arrow-left"></i> Modifica profilo</a><?php endif; ?>
  <div class="page-head"><h1>Sicurezza dell'account</h1></div>
  <p class="muted">Account <strong><?= h($acc['username']) ?></strong></p>

  <section class="card" id="email">
    <h2><i class="ti ti-mail"></i> Email</h2>
    <p class="muted small">Serve per recuperare la password da solo se la dimentichi, e per avvisarti se qualcuno cambia i dati dell'account. Non la vede nessun altro giocatore.</p>
    <?php if ($acc['email']): ?>
      <p><i class="ti ti-circle-check acc-ok"></i> <strong><?= h($acc['email']) ?></strong> <span class="tag">confermata</span></p>
    <?php else: ?>
      <p><i class="ti ti-alert-triangle"></i> Nessuna email confermata: se dimentichi la password dovrai chiedere all'admin.</p>
    <?php endif; ?>
    <?php if ($acc['pending_email']): ?>
      <div><i class="ti ti-hourglass"></i> In attesa di conferma: <strong><?= h($acc['pending_email']) ?></strong> — apri il link nell'email che ti abbiamo mandato (controlla anche lo spam).
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="resend"><button class="btn btn-ghost btn-sm">Rimanda l'email</button></form></div>
    <?php endif; ?>
    <form method="post" class="form form-grid">
      <?= csrf_field() ?><input type="hidden" name="do" value="set_email">
      <label class="field"><span><?= $acc['email'] ? 'Cambia email' : 'Aggiungi email' ?></span><input type="email" name="email" required maxlength="190" autocomplete="email" placeholder="nome@esempio.it"></label>
      <label class="field"><span>Password attuale</span><input type="password" name="current" required autocomplete="current-password"></label>
      <div><button class="btn btn-primary btn-sm">Manda l'email di conferma</button></div>
    </form>
    <?php if ($acc['email'] || $acc['pending_email']): ?>
      <details class="collapsible">
        <summary><strong>Togli l'email dall'account</strong></summary>
        <form method="post" class="form form-grid">
          <?= csrf_field() ?><input type="hidden" name="do" value="remove_email">
          <label class="field"><span>Password attuale</span><input type="password" name="current" required autocomplete="current-password"></label>
          <div><button class="btn btn-danger btn-sm" data-confirm="Togliere l'email dall'account?">Togli l'email</button></div>
        </form>
      </details>
    <?php endif; ?>
  </section>

  <section class="card" id="password">
    <h2><i class="ti ti-key"></i> Cambia password</h2>
    <form method="post" class="form form-grid">
      <?= csrf_field() ?><input type="hidden" name="do" value="change_password">
      <label class="field span-2"><span>Password attuale</span><input type="password" name="current" required autocomplete="current-password"></label>
      <label class="field"><span>Nuova password (min. <?= PASSWORD_MIN ?>)</span><input type="password" name="password" required minlength="<?= PASSWORD_MIN ?>" maxlength="72" autocomplete="new-password"></label>
      <label class="field"><span>Ripeti la nuova password</span><input type="password" name="password2" required minlength="<?= PASSWORD_MIN ?>" maxlength="72" autocomplete="new-password"></label>
      <div class="span-2"><button class="btn btn-primary btn-sm">Cambia password</button></div>
    </form>
    <p class="muted small">Cambiando la password gli altri dispositivi vengono scollegati e, se hai un'email confermata, ti arriva un avviso.</p>
  </section>

  <section class="card" id="dispositivi">
    <h2><i class="ti ti-devices"></i> Dispositivi</h2>
    <p class="muted small">Su ogni dispositivo dove hai fatto l'accesso resti collegato per <?= REMEMBER_DAYS ?> giorni. Se hai perso un telefono o hai usato un computer che non è tuo, scollega tutti gli altri dispositivi: dovranno rifare l'accesso.
      Dispositivi che restano collegati (compreso questo): <strong><?= $devices ?></strong>.</p>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="logout_all">
      <button class="btn btn-ghost btn-sm" data-confirm="Scollegare tutti gli altri dispositivi?"><i class="ti ti-logout"></i> Esci da tutti gli altri dispositivi</button></form>
  </section>
</div>
<?php
layout_end();
