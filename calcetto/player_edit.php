<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

$id = int_get('id');
$isNew = $id === 0;
$admin = is_admin();
if ($isNew ? !$admin : (!$admin && my_player_id() !== $id)) {
    require_admin(); // mostra "accesso negato"
}
$p = $isNew ? [
    'id' => 0, 'name' => '', 'photo' => null, 'shirt_number' => null, 'position' => 'Centrocampista', 'position2' => null, 'foot' => 'Destro',
    'base_rating' => '6.0', 'active' => 1, 'adj_apps' => 0, 'adj_wins' => 0, 'adj_draws' => 0, 'adj_losses' => 0,
    'adj_goals' => 0, 'adj_assists' => 0, 'adj_own_goals' => 0, 'adj_mvp' => 0,
] : get_player($id);
if (!$p) {
    redirect('players.php');
}
[$p['position'], $p['position2']] = normalize_positions($p['position'] ?? null, $p['position2'] ?? null);
$account = $isNew ? null : (q('SELECT id, username, role FROM users WHERE player_id = ?', [$id])->fetch() ?: null);
$adjFields = ['adj_apps' => 'Presenze', 'adj_goals' => 'Gol', 'adj_assists' => 'Assist', 'adj_mvp' => 'MVP',
    'adj_wins' => 'Vittorie', 'adj_draws' => 'Pareggi', 'adj_losses' => 'Sconfitte', 'adj_own_goals' => 'Autogol'];
$errors = [];

if (is_post()) {
    $do = $_POST['do'] ?? 'save';

    if ($do === 'delete' && $admin && !$isNew) {
        delete_photo_file($p['photo']);
        q('DELETE FROM players WHERE id = ?', [$id]);
        flash('ok', 'Giocatore eliminato.');
        redirect('players.php');
    }
    if ($do === 'remove_photo' && !$isNew) {
        delete_photo_file($p['photo']);
        q('UPDATE players SET photo = NULL WHERE id = ?', [$id]);
        flash('ok', 'Foto rimossa.');
        redirect('player_edit.php?id=' . $id);
    }

    $name = trim($_POST['name'] ?? '');
    $num = trim($_POST['shirt_number'] ?? '');
    [$pos, $pos2] = normalize_positions(
        is_string($_POST['position'] ?? null) ? $_POST['position'] : null,
        is_string($_POST['position2'] ?? null) && $_POST['position2'] !== '' ? $_POST['position2'] : null);
    $foot = in_array($_POST['foot'] ?? '', feet(), true) ? $_POST['foot'] : 'Destro';
    if ($name === '' || mb_strlen($name) > 80) {
        $errors[] = 'Inserisci un nome (max 80 caratteri).';
    }
    if ($num !== '' && (!ctype_digit($num) || (int) $num > 99)) {
        $errors[] = 'Il numero di maglia va da 0 a 99.';
    }

    // account (username/password): l'admin li gestisce per tutti, il giocatore cambia solo la propria password
    $username = trim($_POST['username'] ?? '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $role = ($_POST['role'] ?? 'player') === 'admin' ? 'admin' : 'player';
    if ($password !== '' && ($err = password_error($password))) {
        $errors[] = $err;
    }
    if ($admin && $username !== '') {
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $errors[] = 'Username: 3-50 caratteri tra lettere, numeri, punto, trattino e underscore.';
        } elseif (q('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$username, $account['id'] ?? 0])->fetch()) {
            $errors[] = 'Username già usato.';
        } elseif (!$account && $password === '') {
            $errors[] = 'Per creare l\'account serve anche una password.';
        }
    }
    if ($admin && $account && (int) $account['id'] === (int) current_user()['id'] && $role !== 'admin') {
        $errors[] = 'Non puoi toglierti il ruolo di admin da solo.';
    }
    // gruppi (solo l'admin li cambia): almeno uno; con un solo gruppo esistente è automatico
    $groupIds = array_values(array_intersect(array_map('intval', (array) ($_POST['groups'] ?? [])), array_keys(all_groups())));
    if ($admin && count(all_groups()) === 1) {
        $groupIds = array_keys(all_groups());
    }
    if ($admin && !$groupIds) {
        $errors[] = 'Scegli almeno un gruppo per il giocatore.';
    }

    if (!$errors) {
        $vals = [$name, $num === '' ? null : (int) $num, $pos, $pos2, $foot];
        if ($isNew) {
            q('INSERT INTO players (name, shirt_number, position, position2, foot) VALUES (?, ?, ?, ?, ?)', $vals);
            $id = (int) db()->lastInsertId();
            set_player_groups($id, $groupIds);   // e con questo entra anche nelle partite già programmate del suo gruppo
        } else {
            q('UPDATE players SET name = ?, shirt_number = ?, position = ?, position2 = ?, foot = ? WHERE id = ?', array_merge($vals, [$id]));
        }

        if ($admin) {
            $rating = max(1, min(10, (float) str_replace(',', '.', $_POST['base_rating'] ?? '6')));
            $sets = ['base_rating = ?', 'active = ?'];
            $params = [$rating, empty($_POST['active']) ? 0 : 1];
            foreach ($adjFields as $f => $_) {
                $sets[] = "$f = ?";
                $params[] = (int) ($_POST[$f] ?? 0);
            }
            $params[] = $id;
            q('UPDATE players SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            if (!$isNew) {
                set_player_groups($id, $groupIds);
            }

            if ($username !== '') {
                if ($account) {
                    q('UPDATE users SET username = ?, role = ? WHERE id = ?', [$username, $role, $account['id']]);
                    if ($password !== '') {
                        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $account['id']]);
                    }
                } else {
                    q('INSERT INTO users (username, password_hash, role, player_id) VALUES (?, ?, ?, ?)',
                        [$username, password_hash($password, PASSWORD_DEFAULT), $role, $id]);
                }
            }
        } elseif ($password !== '' && $account) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $account['id']]);
        }

        $photoErr = null;
        $photo = save_player_photo($_FILES['photo'] ?? [], $id, $photoErr);
        if ($photo) {
            delete_photo_file($p['photo']);
            q('UPDATE players SET photo = ? WHERE id = ?', [$photo, $id]);
        }
        if ($photoErr) {
            flash('err', $photoErr);
        }
        flash('ok', $isNew ? 'Giocatore creato.' : 'Profilo salvato.');
        redirect('player.php?id=' . $id);
    }
    // in caso di errore ripropone quanto inserito
    $p = array_merge($p, array_diff_key(array_intersect_key($_POST, $p), ['id' => 1, 'photo' => 1]));
}

layout_start($isNew ? 'Nuovo giocatore' : 'Modifica ' . $p['name'], 'players');
?>
<a class="back" href="<?= $isNew ? 'players.php' : 'player.php?id=' . $id ?>"><i class="ti ti-arrow-left"></i> Indietro</a>
<div class="narrow-lg">
<h1><?= $isNew ? 'Nuovo giocatore' : 'Modifica profilo' ?></h1>
<?php foreach ($errors as $e): ?><div class="flash flash-err"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="form">
  <?= csrf_field() ?><input type="hidden" name="do" value="save">

  <section class="card">
    <h2>Profilo</h2>
    <div class="photo-edit">
      <div id="photo-preview"><?= avatar($p + ['name' => $p['name'] ?: '?'], 'xl') ?></div>
      <div>
        <label class="btn btn-ghost btn-sm file-btn"><i class="ti ti-camera"></i> Scegli foto<input type="file" name="photo" accept="image/*" id="photo-input"></label>
        <p class="muted small">JPG, PNG o WEBP, max 6 MB. Viene ritagliata quadrata.</p>
      </div>
    </div>
    <div class="form-grid">
      <label class="field span-2"><span>Nome</span><input name="name" required maxlength="80" value="<?= h($p['name']) ?>"></label>
      <label class="field"><span>Numero di maglia</span><input type="number" name="shirt_number" min="0" max="99" value="<?= h($p['shirt_number']) ?>"></label>
      <label class="field"><span>Posizione preferita</span><select name="position">
        <?php foreach (main_positions() as $o): ?><option <?= $p['position'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Seconda posizione (facoltativa; Jolly = si adatta a tutto)</span><select name="position2">
        <option value="">— nessuna —</option>
        <?php foreach (positions() as $o): ?><option <?= ($p['position2'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Piede preferito</span><select name="foot">
        <?php foreach (feet() as $o): ?><option <?= $p['foot'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
    </div>
  </section>

  <section class="card">
    <h2>Account</h2>
    <?php if ($admin): ?>
      <div class="form-grid">
        <label class="field"><span>Username</span><input name="username" value="<?= h($_POST['username'] ?? ($account['username'] ?? '')) ?>" placeholder="<?= $account ? '' : 'Vuoto = nessun account' ?>" autocomplete="off"></label>
        <label class="field"><span><?= $account ? 'Nuova password (vuoto = invariata)' : 'Password' ?></span><input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password"></label>
        <label class="field"><span>Ruolo</span><select name="role">
          <option value="player" <?= ($account['role'] ?? '') !== 'admin' ? 'selected' : '' ?>>Giocatore</option>
          <option value="admin" <?= ($account['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option></select></label>
      </div>
      <p class="muted small">Con l'account il giocatore può confermare le presenze, votare e modificare il proprio profilo.</p>
    <?php elseif ($account): ?>
      <p class="muted">Username: <strong><?= h($account['username']) ?></strong></p>
      <label class="field"><span>Nuova password (vuoto = invariata)</span><input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password"></label>
    <?php endif; ?>
  </section>

  <?php if ($admin): ?>
  <section class="card">
    <h2>Solo admin</h2>
    <?php $allGroups = all_groups(); ?>
      <?php $checked = $errors && isset($_POST['groups']) ? array_map('intval', (array) $_POST['groups']) : ($isNew ? [group_filter() ?: (int) array_key_first($allGroups)] : player_group_ids($id)); ?>
      <fieldset class="group-box"><legend>Gruppi</legend>
        <div class="group-checks">
          <?php foreach ($allGroups as $gid => $gname): ?>
            <label><input type="checkbox" name="groups[]" value="<?= $gid ?>" <?= in_array($gid, $checked, true) || count($allGroups) === 1 ? 'checked' : '' ?> <?= count($allGroups) === 1 ? 'disabled' : '' ?>> <?= h($gname) ?></label>
          <?php endforeach; ?>
        </div>
        <p class="muted small">Il giocatore vede solo giocatori e partite dei suoi gruppi e può partecipare solo alle partite di quei gruppi.
          <?= count($allGroups) === 1 ? 'Esiste un solo gruppo: creane un altro da <a class="link" href="admin.php#gruppi">Admin → Gruppi</a> per poter scegliere.' : '' ?></p>
      </fieldset>
    <div class="form-grid">
      <label class="field"><span>Rating base (1-10)</span><input name="base_rating" inputmode="decimal" value="<?= h(str_replace('.', ',', (string) $p['base_rating'])) ?>"></label>
      <label class="field check"><input type="checkbox" name="active" value="1" <?= $p['active'] ? 'checked' : '' ?>><span>Attivo (compare nelle nuove partite)</span></label>
    </div>
    <p class="muted small">Il rating base è il livello di partenza per il bilanciamento delle squadre. Con le partite votate si combina con la media voto e la % di vittorie.</p>
    <h3>Correzioni statistiche</h3>
    <p class="muted small">Valori che si <strong>sommano</strong> a quelli calcolati dalle partite: servono per inserire lo storico precedente al sito o correggere errori.</p>
    <div class="form-grid form-grid-4">
      <?php foreach ($adjFields as $f => $l): ?>
        <label class="field"><span><?= $l ?></span><input type="number" name="<?= $f ?>" value="<?= (int) $p[$f] ?>"></label>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <div class="btn-row">
    <button class="btn btn-primary">Salva</button>
  </div>
</form>

<?php if (!$isNew): ?>
  <div class="btn-row danger-zone">
    <?php if ($p['photo']): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="remove_photo">
        <button class="btn btn-ghost btn-sm">Rimuovi foto</button></form>
    <?php endif; ?>
    <?php if ($admin): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="delete">
        <button class="btn btn-danger btn-sm" data-confirm="Eliminare il giocatore e tutte le sue presenze, gol e voti? Se ha smesso di giocare, meglio togliere la spunta 'Attivo'."><i class="ti ti-trash"></i> Elimina giocatore</button></form>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>
<?php
layout_end();
