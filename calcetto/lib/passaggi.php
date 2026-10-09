<?php
/*
 * Passaggi di KOIN tra giocatori: chiunque può passare una parte dei suoi KOIN a un compagno di lega (Scommesse → «Passa KOIN»,
 * o dal profilo del compagno). Sono due mosse del portafoglio (wallet_moves, kind 'passaggio'): -N a chi dà e +N a chi riceve,
 * ognuna con peer_id = l'altro giocatore e il messaggio facoltativo (note). Così il saldo resta la somma delle mosse come per tutto il resto.
 *  - si passano solo KOIN disponibili (non quelli puntati) e solo dentro la stessa economia: i KOIN di una lega restano in quella lega;
 *  - al massimo KOIN_PASS_DAILY_MAX al giorno per chi dà (dalla mezzanotte), contro gli account fatti apposta per raccogliere KOIN;
 *  - chi riceve ha la notifica push e, alla prima pagina che apre, la sovraimpressione col KOIN (lib/layout.php, come i regali dell'admin);
 *  - i passaggi della settimana finiscono nella Gazzetta del mercoledì (lib/gazzetta.php).
 */
const KOIN_PASS_DAILY_MAX = 1000;
const KOIN_PASS_NOTE_MAX = 120;

/** Compagni a cui $from può passare KOIN nell'economia $eco: [id => nome], giocatori attivi delle sue leghe con quell'economia. */
function koin_pass_recipients(int $from, int $eco): array
{
    $gids = array_values(array_filter(player_group_ids($from), fn($g) => eco_of_group((int) $g) === $eco));
    if (!$gids) {
        return [];
    }
    return q('SELECT DISTINCT p.id, p.name FROM players p JOIN player_groups pg ON pg.player_id = p.id
              WHERE pg.group_id IN (' . implode(',', array_map('intval', $gids)) . ') AND p.id <> ? AND p.is_guest = 0 AND p.active = 1
              ORDER BY p.name', [$from])->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** KOIN che $from ha già passato oggi (in tutte le economie). */
function koin_passed_today(int $from): int
{
    return (int) q("SELECT COALESCE(-SUM(delta), 0) FROM wallet_moves WHERE player_id = ? AND kind = 'passaggio' AND delta < 0 AND created_at >= ?",
        [$from, date('Y-m-d 00:00:00')])->fetchColumn();
}

/** Passa $amount KOIN da $from a $to nell'economia $eco. Ritorna il messaggio d'errore oppure null se è andata. */
function koin_pass(int $from, int $to, int $amount, string $note, int $eco): ?string
{
    $note = mb_substr(trim(preg_replace('/\s+/u', ' ', $note)), 0, KOIN_PASS_NOTE_MAX);
    if (!isset(koin_pass_recipients($from, $eco)[$to])) {
        return 'Scegli un compagno della tua lega.';
    }
    if ($amount < 1) {
        return 'Scegli quanti KOIN passare.';
    }
    return bet_atomic(function () use ($from, $to, $amount, $note, $eco) {
        q('SELECT id FROM players WHERE id = ? FOR UPDATE', [$from]);   // due passaggi insieme non spendono due volte gli stessi KOIN
        $left = KOIN_PASS_DAILY_MAX - koin_passed_today($from);
        if ($left <= 0) {
            return 'Oggi hai già passato ' . KOIN_PASS_DAILY_MAX . ' KOIN, il massimo: riprova domani.';
        }
        if ($amount > $left) {
            return 'Oggi puoi passare ancora ' . $left . ' KOIN (massimo ' . KOIN_PASS_DAILY_MAX . ' al giorno).';
        }
        $have = wallet_balance($from, $eco);
        if ($amount > $have) {
            return 'Hai solo ' . $have . ' KOIN disponibili (quelli puntati non si possono passare).';
        }
        $n = $note !== '' ? $note : null;
        q("INSERT INTO wallet_moves (player_id, eco, delta, kind, note, peer_id) VALUES (?, ?, ?, 'passaggio', ?, ?)", [$from, $eco, -$amount, $n, $to]);
        q("INSERT INTO wallet_moves (player_id, eco, delta, kind, note, peer_id) VALUES (?, ?, ?, 'passaggio', ?, ?)", [$to, $eco, $amount, $n, $from]);
        return null;
    });
}

/** Push a chi riceve (a fine richiesta, per non rallentare la pagina di chi dà). */
function koin_pass_notify(int $from, int $to, int $amount, string $note): void
{
    push_defer(function () use ($from, $to, $amount, $note) {
        $users = array_values(push_users_of_players([$to]));
        if ($users) {
            push_notify_users($users, [
                'title' => 'Hai ricevuto ' . $amount . ' KOIN!',
                'body' => (get_player($from)['name'] ?? 'Un compagno') . ' ti ha passato ' . $amount . ' KOIN.' . ($note !== '' ? ' «' . $note . '»' : ''),
                'url' => 'bets.php', 'tag' => 'pass-' . $to . '-' . time(),
            ], 'normal', 'passaggio');
        }
    });
}

/** Passaggi ricevuti e non ancora mostrati (stesso players.gift_seen_id dei regali): [['id', 'amount', 'from', 'note'], ...], dal più vecchio. */
function koin_passes_unseen(int $playerId): array
{
    try {
        return q("SELECT w.id, w.delta AS amount, w.note, f.name AS `from` FROM wallet_moves w JOIN players p ON p.id = w.player_id
                  LEFT JOIN players f ON f.id = w.peer_id
                  WHERE w.player_id = ? AND w.id > p.gift_seen_id AND w.kind = 'passaggio' AND w.delta > 0 ORDER BY w.id", [$playerId])->fetchAll();
    } catch (Throwable $e) {
        return [];   // colonna non ancora creata
    }
}

/**
 * Passaggi di un'economia dal momento $since (per la Gazzetta e per lo storico): [['id', 'from_id', 'from', 'to_id', 'to', 'amount', 'note', 'created_at'], ...],
 * dal più recente. Ogni passaggio una volta sola (la mossa di chi riceve).
 */
function koin_passes_since(int $eco, string $since, int $limit = 50): array
{
    try {
        return q("SELECT w.id, w.peer_id AS from_id, f.name AS `from`, w.player_id AS to_id, t.name AS `to`, w.delta AS amount, w.note, w.created_at
                  FROM wallet_moves w JOIN players t ON t.id = w.player_id LEFT JOIN players f ON f.id = w.peer_id
                  WHERE w.kind = 'passaggio' AND w.delta > 0 AND w.eco = ? AND w.created_at >= ?
                  ORDER BY w.created_at DESC, w.id DESC LIMIT " . max(1, $limit), [$eco, $since])->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Passaggi fatti o ricevuti da un giocatore in un'economia (gli ultimi $limit), per la sua pagina Scommesse. */
function koin_passes_of(int $playerId, int $eco, int $limit = 8): array
{
    try {
        return q("SELECT w.delta, w.note, w.created_at, o.name AS other, w.peer_id FROM wallet_moves w LEFT JOIN players o ON o.id = w.peer_id
                  WHERE w.player_id = ? AND w.eco = ? AND w.kind = 'passaggio' ORDER BY w.id DESC LIMIT " . max(1, $limit), [$playerId, $eco])->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
