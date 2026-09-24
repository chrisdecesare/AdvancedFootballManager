<?php
/*
 * Leghe create dagli utenti (self-service) e registro delle operazioni.
 *
 * Una "lega" è un gruppo (tabella squad_groups). Le leghe storiche, create dall'admin del sito da admin.php, hanno
 * owner_user_id NULL; quelle create da chiunque con create_league.php hanno il loro proprietario.
 * Dentro una lega i poteri stanno in group_roles:
 *  - owner: chi l'ha creata. Può tutto nella sua lega (anche cederla o eliminarla);
 *  - admin: come l'owner, tranne cedere/eliminare la lega e nominare altri admin;
 *  - manager: gestisce le partite (presenze, squadre, risultato, votazioni).
 * Nessuno di questi ruoli dà poteri sugli account (password, email, ruoli del sito): quelli restano all'admin del sito.
 *
 * L'admin del sito (users.role = 'admin') può tutto ovunque e vede tutte le leghe da platform.php; nelle pagine normali però
 * vede solo le sue (le storiche e quelle di cui fa parte), per non mescolare le statistiche di sconosciuti con le proprie.
 *
 * Entrare in una lega: ogni lega ha un codice d'invito (join.php?c=...). Con join_mode 'approvazione' la richiesta aspetta
 * un admin della lega, con 'libero' chi ha il link entra subito.
 */

const LEAGUE_ROLES = ['owner' => 'Proprietario', 'admin' => 'Admin', 'manager' => 'Manager'];
const LEAGUE_MAX_PER_USER = 3;        // leghe che un account può aver creato (e possedere) insieme
const LEAGUE_MAX_PER_IP_DAY = 5;      // leghe create da una stessa connessione in 24 ore
const LEAGUE_MAX_PENDING = 200;       // richieste in attesa in una lega (oltre, il link smette di accettarne)
const ACTIVITY_KEEP_DAYS = 365;       // il registro delle operazioni tiene un anno

/* ---------------------------------------------------------------- dati della lega */

function league_get(int $gid): ?array
{
    return q('SELECT * FROM squad_groups WHERE id = ?', [$gid])->fetch() ?: null;
}

function league_by_code(string $code): ?array
{
    $code = strtolower(trim($code));
    if (!preg_match('/^[a-z0-9]{6,16}$/', $code)) {
        return null;
    }
    return q('SELECT * FROM squad_groups WHERE invite_code = ?', [$code])->fetch() ?: null;
}

/** Codice d'invito nuovo: 10 caratteri senza quelli che si confondono (0/o, 1/l/i). */
function league_new_code(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    do {
        $c = '';
        for ($i = 0; $i < 10; $i++) {
            $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
    } while (q('SELECT 1 FROM squad_groups WHERE invite_code = ?', [$c])->fetch());
    return $c;
}

/** Codice d'invito di una lega (creato al primo bisogno: le leghe storiche non ce l'hanno finché qualcuno non lo chiede). */
function league_code(int $gid): string
{
    $c = (string) q('SELECT invite_code FROM squad_groups WHERE id = ?', [$gid])->fetchColumn();
    if ($c === '') {
        $c = league_new_code();
        q('UPDATE squad_groups SET invite_code = ? WHERE id = ?', [$c, $gid]);
    }
    return $c;
}

function league_invite_url(int $gid): string
{
    return trusted_base_url() . 'join.php?c=' . league_code($gid);
}

/* ---------------------------------------------------------------- ruoli e permessi */

/** Ruoli dell'utente collegato nelle leghe: [group_id => 'owner'|'admin'|'manager']. */
function my_league_roles(): array
{
    if (($r = groups_cache('roles')) !== null) {
        return $r;
    }
    $u = current_user();
    $out = [];
    if ($u) {
        try {
            foreach (q('SELECT group_id, role FROM group_roles WHERE user_id = ?', [$u['id']])->fetchAll() as $row) {
                $out[(int) $row['group_id']] = $row['role'];
            }
        } catch (PDOException $e) {   // database non ancora aggiornato
        }
    }
    groups_cache('roles', $out);
    return $out;
}

/** Ruolo di un account in una lega (null = semplice giocatore o non membro). */
function league_role_of(int $gid, int $uid): ?string
{
    $r = q('SELECT role FROM group_roles WHERE group_id = ? AND user_id = ?', [$gid, $uid])->fetchColumn();
    return $r === false ? null : (string) $r;
}

/** Può amministrare la lega (richieste, membri, ruoli, invito, pagamenti, giocatori): admin del sito, owner e admin della lega. */
function can_admin_group(int $gid): bool
{
    return is_admin() || in_array(my_league_roles()[$gid] ?? null, ['owner', 'admin'], true);
}

/** Può gestire le partite della lega: chi la amministra, i suoi manager e i manager "del sito" che ne fanno parte. */
function can_manage_group(int $gid): bool
{
    return can_admin_group($gid)
        || (my_league_roles()[$gid] ?? null) === 'manager'
        || (is_manager() && in_array($gid, allowed_group_ids(), true));
}

/** Leghe che l'utente amministra: [id => nome]. Per l'admin del sito: le sue (storiche e di cui fa parte). */
function admin_groups(): array
{
    $all = all_groups();
    if (is_admin()) {
        return array_intersect_key($all, array_flip(home_group_ids()));
    }
    $out = [];
    foreach (my_league_roles() as $gid => $role) {
        if (in_array($role, ['owner', 'admin'], true) && isset($all[$gid])) {
            $out[$gid] = $all[$gid];
        }
    }
    return $out;
}

/** Leghe create da utenti (non storiche) che l'utente amministra: servono al link «La mia lega» nel menu. */
function my_owned_leagues(): array
{
    $out = [];
    foreach (my_league_roles() as $gid => $role) {
        if (in_array($role, ['owner', 'admin'], true) && isset(all_groups()[$gid])) {
            $out[$gid] = all_groups()[$gid];
        }
    }
    return $out;
}

/**
 * Leghe "di casa" dell'admin del sito: le storiche (senza proprietario) più quelle di cui fa parte o in cui ha un ruolo.
 * Sono quelle che vede nelle pagine normali; le altre le guarda da platform.php (o scegliendole apposta).
 */
function home_group_ids(): array
{
    if (($h = groups_cache('home')) !== null) {
        return $h;
    }
    $ids = [];
    try {
        $ids = array_map('intval', q('SELECT id FROM squad_groups WHERE owner_user_id IS NULL')->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        $ids = array_keys(all_groups());   // database non ancora aggiornato: tutte, come prima
    }
    $u = current_user();
    if ($u && !empty($u['player_id'])) {
        $ids = array_merge($ids, player_group_ids((int) $u['player_id']));
    }
    $ids = array_merge($ids, array_keys(my_league_roles()));
    $ids = array_values(array_unique(array_intersect($ids, array_keys(all_groups()))));
    sort($ids);
    groups_cache('home', $ids);
    return $ids;
}

/** Esistono leghe create da utenti? Finché no, per l'admin del sito tutto resta com'era (vede tutto senza filtri). */
function has_user_leagues(): bool
{
    if (($h = groups_cache('has_user_leagues')) !== null) {
        return $h;
    }
    try {
        $h = (bool) q('SELECT 1 FROM squad_groups WHERE owner_user_id IS NOT NULL LIMIT 1')->fetch();
    } catch (PDOException $e) {
        $h = false;
    }
    groups_cache('has_user_leagues', $h);
    return $h;
}

/* ---------------------------------------------------------------- creare ed entrare */

/** Giocatore dell'account (creato dal nome d'iscrizione o dallo username se non c'è ancora). */
function ensure_user_player(int $uid): int
{
    $u = q('SELECT id, username, reg_name, reg_json, player_id FROM users WHERE id = ?', [$uid])->fetch();
    if (!$u) {
        throw new RuntimeException('Account inesistente');
    }
    if ($u['player_id']) {
        return (int) $u['player_id'];
    }
    $d = json_decode($u['reg_json'] ?? '', true) ?: [];
    [$pos1, $pos2] = normalize_positions($d['position'] ?? null, $d['position2'] ?? null);
    q('INSERT INTO players (name, shirt_number, position, position2, foot) VALUES (?, ?, ?, ?, ?)',
        [mb_substr(trim((string) ($u['reg_name'] ?: $u['username'])), 0, 80), $d['shirt_number'] ?? null, $pos1, $pos2, $d['foot'] ?? 'Destro']);
    $pid = (int) db()->lastInsertId();
    q('UPDATE users SET player_id = ? WHERE id = ?', [$pid, $uid]);
    return $pid;
}

/** Aggiunge un giocatore a una lega (tenendo le altre): entra anche nelle partite programmate della lega. */
function league_add_player(int $pid, int $gid): void
{
    set_player_groups($pid, array_values(array_unique(array_merge(player_group_ids($pid), [$gid]))));
}

/** Toglie un giocatore da una lega (lo storico resta); il suo account perde anche il ruolo in quella lega. */
function league_remove_player(int $pid, int $gid): void
{
    set_player_groups($pid, array_values(array_diff(player_group_ids($pid), [$gid])));
    q('DELETE gr FROM group_roles gr JOIN users u ON u.id = gr.user_id WHERE gr.group_id = ? AND u.player_id = ?', [$gid, $pid]);
    groups_cache(null, null, true);
}

/** Leghe che un account possiede. */
function leagues_owned_count(int $uid): int
{
    return (int) q('SELECT COUNT(*) FROM squad_groups WHERE owner_user_id = ?', [$uid])->fetchColumn();
}

/** Messaggio d'errore se il nome della lega non va bene, altrimenti null. */
function league_name_error(string $name): ?string
{
    if ($name === '' || mb_strlen($name) > 40) {
        return 'Il nome della lega deve avere da 1 a 40 caratteri.';
    }
    if (!preg_match('/[\p{L}\p{N}]/u', $name)) {
        return 'Il nome della lega deve contenere almeno una lettera o un numero.';
    }
    return null;
}

/** Crea una lega: chi la crea ne è il proprietario e ci entra come giocatore. Ritorna l'id. */
function league_create(string $name, int $ownerUid): int
{
    $pid = ensure_user_player($ownerUid);
    q('INSERT INTO squad_groups (name, owner_user_id, invite_code) VALUES (?, ?, ?)', [$name, $ownerUid, league_new_code()]);
    $gid = (int) db()->lastInsertId();
    q("INSERT INTO group_roles (group_id, user_id, role) VALUES (?, ?, 'owner')", [$gid, $ownerUid]);
    groups_cache(null, null, true);
    league_add_player($pid, $gid);
    log_activity('lega_creata', $name, $gid, $ownerUid);
    return $gid;
}

/**
 * Un account già attivo chiede di entrare in una lega (dal link d'invito). Ritorna 'joined' (entrato subito: lega libera),
 * 'requested' (richiesta in attesa), 'already' (ne fa già parte) o 'pending' (aveva già chiesto).
 */
function league_join(int $uid, array $league): string
{
    $gid = (int) $league['id'];
    $pid = (int) q('SELECT player_id FROM users WHERE id = ?', [$uid])->fetchColumn();
    if ($pid && player_in_group($pid, $gid)) {
        return 'already';
    }
    if ($league['join_mode'] === 'libero') {
        league_add_player(ensure_user_player($uid), $gid);
        q('DELETE FROM group_requests WHERE group_id = ? AND user_id = ?', [$gid, $uid]);
        groups_cache(null, null, true);
        log_activity('lega_ingresso', 'entrato con il link', $gid, $uid);
        return 'joined';
    }
    if (q('SELECT 1 FROM group_requests WHERE group_id = ? AND user_id = ?', [$gid, $uid])->fetch()) {
        return 'pending';
    }
    q('INSERT INTO group_requests (group_id, user_id) VALUES (?, ?)', [$gid, $uid]);
    log_activity('lega_richiesta', 'chiede di entrare', $gid, $uid);
    push_notify_league_request($gid, $uid);
    return 'requested';
}

/** Iscrizioni (account nuovi) e richieste (account esistenti) in attesa in una lega. */
function league_pending(int $gid): array
{
    $regs = q("SELECT * FROM users WHERE status = 'in_attesa' AND reg_group_id = ? ORDER BY created_at", [$gid])->fetchAll();
    $reqs = q('SELECT r.created_at AS requested_at, u.id, u.username, u.email, u.player_id, p.name AS player_name
               FROM group_requests r JOIN users u ON u.id = r.user_id LEFT JOIN players p ON p.id = u.player_id
               WHERE r.group_id = ? ORDER BY r.created_at', [$gid])->fetchAll();
    return ['registrations' => $regs, 'requests' => $reqs];
}

function league_pending_count(int $gid): int
{
    return (int) q("SELECT COUNT(*) FROM users WHERE status = 'in_attesa' AND reg_group_id = ?", [$gid])->fetchColumn()
        + (int) q('SELECT COUNT(*) FROM group_requests WHERE group_id = ?', [$gid])->fetchColumn();
}

/** Accetta la richiesta di un account esistente di entrare nella lega. */
function league_accept_request(int $gid, int $uid): bool
{
    if (!q('DELETE FROM group_requests WHERE group_id = ? AND user_id = ?', [$gid, $uid])->rowCount()) {
        return false;
    }
    league_add_player(ensure_user_player($uid), $gid);
    push_notify_approved($uid, [group_name($gid)]);
    return true;
}

/**
 * Approva un'iscrizione in attesa: collega l'account a un giocatore già in rosa ($link, se libero) o ne crea uno nuovo,
 * lo mette nelle leghe indicate e (con $notify) lo avvisa con notifica ed email. Ritorna [nome, email partita?] oppure null se non c'era.
 * La usano sia admin.php (leghe storiche) sia league.php (leghe degli utenti).
 */
function approve_registration(int $uid, array $groupIds, int $link = 0, bool $notify = true): ?array
{
    $u = q("SELECT * FROM users WHERE id = ? AND status = 'in_attesa'", [$uid])->fetch();
    if (!$u) {
        return null;
    }
    $data = json_decode($u['reg_json'] ?? '', true) ?: [];
    [$pos1, $pos2] = normalize_positions($data['position'] ?? null, $data['position2'] ?? null);
    db()->beginTransaction();
    if ($link && !q('SELECT 1 FROM users WHERE player_id = ?', [$link])->fetch()) {
        // giocatore già in rosa: collega l'account e aggiorna le sue preferenze
        q('UPDATE players SET position = ?, position2 = ?, foot = ?, shirt_number = COALESCE(?, shirt_number), active = 1 WHERE id = ?',
            [$pos1, $pos2, $data['foot'] ?? 'Destro', $data['shirt_number'] ?? null, $link]);
        $pid = $link;
    } else {
        q('INSERT INTO players (name, shirt_number, position, position2, foot) VALUES (?, ?, ?, ?, ?)',
            [$u['reg_name'], $data['shirt_number'] ?? null, $pos1, $pos2, $data['foot'] ?? 'Destro']);
        $pid = (int) db()->lastInsertId();
    }
    q("UPDATE users SET status = 'attivo', player_id = ?, reg_json = NULL WHERE id = ?", [$pid, $uid]);
    db()->commit();
    set_player_groups($pid, array_values(array_unique(array_merge(player_group_ids($pid), $groupIds))));   // entra anche nelle partite programmate
    guests_merge_for_user($uid);          // se aveva giocato da ospite con questa email (confermata), la partita gli compare
    $groupNames = array_values(array_intersect_key(all_groups(), array_flip($groupIds)));
    $mailed = false;
    if ($notify) {   // non serve per chi entra da solo in una lega ad ingresso libero
        push_notify_approved($uid, $groupNames);
        $mailed = send_approval_email($uid, $groupNames);
    }
    if ($notify) {   // chi entra da solo in una lega libera ha già la sua riga «Entrato nella lega»
        foreach ($groupIds as $gid) {
            log_activity('iscrizione_approvata', (string) $u['reg_name'], (int) $gid, null);
        }
    }
    return [(string) $u['reg_name'], $mailed];
}

/** Richieste da guardare per chi è collegato: l'admin del sito quelle delle leghe storiche, gli admin di lega quelle delle loro. */
function pending_for_me(): int
{
    $u = current_user();
    if (!$u) {
        return 0;
    }
    $n = 0;
    try {
        if (is_admin()) {
            $n += (int) q("SELECT COUNT(*) FROM users WHERE status = 'in_attesa' AND (reg_group_id IS NULL OR reg_group_id IN
                           (SELECT id FROM squad_groups WHERE owner_user_id IS NULL))")->fetchColumn();
        }
        foreach (my_owned_leagues() as $gid => $_) {
            $n += league_pending_count($gid);
        }
    } catch (PDOException $e) {
        return is_admin() ? pending_count() : 0;
    }
    return $n;
}

/** Elimina una lega senza partite: i giocatori senza account che erano solo lì se ne vanno con lei. */
function league_delete(int $gid): ?string
{
    $l = league_get($gid);
    if (!$l) {
        return 'Lega non trovata.';
    }
    if ((int) q('SELECT COUNT(*) FROM matches WHERE group_id = ?', [$gid])->fetchColumn() > 0) {
        return 'La lega ha delle partite: non si può eliminare (chiedi all\'admin del sito se serve davvero).';
    }
    $orphans = q('SELECT pg.player_id FROM player_groups pg LEFT JOIN users u ON u.player_id = pg.player_id
                  WHERE pg.group_id = ? AND u.id IS NULL
                    AND NOT EXISTS (SELECT 1 FROM player_groups o WHERE o.player_id = pg.player_id AND o.group_id <> pg.group_id)', [$gid])->fetchAll(PDO::FETCH_COLUMN);
    q("UPDATE users SET reg_group_id = NULL WHERE reg_group_id = ? AND status = 'attivo'", [$gid]);
    q("DELETE FROM users WHERE reg_group_id = ? AND status = 'in_attesa'", [$gid]);   // iscrizioni in attesa per quella lega
    q('DELETE FROM squad_groups WHERE id = ?', [$gid]);   // ruoli, richieste e appartenenze se ne vanno con lei
    foreach ($orphans as $pid) {
        q('DELETE FROM players WHERE id = ?', [(int) $pid]);
    }
    groups_cache(null, null, true);
    log_activity('lega_eliminata', (string) $l['name'], $gid);
    return null;
}

/* ---------------------------------------------------------------- registro delle operazioni */

/** Nomi leggibili delle operazioni registrate (platform.php e «La mia lega»). */
function activity_labels(): array
{
    return [
        'accesso' => 'Accesso', 'uscita' => 'Uscita',
        'iscrizione' => 'Iscrizione', 'iscrizione_approvata' => 'Iscrizione approvata', 'iscrizione_rifiutata' => 'Iscrizione rifiutata',
        'lega_creata' => 'Lega creata', 'lega_richiesta' => 'Richiesta di entrare', 'lega_ingresso' => 'Entrato nella lega',
        'lega_accettato' => 'Richiesta accettata', 'lega_rifiutato' => 'Richiesta rifiutata', 'lega_rinominata' => 'Lega rinominata',
        'lega_invito' => 'Link d\'invito cambiato', 'lega_modo' => 'Modo di ingresso cambiato', 'lega_ruolo' => 'Ruolo nella lega',
        'lega_rimosso' => 'Tolto dalla lega', 'lega_giocatore' => 'Giocatore aggiunto', 'lega_ceduta' => 'Lega ceduta',
        'lega_eliminata' => 'Lega eliminata',
        'partita_creata' => 'Partita creata', 'partita' => 'Gestione partita', 'presenza' => 'Presenza', 'voto' => 'Voto',
        'puntata' => 'Scommessa', 'multipla' => 'Multipla', 'puntata_ritirata' => 'Scommessa ritirata',
        'negozio' => 'Negozio', 'giocatore' => 'Scheda giocatore', 'pagamenti' => 'Pagamenti',
        'account' => 'Account', 'admin' => 'Admin del sito',
    ];
}

/**
 * Registra un'operazione. Non deve mai far fallire la pagina: se il database non è pronto, non registra.
 * $userId null = chi è collegato adesso.
 */
function log_activity(string $action, string $detail = '', ?int $groupId = null, ?int $userId = null): void
{
    try {
        $userId ??= current_user() ? (int) current_user()['id'] : null;
        q('INSERT INTO activity_log (user_id, group_id, action, detail, ip) VALUES (?, ?, ?, ?, ?)',
            [$userId, $groupId, mb_substr($action, 0, 40), mb_substr($detail, 0, 255), client_ip()]);
    } catch (Throwable $e) {
        error_log('log_activity: ' . $e->getMessage());
    }
}

/** Una volta al giorno: via le righe del registro più vecchie di un anno. */
function activity_maybe_prune(): void
{
    if ((int) meta_get('activity_pruned') > time() - 86400) {
        return;
    }
    meta_set('activity_pruned', (string) time());
    q('DELETE FROM activity_log WHERE created_at < ?', [date('Y-m-d H:i:s', time() - ACTIVITY_KEEP_DAYS * 86400)]);
}

/** Segna l'ultima volta che l'account ha usato il sito (al massimo una scrittura ogni 5 minuti per sessione). */
function touch_last_seen(): void
{
    if (empty($_SESSION['uid']) || (int) ($_SESSION['seen_at'] ?? 0) > time() - 300) {
        return;
    }
    $_SESSION['seen_at'] = time();
    try {
        q('UPDATE users SET last_seen_at = NOW() WHERE id = ?', [(int) $_SESSION['uid']]);
    } catch (PDOException $e) {
    }
}
