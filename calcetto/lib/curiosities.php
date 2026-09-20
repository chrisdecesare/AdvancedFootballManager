<?php
/*
 * Curiosità sui giocatori ("Lo sapevi che...?"). Le scrive il giocatore stesso o l'admin dalla scheda del giocatore;
 * si leggono nella scheda del giocatore, nella pagina Curiosità e, ogni tanto, in Home.
 * Come per il resto del sito, si vedono solo quelle dei giocatori dei propri gruppi.
 */

const CURIOSITY_MAX = 300;

/** Chi può scrivere e cancellare le curiosità di un giocatore: lui stesso e l'admin. */
function can_edit_curiosities(int $playerId): bool
{
    return is_admin() || (my_player_id() !== null && my_player_id() === $playerId);
}

/** Curiosità di un giocatore, dalla più recente. */
function player_curiosities(int $playerId): array
{
    return q('SELECT id, body, created_at FROM curiosities WHERE player_id = ? ORDER BY id DESC', [$playerId])->fetchAll();
}

/** Curiosità dei giocatori visibili ora, dalla più recente, con nome e foto di chi riguardano. */
function all_curiosities(?int $limit = null): array
{
    $sql = 'SELECT c.id, c.body, c.created_at, c.player_id, p.name, p.photo, p.position, p.active
            FROM curiosities c JOIN players p ON p.id = c.player_id
            WHERE ' . player_scope_sql('p.id') . ' ORDER BY c.id DESC' . ($limit ? ' LIMIT ' . (int) $limit : '');
    return q($sql)->fetchAll();
}

/** Testo pulito di una curiosità (una riga, senza spazi doppi) oppure null se vuoto. */
function clean_curiosity($text): ?string
{
    $t = trim(preg_replace('/\s+/u', ' ', (string) $text));
    return $t === '' ? null : $t;
}

/** Una curiosità per la Home: cambia una volta al giorno (uguale per tutti nella stessa giornata), null se non ce ne sono. */
function curiosity_of_the_day(): ?array
{
    $all = all_curiosities();
    if (!$all) {
        return null;
    }
    return $all[crc32(date('Y-m-d') . scope_key(scope_ids())) % count($all)];
}
