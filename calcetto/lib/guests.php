<?php
/*
 * Profili "Ospite": chi gioca una partita sola e non fa parte della lega.
 *
 * Com'è fatto: un Ospite è un giocatore vero (riga di `players` con is_guest = 1, così può stare nelle squadre e nel
 * risultato) collegato a un account con ruolo "ospite". Non sta in nessun gruppo (player_groups), ed è proprio questo
 * che lo tiene fuori da rosa, classifiche, statistiche, intesa e scommesse: tutte quelle pagine leggono i giocatori
 * "del gruppo". Dove serve, il resto è escluso a mano (voti, MVP, scommesse: cerca is_guest).
 *
 * Cosa può fare: vedere la SUA partita (data, campo, in che squadra gioca, risultato e la formazione in campo) e dire
 * se ci sarà. Non vede le presenze né i voti, non vota, non scommette, e gli altri non possono votarlo né scommettere su di lui.
 *
 * Dopo GUEST_KEEP_DAYS giorni dalla partita l'account sparisce. Se l'admin aveva scritto la sua email, il giocatore
 * resta nella partita (con il nome): se un giorno si iscrive davvero con quella email confermata, la partita gli
 * compare tra quelle giocate (guest_merge). Senza email sparisce del tutto.
 */

const GUEST_KEEP_DAYS = 7;

/** Pagine che un Ospite può aprire (tutte le altre lo riportano alla sua partita). */
const GUEST_PAGES = ['match.php', 'action.php', 'account.php', 'logout.php', 'push.php', 'verify_email.php'];

function is_guest(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'ospite';
}

/** La partita a cui è invitato l'Ospite che ha fatto l'accesso (null = non è un Ospite, o la partita non c'è più). */
function guest_match_id(): ?int
{
    $u = current_user();
    return ($u && $u['role'] === 'ospite' && !empty($u['guest_match_id'])) ? (int) $u['guest_match_id'] : null;
}

/** Chiamata da require_login/require_view: un Ospite vede solo la sua partita, ogni altra pagina lo riporta lì. */
function guest_gate(): void
{
    if (!is_guest()) {
        return;
    }
    $mid = guest_match_id();
    if ($mid === null) {
        redirect('logout.php');      // la partita è stata eliminata: non c'è più niente da vedere
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (!in_array($script, GUEST_PAGES, true) || ($script === 'match.php' && int_get('id') !== $mid)) {
        redirect('match.php?id=' . $mid);
    }
}

/* ---------------------------------------------------------------- creazione e rimozione */

/** Nome utente libero ricavato dal nome (mario.rossi, mario.rossi2...). */
function guest_username_from(string $name): string
{
    $s = mb_strtolower(trim($name));
    $s = strtr($s, ['à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c', 'ñ' => 'n']);
    $s = trim(preg_replace('/[^a-z0-9]+/', '.', $s), '.');
    $s = substr($s !== '' ? $s : 'ospite', 0, 40);
    $u = $s;
    for ($i = 2; strlen($u) < 3 || q('SELECT 1 FROM users WHERE LOWER(username) = ?', [$u])->fetch(); $i++) {
        $u = $s . $i;
    }
    return $u;
}

/** Password casuale da dettare a voce o mandare su WhatsApp: niente caratteri che si confondono (0/o, 1/l). */
function guest_random_password(): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    $p = '';
    for ($i = 0; $i < 8; $i++) {
        $p .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $p;
}

/**
 * Crea un Ospite per una partita in programma. $in: name, email, position, position2, foot, shirt_number, username, password
 * (username e password se vuoti si generano). Ritorna ['error' => testo] oppure ['username' => ..., 'password' => ...].
 * @return array{error?: string, username?: string, password?: string}
 */
function guest_create(array $match, array $in): array
{
    if ($match['status'] !== 'programmata') {
        return ['error' => 'Gli ospiti si aggiungono solo alle partite in programma.'];
    }
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) {
        return ['error' => 'Scrivi nome e cognome dell\'ospite (max 80 caratteri).'];
    }
    $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
        return ['error' => 'L\'indirizzo email non è valido (puoi anche lasciarlo vuoto).'];
    }
    $shirt = trim((string) ($in['shirt_number'] ?? ''));
    if ($shirt !== '' && (!ctype_digit($shirt) || (int) $shirt > 99)) {
        return ['error' => 'Il numero di maglia va da 0 a 99.'];
    }
    [$pos, $pos2] = normalize_positions(trim((string) ($in['position'] ?? '')), trim((string) ($in['position2'] ?? '')) ?: null);
    $foot = in_array($in['foot'] ?? '', feet(), true) ? $in['foot'] : 'Destro';

    $username = trim((string) ($in['username'] ?? ''));
    if ($username === '') {
        $username = guest_username_from($name);
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        return ['error' => 'Username: 3-50 caratteri tra lettere, numeri, punto, trattino e underscore.'];
    } elseif (q('SELECT 1 FROM users WHERE LOWER(username) = ?', [mb_strtolower($username)])->fetch()) {
        return ['error' => 'Username già usato: scegline un altro (o lascialo vuoto e lo scelgo io).'];
    }
    $password = (string) ($in['password'] ?? '');
    if ($password === '') {
        $password = guest_random_password();
    } elseif ($err = password_error($password, $username)) {
        return ['error' => $err];
    }

    db()->beginTransaction();
    try {
        q('INSERT INTO players (name, shirt_number, position, position2, foot, is_guest, guest_email, guest_match_id)
           VALUES (?, ?, ?, ?, ?, 1, ?, ?)', [$name, $shirt === '' ? null : (int) $shirt, $pos, $pos2, $foot, $email ?: null, $match['id']]);
        $pid = (int) db()->lastInsertId();
        q("INSERT INTO users (username, password_hash, role, player_id, tour_done) VALUES (?, ?, 'ospite', ?, 1)",
            [$username, password_hash($password, PASSWORD_DEFAULT), $pid]);
        q('INSERT INTO match_players (match_id, player_id) VALUES (?, ?)', [$match['id'], $pid]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('guest_create: ' . $e->getMessage());
        return ['error' => 'Non sono riuscito a creare l\'ospite: riprova.'];
    }
    return ['username' => $username, 'password' => $password];
}

/** L'admin toglie un Ospite dalla partita: sparisce con il suo account, da squadre e presenze. */
function guest_delete(int $playerId): bool
{
    $g = q('SELECT id, guest_match_id FROM players WHERE id = ? AND is_guest = 1', [$playerId])->fetch();
    if (!$g) {
        return false;
    }
    q('DELETE FROM users WHERE player_id = ?', [$playerId]);
    q('DELETE FROM players WHERE id = ?', [$playerId]);      // presenze e squadre partono a cascata
    if ($g['guest_match_id']) {
        assign_formation((int) $g['guest_match_id']);          // il campo si riassesta senza di lui
    }
    return true;
}

/** Ospiti di una partita (con il giocatore), per l'elenco dell'admin. */
function match_guests(int $matchId): array
{
    return q("SELECT p.id, p.name, p.guest_email, u.username, mp.availability
              FROM players p JOIN match_players mp ON mp.player_id = p.id AND mp.match_id = ?
              LEFT JOIN users u ON u.player_id = p.id
              WHERE p.is_guest = 1 ORDER BY p.name", [$matchId])->fetchAll();
}

/* ---------------------------------------------------------------- scadenza e collegamento con l'account vero */

/**
 * Toglie gli account degli Ospiti la cui partita è di più di GUEST_KEEP_DAYS giorni fa (o è stata eliminata).
 * Il giocatore resta solo se c'è la sua email (per il collegamento futuro), altrimenti sparisce.
 * @return int account tolti
 */
function guests_cleanup(): int
{
    $limit = date('Y-m-d H:i:s', time() - GUEST_KEEP_DAYS * 86400);
    $rows = q("SELECT u.id AS uid, p.id AS pid, p.guest_email
               FROM users u LEFT JOIN players p ON p.id = u.player_id LEFT JOIN matches m ON m.id = p.guest_match_id
               WHERE u.role = 'ospite' AND (p.id IS NULL OR m.id IS NULL OR m.match_date < ?)", [$limit])->fetchAll();
    foreach ($rows as $r) {
        q('DELETE FROM users WHERE id = ?', [$r['uid']]);          // con lui se ne vanno sessioni, cookie e notifiche
        if ($r['pid'] === null) {
            continue;
        }
        $email = (string) $r['guest_email'];
        $real = $email !== '' ? q('SELECT player_id FROM users WHERE email = ? AND player_id IS NOT NULL', [$email])->fetchColumn() : false;
        if ($real) {
            guest_merge((int) $r['pid'], (int) $real);               // ha già un account vero: la partita passa a lui
        } elseif ($email === '') {
            q('DELETE FROM players WHERE id = ? AND is_guest = 1', [$r['pid']]);
        } else {
            q('UPDATE players SET photo = NULL WHERE id = ?', [$r['pid']]);
        }
    }
    return count($rows);
}

/** Come guests_cleanup(), ma al massimo ogni mezz'ora (chi arriva per primo, dopo aver ricevuto la pagina). */
function guests_maybe_cleanup(): void
{
    $now = time();
    if (q("UPDATE meta SET v = ? WHERE k = 'guests_cleanup' AND CAST(v AS UNSIGNED) < ?", [(string) $now, $now - 1800])->rowCount() === 1) {
        guests_cleanup();
    }
}

/**
 * La partita giocata da un Ospite passa al giocatore vero (presenze, squadra, gol, assist) e l'Ospite sparisce.
 * Se il giocatore vero era già in quella partita, resta la sua riga e quella dell'Ospite si butta.
 */
function guest_merge(int $guestId, int $realId): void
{
    if ($guestId === $realId) {
        return;
    }
    db()->beginTransaction();
    try {
        foreach (q('SELECT match_id FROM match_players WHERE player_id = ?', [$guestId])->fetchAll(PDO::FETCH_COLUMN) as $mid) {
            if (q('SELECT 1 FROM match_players WHERE match_id = ? AND player_id = ?', [$mid, $realId])->fetch()) {
                q('DELETE FROM match_players WHERE match_id = ? AND player_id = ?', [$mid, $guestId]);
            } else {
                q('UPDATE match_players SET player_id = ? WHERE match_id = ? AND player_id = ?', [$realId, $mid, $guestId]);
            }
        }
        q('UPDATE IGNORE match_links SET assister_id = ? WHERE assister_id = ?', [$realId, $guestId]);
        q('UPDATE IGNORE match_links SET scorer_id = ? WHERE scorer_id = ?', [$realId, $guestId]);
        q('DELETE FROM players WHERE id = ? AND is_guest = 1', [$guestId]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('guest_merge: ' . $e->getMessage());
    }
}

/**
 * Un account vero ha l'email confermata (o è appena stato approvato): le partite giocate come Ospite con quella email
 * passano a lui. Si chiama da verify_email.php e dall'approvazione in admin.php. @return int Ospiti collegati
 */
function guests_merge_for_user(int $userId): int
{
    $u = q('SELECT email, player_id FROM users WHERE id = ?', [$userId])->fetch();
    if (!$u || !$u['email'] || !$u['player_id']) {
        return 0;
    }
    // solo Ospiti il cui account è già scaduto: uno ancora in gioco non va toccato
    $ids = q('SELECT g.id FROM players g WHERE g.is_guest = 1 AND g.guest_email = ?
              AND NOT EXISTS (SELECT 1 FROM users x WHERE x.player_id = g.id)', [$u['email']])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $gid) {
        guest_merge((int) $gid, (int) $u['player_id']);
    }
    return count($ids);
}
