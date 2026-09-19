<?php
/*
 * Installazione: crea le tabelle e il primo account admin.
 * Serve il codice INSTALL_KEY di config.php. A fine installazione il file si cancella da solo
 * (se il server non glielo permette, cancellalo tu via FTP).
 */
require __DIR__ . '/lib/bootstrap.php';

$installed = tables_exist() && (int) q('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
if ($installed) {
    // già installato: questa pagina non deve più esistere
    if (@unlink(__FILE__)) {
        redirect('login.php');
    }
    http_response_code(404);
    exit('Installazione già eseguita. Cancella install.php dal server.');
}

$errors = [];
$keyOk = INSTALL_KEY !== '' && INSTALL_KEY !== 'CAMBIAMI';

if (is_post() && $keyOk) {
    $username = trim($_POST['username'] ?? '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $name = trim($_POST['name'] ?? '');
    $key = is_string($_POST['key'] ?? null) ? $_POST['key'] : '';
    if (!hash_equals(INSTALL_KEY, $key)) {
        usleep(700000);
        $errors[] = 'Codice di installazione errato (è INSTALL_KEY in config.php).';
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        $errors[] = 'Username: 3-50 caratteri tra lettere, numeri, punto, trattino e underscore.';
    }
    if ($err = password_error($password)) {
        $errors[] = $err;
    }
    if (mb_strlen($name) > 80) {
        $errors[] = 'Il nome può avere al massimo 80 caratteri.';
    }
    if (!$errors) {
        $sql = file_get_contents(__DIR__ . '/lib/schema.sql');
        $sql = preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            db()->exec($stmt);
        }
        $playerId = null;
        if ($name !== '') {
            q('INSERT INTO players (name) VALUES (?)', [$name]);
            $playerId = (int) db()->lastInsertId();
        }
        q("INSERT INTO users (username, password_hash, role, player_id) VALUES (?, ?, 'admin', ?)",
            [$username, password_hash($password, PASSWORD_DEFAULT), $playerId]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) db()->lastInsertId();
        if (@unlink(__FILE__)) {
            flash('ok', 'Installazione completata. install.php è stato cancellato.');
        } else {
            flash('err', 'Installazione completata, ma install.php non si è cancellato da solo: eliminalo via FTP.');
        }
        redirect('index.php');
    }
}

layout_start('Installazione');
?>
<div class="narrow">
  <div class="card">
    <h1>Installazione</h1>
    <?php if (!$keyOk): ?>
      <div class="flash flash-err">In <code>config.php</code> imposta <code>INSTALL_KEY</code> con un codice a tua scelta, poi ricarica.</div>
    <?php else: ?>
      <p class="muted">Crea le tabelle nel database <code><?= h(DB_NAME) ?></code> e il primo account admin.</p>
      <?php foreach ($errors as $e): ?><div class="flash flash-err"><?= h($e) ?></div><?php endforeach; ?>
      <form method="post" class="form" autocomplete="off">
        <?= csrf_field() ?>
        <label class="field"><span>Codice di installazione (INSTALL_KEY in config.php)</span>
          <input type="password" name="key" required autocomplete="off"></label>
        <label class="field"><span>Username admin</span>
          <input name="username" required value="<?= h($_POST['username'] ?? '') ?>" autocomplete="username"></label>
        <label class="field"><span>Password (min. <?= PASSWORD_MIN ?> caratteri)</span>
          <input type="password" name="password" required minlength="<?= PASSWORD_MIN ?>" maxlength="72" autocomplete="new-password"></label>
        <label class="field"><span>Il tuo nome da giocatore (facoltativo)</span>
          <input name="name" maxlength="80" value="<?= h($_POST['name'] ?? '') ?>" placeholder="Lascia vuoto se l'admin non gioca"></label>
        <button class="btn btn-primary">Installa</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
