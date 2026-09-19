<?php
/* Iscrizione dei giocatori: l'account resta "in attesa" finché un admin non lo approva. */
require __DIR__ . '/lib/bootstrap.php';

if (!tables_exist()) {
    redirect('install.php');
}
if (!REGISTRATION) {
    flash('err', 'Le iscrizioni sono chiuse: chiedi le credenziali all\'admin.');
    redirect('login.php');
}
if (current_user()) {
    redirect('index.php');
}

$errors = [];
$done = false;
$v = ['name' => '', 'username' => '', 'shirt_number' => '', 'position' => 'Jolly', 'position2' => '', 'foot' => 'Destro'];

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
    } elseif (pending_count() >= REGISTER_MAX_PENDING) {
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
    if ($err = password_error($password)) {
        $errors[] = $err;
    } elseif ($password !== $password2) {
        $errors[] = 'Le due password non coincidono.';
    }
    if ($v['shirt_number'] !== '' && (!ctype_digit($v['shirt_number']) || (int) $v['shirt_number'] > 99)) {
        $errors[] = 'Il numero di maglia va da 0 a 99.';
    }
    if (!in_array($v['position'], positions(), true)) {
        $v['position'] = 'Jolly';
    }
    if (!in_array($v['position2'], positions(), true) || $v['position2'] === $v['position']) {
        $v['position2'] = '';
    }
    if (!in_array($v['foot'], feet(), true)) {
        $v['foot'] = 'Destro';
    }

    if (!$errors) {
        $data = [
            'shirt_number' => $v['shirt_number'] === '' ? null : (int) $v['shirt_number'],
            'position' => $v['position'],
            'position2' => $v['position2'] ?: null,
            'foot' => $v['foot'],
        ];
        q("INSERT INTO users (username, password_hash, role, status, reg_name, reg_json) VALUES (?, ?, 'player', 'in_attesa', ?, ?)",
            [$v['username'], password_hash($password, PASSWORD_DEFAULT), $v['name'], json_encode($data)]);
        registration_hit();
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
      <p>Appena l'admin approva la tua iscrizione potrai entrare con <strong><?= h($v['username']) ?></strong> e la tua password.</p>
      <a class="btn btn-primary btn-block" href="login.php">Vai al login</a>
    <?php else: ?>
      <h1>Iscriviti</h1>
      <p class="muted">L'admin del gruppo approva le iscrizioni e vede i dati che inserisci qui. Se sei già in rosa, verrai collegato al tuo profilo.</p>
      <?php foreach ($errors as $e): ?><div class="flash flash-err"><?= h($e) ?></div><?php endforeach; ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <label class="field"><span>Nome e cognome</span><input name="name" required maxlength="80" value="<?= h($v['name']) ?>" autocomplete="name"></label>
        <label class="field"><span>Username</span><input name="username" required maxlength="50" value="<?= h($v['username']) ?>" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"></label>
        <div class="form-grid">
          <label class="field"><span>Password (min. <?= PASSWORD_MIN ?>)</span><input type="password" name="password" required minlength="<?= PASSWORD_MIN ?>" maxlength="72" autocomplete="new-password"></label>
          <label class="field"><span>Ripeti password</span><input type="password" name="password2" required minlength="<?= PASSWORD_MIN ?>" maxlength="72" autocomplete="new-password"></label>
          <label class="field"><span>Posizione preferita</span><select name="position">
            <?php foreach (positions() as $o): ?><option <?= $v['position'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
          <label class="field"><span>Seconda posizione</span><select name="position2">
            <option value="">— nessuna —</option>
            <?php foreach (positions() as $o): ?><option <?= $v['position2'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
          <label class="field"><span>Numero di maglia</span><input type="number" name="shirt_number" min="0" max="99" value="<?= h($v['shirt_number']) ?>"></label>
          <label class="field"><span>Piede</span><select name="foot">
            <?php foreach (feet() as $o): ?><option <?= $v['foot'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
        </div>
        <label class="hp" aria-hidden="true">Sito web <input name="website" tabindex="-1" autocomplete="off"></label>
        <button class="btn btn-primary btn-block">Invia richiesta</button>
      </form>
      <p class="login-alt">Hai già un account? <a class="link" href="login.php">Accedi</a></p>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
