<?php
/*
 * Crea la tua lega (self-service): chi non ha un account lo crea qui insieme alla lega, chi ce l'ha scrive solo il nome.
 * Chi crea la lega ne è il proprietario: invita i giocatori con un link e la gestisce da «La mia lega» (league.php),
 * senza passare dall'admin del sito. Limiti anti-abuso: LEAGUE_MAX_PER_USER leghe per account, LEAGUE_MAX_PER_IP_DAY al giorno per connessione.
 */
require __DIR__ . '/lib/bootstrap.php';

if (!tables_exist()) {
    redirect('install.php');
}
if (!LEAGUE_CREATION) {
    flash('err', 'La creazione di nuove leghe è chiusa.');
    redirect(current_user() ? 'index.php' : 'login.php');
}
$u = current_user();
if ($u && is_guest()) {
    redirect('index.php');
}

/** Troppe leghe create da questa connessione nelle ultime 24 ore? */
function league_ip_blocked(): bool
{
    return (int) q("SELECT COUNT(*) FROM activity_log WHERE action = 'lega_creata' AND ip = ? AND created_at > ?",
        [client_ip(), date('Y-m-d H:i:s', time() - 86400)])->fetchColumn() >= LEAGUE_MAX_PER_IP_DAY;
}

$errors = [];
$v = ['league' => '', 'join_mode' => 'approvazione', 'name' => '', 'username' => '', 'email' => '', 'shirt_number' => '',
    'position' => 'Centrocampista', 'position2' => '', 'foot' => 'Destro'];

if (is_post()) {
    if (($_POST['website'] ?? '') !== '') {   // campo trappola per i bot
        security_log('bot', 'create_league.php · campo trappola');
        usleep(700000);
        redirect('login.php');
    }
    $v = array_merge($v, array_map(fn($x) => is_string($x) ? trim($x) : '', array_intersect_key($_POST, $v)));
    $v['join_mode'] = $v['join_mode'] === 'libero' ? 'libero' : 'approvazione';
    if (!$u && !form_trap_ok(4)) {   // modulo lungo compilato in un lampo: un bot
        $errors[] = 'Qualcosa non va con il modulo: ricontrolla i dati e premi di nuovo il pulsante.';
    }
    if ($err = league_name_error($v['league'])) {
        $errors[] = $err;
    }
    if (league_ip_blocked()) {
        $errors[] = 'Troppe leghe create da questa connessione oggi: riprova domani.';
    }
    if ($u && leagues_owned_count((int) $u['id']) >= LEAGUE_MAX_PER_USER && !is_admin()) {
        $errors[] = 'Hai già ' . LEAGUE_MAX_PER_USER . ' leghe: per crearne un\'altra eliminane una da «La mia lega».';
    }

    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if (!$u) {
        // stessi controlli dell'iscrizione normale (register.php)
        if (registration_blocked()) {
            $errors[] = 'Troppe iscrizioni da questa connessione: riprova tra un po\'.';
        }
        if ($v['name'] === '' || mb_strlen($v['name']) > 80) {
            $errors[] = 'Scrivi nome e cognome (max 80 caratteri).';
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $v['username'])) {
            $errors[] = 'Username: 3-50 caratteri tra lettere, numeri, punto, trattino e underscore.';
        } elseif (q('SELECT 1 FROM users WHERE LOWER(username) = ?', [mb_strtolower($v['username'])])->fetch()) {
            $errors[] = 'Username già usato: scegline un altro.';
        }
        $v['email'] = mb_strtolower($v['email']);
        if ($v['email'] !== '' && (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['email']) > 190)) {
            $errors[] = 'L\'indirizzo email non è valido (puoi anche lasciarlo vuoto).';
        }
        $password2 = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
        if ($err = password_error($password, $v['username'])) {
            $errors[] = $err;
        } elseif ($password !== $password2) {
            $errors[] = 'Le due password non coincidono.';
        }
        if ($v['shirt_number'] !== '' && (!ctype_digit($v['shirt_number']) || (int) $v['shirt_number'] > 99)) {
            $errors[] = 'Il numero di maglia va da 0 a 99.';
        }
        [$v['position'], $pos2] = normalize_positions($v['position'], $v['position2'] ?: null);
        $v['position2'] = $pos2 ?? '';
        if (!in_array($v['foot'], feet(), true)) {
            $v['foot'] = 'Destro';
        }
    }

    if (!$errors) {
        $emailSent = false;
        if (!$u) {
            $data = ['shirt_number' => $v['shirt_number'] === '' ? null : (int) $v['shirt_number'], 'position' => $v['position'],
                'position2' => $v['position2'] ?: null, 'foot' => $v['foot']];
            q("INSERT INTO users (username, password_hash, role, status, reg_name, reg_json, tour_done) VALUES (?, ?, 'player', 'attivo', ?, ?, 0)",
                [$v['username'], password_hash($password, PASSWORD_DEFAULT), $v['name'], json_encode($data)]);
            $uid = (int) db()->lastInsertId();
            registration_hit();
            log_activity('iscrizione', $v['name'] . ' (@' . $v['username'] . ') per creare una lega', null, $uid);
            if ($v['email'] !== '') {
                $emailSent = start_email_verification($uid, $v['email']) === null;
            }
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            $_SESSION['sv'] = 0;
            remember_issue($uid);
        } else {
            $uid = (int) $u['id'];
        }
        $gid = league_create($v['league'], $uid);
        q('UPDATE squad_groups SET join_mode = ? WHERE id = ?', [$v['join_mode'], $gid]);
        q('UPDATE users SET reg_json = NULL WHERE id = ?', [$uid]);
        $_SESSION['group'] = $gid;   // si guarda subito la lega nuova
        flash('ok', 'Lega «' . $v['league'] . '» creata! Ora invita i tuoi giocatori mandando il link qui sotto.'
            . ($emailSent ? ' Ti abbiamo mandato anche un\'email per confermare l\'indirizzo (serve a recuperare la password).' : ''));
        redirect('league.php?id=' . $gid);
    }
}

layout_start('Crea la tua lega');
?>
<div class="narrow">
  <div class="card login-card">
    <div class="login-ball"><i class="ti ti-trophy"></i></div>
    <h1>Crea la tua lega</h1>
    <p class="muted">Organizza il tuo calcetto: presenze, squadre bilanciate in automatico, voti e MVP, classifica, pagamenti delle quote e scommesse a gettoni finti.
      La lega è tua: inviti i giocatori con un link, approvi chi entra e scegli chi ti aiuta a gestirla. Gli altri utenti del sito non la vedono.</p>
    <?php foreach ($errors as $e): ?><div class="flash flash-err"><?= h($e) ?></div><?php endforeach; ?>
    <form method="post" class="form">
      <?= csrf_field() ?><?= form_trap_field() ?>
      <label class="field"><span>Nome della lega</span><input name="league" required maxlength="40" value="<?= h($v['league']) ?>" placeholder="es. Calcetto del giovedì" autocomplete="off"></label>
      <fieldset class="group-box"><legend>Chi può entrare</legend>
        <label class="radio-opt"><input type="radio" name="join_mode" value="approvazione" <?= $v['join_mode'] !== 'libero' ? 'checked' : '' ?>> <span>Chi ha il link chiede di entrare e <strong>tu approvi</strong> (consigliato)</span></label>
        <label class="radio-opt"><input type="radio" name="join_mode" value="libero" <?= $v['join_mode'] === 'libero' ? 'checked' : '' ?>> <span>Chi ha il link <strong>entra subito</strong></span></label>
        <p class="muted small">Si cambia quando vuoi da «La mia lega».</p>
      </fieldset>
      <?php if (!$u): ?>
        <h2>Il tuo account</h2>
        <p class="muted small">Con questo account entri nel sito, giochi nella lega e la gestisci. Hai già un account? <a class="link" href="login.php?next=create_league.php">Accedi</a> e poi crea la lega.</p>
        <label class="field"><span>Nome e cognome</span><input name="name" required maxlength="80" value="<?= h($v['name']) ?>" autocomplete="name"></label>
        <label class="field"><span>Username</span><input name="username" required maxlength="50" value="<?= h($v['username']) ?>" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"></label>
        <label class="field"><span>Email (facoltativa, ma serve per recuperare la password)</span><input type="email" name="email" maxlength="190" value="<?= h($v['email']) ?>" autocomplete="email" placeholder="nome@esempio.it"></label>
        <div class="form-grid">
          <label class="field"><span>Password (min. <?= PASSWORD_MIN ?>)</span><input type="password" name="password" required minlength="<?= PASSWORD_MIN ?>" maxlength="72" autocomplete="new-password"></label>
          <label class="field"><span>Ripeti password</span><input type="password" name="password2" required minlength="<?= PASSWORD_MIN ?>" maxlength="72" autocomplete="new-password"></label>
          <label class="field"><span>Posizione preferita</span><select name="position">
            <?php foreach (main_positions() as $o): ?><option <?= $v['position'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
          <label class="field"><span>Seconda posizione</span><select name="position2">
            <option value="">— nessuna —</option>
            <?php foreach (positions() as $o): ?><option <?= $v['position2'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
          <label class="field"><span>Numero di maglia</span><input type="number" name="shirt_number" min="0" max="99" value="<?= h($v['shirt_number']) ?>"></label>
          <label class="field"><span>Piede</span><select name="foot">
            <?php foreach (feet() as $o): ?><option <?= $v['foot'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
        </div>
        <label class="hp" aria-hidden="true">Sito web <input name="website" tabindex="-1" autocomplete="off"></label>
      <?php endif; ?>
      <button class="btn btn-primary btn-block"><i class="ti ti-trophy"></i> Crea la lega</button>
    </form>
    <?php if (!$u): ?><p class="login-alt">Ti hanno mandato un link d'invito? Aprilo: ti porta direttamente nella lega giusta.</p><?php endif; ?>
  </div>
</div>
<?php
layout_end();
