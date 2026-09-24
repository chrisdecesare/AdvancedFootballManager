<?php
/* Sicurezza dell'account: email (per recuperare la password), cambio password, verifica in due passaggi, uscita dagli altri dispositivi. */
require __DIR__ . '/lib/bootstrap.php';
require_login();

$uid = (int) current_user()['id'];
$acc = q('SELECT id, username, password_hash, email, pending_email, totp_secret, totp_recovery FROM users WHERE id = ?', [$uid])->fetch();

if (is_post()) {
    $do = $_POST['do'] ?? '';
    $cur = is_string($_POST['current'] ?? null) ? $_POST['current'] : '';
    if (in_array($do, ['set_email', 'remove_email', 'change_password', 'totp_enable', 'totp_disable', 'totp_recovery_new'], true)) {
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
    $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
    switch ($do) {
        case 'totp_start':
            // nuovo segreto, tenuto in sessione finché l'utente non dimostra di averlo messo nell'app (con un codice giusto)
            $_SESSION['totp_setup'] = totp_new_secret();
            redirect('account.php#due-passaggi');

        case 'totp_cancel':
            unset($_SESSION['totp_setup']);
            break;

        case 'totp_enable':
            $secret = (string) ($_SESSION['totp_setup'] ?? '');
            if ($secret === '' || $acc['totp_secret']) {
                break;
            }
            $step = totp_match($secret, $code);
            if ($step === null) {
                flash('err', 'Il codice non corrisponde: controlla l\'ora del telefono e scrivi il codice che vedi adesso nell\'app.');
                redirect('account.php#due-passaggi');
            }
            [$plain, $hashes] = totp_new_recovery();
            q('UPDATE users SET totp_secret = ?, totp_recovery = ?, totp_last_step = ? WHERE id = ?', [$secret, json_encode($hashes), $step, $uid]);
            unset($_SESSION['totp_setup']);
            $_SESSION['totp_show_codes'] = $plain;   // si vedono una volta sola, nella pagina che segue
            security_log('2fa_attivata');
            if ($email = verified_email($uid)) {
                send_mail($email, 'Verifica in due passaggi attivata · ' . APP_NAME, mail_text(mail_name($uid), [
                    'Sul tuo account ora serve anche il codice dell\'app di autenticazione per entrare.',
                    'Se non sei stato tu, scrivi subito all\'admin del sito.',
                ]));
            }
            flash('ok', 'Verifica in due passaggi attivata. Salva i codici di recupero qui sotto: senza telefono sono l\'unico modo per entrare.');
            redirect('account.php#due-passaggi');

        case 'totp_disable':
        case 'totp_recovery_new':
            if (!$acc['totp_secret']) {
                break;
            }
            if (!totp_verify_user($uid, $code)) {
                rate_record('__pwcheck_' . $uid);
                security_log('2fa_fallito', 'dalla pagina Sicurezza');
                flash('err', 'Codice sbagliato o scaduto.');
                redirect('account.php#due-passaggi');
            }
            if ($do === 'totp_disable') {
                q('UPDATE users SET totp_secret = NULL, totp_recovery = NULL, totp_last_step = 0 WHERE id = ?', [$uid]);
                security_log('2fa_disattivata');
                if ($email = verified_email($uid)) {
                    send_mail($email, 'Verifica in due passaggi disattivata · ' . APP_NAME, mail_text(mail_name($uid), [
                        'Sul tuo account la verifica in due passaggi è stata disattivata il ' . date('d/m/Y') . ' alle ' . date('H:i') . ': ora per entrare basta la password.',
                        'Se non sei stato tu, cambia subito la password da ' . trusted_base_url() . 'account.php e avvisa l\'admin.',
                    ]));
                }
                flash('ok', 'Verifica in due passaggi disattivata: ora per entrare basta la password.');
            } else {
                [$plain, $hashes] = totp_new_recovery();
                q('UPDATE users SET totp_recovery = ? WHERE id = ?', [json_encode($hashes), $uid]);
                $_SESSION['totp_show_codes'] = $plain;
                security_log('2fa_nuovi_codici');
                flash('ok', 'Nuovi codici di recupero creati: quelli vecchi non valgono più.');
            }
            redirect('account.php#due-passaggi');

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
$totpSetup = !$acc['totp_secret'] ? ($_SESSION['totp_setup'] ?? null) : null;
$showCodes = $_SESSION['totp_show_codes'] ?? null;
unset($_SESSION['totp_show_codes']);
$recoveryLeft = $acc['totp_secret'] ? count(json_decode($acc['totp_recovery'] ?? '[]', true) ?: []) : 0;
$knownDevices = q('SELECT device, first_seen, last_seen FROM known_devices WHERE user_id = ? ORDER BY last_seen DESC', [$uid])->fetchAll();

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

  <section class="card" id="due-passaggi">
    <h2><i class="ti ti-shield-lock"></i> Verifica in due passaggi</h2>
    <p class="muted small">Oltre alla password, per entrare serve un codice di 6 cifre che cambia ogni 30 secondi, generato da un'app sul tuo telefono
      (Google Authenticator, Microsoft Authenticator, Authy, 1Password...). Anche se qualcuno scopre la tua password, senza il telefono non entra.
      <?= is_admin() || my_owned_leagues() ? '<strong>Chi gestisce una lega o il sito dovrebbe attivarla.</strong>' : '' ?></p>
    <?php if ($showCodes): ?>
      <div class="recovery-box">
        <p><strong>Codici di recupero</strong> — scrivili o salvali in un posto sicuro. Ognuno vale una volta sola, al posto del codice dell'app, se perdi il telefono. Non li vedrai più.</p>
        <ol class="recovery-codes"><?php foreach ($showCodes as $c): ?><li><code><?= h($c) ?></code></li><?php endforeach; ?></ol>
      </div>
    <?php endif; ?>
    <?php if ($acc['totp_secret']): ?>
      <p><i class="ti ti-circle-check acc-ok"></i> <strong>Attiva.</strong> Codici di recupero rimasti: <strong><?= $recoveryLeft ?></strong> su <?= TOTP_RECOVERY_CODES ?>.</p>
      <details class="collapsible">
        <summary><strong>Nuovi codici di recupero o disattivazione</strong></summary>
        <form method="post" class="form form-grid">
          <?= csrf_field() ?>
          <label class="field"><span>Password attuale</span><input type="password" name="current" required autocomplete="current-password"></label>
          <label class="field"><span>Codice dell'app</span><input name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="12" class="code-input"></label>
          <div class="span-2 btn-row">
            <button class="btn btn-ghost btn-sm" name="do" value="totp_recovery_new">Crea nuovi codici di recupero</button>
            <button class="btn btn-danger btn-sm" name="do" value="totp_disable" data-confirm="Disattivare la verifica in due passaggi? Per entrare basterà la password.">Disattiva</button>
          </div>
        </form>
      </details>
    <?php elseif ($totpSetup): ?>
      <ol class="howto">
        <li>Apri l'app di autenticazione sul telefono e aggiungi un account (di solito il pulsante «+»).</li>
        <li>Inquadra il QR code qui sotto, oppure scrivi a mano la chiave.</li>
        <li>Scrivi qui sotto il codice di 6 cifre che compare nell'app, con la tua password.</li>
      </ol>
      <div class="totp-setup">
        <div id="totp-qr" class="totp-qr" data-uri="<?= h(totp_uri($totpSetup, $acc['username'])) ?>" aria-label="QR code da inquadrare con l'app"></div>
        <div><span class="muted small">Chiave da scrivere a mano:</span><br><code class="totp-key"><?= h(trim(chunk_split($totpSetup, 4, ' '))) ?></code></div>
      </div>
      <form method="post" class="form form-grid">
        <?= csrf_field() ?><input type="hidden" name="do" value="totp_enable">
        <label class="field"><span>Codice dell'app</span><input name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="code-input" autofocus></label>
        <label class="field"><span>Password attuale</span><input type="password" name="current" required autocomplete="current-password"></label>
        <div class="span-2 btn-row"><button class="btn btn-primary btn-sm"><i class="ti ti-shield-check"></i> Attiva</button></div>
      </form>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="totp_cancel"><button class="btn btn-ghost btn-sm">Annulla</button></form>
      <script src="assets/vendor/qrcode.js?v=1.4.4"></script>
      <script>
      (() => {
        const box = document.getElementById('totp-qr');
        if (!box || typeof qrcode !== 'function') return;
        const qr = qrcode(0, 'M');
        qr.addData(box.dataset.uri);
        qr.make();
        box.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
      })();
      </script>
    <?php else: ?>
      <p><i class="ti ti-alert-triangle"></i> Non attiva: per entrare basta la password.</p>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="totp_start">
        <button class="btn btn-primary btn-sm"><i class="ti ti-shield-lock"></i> Attiva la verifica in due passaggi</button></form>
    <?php endif; ?>
  </section>

  <section class="card" id="dispositivi">
    <h2><i class="ti ti-devices"></i> Dispositivi</h2>
    <p class="muted small">Su ogni dispositivo dove hai fatto l'accesso resti collegato per <?= REMEMBER_DAYS ?> giorni. Se hai perso un telefono o hai usato un computer che non è tuo, scollega tutti gli altri dispositivi: dovranno rifare l'accesso.
      Dispositivi che restano collegati (compreso questo): <strong><?= $devices ?></strong>.</p>
    <?php if ($knownDevices): ?>
      <p class="small">Dispositivi da cui sei entrato (ti avvisiamo via email quando se ne aggiunge uno nuovo):</p>
      <ul class="plain-list small"><?php foreach ($knownDevices as $d): ?><li><i class="ti ti-device-mobile"></i> <strong><?= h($d['device']) ?></strong> <span class="muted">· ultimo accesso <?= fmt_date_short($d['last_seen']) ?> <?= fmt_time($d['last_seen']) ?></span></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="logout_all">
      <button class="btn btn-ghost btn-sm" data-confirm="Scollegare tutti gli altri dispositivi?"><i class="ti ti-logout"></i> Esci da tutti gli altri dispositivi</button></form>
  </section>
</div>
<?php
layout_end();
