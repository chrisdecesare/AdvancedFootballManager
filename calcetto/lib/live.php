<?php
/*
 * Cronaca in diretta di una partita (tabella match_events): dal calcio d'inizio chi gioca, e chi gestisce le partite, segna
 * i gol (con l'assist, facoltativo), gli autogol e chi si infortuna. Ogni gol aggiorna subito il risultato e i gol/assist
 * della tabella "Risultato e marcatori" (che resta la parola finale: a fine partita si corregge lì e si conclude), e arriva
 * come notifica a chi del gruppo non sta giocando. Un evento sbagliato si toglie e i conti tornano indietro.
 */

const LIVE_PLAYER_HOURS = 4;   // chi gioca può segnare eventi fino a 4 ore dopo il calcio d'inizio (chi gestisce le partite sempre)

/** La partita è cominciata e non è ancora chiusa: si possono segnare gol e autogol. */
function live_is_on(array $m): bool
{
    return $m['status'] === 'programmata' && time() >= strtotime($m['match_date']);
}

/**
 * Può segnare eventi su questa partita? Chi gestisce le partite sì; un giocatore solo se è in una delle due squadre
 * (non un ospite) e non oltre LIVE_PLAYER_HOURS dall'inizio. Gli infortuni si segnano anche a partita chiusa, ma solo da chi la gestisce.
 */
function live_can_edit(array $m, ?int $me, string $kind = 'gol'): bool
{
    if (!current_user() || is_guest()) {
        return false;
    }
    $started = time() >= strtotime($m['match_date']);
    if (can_manage_group((int) $m['group_id'])) {
        return $kind === 'infortunio' ? $started : live_is_on($m);
    }
    if (!$me || !live_is_on($m) || time() > strtotime($m['match_date']) + LIVE_PLAYER_HOURS * 3600) {
        return false;
    }
    return (bool) q('SELECT 1 FROM match_players mp JOIN players p ON p.id = mp.player_id
                     WHERE mp.match_id = ? AND mp.player_id = ? AND mp.team IS NOT NULL AND p.is_guest = 0', [$m['id'], $me])->fetchColumn();
}

/** Eventi della partita in ordine di tempo, con i nomi. */
function live_events(int $matchId): array
{
    return q('SELECT e.*, p.name, a.name AS assist_name FROM match_events e
              JOIN players p ON p.id = e.player_id LEFT JOIN players a ON a.id = e.assist_id
              WHERE e.match_id = ? ORDER BY e.created_at, e.id', [$matchId])->fetchAll();
}

/** Minuto di gioco di un evento ("12'"), contato dal calcio d'inizio. */
function live_minute(array $m, string $at): string
{
    return max(0, (int) floor((strtotime($at) - strtotime($m['match_date'])) / 60)) . "'";
}

/** Giocatori infortunati nella partita: [id giocatore => nota o ''] */
function live_injured(int $matchId): array
{
    $out = [];
    foreach (q("SELECT player_id, note FROM match_events WHERE match_id = ? AND kind = 'infortunio'", [$matchId])->fetchAll() as $r) {
        $out[(int) $r['player_id']] = (string) $r['note'];
    }
    return $out;
}

/** Squadra (A/B) di un giocatore nella partita, null se non gioca. */
function live_team_of(int $matchId, int $playerId): ?string
{
    $t = q('SELECT team FROM match_players WHERE match_id = ? AND player_id = ? AND team IS NOT NULL', [$matchId, $playerId])->fetchColumn();
    return $t ?: null;
}

/**
 * Segna un gol (o un autogol) in diretta: +1 al risultato della squadra che ha segnato, +1 ai gol (o autogol) del giocatore
 * e, se c'è, +1 all'assist (e all'intesa "chi ha servito chi"). Ritorna il messaggio d'errore oppure null.
 */
function live_add_goal(array $m, int $playerId, ?int $assistId, bool $own, int $actorUser): ?string
{
    $id = (int) $m['id'];
    $team = live_team_of($id, $playerId);
    if (!$team) {
        return 'Scegli un giocatore in campo.';
    }
    if ($own) {
        $assistId = null;
    }
    if ($assistId) {
        if ($assistId === $playerId || live_team_of($id, $assistId) !== $team) {
            return 'L\'assist lo fa un compagno di squadra di chi segna.';
        }
    }
    $scored = $own ? ($team === 'A' ? 'B' : 'A') : $team;
    db()->beginTransaction();
    try {
        q('INSERT INTO match_events (match_id, kind, team, player_id, assist_id, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $own ? 'autogol' : 'gol', $team, $playerId, $assistId, $actorUser]);
        q('UPDATE match_players SET ' . ($own ? 'own_goals = own_goals + 1' : 'goals = goals + 1') . ' WHERE match_id = ? AND player_id = ?', [$id, $playerId]);
        if ($assistId) {
            q('UPDATE match_players SET assists = assists + 1 WHERE match_id = ? AND player_id = ?', [$id, $assistId]);
            q('INSERT INTO match_links (match_id, assister_id, scorer_id, n) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE n = n + 1', [$id, $assistId, $playerId]);
        }
        live_bump_score($id, $scored, 1);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('live_add_goal: ' . $e->getMessage());
        return 'Il gol non è stato salvato: riprova.';
    }
    $name = fn(?int $pid) => $pid ? (string) (get_player($pid)['name'] ?? '?') : null;
    push_notify_goal($id, $name($playerId), $name($assistId), $own, $scored, $actorUser);
    return null;
}

/** Segna un infortunio (una volta per giocatore e partita: rifarlo aggiorna la nota). */
function live_add_injury(array $m, int $playerId, string $note, int $actorUser): ?string
{
    $id = (int) $m['id'];
    $team = live_team_of($id, $playerId);
    if (!$team) {
        return 'Scegli un giocatore in campo.';
    }
    $note = mb_substr(trim(preg_replace('/\s+/', ' ', $note)), 0, 120);
    $old = q("SELECT id FROM match_events WHERE match_id = ? AND player_id = ? AND kind = 'infortunio'", [$id, $playerId])->fetchColumn();
    if ($old) {
        q('UPDATE match_events SET note = ? WHERE id = ?', [$note !== '' ? $note : null, $old]);
    } else {
        q("INSERT INTO match_events (match_id, kind, team, player_id, note, created_by) VALUES (?, 'infortunio', ?, ?, ?, ?)",
            [$id, $team, $playerId, $note !== '' ? $note : null, $actorUser]);
    }
    return null;
}

/**
 * Toglie un evento segnato per sbaglio, rimettendo a posto risultato, gol, assist e intesa.
 * Può toglierlo chi gestisce le partite o chi l'ha segnato (finché può ancora segnare eventi).
 */
function live_remove_event(array $m, int $eventId, ?int $me): ?string
{
    $id = (int) $m['id'];
    $e = q('SELECT * FROM match_events WHERE id = ? AND match_id = ?', [$eventId, $id])->fetch();
    if (!$e) {
        return 'Evento non trovato.';
    }
    $mine = (int) $e['created_by'] === (int) (current_user()['id'] ?? 0);
    if (!live_can_edit($m, $me, $e['kind']) || (!can_manage_group((int) $m['group_id']) && !$mine)) {
        return 'Non puoi togliere questo evento.';
    }
    if ($e['kind'] !== 'infortunio' && !live_is_on($m)) {
        return 'La partita è chiusa: correggi gol e risultato dalla tabella «Risultato e marcatori».';
    }
    db()->beginTransaction();
    try {
        if ($e['kind'] !== 'infortunio') {
            $own = $e['kind'] === 'autogol';
            $col = $own ? 'own_goals' : 'goals';
            q("UPDATE match_players SET $col = IF($col > 0, $col - 1, 0) WHERE match_id = ? AND player_id = ?", [$id, $e['player_id']]);
            if ($e['assist_id']) {
                q('UPDATE match_players SET assists = IF(assists > 0, assists - 1, 0) WHERE match_id = ? AND player_id = ?', [$id, $e['assist_id']]);
                // le colonne sono senza segno: si toglie la riga quando arriva a zero invece di andare sotto
                q('DELETE FROM match_links WHERE match_id = ? AND assister_id = ? AND scorer_id = ? AND n <= 1', [$id, $e['assist_id'], $e['player_id']]);
                q('UPDATE match_links SET n = n - 1 WHERE match_id = ? AND assister_id = ? AND scorer_id = ?', [$id, $e['assist_id'], $e['player_id']]);
            }
            live_bump_score($id, $own ? ($e['team'] === 'A' ? 'B' : 'A') : (string) $e['team'], -1);
        }
        q('DELETE FROM match_events WHERE id = ?', [$eventId]);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        error_log('live_remove_event: ' . $ex->getMessage());
        return 'Non è stato possibile togliere l\'evento: riprova.';
    }
    return null;
}

/** Aggiunge (o toglie) un gol al risultato: al primo gol il risultato parte da 0-0. */
function live_bump_score(int $matchId, string $team, int $delta): void
{
    $a = $team === 'A' ? $delta : 0;
    $b = $team === 'B' ? $delta : 0;
    // i punteggi sono senza segno: il conto si fa con segno (CAST) e non scende sotto zero
    q('UPDATE matches SET score_a = GREATEST(CAST(COALESCE(score_a, 0) AS SIGNED) + ?, 0),
                          score_b = GREATEST(CAST(COALESCE(score_b, 0) AS SIGNED) + ?, 0) WHERE id = ?', [$a, $b, $matchId]);
}
