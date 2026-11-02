<?php
/*
 * «Inserita nel calendario»: chi tocca «Aggiungi a Google Calendar» segna la partita come inserita, così non la aggiunge più volte
 * (come next-app/src/lib/calendar-mark.ts, stessa tabella e stessa impronta: il segno vale su tutti e due i siti). Non sappiamo se l'evento
 * sia davvero stato salvato (lo decide Google): è un promemoria per chi l'ha toccato, annullabile con un tocco. Se data o campo della
 * partita cambiano, il segno non vale più e il pulsante torna per aggiornare l'evento.
 */
function cal_schema_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (meta_get('calendar_schema') === '1') {
        return;
    }
    db()->exec('CREATE TABLE IF NOT EXISTS match_calendar (
        user_id INT NOT NULL,
        match_id INT NOT NULL,
        sig CHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, match_id),
        KEY ix_mc_match (match_id)
    ) DEFAULT CHARSET=utf8mb4');
    meta_set('calendar_schema', '1');
}

/** Impronta di ciò che finisce nell'evento del calendario (data e campo). */
function cal_sig(array $m): string
{
    return md5(($m['match_date'] ?? '') . '|' . ($m['location'] ?? ''));
}

/** Lo stato per l'utente collegato, partita per partita: 'none' (mai inserita), 'added' (inserita), 'stale' (inserita ma la partita è cambiata dopo). */
function cal_states(array $matches): array
{
    $out = [];
    foreach ($matches as $m) {
        $out[(int) $m['id']] = 'none';
    }
    $u = current_user();
    $ids = array_values(array_unique(array_filter(array_map(fn($m) => (int) $m['id'], $matches))));
    if (!$u || !$ids) {
        return $out;
    }
    cal_schema_ensure();
    $rows = q('SELECT match_id, sig FROM match_calendar WHERE user_id = ? AND match_id IN (' . implode(',', $ids) . ')', [(int) $u['id']])->fetchAll();
    $sigs = [];
    foreach ($rows as $r) {
        $sigs[(int) $r['match_id']] = $r['sig'];
    }
    foreach ($matches as $m) {
        if (isset($sigs[(int) $m['id']])) {
            $out[(int) $m['id']] = $sigs[(int) $m['id']] === cal_sig($m) ? 'added' : 'stale';
        }
    }
    return $out;
}

/** Lo stato di una sola partita (con una cache per richiesta; cal_preload() la riempie in una domanda sola per un elenco). */
function cal_state(array $m, ?array $preload = null): string
{
    static $cache = [];
    if ($preload !== null) {
        $cache = cal_states($preload) + $cache;
        return 'none';
    }
    $id = (int) $m['id'];
    return $cache[$id] ??= cal_states([$m])[$id] ?? 'none';
}

/** Carica in una volta sola lo stato di un elenco di partite (per l'elenco delle partite in programma). */
function cal_preload(array $matches): void
{
    cal_state([], $matches);
}

/** Segna la partita come inserita (solo se è in programma). false se la partita non c'è o non è più in programma. */
function cal_mark(int $userId, int $matchId): bool
{
    cal_schema_ensure();
    $m = q('SELECT id, match_date, location, status FROM matches WHERE id = ?', [$matchId])->fetch();
    if (!$m || $m['status'] !== 'programmata') {
        return false;
    }
    q('INSERT INTO match_calendar (user_id, match_id, sig) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE sig = VALUES(sig)', [$userId, $matchId, cal_sig($m)]);
    return true;
}

function cal_unmark(int $userId, int $matchId): void
{
    cal_schema_ensure();
    q('DELETE FROM match_calendar WHERE user_id = ? AND match_id = ?', [$userId, $matchId]);
}
