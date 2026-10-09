<?php
/*
 * Il pensiero sulla partita: a chi ha giocato l'ultima partita della sua lega compare un messaggio (lib/layout.php) che chiede
 * poche righe sulla partita. I pensieri finiscono nella Gazzetta del mercoledì (pagina «Spogliatoio», lib/gazzetta.php).
 * Tabella match_thoughts: uno per giocatore e partita; body NULL con skipped = 1 vuol dire «No grazie» (non lo si chiede più).
 * «Più tardi» lo rimanda solo per questa sessione. Si chiede per THOUGHT_DAYS giorni dopo la partita, poi basta.
 */
const THOUGHT_MAX = 280;   // caratteri: poche righe
const THOUGHT_DAYS = 7;

/** L'ultima partita giocata (degli ultimi THOUGHT_DAYS giorni) su cui il giocatore non ha ancora scritto né detto «No grazie». */
function thought_pending(int $playerId): ?array
{
    try {
        $m = q("SELECT m.* FROM matches m JOIN match_players mp ON mp.match_id = m.id AND mp.player_id = ? AND mp.team IS NOT NULL
                WHERE m.status = 'giocata' AND m.match_date >= ? AND m.match_date <= NOW()
                  AND NOT EXISTS (SELECT 1 FROM match_thoughts t WHERE t.match_id = m.id AND t.player_id = mp.player_id)
                ORDER BY m.match_date DESC LIMIT 1", [$playerId, date('Y-m-d H:i:s', time() - THOUGHT_DAYS * 86400)])->fetch();
    } catch (Throwable $e) {
        return null;   // tabella non ancora creata
    }
    if (!$m || !empty($_SESSION['thought_later'][(int) $m['id']])) {
        return null;
    }
    // solo l'ultima partita della sua lega: per una vecchia, se nel frattempo se n'è giocata un'altra, non si chiede più
    $newer = q("SELECT 1 FROM matches WHERE group_id = ? AND status = 'giocata' AND match_date > ? LIMIT 1", [$m['group_id'], $m['match_date']])->fetchColumn();
    return $newer ? null : $m;
}

/** Salva il pensiero (o il «No grazie» se $body è null). Ritorna il messaggio d'errore oppure null. */
function thought_save(int $matchId, int $playerId, ?string $body): ?string
{
    $played = q("SELECT 1 FROM match_players mp JOIN matches m ON m.id = mp.match_id
                 WHERE mp.match_id = ? AND mp.player_id = ? AND mp.team IS NOT NULL AND m.status = 'giocata'", [$matchId, $playerId])->fetchColumn();
    if (!$played) {
        return 'Puoi scrivere solo sulle partite che hai giocato.';
    }
    if ($body !== null) {
        $body = trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', $body)));
        $body = preg_replace("/\n{3,}/", "\n\n", $body);
        if ($body === '') {
            return 'Scrivi qualcosa, anche solo una riga.';
        }
        if (mb_strlen($body) > THOUGHT_MAX) {
            return 'Massimo ' . THOUGHT_MAX . ' caratteri: poche righe.';
        }
    }
    q('INSERT INTO match_thoughts (match_id, player_id, body, skipped) VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE body = VALUES(body), skipped = VALUES(skipped), created_at = NOW()',
        [$matchId, $playerId, $body, $body === null ? 1 : 0]);
    return null;
}

/** Pensieri scritti su una partita, dal primo: [['player_id', 'name', 'body', 'team', 'created_at'], ...]. */
function match_thoughts(int $matchId): array
{
    try {
        return q("SELECT t.player_id, t.body, t.created_at, p.name, mp.team FROM match_thoughts t JOIN players p ON p.id = t.player_id
                  LEFT JOIN match_players mp ON mp.match_id = t.match_id AND mp.player_id = t.player_id
                  WHERE t.match_id = ? AND t.body IS NOT NULL AND t.body <> '' ORDER BY t.created_at, t.id", [$matchId])->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
