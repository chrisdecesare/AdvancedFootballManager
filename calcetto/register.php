<?php
/*
 * Iscrizione dei giocatori: l'account resta "in attesa" finché un admin non lo approva.
 * Con ?c=CODICE (link d'invito di una lega, vedi join.php) l'iscrizione è per quella lega: la approva un suo admin,
 * oppure si entra subito se la lega è ad ingresso libero.
 */
require __DIR__ . '/lib/bootstrap.php';

if (!tables_exist()) {
    redirect('install.php');
}
$code = is_string($_GET['c'] ?? $_POST['c'] ?? null) ? (string) ($_GET['c'] ?? $_POST['c']) : '';
$league = $code !== '' ? league_by_code($code) : null;
if ($code !== '' && !$league) {
    flash('err', 'Il link d\'invito non è valido (forse è stato cambiato): chiedine uno nuovo a chi gestisce la lega.');
    redirect('login.php');
}
$userLeague = $league && $league['owner_user_id'] !== null;
if ($league ? ($userLeague ? !LEAGUE_CREATION : !REGISTRATION) : !REGISTRATION) {
    flash('err', 'Le iscrizioni sono chiuse: chiedi le credenziali all\'admin.');
    redirect('login.php');
}
if (current_user()) {
    redirect($league ? 'join.php?c=' . urlencode($league['invite_code']) : 'index.php');
}
$open = $league && $league['join_mode'] === 'libero';   // lega ad ingresso libero: niente attesa

$errors = [];
$done = false;
$matchName = null;
$emailSent = false;
$v = ['name' => '', 'username' => '', 'email' => '', 'shirt_number' => '', 'position' => 'Centrocampista', 'position2' => '', 'foot' => 'Destro'];

if (is_post()) {
    // campo trappola per i bot: le persone non lo vedono
    if (($_POST['website'] ?? '') !== '') {
        usleep(700000);
        redirect('login.php');
    }
    $v = array_merge($v, array_map(fn($x) => is_string($x) ? trim($x) : '', array_intersect_key($_POST, $v)));
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $password2 = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';

    if (registration_blocked()) {
        $errors[] = 'Troppe iscrizioni da questa connessione: riprova tra un po\'.';
    } elseif ($league ? (!$open && league_pending_count((int) $league['id']) >= LEAGUE_MAX_PENDING) : pending_count() >= REGISTER_MAX_PENDING) {
        $errors[] = 'Ci sono troppe iscrizioni in attesa: chiedi all\'admin di approvarle e riprova.';
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

    if (!$errors) {
        // se è già in rosa (stesso nome) l'iscrizione viene abbinata a quel giocatore, cercandolo solo nella lega giusta
        $matchId = find_roster_match($v['name'], $league ? free_roster_players([(int) $league['id']]) : free_roster_players(legacy_group_ids(), true));
        if ($matchId) {
            $matchName = (string) q('SELECT name FROM players WHERE id = ?', [$matchId])->fetchColumn();
        }
        $data = [
            'shirt_number' => $v['shirt_number'] === '' ? null : (int) $v['shirt_number'],
            'position' => $v['position'],
            'position2' => $v['position2'] ?: null,
            'foot' => $v['foot'],
            'player_id' => $matchId,
        ];
        q("INSERT INTO users (username, password_hash, role, status, reg_name, reg_json, reg_group_id) VALUES (?, ?, 'player', 'in_attesa', ?, ?, ?)",
            [$v['username'], password_hash($password, PASSWORD_DEFAULT), $v['name'], json_encode($data), $league ? (int) $league['id'] : null]);
        $newUserId = (int) db()->lastInsertId();   // prima di registration_hit(), che scrive un'altra riga
        registration_hit();
        log_activity('iscrizione', $v['name'] . ' (@' . $v['username'] . ')', $league ? (int) $league['id'] : null, $newUserId);
        $emailSent = false;
        if ($v['email'] !== '') {
            // l'indirizzo va confermato dal link nell'email; senza conferma non serve al recupero della password
            $emailSent = start_email_verification($newUserId, $v['email']) === null;
        }
        if ($open) {
            // lega ad ingresso libero: account subito attivo, dentro la lega, e già collegato
            approve_registration($newUserId, [(int) $league['id']], (int) ($matchId ?? 0), false);
            session_regenerate_id(true);
            $_SESSION['uid'] = $newUserId;
            $_SESSION['sv'] = 0;
            remember_issue($newUserId);
            log_activity('lega_ingresso', 'iscritto con il link', (int) $league['id'], $newUserId);
            flash('ok', 'Benvenuto in ' . $league['name'] . '!' . ($emailSent ? ' Ti abbiamo mandato un\'email per confermare l\'indirizzo.' : ''));
            redirect('index.php');
        }
        $_SESSION['reg_uid'] = $newUserId;          // questo browser potrà attivare le notifiche per sapere quando viene approvato
        $leagueId = $league ? (int) $league['id'] : null;
        push_notify_registration($newUserId, $v['name'], $v['username'], $matchName ?: null, $leagueId);   // avvisa chi approva, a pagina già inviata
        push_defer(fn() => notify_admins_registration($v['name'], $v['username'], $leagueId));             // e per sicurezza anche via email
        $done = true;
    }
}

layout_start('Iscriviti');
?>
<div class="narrow">
  <div class="card login-card">
    <div class="login-ball"><i class="ti ti-shirt"></i></div>
    <?php if ($done): ?>
      <h1>Richiesta inviata!</h1>
      <?php if ($matchName): ?>
        <p><i class="ti ti-link"></i> Sei già in rosa come <strong><?= h($matchName) ?></strong>: il tuo account verrà collegato a quel giocatore.</p>
      <?php else: ?>
        <p><i class="ti ti-shirt"></i> Verrai aggiunto alla rosa come nuovo giocatore.</p>
      <?php endif; ?>
      <p>Appena <?= $league ? 'un admin di <strong>' . h($league['name']) . '</strong>' : 'l\'admin' ?> approva la tua iscrizione potrai entrare con <strong><?= h($v['username']) ?></strong> e la tua password.</p>
      <?php if ($emailSent): ?><p><i class="ti ti-mail-check"></i> Ti abbiamo mandato un'email a <strong><?= h($v['email']) ?></strong>: apri il link per confermare l'indirizzo (controlla anche lo spam).</p><?php endif; ?>
      <?= push_card(false, true) ?>
      <a class="btn btn-primary btn-block" href="login.php">Vai al login</a>
    <?php else: ?>
      <h1><?= $league ? 'Entra in ' . h($league['name']) : 'Iscriviti' ?></h1>
      <?php if ($league): ?>
        <p class="muted"><?= $open ? 'Crea il tuo account: entri subito nella lega.' : 'Crea il tuo account: un admin della lega approva la richiesta e vede i dati che inserisci qui.' ?> Se sei già nella rosa della lega, verrai collegato al tuo profilo.</p>
      <?php else: ?>
        <p class="muted">L'admin approva le iscrizioni e vede i dati che inserisci qui. Se sei già in rosa, verrai collegato al tuo profilo.</p>
      <?php endif; ?>
      <?php foreach ($errors as $e): ?><div class="flash flash-err"><?= h($e) ?></div><?php endforeach; ?>
      <form method="post" class="form">
        <?= csrf_field() ?><?php if ($league): ?><input type="hidden" name="c" value="<?= h($league['invite_code']) ?>"><?php endif; ?>
        <label class="field"><span>Nome e cognome</span><input name="name" required maxlength="80" value="<?= h($v['name']) ?>" autocomplete="name"></label>
        <label class="field"><span>Username</span><input name="username" required maxlength="50" value="<?= h($v['username']) ?>" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"></label>
        <label class="field"><span>Email (facoltativa, ma serve per recuperare la password)</span><input type="email" name="email" maxlength="190" value="<?= h($v['email']) ?>" autocomplete="email" placeholder="nome@esempio.it"></label>
        <div class="form-grid">
          <label class="field"><span>Password (min. <?= PASSWORD_MIN ?>)</span><input type="password" name="password" required minlength="<?= PASSWORD_MIN ?>" maxlength="72" autocomplete="new-password"></label>
          <label class="field"><span>Ripeti password</span><input type="password" name="password2" required minlength="<?= PASSWORD_MIN ?>" maxlength="72" autocomplete="new-password"></label>
          <label class="field"><span>Posizione preferita</span><select name="position">
            <?php foreach (main_positions() as $o): ?><option <?= $v['position'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
          <label class="field"><span>Seconda posizione (Jolly = ti adatti a tutto)</span><select name="position2">
            <option value="">— nessuna —</option>
            <?php foreach (positions() as $o): ?><option <?= $v['position2'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
          <label class="field"><span>Numero di maglia</span><input type="number" name="shirt_number" min="0" max="99" value="<?= h($v['shirt_number']) ?>"></label>
          <label class="field"><span>Piede</span><select name="foot">
            <?php foreach (feet() as $o): ?><option <?= $v['foot'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
        </div>
        <label class="hp" aria-hidden="true">Sito web <input name="website" tabindex="-1" autocomplete="off"></label>
        <button class="btn btn-primary btn-block"><?= $open ? 'Crea account ed entra' : 'Invia richiesta' ?></button>
      </form>
      <p class="login-alt">Hai già un account? <a class="link" href="login.php<?= $league ? '?next=' . h(urlencode('join.php?c=' . $league['invite_code'])) : '' ?>">Accedi</a></p>
      <?php if (!$league && LEAGUE_CREATION): ?><p class="login-alt">Vuoi organizzare il tuo calcetto? <a class="link" href="create_league.php">Crea la tua lega</a></p><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
