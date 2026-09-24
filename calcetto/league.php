<?php
/*
 * «La mia lega»: gestione di una lega da parte del suo proprietario e dei suoi admin (e dell'admin del sito).
 * Link d'invito e modo di ingresso, richieste da approvare, membri e ruoli, giocatori senza account, registro delle operazioni.
 * Niente poteri sugli account (password, email, ruoli del sito): restano all'admin del sito (admin.php).
 */
require __DIR__ . '/lib/bootstrap.php';
require_login();

$gid = int_get('id') ?: (int) ($_POST['group_id'] ?? 0);
if (!$gid) {
    $mine = my_owned_leagues();
    if (count($mine) === 1) {
        redirect('league.php?id=' . array_key_first($mine));
    }
    if (!$mine) {
        redirect(is_admin() ? 'platform.php' : (LEAGUE_CREATION ? 'create_league.php' : 'index.php'));
    }
}
$league = $gid ? league_get($gid) : null;
if ($gid && (!$league || !can_admin_group($gid))) {
    security_log('accesso_negato', (string) ($_SERVER['REQUEST_URI'] ?? ''));
    http_response_code(403);
    layout_start('Accesso negato');
    echo '<div class="card"><h2>Accesso negato</h2><p>Questa pagina è riservata a chi gestisce la lega.</p></div>';
    layout_end();
    exit;
}

if (!$league) {   // amministra più leghe: scelta
    layout_start('Le mie leghe', 'league');
    echo '<div class="page-head"><h1>Le mie leghe</h1></div><div class="list">';
    foreach (my_owned_leagues() as $id => $name) {
        $n = league_pending_count($id);
        echo '<a class="card admin-link" href="league.php?id=' . (int) $id . '"><span><i class="ti ti-users-group"></i></span><strong>' . h($name) . '</strong>'
            . '<small>' . ($n ? $n . ' richieste da guardare' : 'Gestisci') . '</small></a>';
    }
    echo '</div>';
    if (LEAGUE_CREATION) {
        echo '<p><a class="btn btn-ghost" href="create_league.php"><i class="ti ti-plus"></i> Crea un\'altra lega</a></p>';
    }
    layout_end();
    exit;
}

$meUid = (int) current_user()['id'];
$myRole = my_league_roles()[$gid] ?? null;
$isOwner = is_admin() || $myRole === 'owner';   // l'admin del sito ha gli stessi poteri del proprietario
$self = 'league.php?id=' . $gid;

/** Account membro della lega (ha un giocatore nella lega)? Ritorna [id, username, nome, ruolo] o null. */
function league_member_account(int $gid, int $uid): ?array
{
    return q('SELECT u.id, u.username, p.name, gr.role FROM users u JOIN players p ON p.id = u.player_id
              JOIN player_groups pg ON pg.player_id = p.id AND pg.group_id = ?
              LEFT JOIN group_roles gr ON gr.group_id = pg.group_id AND gr.user_id = u.id
              WHERE u.id = ? AND u.status = \'attivo\'', [$gid, $uid])->fetch() ?: null;
}

if (is_post()) {
    $do = $_POST['do'] ?? '';
    $uid = (int) ($_POST['user_id'] ?? 0);
    $pid = (int) ($_POST['player_id'] ?? 0);
    switch ($do) {
        case 'rename':
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($err = league_name_error($name)) {
                flash('err', $err);
            } else {
                q('UPDATE squad_groups SET name = ? WHERE id = ?', [$name, $gid]);
                groups_cache(null, null, true);
                log_activity('lega_rinominata', $league['name'] . ' → ' . $name, $gid);
                flash('ok', 'Nome della lega aggiornato.');
            }
            break;

        case 'invite_regen':
            q('UPDATE squad_groups SET invite_code = ? WHERE id = ?', [league_new_code(), $gid]);
            log_activity('lega_invito', 'nuovo link', $gid);
            flash('ok', 'Nuovo link d\'invito creato: quello vecchio non funziona più.');
            break;

        case 'join_mode':
            $mode = ($_POST['join_mode'] ?? '') === 'libero' ? 'libero' : 'approvazione';
            q('UPDATE squad_groups SET join_mode = ? WHERE id = ?', [$mode, $gid]);
            log_activity('lega_modo', $mode, $gid);
            flash('ok', $mode === 'libero' ? 'Ora chi ha il link entra subito.' : 'Ora chi ha il link chiede di entrare e un admin approva.');
            break;

        case 'reg_approve':
            if (!q("SELECT 1 FROM users WHERE id = ? AND status = 'in_attesa' AND reg_group_id = ?", [$uid, $gid])->fetch()) {
                break;
            }
            $free = array_map('intval', array_column(free_roster_players([$gid]), 'id'));
            $res = approve_registration($uid, [$gid], in_array($pid, $free, true) ? $pid : 0);
            if ($res) {
                flash('ok', 'Iscrizione di ' . $res[0] . ' approvata.' . ($res[1] ? ' Gli abbiamo mandato anche un\'email.' : ''));
            }
            break;

        case 'reg_reject':
            $name = q("SELECT reg_name FROM users WHERE id = ? AND status = 'in_attesa' AND reg_group_id = ?", [$uid, $gid])->fetchColumn();
            if ($name !== false) {
                q("DELETE FROM users WHERE id = ? AND status = 'in_attesa' AND reg_group_id = ?", [$uid, $gid]);
                log_activity('iscrizione_rifiutata', (string) $name, $gid);
                flash('ok', 'Richiesta rifiutata.');
            }
            break;

        case 'req_accept':
            if (league_accept_request($gid, $uid)) {
                log_activity('lega_accettato', (string) q('SELECT username FROM users WHERE id = ?', [$uid])->fetchColumn(), $gid);
                flash('ok', 'Richiesta accettata: ora fa parte della lega.');
            }
            break;

        case 'req_reject':
            if (q('DELETE FROM group_requests WHERE group_id = ? AND user_id = ?', [$gid, $uid])->rowCount()) {
                log_activity('lega_rifiutato', (string) q('SELECT username FROM users WHERE id = ?', [$uid])->fetchColumn(), $gid);
                flash('ok', 'Richiesta rifiutata.');
            }
            break;

        case 'set_role':
            $m = league_member_account($gid, $uid);
            $role = in_array($_POST['role'] ?? '', ['admin', 'manager'], true) ? $_POST['role'] : 'player';
            if (!$m) {
                flash('err', 'Account non trovato nella lega.');
            } elseif ($m['role'] === 'owner') {
                flash('err', 'Il proprietario resta proprietario: per cambiarlo usa «Cedi la lega».');
            } elseif (!$isOwner && ($role === 'admin' || $m['role'] === 'admin')) {
                flash('err', 'Solo il proprietario nomina o toglie gli admin della lega.');
            } else {
                if ($role === 'player') {
                    q('DELETE FROM group_roles WHERE group_id = ? AND user_id = ?', [$gid, $uid]);
                } else {
                    q('INSERT INTO group_roles (group_id, user_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)', [$gid, $uid, $role]);
                }
                log_activity('lega_ruolo', $m['name'] . ': ' . (LEAGUE_ROLES[$role] ?? 'Giocatore'), $gid);
                flash('ok', 'Ruolo di ' . $m['name'] . ' aggiornato.');
            }
            break;

        case 'transfer':
            require_recent_auth(RECENT_AUTH_DANGER, false);   // cedere la lega: password confermata negli ultimi minuti
            $m = league_member_account($gid, $uid);
            if (!$isOwner) {
                flash('err', 'Solo il proprietario può cedere la lega.');
            } elseif (!$m || $m['role'] === 'owner') {
                flash('err', 'Scegli un altro membro della lega con un account.');
            } elseif (!is_admin() && leagues_owned_count($uid) >= LEAGUE_MAX_PER_USER) {
                flash('err', $m['name'] . ' ha già ' . LEAGUE_MAX_PER_USER . ' leghe: non può riceverne un\'altra.');
            } else {
                $oldOwner = (int) $league['owner_user_id'];
                q('UPDATE squad_groups SET owner_user_id = ? WHERE id = ?', [$uid, $gid]);
                if ($oldOwner) {
                    q("UPDATE group_roles SET role = 'admin' WHERE group_id = ? AND user_id = ?", [$gid, $oldOwner]);   // il vecchio proprietario resta admin
                }
                q("INSERT INTO group_roles (group_id, user_id, role) VALUES (?, ?, 'owner') ON DUPLICATE KEY UPDATE role = 'owner'", [$gid, $uid]);
                groups_cache(null, null, true);
                log_activity('lega_ceduta', 'a ' . $m['name'], $gid);
                flash('ok', 'La lega ora è di ' . $m['name'] . '.' . ($oldOwner === $meUid ? ' Tu resti admin.' : ''));
            }
            break;

        case 'remove_member':
            $row = q('SELECT p.name, u.id AS uid, gr.role FROM players p JOIN player_groups pg ON pg.player_id = p.id AND pg.group_id = ?
                      LEFT JOIN users u ON u.player_id = p.id LEFT JOIN group_roles gr ON gr.group_id = pg.group_id AND gr.user_id = u.id
                      WHERE p.id = ?', [$gid, $pid])->fetch();
            if (!$row) {
                break;
            }
            if ($row['role'] === 'owner') {
                flash('err', 'Il proprietario non si può togliere dalla lega (prima cedila a qualcun altro).');
            } elseif ($row['role'] === 'admin' && !$isOwner) {
                flash('err', 'Solo il proprietario toglie un admin dalla lega.');
            } else {
                league_remove_player($pid, $gid);
                log_activity('lega_rimosso', (string) $row['name'], $gid);
                flash('ok', $row['name'] . ' non fa più parte della lega (le sue partite giocate restano nello storico).');
            }
            break;

        case 'add_player':
            $name = trim((string) ($_POST['name'] ?? ''));
            [$pos1, $pos2] = normalize_positions(is_string($_POST['position'] ?? null) ? $_POST['position'] : null, null);
            if ($name === '' || mb_strlen($name) > 80) {
                flash('err', 'Scrivi il nome del giocatore (max 80 caratteri).');
            } else {
                q('INSERT INTO players (name, position) VALUES (?, ?)', [$name, $pos1]);
                league_add_player((int) db()->lastInsertId(), $gid);
                log_activity('lega_giocatore', $name, $gid);
                flash('ok', $name . ' aggiunto alla rosa. Se un giorno si iscrive con il link e lo stesso nome, verrà collegato a questo giocatore.');
            }
            break;

        case 'delete_league':
            require_recent_auth(RECENT_AUTH_DANGER, false);
            if (!$isOwner) {
                flash('err', 'Solo il proprietario può eliminare la lega.');
            } elseif (mb_strtolower(trim((string) ($_POST['confirm'] ?? ''))) !== mb_strtolower($league['name'])) {
                flash('err', 'Per eliminare la lega scrivi esattamente il suo nome.');
            } elseif ($err = league_delete($gid)) {
                flash('err', $err);
            } else {
                flash('ok', 'Lega eliminata.');
                redirect(is_admin() ? 'platform.php' : 'index.php');
            }
            break;
    }
    redirect($self);
}

/* ---------------------------------------------------------------- dati */
$pending = league_pending($gid);
$freeInLeague = free_roster_players([$gid]);
$members = q("SELECT p.id, p.name, p.photo, p.position, p.position2, p.active, u.id AS uid, u.username, u.email, u.last_seen_at, gr.role
              FROM player_groups pg JOIN players p ON p.id = pg.player_id
              LEFT JOIN users u ON u.player_id = p.id AND u.status = 'attivo'
              LEFT JOIN group_roles gr ON gr.group_id = pg.group_id AND gr.user_id = u.id
              WHERE pg.group_id = ? AND p.is_guest = 0
              ORDER BY FIELD(COALESCE(gr.role, 'z'), 'owner', 'admin', 'manager', 'z'), p.name", [$gid])->fetchAll();
$counts = q("SELECT COUNT(*) AS n, SUM(status = 'giocata') AS played, SUM(status = 'programmata' AND match_date > NOW()) AS upcoming
             FROM matches WHERE group_id = ?", [$gid])->fetch();
$log = q('SELECT a.*, u.username, p.name AS player_name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN players p ON p.id = u.player_id
          WHERE a.group_id = ? ORDER BY a.id DESC LIMIT 40', [$gid])->fetchAll();
$labels = activity_labels();
$inviteUrl = league_invite_url($gid);
$league = league_get($gid);
$withAccount = array_filter($members, fn($m) => $m['uid']);

layout_start($league['name'] . ' · gestione', 'league');
?>
<div class="page-head">
  <h1><?= h($league['name']) ?> <span class="muted small"><?= $league['owner_user_id'] ? 'la tua lega' : 'lega storica' ?></span></h1>
  <div class="btn-row">
    <form method="post" action="group.php" class="inline"><?= csrf_field() ?><input type="hidden" name="g" value="<?= $gid ?>"><input type="hidden" name="back" value="index.php">
      <button class="btn btn-ghost btn-sm"><i class="ti ti-eye"></i> Apri la lega</button></form>
  </div>
</div>

<div class="admin-links">
  <a class="card admin-link" href="matches.php#nuova"><span><i class="ti ti-calendar-event"></i></span><strong>Crea partita</strong><small><?= (int) $counts['upcoming'] ?> in programma · <?= (int) $counts['played'] ?> giocate</small></a>
  <a class="card admin-link" href="payments.php"><span><i class="ti ti-currency-euro"></i></span><strong>Pagamenti</strong><small>Quote e saldi</small></a>
  <a class="card admin-link" href="player_edit.php"><span><i class="ti ti-user-plus"></i></span><strong>Nuovo giocatore</strong><small>Scheda completa</small></a>
  <a class="card admin-link" href="#membri"><span><i class="ti ti-users"></i></span><strong><?= count($members) ?> giocatori</strong><small><?= count($withAccount) ?> con account</small></a>
</div>

<section class="card" id="invito">
  <h2><i class="ti ti-link"></i> Invita i giocatori</h2>
  <p class="muted small">Manda questo link nel gruppo WhatsApp della squadra: chi lo apre crea il suo account (o entra con quello che ha) e
    <?= $league['join_mode'] === 'libero' ? '<strong>entra subito</strong> nella lega.' : '<strong>chiede di entrare</strong>: lo approvi qui sotto.' ?></p>
  <div class="invite-row">
    <input type="text" readonly value="<?= h($inviteUrl) ?>" class="mini-input invite-url" id="invite-url" aria-label="Link d'invito" onclick="this.select()">
    <button type="button" class="btn btn-primary btn-sm" data-copy="#invite-url"><i class="ti ti-copy"></i> Copia</button>
    <a class="btn btn-ghost btn-sm" href="https://wa.me/?text=<?= h(rawurlencode('Entra nella lega «' . $league['name'] . '» per il calcetto: ' . $inviteUrl)) ?>" target="_blank" rel="noopener noreferrer"><i class="ti ti-brand-whatsapp"></i> WhatsApp</a>
  </div>
  <div class="btn-row">
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="join_mode"><input type="hidden" name="group_id" value="<?= $gid ?>">
      <select name="join_mode" class="mini-select" data-autosubmit aria-label="Chi può entrare">
        <option value="approvazione" <?= $league['join_mode'] !== 'libero' ? 'selected' : '' ?>>Chi ha il link chiede, un admin approva</option>
        <option value="libero" <?= $league['join_mode'] === 'libero' ? 'selected' : '' ?>>Chi ha il link entra subito</option>
      </select></form>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="invite_regen"><input type="hidden" name="group_id" value="<?= $gid ?>">
      <button class="btn btn-ghost btn-sm" data-confirm="Creare un nuovo link? Quello vecchio smette di funzionare."><i class="ti ti-refresh"></i> Nuovo link</button></form>
  </div>
</section>

<?php $nPending = count($pending['registrations']) + count($pending['requests']); ?>
<section class="card pending-card" id="richieste">
  <h2><i class="ti ti-user-plus"></i> Richieste <?php if ($nPending): ?><span class="count count-no"><?= $nPending ?></span><?php endif; ?></h2>
  <?php if (!$nPending): ?><p class="empty">Nessuna richiesta in attesa.</p><?php endif; ?>
  <div class="list">
    <?php foreach ($pending['registrations'] as $r): $d = json_decode($r['reg_json'] ?? '', true) ?: [];
        $match = (int) ($d['player_id'] ?? 0);
        $match = in_array($match, array_map('intval', array_column($freeInLeague, 'id')), true) ? $match : (find_roster_match($r['reg_name'] ?? '', $freeInLeague) ?? 0); ?>
      <div class="pending-row">
        <div class="pending-who"><?= avatar(['name' => $r['reg_name']], 'md') ?>
          <div><strong><?= h($r['reg_name']) ?></strong> <span class="muted small">@<?= h($r['username']) ?> · nuovo account · <?= fmt_date_short($r['created_at']) ?> <?= fmt_time($r['created_at']) ?></span>
            <div class="small"><?= h(($d['position'] ?? 'Centrocampista') . (!empty($d['position2']) ? ' / ' . $d['position2'] : '')) ?><?= isset($d['shirt_number']) ? ' · maglia n. ' . (int) $d['shirt_number'] : '' ?></div></div></div>
        <form method="post" class="pending-actions"><?= csrf_field() ?><input type="hidden" name="group_id" value="<?= $gid ?>"><input type="hidden" name="user_id" value="<?= (int) $r['id'] ?>">
          <select name="player_id" class="mini-select" aria-label="Collega a un giocatore">
            <option value="">Nuovo giocatore</option>
            <?php foreach ($freeInLeague as $f): ?><option value="<?= (int) $f['id'] ?>" <?= $match === (int) $f['id'] ? 'selected' : '' ?>>È già in rosa: <?= h($f['name']) ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-primary btn-sm" name="do" value="reg_approve"><i class="ti ti-check"></i> Approva</button>
          <button class="btn btn-danger btn-sm" name="do" value="reg_reject" data-confirm="Rifiutare la richiesta di <?= h($r['reg_name']) ?>?"><i class="ti ti-x"></i></button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php foreach ($pending['requests'] as $r): ?>
      <div class="pending-row">
        <div class="pending-who"><?= avatar(['name' => $r['player_name'] ?: $r['username']], 'md') ?>
          <div><strong><?= h($r['player_name'] ?: $r['username']) ?></strong> <span class="muted small">@<?= h($r['username']) ?> · ha già un account · <?= fmt_date_short($r['requested_at']) ?> <?= fmt_time($r['requested_at']) ?></span></div></div>
        <form method="post" class="pending-actions"><?= csrf_field() ?><input type="hidden" name="group_id" value="<?= $gid ?>"><input type="hidden" name="user_id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn-primary btn-sm" name="do" value="req_accept"><i class="ti ti-check"></i> Accetta</button>
          <button class="btn btn-danger btn-sm" name="do" value="req_reject" data-confirm="Rifiutare la richiesta?"><i class="ti ti-x"></i></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="card" id="membri">
  <h2><i class="ti ti-users"></i> Membri</h2>
  <p class="muted small"><strong>Admin</strong>: gestisce la lega come te (richieste, membri, partite, pagamenti). <strong>Manager</strong>: gestisce solo le partite (presenze, squadre, risultato, voti).
    Password ed email le cambia ognuno dal proprio profilo.</p>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Giocatore</th><th>Account</th><th>Ruolo</th><th>Ultimo accesso</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($members as $m): $isOwnerRow = $m['role'] === 'owner';
        $canTouch = !$isOwnerRow && ($isOwner || $m['role'] !== 'admin'); ?>
      <tr<?= $m['active'] ? '' : ' class="is-off"' ?>>
        <td><a class="tname" href="player.php?id=<?= (int) $m['id'] ?>"><?= avatar($m, 'xs') ?> <?= h($m['name']) ?></a></td>
        <td class="small"><?= $m['uid'] ? '@' . h($m['username']) : '<span class="muted">senza account</span>' ?></td>
        <td>
          <?php if (!$m['uid']): ?><span class="muted small">—</span>
          <?php elseif ($isOwnerRow): ?><span class="tag tag-mvp"><i class="ti ti-crown"></i> Proprietario</span>
          <?php elseif ($canTouch): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="set_role"><input type="hidden" name="group_id" value="<?= $gid ?>"><input type="hidden" name="user_id" value="<?= (int) $m['uid'] ?>">
              <select name="role" class="mini-select" data-autosubmit aria-label="Ruolo nella lega">
                <option value="player" <?= !$m['role'] ? 'selected' : '' ?>>Giocatore</option>
                <option value="manager" <?= $m['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
                <?php if ($isOwner): ?><option value="admin" <?= $m['role'] === 'admin' ? 'selected' : '' ?>>Admin</option><?php endif; ?>
              </select></form>
          <?php else: ?><span class="tag"><?= h(LEAGUE_ROLES[$m['role']] ?? 'Giocatore') ?></span><?php endif; ?>
        </td>
        <td class="small muted"><?= $m['last_seen_at'] ? fmt_date_short($m['last_seen_at']) . ' ' . fmt_time($m['last_seen_at']) : '—' ?></td>
        <td><?php if ($canTouch): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="remove_member"><input type="hidden" name="group_id" value="<?= $gid ?>"><input type="hidden" name="player_id" value="<?= (int) $m['id'] ?>">
            <button class="icon-btn" title="Togli dalla lega" data-confirm="Togliere <?= h($m['name']) ?> dalla lega? Le partite già giocate restano nello storico."><i class="ti ti-user-minus"></i></button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <h3>Aggiungi un giocatore senza account</h3>
  <p class="muted small">Per chi gioca ma non userà il sito (o per metterlo in rosa prima che si iscriva: se si iscrive con il link e lo stesso nome, viene collegato a lui).</p>
  <form method="post" class="form form-grid"><?= csrf_field() ?><input type="hidden" name="do" value="add_player"><input type="hidden" name="group_id" value="<?= $gid ?>">
    <label class="field"><span>Nome e cognome</span><input name="name" required maxlength="80" autocomplete="off"></label>
    <label class="field"><span>Posizione</span><select name="position"><?php foreach (main_positions() as $o): ?><option <?= $o === 'Centrocampista' ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
    <div><button class="btn btn-primary">Aggiungi</button></div>
  </form>
</section>

<section class="card">
  <h2><i class="ti ti-settings"></i> Impostazioni</h2>
  <form method="post" class="form form-grid"><?= csrf_field() ?><input type="hidden" name="do" value="rename"><input type="hidden" name="group_id" value="<?= $gid ?>">
    <label class="field"><span>Nome della lega</span><input name="name" required maxlength="40" value="<?= h($league['name']) ?>"></label>
    <div><button class="btn btn-ghost">Rinomina</button></div>
  </form>
  <?php if ($isOwner): ?>
    <?php $others = array_filter($withAccount, fn($m) => $m['role'] !== 'owner'); ?>
    <?php if ($others): ?>
      <h3>Cedi la lega</h3>
      <p class="muted small">Il nuovo proprietario avrà tutti i poteri sulla lega<?= $myRole === 'owner' ? '; tu resti admin' : '' ?>.</p>
      <form method="post" class="form form-grid"><?= csrf_field() ?><input type="hidden" name="do" value="transfer"><input type="hidden" name="group_id" value="<?= $gid ?>">
        <label class="field"><span>A chi</span><select name="user_id"><?php foreach ($others as $m): ?><option value="<?= (int) $m['uid'] ?>"><?= h($m['name']) ?> (@<?= h($m['username']) ?>)</option><?php endforeach; ?></select></label>
        <div><button class="btn btn-ghost" data-confirm="Cedere la lega? Non potrai riprendertela da solo.">Cedi</button></div>
      </form>
    <?php endif; ?>
    <h3>Elimina la lega</h3>
    <?php if ((int) $counts['n'] > 0): ?>
      <p class="muted small">La lega ha delle partite, quindi non si può eliminare da qui (serve l'admin del sito): lo storico dei giocatori sparirebbe.</p>
    <?php else: ?>
      <p class="muted small">Si può eliminare finché non ha partite. I giocatori senza account se ne vanno con lei; gli account restano, senza questa lega.</p>
      <form method="post" class="form form-grid"><?= csrf_field() ?><input type="hidden" name="do" value="delete_league"><input type="hidden" name="group_id" value="<?= $gid ?>">
        <label class="field"><span>Scrivi «<?= h($league['name']) ?>» per confermare</span><input name="confirm" required autocomplete="off"></label>
        <div><button class="btn btn-danger" data-confirm="Eliminare la lega definitivamente?"><i class="ti ti-trash"></i> Elimina</button></div>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="card">
  <h2><i class="ti ti-list-details"></i> Ultime operazioni nella lega</h2>
  <?php if (!$log): ?><p class="empty">Ancora niente.</p><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Quando</th><th>Chi</th><th>Cosa</th><th>Dettaglio</th></tr></thead><tbody>
    <?php foreach ($log as $a): ?>
      <tr><td class="small nowrap"><?= fmt_date_short($a['created_at']) ?> <?= fmt_time($a['created_at']) ?></td>
        <td class="small"><?= h($a['player_name'] ?: ($a['username'] ?: '—')) ?></td>
        <td class="small"><?= h($labels[$a['action']] ?? $a['action']) ?></td>
        <td class="small muted"><?= h($a['detail']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</section>
<script>
document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', () => {
  const input = document.querySelector(b.dataset.copy);
  const done = () => { const t = b.innerHTML; b.innerHTML = '<i class="ti ti-check"></i> Copiato'; setTimeout(() => { b.innerHTML = t; }, 1600); };
  if (navigator.clipboard) { navigator.clipboard.writeText(input.value).then(done, () => { input.select(); document.execCommand('copy'); done(); }); }
  else { input.select(); document.execCommand('copy'); done(); }
}));
</script>
<?php
layout_end();
