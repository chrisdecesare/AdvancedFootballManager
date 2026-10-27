<?php
/*
 * L'invito («cartolina») per chi ha appena creato un account per un altro: un ospite della partita, un account da Admin o dalla scheda del giocatore.
 * Ha username, password, ruolo, lega, chi l'ha creato e il QR della pagina di accesso, da condividere, scaricare come immagine o stampare (invito.php).
 * La password sta solo nella sessione di chi l'ha creato e vale 30 minuti: mai nell'indirizzo, mai nel database.
 */

const INVITE_TTL = 1800;

/** Ruolo in parole: admin, manager, ospite, giocatore. */
function invite_role_word(string $role): string
{
    return ['ospite' => 'Ospite', 'player' => 'Giocatore', 'manager' => 'Manager', 'admin' => 'Admin'][$role] ?? 'Giocatore';
}

/** «admin Christian», «manager Christian»: chi ha creato l'account, con il suo ruolo (nella lega se ha un ruolo più specifico). */
function invite_by(array $u, ?int $groupId = null): string
{
    $name = (string) ($u['player_name'] ?: $u['username']);
    if (($u['role'] ?? '') === 'admin') {
        return 'admin ' . $name;
    }
    $lr = $groupId ? q('SELECT role FROM group_roles WHERE group_id = ? AND user_id = ?', [$groupId, $u['id']])->fetchColumn() : null;
    if ($lr === 'owner' || $lr === 'admin') {
        return 'admin ' . $name;
    }
    if ($lr === 'manager' || ($u['role'] ?? '') === 'manager') {
        return 'manager ' . $name;
    }
    return $name;
}

/** Nomi delle leghe di un giocatore (per un account creato da Admin). */
function invite_leagues_of_player(?int $playerId): array
{
    if (!$playerId) {
        return [];
    }
    return array_map('strval', q('SELECT g.name FROM player_groups pg JOIN squad_groups g ON g.id = pg.group_id WHERE pg.player_id = ? ORDER BY g.name', [$playerId])->fetchAll(PDO::FETCH_COLUMN));
}

/** Prepara l'invito: $inv = name, username, password, role (parola), leagues[], by, match? {date, location}, validDays? */
function invite_set(array $inv): void
{
    $inv['loginUrl'] = trusted_base_url() . 'login.php';
    $inv['created'] = time();
    $_SESSION['invite'] = $inv;
}

function invite_get(): ?array
{
    $v = $_SESSION['invite'] ?? null;
    if (!is_array($v) || time() - (int) ($v['created'] ?? 0) > INVITE_TTL) {
        unset($_SESSION['invite']);
        return null;
    }
    return $v;
}
