<?php
require __DIR__ . '/lib/bootstrap.php';
require_admin();
require_recent_auth();   // password riconfermata se l'area admin non si usa da un po' (lib/security.php)

$meUid = (int) current_user()['id'];
// questa pagina gestisce le leghe "di casa" (storiche e quelle di cui l'admin fa parte): le leghe create dagli altri utenti
// si guardano da platform.php e si gestiscono da league.php, così qui non si mescolano account e giocatori di sconosciuti
$homeGroups = admin_groups();
$homeIn = $homeGroups ? implode(',', array_map('intval', array_keys($homeGroups))) : '0';
$legacyIn = implode(',', array_map('intval', legacy_group_ids() ?: [0]));
// account "di casa": giocatore in una lega di casa, oppure in nessuna lega (o senza giocatore)
$homeUserSql = "(u.player_id IS NULL OR NOT EXISTS (SELECT 1 FROM player_groups x WHERE x.player_id = u.player_id)
                 OR EXISTS (SELECT 1 FROM player_groups x WHERE x.player_id = u.player_id AND x.group_id IN ($homeIn)))";

if (is_post()) {
    $do = $_POST['do'] ?? '';
    $uid = (int) ($_POST['user_id'] ?? 0);
    if ($do !== 'mail_test') {
        log_activity('admin', $do . ($uid ? ' · account #' . $uid : '') . (!empty($_POST['group_id']) ? ' · lega #' . (int) $_POST['group_id'] : ''));
    }
    switch ($do) {
        case 'approve':
            $link = (int) ($_POST['player_id'] ?? 0);
            $groupIds = array_values(array_intersect(array_map('intval', (array) ($_POST['groups'] ?? [])), array_keys($homeGroups)));
            if (count($homeGroups) === 1) {
                $groupIds = array_keys($homeGroups);
            }
            if (!$groupIds) {
                flash('err', "Scegli almeno un gruppo per approvare l'iscrizione.");
                break;
            }
            $res = approve_registration($uid, $groupIds, $link);   // collega o crea il giocatore, lo mette nei gruppi e lo avvisa
            if ($res) {
                flash('ok', 'Iscrizione di ' . $res[0] . ' approvata.' . ($res[1] ? ' Gli abbiamo mandato anche un\'email.' : ''));
            }
            break;
        case 'group_add':
        case 'group_rename':
            $gid = (int) ($_POST['group_id'] ?? 0);
            $gname = trim((string) ($_POST['name'] ?? ''));
            if ($gname === '' || mb_strlen($gname) > 40) {
                flash('err', 'Il nome del gruppo deve avere da 1 a 40 caratteri.');
            } elseif (q("SELECT 1 FROM squad_groups WHERE LOWER(name) = ? AND id <> ? AND id IN ($homeIn)", [mb_strtolower($gname), $do === 'group_rename' ? $gid : 0])->fetch()) {
                flash('err', 'Esiste già un gruppo con questo nome.');
            } elseif ($do === 'group_add') {
                q('INSERT INTO squad_groups (name) VALUES (?)', [$gname]);
                groups_cache(null, null, true);
                flash('ok', "Gruppo \"$gname\" creato: assegna i giocatori dalla loro scheda (Modifica) o all'approvazione delle iscrizioni.");
            } elseif (isset($homeGroups[$gid])) {
                q('UPDATE squad_groups SET name = ? WHERE id = ?', [$gname, $gid]);
                groups_cache(null, null, true);
                flash('ok', 'Gruppo rinominato.');
            }
            break;
        case 'group_members':
            // tabella "giocatori x gruppi": salva i gruppi di ogni giocatore mostrato (ne serve almeno uno);
            // le leghe degli altri utenti in cui il giocatore eventualmente sta non si toccano
            $validGroups = array_keys($homeGroups);
            $changed = 0;
            $kept = [];
            foreach ((array) ($_POST['pids'] ?? []) as $pidRaw) {
                $pid = (int) $pidRaw;
                $pn = q('SELECT name FROM players WHERE id = ?', [$pid])->fetchColumn();
                if ($pn === false) {
                    continue;
                }
                $have = player_group_ids($pid);
                $foreign = array_values(array_diff($have, $validGroups));
                $mine = array_values(array_intersect(array_map('intval', (array) ($_POST['pg'][$pid] ?? [])), $validGroups));
                $want = array_values(array_unique(array_merge($foreign, $mine)));
                sort($want);
                sort($have);
                if (!$mine && !$foreign) {
                    $kept[] = $pn;          // senza gruppo non vedrebbe nulla: resta com'e'
                } elseif ($want !== $have) {
                    set_player_groups($pid, $want);
                    $changed++;
                }
            }
            if ($kept) {
                flash('err', 'Ogni giocatore deve stare in almeno un gruppo: non modificati ' . implode(', ', array_slice($kept, 0, 6)) . (count($kept) > 6 ? ' e altri' : '') . '.');
            }
            flash('ok', $changed ? "Gruppi aggiornati per $changed giocatori." : 'Nessuna modifica ai gruppi.');
            break;
        case 'group_delete':
            $gid = (int) ($_POST['group_id'] ?? 0);
            $onlyHere = (int) q('SELECT COUNT(*) FROM player_groups pg WHERE pg.group_id = ? AND NOT EXISTS
                (SELECT 1 FROM player_groups o WHERE o.player_id = pg.player_id AND o.group_id <> pg.group_id)', [$gid])->fetchColumn();
            if (!isset($homeGroups[$gid])) {
                break;
            }
            if (count($homeGroups) <= 1) {
                flash('err', 'Deve esistere almeno un gruppo.');
            } elseif ((int) q('SELECT COUNT(*) FROM matches WHERE group_id = ?', [$gid])->fetchColumn() > 0) {
                flash('err', 'Il gruppo ha delle partite: non si può eliminare (rinominalo, se serve).');
            } elseif ($onlyHere > 0) {
                flash('err', "$onlyHere giocatori fanno parte solo di questo gruppo: assegnali prima a un altro gruppo.");
            } else {
                q('DELETE FROM squad_groups WHERE id = ?', [$gid]);
                groups_cache(null, null, true);
                flash('ok', 'Gruppo eliminato.');
            }
            break;
        case 'reject':
            q("DELETE FROM users WHERE id = ? AND status = 'in_attesa'", [$uid]);
            flash('ok', 'Richiesta rifiutata.');
            break;
        case 'create':
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = clean_role($_POST['role'] ?? '');
            $pid = (int) ($_POST['player_id'] ?? 0) ?: null;
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
                flash('err', 'Username: 3-50 caratteri tra lettere, numeri, punto, trattino e underscore.');
            } elseif ($err = password_error($password)) {
                flash('err', $err);
            } elseif (q('SELECT 1 FROM users WHERE LOWER(username) = ?', [mb_strtolower($username)])->fetch()) {
                flash('err', 'Username già usato.');
            } elseif ($pid && q('SELECT 1 FROM users WHERE player_id = ?', [$pid])->fetch()) {
                flash('err', 'Quel giocatore ha già un account.');
            } else {
                q('INSERT INTO users (username, password_hash, role, player_id) VALUES (?, ?, ?, ?)',
                    [$username, password_hash($password, PASSWORD_DEFAULT), $role, $pid]);
                flash('ok', "Account \"$username\" creato.");
            }
            break;
        case 'mail_test':
            $to = mb_strtolower(trim((string) ($_POST['to'] ?? '')));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                flash('err', 'Scrivi un indirizzo email valido per la prova.');
            } elseif (send_mail($to, 'Email di prova · ' . APP_NAME, mail_text('admin', ['Questa è una email di prova: se la leggi, il sito riesce a mandare posta e il recupero password funzionerà.']))) {
                flash('ok', 'Email di prova accettata dal server di posta e diretta a ' . $to . '. Controlla la posta, anche lo spam: se non arriva, il recupero password non è affidabile.');
            } else {
                flash('err', 'Il server di posta ha rifiutato l\'invio: su questo hosting le email non partono.');
            }
            break;
        case 'reset':
            $password = $_POST['password'] ?? '';
            if ($err = password_error($password)) {
                flash('err', $err);
            } else {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $uid]);
                security_reset_sessions($uid);   // con la nuova password i dispositivi già collegati devono rifare l'accesso
                notify_password_changed($uid);   // e, se ha un'email confermata, lo sa
                flash('ok', 'Password aggiornata.');
            }
            break;
        case 'role':
            if ($uid === $meUid) {
                flash('err', 'Non puoi cambiare il tuo ruolo.');
            } elseif (q("SELECT 1 FROM users WHERE id = ? AND role = 'ospite'", [$uid])->fetch()) {
                flash('err', 'Un ospite resta ospite: togli l\'ospite dalla sua partita e, se serve, crea un account vero.');
            } else {
                q('UPDATE users SET role = ? WHERE id = ?', [clean_role($_POST['role'] ?? ''), $uid]);
                flash('ok', 'Ruolo aggiornato.');
            }
            break;
        case 'link':
            $pid = (int) ($_POST['player_id'] ?? 0) ?: null;
            if (q("SELECT 1 FROM users WHERE id = ? AND role = 'ospite'", [$uid])->fetch()) {
                flash('err', 'Un ospite non si collega a un giocatore della rosa.');
            } elseif ($pid && q('SELECT 1 FROM users WHERE player_id = ? AND id <> ?', [$pid, $uid])->fetch()) {
                flash('err', 'Quel giocatore ha già un account.');
            } else {
                q('UPDATE users SET player_id = ? WHERE id = ?', [$pid, $uid]);
                flash('ok', 'Collegamento aggiornato.');
            }
            break;
        case 'delete':
            if ($uid === $meUid) {
                flash('err', 'Non puoi eliminare il tuo account.');
            } elseif (q('SELECT 1 FROM squad_groups WHERE owner_user_id = ?', [$uid])->fetch()) {
                flash('err', 'L\'account possiede una lega: prima cedila a qualcun altro (o eliminala) da Piattaforma → Leghe → Gestisci.');
            } else {
                q('DELETE FROM users WHERE id = ?', [$uid]);
                flash('ok', 'Account eliminato (il giocatore e le sue statistiche restano).');
            }
            break;
    }
    redirect('admin.php');
}

$users = q("SELECT u.*, p.name AS player_name, p.position, p.position2, p.foot, p.shirt_number
            FROM users u LEFT JOIN players p ON p.id = u.player_id
            WHERE u.status = 'attivo' AND $homeUserSql ORDER BY u.role, u.username")->fetchAll();
// iscrizioni da approvare qui: quelle senza lega (register.php senza invito) e quelle per le leghe storiche
$pendingUsers = q("SELECT * FROM users WHERE status = 'in_attesa' AND (reg_group_id IS NULL OR reg_group_id IN ($legacyIn)) ORDER BY created_at")->fetchAll();
$players = all_players();
$groupList = $homeGroups;
$groupStats = [];
foreach (q("SELECT g.id, (SELECT COUNT(*) FROM player_groups pg WHERE pg.group_id = g.id) AS n_players,
                   (SELECT COUNT(*) FROM matches m WHERE m.group_id = g.id) AS n_matches FROM squad_groups g WHERE g.id IN ($homeIn)")->fetchAll() as $r) {
    $groupStats[(int) $r['id']] = $r;
}
$playerGroups = [];
foreach (q('SELECT player_id, group_id FROM player_groups')->fetchAll() as $r) {
    $playerGroups[(int) $r['player_id']][] = (int) $r['group_id'];
}
$allPlayers = q("SELECT id, name, position, active FROM players p WHERE is_guest = 0
                  AND (NOT EXISTS (SELECT 1 FROM player_groups x WHERE x.player_id = p.id) OR EXISTS (SELECT 1 FROM player_groups x WHERE x.player_id = p.id AND x.group_id IN ($homeIn)))
                  ORDER BY name")->fetchAll();
$membersOf = [];   // gruppo => [giocatori]
foreach ($allPlayers as $ap) {
    foreach ($playerGroups[(int) $ap['id']] ?? [] as $gid) {
        $membersOf[$gid][] = $ap;
    }
}
$free = free_roster_players(array_keys($homeGroups), true);

// notifiche push: chi le ha attive (almeno un dispositivo) e chi no
$pushAccounts = q("SELECT u.id, u.username, p.name AS player_name, (SELECT COUNT(*) FROM push_subscriptions s WHERE s.user_id = u.id) AS n
                   FROM users u LEFT JOIN players p ON p.id = u.player_id WHERE u.status = 'attivo' AND u.role <> 'ospite' AND $homeUserSql ORDER BY p.name, u.username")->fetchAll();
$pushOn = array_filter($pushAccounts, fn($a) => (int) $a['n'] > 0);
$pushOff = array_filter($pushAccounts, fn($a) => (int) $a['n'] === 0);
$cronUrl = site_base_url() . 'cron.php?key=' . push_cron_key();
// registro di consegna: com'è andata alle ultime notifiche (chi non l'ha ricevuta, e perché)
$pushRecent = q("SELECT p.kind, p.status, p.attempts, p.last_error, p.created_at, u.username, pl.name AS player_name
                 FROM push_queue p JOIN users u ON u.id = p.user_id LEFT JOIN players pl ON pl.id = u.player_id
                 ORDER BY p.id DESC LIMIT 50")->fetchAll();
$pushQueued = (int) q("SELECT COUNT(*) FROM push_queue WHERE status = 'in_attesa'")->fetchColumn();
$pushFailed = (int) q("SELECT COUNT(*) FROM push_queue WHERE status = 'fallita' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

layout_start('Admin', 'admin');
?>
<div class="page-head"><h1>Admin</h1></div>

<?php // l'installazione è finita: provo a cancellare install.php io; l'avviso resta solo se il server non me lo lascia fare
if (is_file(__DIR__ . '/install.php') && !@unlink(__DIR__ . '/install.php')): ?>
  <div class="flash flash-err"><i class="ti ti-alert-triangle"></i> <strong>install.php</strong> è ancora sul server: cancellalo.</div>
<?php endif; ?>

<?php if ($pendingUsers): ?>
<section class="card pending-card">
  <h2><i class="ti ti-user-plus"></i> Iscrizioni da approvare <span class="count count-no"><?= count($pendingUsers) ?></span></h2>
  <div class="list">
    <?php foreach ($pendingUsers as $u): $d = json_decode($u['reg_json'] ?? '', true) ?: [];
      [$d['position'], $d['position2']] = normalize_positions($d['position'] ?? null, $d['position2'] ?? null);
      // giocatore in rosa abbinato all'iscrizione: quello trovato all'iscrizione (se è ancora libero),
      // altrimenti si ricerca di nuovo per nome (magari è stato aggiunto in rosa dopo)
      $match = null;
      $wanted = (int) ($d['player_id'] ?? 0);
      foreach ($free as $f) {
          if ($wanted && (int) $f['id'] === $wanted) {
              $match = $wanted;
          }
      }
      $match = $match ?: find_roster_match($u['reg_name'] ?? '', $free);
      $matchName = '';
      foreach ($free as $f) {
          if ($match && (int) $f['id'] === $match) {
              $matchName = $f['name'];
          }
      }
      // il gruppo lo decide l'admin: se è già in rosa si parte dai gruppi di quel giocatore, altrimenti nessuno spuntato
      $reqGroups = $match ? ($playerGroups[$match] ?? []) : []; ?>
      <div class="pending-row">
        <div class="pending-who">
          <?= avatar(['name' => $u['reg_name']], 'md') ?>
          <div><strong><?= h($u['reg_name']) ?></strong> <span class="muted small">@<?= h($u['username']) ?> · iscritto il <?= fmt_date_short($u['created_at']) ?> alle <?= fmt_time($u['created_at']) ?></span>
            <div class="small"><?= h(($d['position'] ?? 'Jolly') . (!empty($d['position2']) ? ' / ' . $d['position2'] : '')) ?>
              · piede <?= h(strtolower($d['foot'] ?? '')) ?><?= isset($d['shirt_number']) ? ' · maglia n. ' . (int) $d['shirt_number'] : ' · nessun numero di maglia' ?></div>
            <div class="small"><?php if ($matchName): ?><i class="ti ti-link"></i> Abbinato in automatico al giocatore in rosa <strong><?= h($matchName) ?></strong><?php else: ?><i class="ti ti-user-plus"></i> Nessun giocatore in rosa con questo nome: verrà aggiunto come nuovo<?php endif; ?></div></div>
        </div>
        <form method="post" class="pending-actions"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <?php if (count($groupList) > 1): ?>
            <div class="group-checks pending-groups" title="Scegli in quale gruppo mettere il giocatore">
              <i class="ti ti-users-group"></i> <strong>Gruppo:</strong>
              <?php foreach ($groupList as $gid => $gname): ?>
                <label><input type="checkbox" name="groups[]" value="<?= $gid ?>" <?= in_array($gid, $reqGroups, true) ? 'checked' : '' ?>> <?= h($gname) ?></label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <select name="player_id" class="mini-select" aria-label="Collega a un giocatore">
            <option value="">Crea nuovo giocatore</option>
            <?php foreach ($free as $f): ?><option value="<?= (int) $f['id'] ?>" <?= $match === (int) $f['id'] ? 'selected' : '' ?>>È già in rosa: <?= h($f['name']) ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-primary btn-sm" name="do" value="approve"><i class="ti ti-check"></i> Approva</button>
          <button class="btn btn-danger btn-sm" name="do" value="reject" data-confirm="Rifiutare la richiesta di <?= h($u['reg_name']) ?>?"><i class="ti ti-x"></i></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if (!REGISTRATION): ?>
  <p class="muted small"><i class="ti ti-lock"></i> Le iscrizioni dei giocatori sono disattivate (<code>REGISTRATION</code> in config.php): gli account li crei tu qui sotto.</p>
<?php else: ?>
  <p class="muted small"><i class="ti ti-link"></i> Per far iscrivere i giocatori manda il link <code><?= h((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/register.php') ?></code>. Ogni iscrizione resta in attesa finché non la approvi qui.</p>
<?php endif; ?>

<div class="admin-links">
  <a class="card admin-link" href="matches.php#nuova"><span><i class="ti ti-calendar-event"></i></span><strong>Crea partita</strong><small>Data, campo, quota</small></a>
  <a class="card admin-link" href="player_edit.php"><span><i class="ti ti-user-plus"></i></span><strong>Aggiungi giocatore</strong><small>Con o senza account</small></a>
  <a class="card admin-link" href="payments.php"><span><i class="ti ti-currency-euro"></i></span><strong>Pagamenti</strong><small>Quote e saldi</small></a>
  <a class="card admin-link" href="players.php?tutti=1"><span><i class="ti ti-chart-bar"></i></span><strong>Statistiche</strong><small>Apri un giocatore → Modifica</small></a>
  <a class="card admin-link" href="platform.php"><span><i class="ti ti-world"></i></span><strong>Piattaforma</strong><small>Tutte le leghe, account e operazioni</small></a>
</div>

<section class="card" id="gruppi">
  <h2><i class="ti ti-users-group"></i> Gruppi</h2>
  <p class="muted small">Ogni partita appartiene a un gruppo. Un giocatore vede solo giocatori e partite dei suoi gruppi (chi è in più gruppi li vede tutti e può filtrare). Tu, come admin, vedi sempre tutti questi gruppi; le leghe create da altri utenti non sono qui ma in <a class="link" href="platform.php?t=leghe">Piattaforma</a>.
    Assegni i giocatori ai gruppi da qui sotto, dalla loro scheda (<em>Modifica</em>) o all'approvazione delle iscrizioni.</p>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Nome</th><th>Giocatori</th><th>Partite</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($groupList as $gid => $gname): $gs = $groupStats[$gid] ?? ['n_players' => 0, 'n_matches' => 0]; ?>
      <tr>
        <td>
          <form method="post" class="inline pw-form"><?= csrf_field() ?><input type="hidden" name="do" value="group_rename"><input type="hidden" name="group_id" value="<?= $gid ?>">
            <input type="text" name="name" value="<?= h($gname) ?>" maxlength="40" required class="mini-input" aria-label="Nome del gruppo">
            <button class="btn btn-ghost btn-sm">Rinomina</button></form>
        </td>
        <td>
          <strong><?= (int) $gs['n_players'] ?></strong>
          <div class="group-members">
            <?php foreach ($membersOf[$gid] ?? [] as $mp): ?>
              <a class="tag tag-member<?= $mp['active'] ? '' : ' is-off' ?>" href="player.php?id=<?= (int) $mp['id'] ?>"><?= h($mp['name']) ?></a>
            <?php endforeach; ?>
            <?php if (empty($membersOf[$gid])): ?><span class="muted small">Nessun giocatore</span><?php endif; ?>
          </div>
        </td>
        <td><?= (int) $gs['n_matches'] ?></td>
        <td>
          <?php if (count($groupList) > 1): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="group_delete"><input type="hidden" name="group_id" value="<?= $gid ?>">
              <button class="icon-btn" title="Elimina il gruppo" data-confirm="Eliminare il gruppo <?= h($gname) ?>?"><i class="ti ti-trash"></i></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <h3>Chi sta in quale gruppo</h3>
  <p class="muted small">Spunta i gruppi di ogni giocatore e premi «Salva gruppi». Un giocatore può stare in più gruppi (li vede tutti); serve almeno un gruppo.
    Se lo togli da un gruppo esce dalle partite <em>programmate</em> di quel gruppo (lo storico resta).</p>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="do" value="group_members">
    <div class="table-wrap"><table class="table table-members">
      <thead><tr><th>Giocatore</th>
        <?php foreach ($groupList as $gname): ?><th class="center"><?= h($gname) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($allPlayers as $ap): $apid = (int) $ap['id']; $mine = $playerGroups[$apid] ?? []; ?>
        <tr<?= $ap['active'] ? '' : ' class="is-off"' ?>>
          <td><input type="hidden" name="pids[]" value="<?= $apid ?>">
            <a href="player.php?id=<?= $apid ?>"><?= h($ap['name']) ?></a><?= $ap['active'] ? '' : ' <span class="muted small">(non attivo)</span>' ?></td>
          <?php foreach ($groupList as $gid => $gname): ?>
            <td class="center"><input type="checkbox" name="pg[<?= $apid ?>][]" value="<?= $gid ?>" <?= in_array($gid, $mine, true) ? 'checked' : '' ?> aria-label="<?= h($ap['name'] . ' - ' . $gname) ?>"></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="btn-row"><button class="btn btn-primary"><i class="ti ti-device-floppy"></i> Salva gruppi</button></div>
  </form>

  <h3>Nuovo gruppo</h3>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?><input type="hidden" name="do" value="group_add">
    <label class="field"><span>Nome (es. YBQ, FANTA)</span><input name="name" required maxlength="40" autocomplete="off"></label>
    <div><button class="btn btn-primary">Crea gruppo</button></div>
  </form>
</section>

<section class="card">
  <h2>Account</h2>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Username</th><th>Dati</th><th>Giocatore collegato</th><th>Ruolo</th><th>Password</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $uid = (int) $u['id']; ?>
      <tr>
        <td><strong><?= h($u['username']) ?></strong><?= $uid === $meUid ? ' <span class="muted small">(tu)</span>' : '' ?></td>
        <td class="small">
          <?php if ($u['player_name']): ?>
            <strong><?= h($u['player_name']) ?></strong><br>
            <?= h(positions_label($u)) ?> · piede <?= h(strtolower($u['foot'] ?? '')) ?><?= $u['shirt_number'] !== null ? ' · n. ' . (int) $u['shirt_number'] : '' ?><br>
          <?php else: ?>
            <span class="muted">nessun giocatore collegato</span><br>
          <?php endif; ?>
          <?php if (count($groupList) > 1 && $u['player_id']): ?>
            <?php foreach ($playerGroups[(int) $u['player_id']] ?? [] as $gid): ?><span class="tag tag-group"><i class="ti ti-users-group"></i> <?= h($groupList[$gid] ?? '?') ?></span> <?php endforeach; ?><br>
          <?php endif; ?>
          <?php if ($u['email']): ?><i class="ti ti-mail-check" title="Email confermata"></i> <?= h($u['email']) ?><br>
          <?php elseif ($u['pending_email']): ?><i class="ti ti-mail-question" title="Email non ancora confermata"></i> <?= h($u['pending_email']) ?> <span class="muted">(da confermare)</span><br>
          <?php else: ?><span class="muted"><i class="ti ti-mail-off"></i> nessuna email</span><br><?php endif; ?>
          <span class="muted">creato il <?= fmt_date_short($u['created_at']) ?></span>
        </td>
        <td>
          <?php if ($u['role'] === 'ospite'): ?><span class="muted small">ospite: solo la sua partita</span><?php else: ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="link"><input type="hidden" name="user_id" value="<?= $uid ?>">
            <select name="player_id" class="mini-select" data-autosubmit>
              <option value="">— nessuno —</option>
              <?php if ($u['player_id']): ?><option value="<?= (int) $u['player_id'] ?>" selected><?= h($u['player_name']) ?></option><?php endif; ?>
              <?php foreach ($free as $f): ?><option value="<?= (int) $f['id'] ?>"><?= h($f['name']) ?></option><?php endforeach; ?>
            </select></form><?php endif; ?>
        </td>
        <td>
          <?php if ($u['role'] === 'ospite'): ?><span class="tag">Ospite</span><?php else: ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="role"><input type="hidden" name="user_id" value="<?= $uid ?>">
            <select name="role" class="mini-select" data-autosubmit <?= $uid === $meUid ? 'disabled' : '' ?>>
              <option value="player" <?= $u['role'] === 'player' ? 'selected' : '' ?>>Giocatore</option>
              <option value="manager" <?= $u['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
              <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select></form><?php endif; ?>
        </td>
        <td>
          <form method="post" class="inline pw-form"><?= csrf_field() ?><input type="hidden" name="do" value="reset"><input type="hidden" name="user_id" value="<?= $uid ?>">
            <input type="text" name="password" placeholder="nuova password" minlength="8" maxlength="72" required class="mini-input" autocomplete="off">
            <button class="btn btn-ghost btn-sm">Imposta</button></form>
        </td>
        <td>
          <?php if ($uid !== $meUid): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="user_id" value="<?= $uid ?>">
              <button class="icon-btn" title="Elimina account" data-confirm="Eliminare l'account <?= h($u['username']) ?>?"><i class="ti ti-trash"></i></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <h3>Nuovo account</h3>
  <form method="post" class="form form-grid form-grid-4">
    <?= csrf_field() ?><input type="hidden" name="do" value="create">
    <label class="field"><span>Username</span><input name="username" required autocomplete="off"></label>
    <label class="field"><span>Password</span><input type="text" name="password" required minlength="8" maxlength="72" autocomplete="off"></label>
    <label class="field"><span>Giocatore</span><select name="player_id">
      <option value="">— nessuno —</option>
      <?php foreach ($free as $f): ?><option value="<?= (int) $f['id'] ?>"><?= h($f['name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Ruolo</span><select name="role"><option value="player">Giocatore</option><option value="manager">Manager</option><option value="admin">Admin</option></select></label>
    <div><button class="btn btn-primary">Crea account</button></div>
  </form>
</section>

<section class="card" id="notifiche">
  <h2><i class="ti ti-bell"></i> Notifiche</h2>
  <?php if (!push_supported()): ?>
    <div class="flash flash-err"><i class="ti ti-alert-triangle"></i> Questo server non può mandare notifiche push (manca l'estensione openssl di PHP con le curve ellittiche).</div>
  <?php endif; ?>
  <p class="muted small">Ogni giocatore le attiva dal proprio telefono (invito in Home, oppure Modifica profilo → Notifiche). Partono quando viene creata una partita, come promemoria
    a chi non ha ancora risposto (a <?= implode(' e ', array_map(fn($h) => $h . ' ore', PUSH_REMINDER_HOURS)) ?> dalla partita, mai di notte) e quando le votazioni si aprono o si chiudono.
    Su iPhone funzionano solo se il sito è stato aggiunto alla schermata Home.</p>
  <p><strong><?= count($pushOn) ?></strong> account su <?= count($pushAccounts) ?> hanno le notifiche attive.</p>
  <?php if ($pushOff): ?>
    <p class="small"><span class="muted">Non ancora attive:</span>
      <?php foreach ($pushOff as $a): ?><span class="tag tag-member"><?= h($a['player_name'] ?: $a['username']) ?></span> <?php endforeach; ?></p>
  <?php endif; ?>
  <?php if ($pushQueued): ?>
    <p class="small"><i class="ti ti-clock"></i> <strong><?= $pushQueued ?></strong> notifiche in coda: verranno riprovate da sole.</p>
  <?php endif; ?>
  <details class="collapsible">
    <summary><strong>Ultime notifiche inviate</strong><?= $pushFailed ? ' <span class="count count-no">' . $pushFailed . ' non riuscite</span>' : '' ?></summary>
    <p class="muted small">Ogni riga è una notifica verso un dispositivo. «Consegnata» vuol dire che Google o Apple l'hanno accettata: se poi non compare sul telefono, il problema è nelle impostazioni di quel telefono. Il registro tiene un mese.</p>
    <?php if (!$pushRecent): ?>
      <p class="muted small">Ancora nessuna notifica inviata.</p>
    <?php else: ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Quando</th><th>A chi</th><th>Tipo</th><th>Esito</th></tr></thead>
        <tbody>
        <?php foreach ($pushRecent as $r): ?>
          <tr>
            <td class="small"><?= h(fmt_date_short($r['created_at'])) ?> <?= h(fmt_time($r['created_at'])) ?></td>
            <td><?= h($r['player_name'] ?: $r['username'] ?: '—') ?></td>
            <td class="small"><?= h($r['kind']) ?></td>
            <td class="small">
              <?php if ($r['status'] === 'consegnata'): ?>
                <i class="ti ti-check"></i> consegnata
              <?php elseif ($r['status'] === 'in_attesa'): ?>
                <i class="ti ti-clock"></i> in coda<?= (int) $r['attempts'] ? ' (tentativi: ' . (int) $r['attempts'] . ')' : '' ?>
              <?php else: ?>
                <i class="ti ti-alert-triangle"></i> <?= h($r['last_error'] ?: 'non riuscita') ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </details>
  <details class="collapsible">
    <summary><strong>Promemoria puntuali (facoltativo)</strong></summary>
    <p class="muted small">I promemoria partono da soli quando qualcuno usa il sito. Per averli puntuali anche quando nessuno lo apre, fai chiamare questo indirizzo ogni 10-15 minuti da un pianificatore (cron del tuo hosting o un servizio gratuito come cron-job.org). Il codice nell'indirizzo è riservato: non condividerlo.</p>
    <p><input type="text" readonly value="<?= h($cronUrl) ?>" class="mini-input" style="width:100%" onclick="this.select()" aria-label="Indirizzo per il cron"></p>
  </details>
</section>

<section class="card" id="email">
  <h2><i class="ti ti-mail"></i> Email</h2>
  <p class="muted small">Ogni giocatore può collegare e confermare la propria email da <em>Modifica profilo → Sicurezza</em>: serve a recuperare la password da solo («Password dimenticata?» nella pagina di accesso) e a ricevere un avviso se la password cambia.
    Su Altervista le email partono con la funzione <code>mail()</code> del server, senza un servizio esterno: possono finire nello spam. Fai la prova qui sotto.</p>
  <p class="small">Mittente: <code><?= h(mail_sender()[0]) ?></code> · indirizzo del sito nei link: <code><?= h(trusted_base_url()) ?></code>
    · account con email confermata: <strong><?= (int) q('SELECT COUNT(*) FROM users WHERE email IS NOT NULL')->fetchColumn() ?></strong> su <?= count($users) ?></p>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?><input type="hidden" name="do" value="mail_test">
    <label class="field"><span>Manda una email di prova a</span><input type="email" name="to" required maxlength="190" placeholder="nome@esempio.it"></label>
    <div><button class="btn btn-ghost btn-sm"><i class="ti ti-send"></i> Invia la prova</button></div>
  </form>
</section>

<section class="card">
  <h2>Come funzionano i calcoli</h2>
  <ul class="howto">
    <li><strong>Voto partita</strong>: a fine partita ogni giocatore vota tutti gli altri (1-10, passi da 0,5). Il voto di un giocatore è la media dei voti ricevuti. Conta nelle statistiche quando chiudi le votazioni.</li>
    <li><strong>MVP</strong>: chi riceve più voti MVP; a parità vince la media voto più alta, poi chi ha segnato di più.</li>
    <li><strong>Rating (OVR)</strong>: rating base dell'admin; con almeno 3 partite votate diventa 50% rating base + 50% media voto (25% con 1-2 partite votate). Aggiunge fino a ±0,5 in base alla % di vittorie.</li>
    <li><strong>Squadre bilanciate</strong>: per ogni divisione possibile (fino a 22 confermati le prova tutte) conta la differenza tra la somma dei <em>rating</em> delle due squadre (rating base + media voto + % vittorie) e poi le <em>posizioni</em>: la 1ª scelta di un giocatore copre un ruolo al 100%, la 2ª al 60%, il Jolly (sempre seconda scelta) al 50% su qualsiasi ruolo. I portieri vengono divisi e ogni squadra deve poter coprire difesa, centrocampo e attacco del suo modulo. Sotto le squadre vedi rating totale e giocatori per ruolo. "Rigenera" propone un'altra divisione quasi equivalente.</li>
    <li><strong>Classifica</strong>: <?= POINTS_WIN ?> punti a vittoria, <?= POINTS_DRAW ?> a pareggio; a parità contano % vittorie e gol.</li>
    <li><strong>Forma</strong>: punti medi nelle ultime 5 partite + andamento del voto rispetto alla media.</li>
    <li><strong>Partecipazione %</strong>: partite giocate / partite totali registrate sul sito.</li>
  </ul>
</section>
<?php
layout_end();
