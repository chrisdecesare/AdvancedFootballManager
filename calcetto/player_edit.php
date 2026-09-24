<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

$id = int_get('id');
$isNew = $id === 0;
$admin = is_admin();
// chi amministra una lega gestisce le schede dei giocatori della sua lega (rating, attivo, correzioni, leghe),
// ma non gli account (username, password, ruoli del sito): quelli restano all'admin del sito
if ($admin) {
    $editableGroups = all_groups();
    $shownGroups = array_intersect_key($editableGroups, array_flip(array_merge(bar_group_ids(), $isNew ? [] : player_group_ids($id))));
} else {
    $editableGroups = $shownGroups = admin_groups();
}
$staff = $admin || ($isNew ? (bool) $editableGroups : (bool) array_intersect(player_group_ids($id), array_keys($editableGroups)));
if ($isNew ? !$staff : (!$staff && my_player_id() !== $id)) {
    require_admin(); // mostra "accesso negato"
}
$p = $isNew ? [
    'id' => 0, 'name' => '', 'photo' => null, 'bg_color' => null, 'bg_image' => null, 'shirt_number' => null, 'position' => 'Centrocampista', 'position2' => null, 'foot' => 'Destro',
    'base_rating' => '6.0', 'active' => 1, 'adj_apps' => 0, 'adj_wins' => 0, 'adj_draws' => 0, 'adj_losses' => 0,
    'adj_goals' => 0, 'adj_assists' => 0, 'adj_own_goals' => 0, 'adj_mvp' => 0,
] : get_player($id);
if (!$p || !empty($p['is_guest'])) {   // un ospite non ha una scheda da modificare: si gestisce dalla sua partita
    redirect('players.php');
}
[$p['position'], $p['position2']] = normalize_positions($p['position'] ?? null, $p['position2'] ?? null);
$account = $isNew ? null : (q('SELECT id, username, role FROM users WHERE player_id = ?', [$id])->fetch() ?: null);
$selfAccount = $account && (int) $account['id'] === (int) current_user()['id'];   // la propria password si cambia da account.php
$adjFields = ['adj_apps' => 'Presenze', 'adj_goals' => 'Gol', 'adj_assists' => 'Assist', 'adj_mvp' => 'MVP',
    'adj_wins' => 'Vittorie', 'adj_draws' => 'Pareggi', 'adj_losses' => 'Sconfitte', 'adj_own_goals' => 'Autogol'];
$errors = [];
// con le scommesse aperte il giocatore non può cambiarsi il ruolo; per correggere possono l'admin del sito
// e chi amministra la sua lega (ma non sul proprio profilo)
$roleLock = $isNew ? null : role_lock_match($id);
$roleLocked = $roleLock && !$admin && !($staff && my_player_id() !== $id);

if (is_post()) {
    $do = $_POST['do'] ?? 'save';

    if ($do === 'delete' && $admin && !$isNew) {
        delete_photo_file($p['photo']);
        foreach (bg_files($p) as $f) {
            delete_photo_file($f);
        }
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
    if ($roleLocked) {
        if (isset($_POST['position']) && ($pos !== $p['position'] || $pos2 !== $p['position2'])) {   // i menu sono disattivati: arriva solo forzando la pagina
            flash('err', 'Ruolo non cambiato: con le scommesse aperte (partita di ' . push_when($roleLock['match_date']) . ') i ruoli sono bloccati fino alla fine della partita.');
        }
        [$pos, $pos2] = [$p['position'], $p['position2']];
    }
    $foot = in_array($_POST['foot'] ?? '', feet(), true) ? $_POST['foot'] : 'Destro';
    if ($name === '' || mb_strlen($name) > 80) {
        $errors[] = 'Inserisci un nome (max 80 caratteri).';
    }
    if ($num !== '' && (!ctype_digit($num) || (int) $num > 99)) {
        $errors[] = 'Il numero di maglia va da 0 a 99.';
    }

    // sfondo del profilo: automatico (colore del ruolo), un colore oppure un'immagine
    $bgMode = in_array($_POST['bg_mode'] ?? 'auto', ['color', 'image'], true) ? $_POST['bg_mode'] : 'auto';
    $bgColor = clean_hex_color($_POST['bg_color'] ?? '');
    if ($bgMode === 'color' && !$bgColor) {
        $errors[] = 'Colore dello sfondo non valido.';
    }

    // account (username/password): l'admin li gestisce per tutti, il giocatore cambia solo la propria password
    $username = trim($_POST['username'] ?? '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $role = clean_role($_POST['role'] ?? 'player');
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
    // leghe (le cambia solo chi le amministra): almeno una; con una sola lega gestibile è automatico.
    // Le leghe del giocatore che chi modifica non amministra restano com'erano.
    $groupIds = array_values(array_intersect(array_map('intval', (array) ($_POST['groups'] ?? [])), array_keys($shownGroups)));
    if ($staff && count($shownGroups) === 1) {
        $groupIds = array_keys($shownGroups);
    }
    $foreign = $isNew ? [] : array_values(array_diff(player_group_ids($id), array_keys($shownGroups)));
    $groupIds = array_values(array_unique(array_merge($foreign, $groupIds)));
    if ($staff && !$groupIds) {
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

        log_activity('giocatore', ($isNew ? 'creato · ' : 'modificato · ') . $name, $groupIds[0] ?? null);
        if ($staff) {
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
        }
        if ($admin) {
            if ($username !== '') {
                if ($account) {
                    q('UPDATE users SET username = ?, role = ? WHERE id = ?', [$username, $role, $account['id']]);
                    if ($password !== '' && !$selfAccount) {
                        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $account['id']]);
                        security_reset_sessions((int) $account['id']);   // i dispositivi di quell'account devono rifare l'accesso
                        notify_password_changed((int) $account['id']);
                    }
                } else {
                    q('INSERT INTO users (username, password_hash, role, player_id) VALUES (?, ?, ?, ?)',
                        [$username, password_hash($password, PASSWORD_DEFAULT), $role, $id]);
                }
            }
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

        // sfondo del profilo: una foto e due ritagli (orizzontale per il profilo, verticale per la card della Rosa)
        $oldFiles = $isNew ? [] : bg_files($p);
        $clearBg = function () use ($id, $oldFiles) {
            foreach ($oldFiles as $f) {
                delete_photo_file($f);
            }
            q('UPDATE players SET bg_image = NULL, bg_image_v = NULL, bg_src = NULL, bg_crop = NULL WHERE id = ?', [$id]);
        };
        if ($bgMode === 'image') {
            $bgErr = null;
            $newFile = ($_FILES['bg_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $recrop = !empty($_POST['bg_recrop']) && !$isNew;   // stessa foto, riquadri cambiati
            if ($newFile || $recrop) {
                // i vecchi sfondi non hanno l'originale: si ritaglia da quello che c'è
                $set = save_profile_bg_set($_FILES['bg_image'] ?? [], $newFile ? null : (($p['bg_src'] ?? null) ?: ($p['bg_image'] ?? null)),
                    is_string($_POST['bg_crop_h'] ?? null) ? $_POST['bg_crop_h'] : null,
                    is_string($_POST['bg_crop_v'] ?? null) ? $_POST['bg_crop_v'] : null, $id, $bgErr);
                if ($set) {
                    q('UPDATE players SET bg_image = ?, bg_image_v = ?, bg_src = ?, bg_crop = ?, bg_color = NULL, bg_preset = NULL WHERE id = ?',
                        [$set['h'], $set['v'], $set['src'], $set['crop'], $id]);
                    foreach (array_diff($oldFiles, [$set['h'], $set['v'], $set['src']]) as $f) {
                        delete_photo_file($f);
                    }
                } elseif ($bgErr) {
                    flash('err', $bgErr);
                }
            } elseif (empty($p['bg_image'])) {
                flash('err', "Sfondo non cambiato: scegli un'immagine.");
            }
        } elseif ($bgMode === 'color') {
            $clearBg();
            q('UPDATE players SET bg_color = ?, bg_preset = NULL WHERE id = ?', [$bgColor, $id]);
        } else {
            $clearBg();
            q('UPDATE players SET bg_color = NULL WHERE id = ?', [$id]);
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
        <label class="btn btn-ghost btn-sm file-btn"><i class="ti ti-camera"></i> Scegli foto<input type="file" name="photo" accept="image/*" id="photo-input"
          data-crop-w="480" data-crop-h="480" data-crop-round="1" data-crop-title="Ritaglia la foto profilo" data-preview="photo-preview" data-preview-type="img"></label>
        <p class="muted small">JPG, PNG o WEBP. Dopo averla scelta trascini e ingrandisci per decidere quale parte tenere.</p>
      </div>
    </div>
    <?php
      $bgMode = $_POST['bg_mode'] ?? (!empty($p['bg_image']) ? 'image' : (!empty($p['bg_color']) ? 'color' : 'auto'));
      $bgColorVal = clean_hex_color($_POST['bg_color'] ?? '') ?: (clean_hex_color($p['bg_color'] ?? '') ?: '#53c8f5');
      // immagini attuali (per le anteprime) e foto originale con i riquadri scelti (per poterli cambiare senza ricaricarla)
      $fileUrl = fn(?string $f) => $f && is_file(__DIR__ . '/' . $f) ? $f . '?v=' . filemtime(__DIR__ . '/' . $f) : '';
      $bgImgUrl = $fileUrl($p['bg_image'] ?? null);
      $bgImgVUrl = $fileUrl($p['bg_image_v'] ?? null) ?: $bgImgUrl;
      $bgSrcUrl = $fileUrl(($p['bg_src'] ?? null) ?: ($p['bg_image'] ?? null));
      $bgCrop = json_decode($p['bg_crop'] ?? '', true) ?: [];
      $cropStr = fn(string $k) => isset($bgCrop[$k]) && count($bgCrop[$k]) === 3 ? implode(',', array_map('floatval', $bgCrop[$k])) : '';
    ?>
    <fieldset class="group-box bg-box" data-bg data-image="<?= h($bgImgUrl) ?>" data-image-v="<?= h($bgImgVUrl) ?>" data-src="<?= h($bgSrcUrl) ?>">
      <legend>Sfondo del profilo</legend>
      <?php if ($id && my_player_id() === $id): ?><p class="muted small">Sfondi speciali, nickname e copricapi si comprano nel <a class="link" href="shop.php">Negozio</a> con i gettoni delle scommesse<?= !empty($p['bg_preset']) ? '. Ora hai uno sfondo speciale: scegliendo qui un colore o un\'immagine lo sostituisci, per toglierlo usa il Negozio' : '' ?>.</p><?php endif; ?>
      <div class="bg-layout">
        <div class="bg-previews">
          <figure><div id="bg-preview" class="bg-preview role-<?= strtolower(position_abbr($p['position'])) ?>" aria-hidden="true"></div><figcaption>Profilo</figcaption></figure>
          <figure><div id="bg-preview-v" class="bg-preview bg-preview-v role-<?= strtolower(position_abbr($p['position'])) ?>" aria-hidden="true"></div><figcaption>Card nella Rosa</figcaption></figure>
        </div>
        <div class="bg-controls">
          <div class="group-checks">
            <label><input type="radio" name="bg_mode" value="auto" <?= $bgMode === 'auto' ? 'checked' : '' ?>> Automatico (colore del ruolo)</label>
            <label><input type="radio" name="bg_mode" value="color" <?= $bgMode === 'color' ? 'checked' : '' ?>> Colore</label>
            <label><input type="radio" name="bg_mode" value="image" <?= $bgMode === 'image' ? 'checked' : '' ?>> Immagine</label>
          </div>
          <div class="bg-panel" data-bg-panel="color">
            <div class="swatches">
              <?php foreach (['#53c8f5', '#38d178', '#ffd23f', '#ff6b9a', '#a67cf2', '#ff8c42', '#ff5a5f', '#1f1a2e'] as $sw): ?>
                <button type="button" class="swatch" data-color="<?= $sw ?>" style="background: <?= $sw ?>" aria-label="Colore <?= $sw ?>"></button>
              <?php endforeach; ?>
              <label class="swatch-custom" title="Un altro colore"><input type="color" name="bg_color" value="<?= h($bgColorVal) ?>" aria-label="Scegli un colore"></label>
            </div>
          </div>
          <div class="bg-panel" data-bg-panel="image">
            <div class="btn-row">
              <label class="btn btn-ghost btn-sm file-btn"><i class="ti ti-photo"></i> <?= $bgSrcUrl ? 'Cambia immagine' : 'Scegli immagine' ?><input type="file" name="bg_image" accept="image/*" data-bg-editor data-max-bytes="<?= upload_max_bytes() ?>"></label>
              <button type="button" class="btn btn-ghost btn-sm bg-recrop" data-bg-recrop <?= $bgSrcUrl ? '' : 'hidden' ?>><i class="ti ti-crop"></i> Cambia ritaglio</button>
            </div>
            <input type="hidden" name="bg_crop_h" value="<?= h($cropStr('h')) ?>"><input type="hidden" name="bg_crop_v" value="<?= h($cropStr('v')) ?>">
            <input type="hidden" name="bg_recrop" value="0">
            <p class="muted small">Dalla stessa foto scegli due parti: quella <strong>orizzontale</strong> per il tuo profilo e quella <strong>verticale</strong> per la tua card nella Rosa (e per il profilo sul telefono). Sposti ogni riquadro per scegliere la zona e lo allarghi o stringi per lo zoom.</p>
          </div>
        </div>
      </div>
    </fieldset>
    <div class="form-grid">
      <label class="field span-2"><span>Nome</span><input name="name" required maxlength="80" value="<?= h($p['name']) ?>"></label>
      <label class="field"><span>Numero di maglia</span><input type="number" name="shirt_number" min="0" max="99" value="<?= h($p['shirt_number']) ?>"></label>
      <?php if ($roleLock): ?>
        <p class="flash <?= $roleLocked ? 'flash-err' : '' ?> span-2 small"><i class="ti ti-lock"></i>
          <?= $roleLocked ? 'Ruolo bloccato' : 'Ruolo bloccato per il giocatore (tu puoi comunque correggerlo)' ?>: le scommesse sulla partita di <?= h(push_when($roleLock['match_date'])) ?> sono aperte,
          e cambiare ruolo cambierebbe le quote. Si sblocca a partita finita.</p>
      <?php endif; ?>
      <label class="field"><span>Posizione preferita</span><select name="position" <?= $roleLocked ? 'disabled' : '' ?>>
        <?php foreach (main_positions() as $o): ?><option <?= $p['position'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Seconda posizione (facoltativa; Jolly = si adatta a tutto)</span><select name="position2" <?= $roleLocked ? 'disabled' : '' ?>>
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
        <?php if (!$selfAccount): ?><label class="field"><span><?= $account ? 'Nuova password (vuoto = invariata)' : 'Password' ?></span><input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password"></label><?php endif; ?>
        <label class="field"><span>Ruolo</span><select name="role">
          <option value="player" <?= !in_array($account['role'] ?? '', ['admin', 'manager'], true) ? 'selected' : '' ?>>Giocatore</option>
          <option value="manager" <?= ($account['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Manager (gestisce le partite)</option>
          <option value="admin" <?= ($account['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option></select></label>
      </div>
      <p class="muted small">Con l'account il giocatore può confermare le presenze, votare e modificare il proprio profilo.<?= $selfAccount ? ' La tua password e la tua email si cambiano da «Sicurezza» qui sotto.' : '' ?></p>
    <?php elseif ($account): ?>
      <p class="muted">Username: <strong><?= h($account['username']) ?></strong></p>
      <p class="muted small">La password e l'email si cambiano da «Sicurezza» (sotto il modulo).</p>
    <?php endif; ?>
  </section>

  <?php if ($staff): ?>
  <section class="card">
    <h2><?= $admin ? 'Solo admin' : 'Gestione della lega' ?></h2>
    <?php $allGroups = $shownGroups; ?>
      <?php $checked = $errors && isset($_POST['groups']) ? array_map('intval', (array) $_POST['groups']) : ($isNew ? [group_filter() ?: (int) array_key_first($allGroups)] : player_group_ids($id)); ?>
      <fieldset class="group-box"><legend>Gruppi</legend>
        <div class="group-checks">
          <?php foreach ($allGroups as $gid => $gname): ?>
            <label><input type="checkbox" name="groups[]" value="<?= $gid ?>" <?= in_array($gid, $checked, true) || count($allGroups) === 1 ? 'checked' : '' ?> <?= count($allGroups) === 1 ? 'disabled' : '' ?>> <?= h($gname) ?></label>
          <?php endforeach; ?>
        </div>
        <p class="muted small">Il giocatore vede solo giocatori e partite dei suoi gruppi e può partecipare solo alle partite di quei gruppi.
          <?= count($allGroups) === 1 && $admin ? 'Esiste un solo gruppo: creane un altro da <a class="link" href="admin.php#gruppi">Admin → Gruppi</a> per poter scegliere.' : '' ?></p>
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

<?php if (!$isNew && my_player_id() === $id): ?>
  <?= security_card() ?>
  <?= push_card() ?>
<?php endif; ?>

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
