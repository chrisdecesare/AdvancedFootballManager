<?php
/*
 * Risultato di una partita: il salvataggio di gol, assist e autogol (match.php, «Salva» e «Salva e concludi partita»), le coppie
 * assist→gol per l'intesa, e l'inserimento una tantum dei gol della partita dell'8 ottobre 2026 (match_oneoff_goals_oct8, lib/db.php).
 */

/**
 * Salva gol, assist e autogol dei giocatori indicati e il punteggio; con $finish conclude la partita (voti aperti, scommesse su esito
 * e marcatori pagate, premi per gol e assist, credito fanta). È la procedura del pulsante «Salva» di match.php, usata anche
 * dall'inserimento una tantum qui sotto.
 * @param array<string, array<int, int>> $stats 'goals', 'assists', 'own_goals': id giocatore => numero
 * @return string|null il messaggio d'errore, oppure null se è andata
 */
function match_apply_result(array $match, array $stats, ?int $scoreA, ?int $scoreB, bool $finish, int $actor): ?string
{
    $id = (int) $match['id'];
    if ($finish && ($scoreA === null || $scoreB === null)) {
        return 'Inserisci il risultato prima di chiudere la partita.';
    }
    $finishNow = $finish && $match['status'] !== 'giocata';   // una partita già giocata non riapre i voti
    db()->beginTransaction();
    try {
        foreach (['goals', 'assists', 'own_goals'] as $k) {
            foreach ($stats[$k] ?? [] as $p => $v) {
                q("UPDATE match_players SET $k = ? WHERE match_id = ? AND player_id = ?", [max(0, min(99, (int) $v)), $id, (int) $p]);
            }
        }
        q('UPDATE matches SET score_a = ?, score_b = ? WHERE id = ?', [$scoreA, $scoreB, $id]);
        if ($finishNow) {
            q("UPDATE matches SET status = 'giocata', voting_open = 1, voting_ends_at = ? WHERE id = ?", [default_voting_end(), $id]);
            push_notify_voting($id, true, $actor);   // parte dopo che la pagina è stata inviata
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    if ($match['status'] === 'giocata' || $finishNow) {
        bets_resettle_result($id);   // risultato salvato o corretto: si pagano (o si rifanno) le scommesse su esito e marcatori e i premi
        fanta_win_credits_sync($id);   // e il credito fanta a chi ha vinto (lib/fanta.php)
    }
    return null;
}

/** Scrive le coppie assist→gol della partita al posto di quelle che c'erano. @param array<string,int> $links "assistente>marcatore" => volte */
function match_replace_links(int $matchId, array $links): void
{
    q('DELETE FROM match_links WHERE match_id = ?', [$matchId]);
    foreach ($links as $k => $n) {
        [$a, $s] = array_map('intval', explode('>', $k));
        q('INSERT INTO match_links (match_id, assister_id, scorer_id, n) VALUES (?, ?, ?, ?)', [$matchId, $a, $s, max(1, min(20, (int) $n))]);
    }
}

/**
 * Inserimento una tantum dei gol e degli assist della partita dell'8 ottobre 2026 (lo chiama ensure_schema, schema 54, una volta sola).
 * Cerca tra le partite di quel giorno (in programma o giocate) quella in cui tutti i giocatori qui sotto sono in squadra, ognuno
 * riconosciuto dal nome di battesimo, con le due squadre già fatte come qui (cinque contro cinque): se è una sola scrive gol,
 * assist, coppie assist→gol e punteggio e conclude la partita come «Salva e concludi» (voti aperti, scommesse su esito e marcatori
 * pagate, premi per gol e assist). Se qualcosa non torna non salva niente.
 * @return string cosa è successo (va nel registro delle attività)
 */
function match_oneoff_goals_oct8(): string
{
    // [chi segna, chi fa l'assist (o null)], nell'ordine in cui sono arrivati
    $events = [['Giovanni', 'Cristian'], ['Davide', 'Danilo'], ['Cristian', 'Angelo'], ['Paolo', 'Adriano'], ['Angelo', null],
        ['Cristian', 'Luigi'], ['Luigi', 'Giovanni'], ['Angelo', null], ['Luigi', null], ['Adolfo', null], ['Paolo', 'Adriano'],
        ['Angelo', 'Adolfo'], ['Adriano', null], ['Giovanni', null], ['Paolo', 'Davide'], ['Luigi', null], ['Paolo', 'Vincenzo'],
        ['Luigi', 'Adolfo']];
    $sides = [['Luigi', 'Angelo', 'Giovanni', 'Cristian', 'Adolfo'], ['Paolo', 'Adriano', 'Davide', 'Danilo', 'Vincenzo']];
    $first = function (string $name): string {
        $n = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)), 'UTF-8');
        return explode(' ', $n)[0];
    };

    $found = [];
    $why = [];
    foreach (q("SELECT id FROM matches WHERE DATE(match_date) = '2026-10-08' AND status IN ('programmata', 'giocata') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        $mid = (int) $mid;
        $roster = q('SELECT mp.player_id, mp.team, p.name FROM match_players mp JOIN players p ON p.id = mp.player_id WHERE mp.match_id = ? AND mp.team IS NOT NULL', [$mid])->fetchAll();
        $by = [];   // nome di battesimo => id giocatori
        foreach ($roster as $r) {
            $by[$first($r['name'])][] = $r;
        }
        $team = [];
        $ids = [];
        $problem = null;
        foreach ($sides as $i => $names) {
            foreach ($names as $name) {
                $hit = $by[mb_strtolower($name, 'UTF-8')] ?? [];
                if (count($hit) !== 1) {
                    $problem = $name . ' ' . (count($hit) ? 'è più di uno' : 'non è in squadra');
                    break 2;
                }
                $ids[$name] = (int) $hit[0]['player_id'];
                $team[$i][$hit[0]['team']] = true;
            }
        }
        if (!$problem && (count($team[0]) !== 1 || count($team[1]) !== 1 || array_key_first($team[0]) === array_key_first($team[1]))) {
            $problem = 'le squadre non sono quelle attese';
        }
        if ($problem) {
            $why[] = 'partita ' . $mid . ': ' . $problem;
            continue;
        }
        $found[$mid] = [$ids, [array_key_first($team[0]), array_key_first($team[1])], $roster];
    }
    if (count($found) !== 1) {
        return $found ? 'più partite adatte (' . implode(', ', array_keys($found)) . '): non ho salvato niente'
            : 'nessuna partita adatta, non ho salvato niente' . ($why ? ' (' . implode('; ', $why) . ')' : '');
    }
    $mid = (int) array_key_first($found);
    [$ids, [$teamX, $teamY], $roster] = $found[$mid];

    $stats = ['goals' => [], 'assists' => [], 'own_goals' => []];
    foreach ($roster as $r) {   // chi non compare nei dati torna a zero
        foreach (array_keys($stats) as $k) {
            $stats[$k][(int) $r['player_id']] = 0;
        }
    }
    $score = [$teamX => 0, $teamY => 0];
    $links = [];
    $sideOf = [];
    foreach ($sides[0] as $n) {
        $sideOf[$n] = $teamX;
    }
    foreach ($sides[1] as $n) {
        $sideOf[$n] = $teamY;
    }
    foreach ($events as [$scorer, $assister]) {
        if ($assister !== null && $sideOf[$assister] !== $sideOf[$scorer]) {
            return 'assist e gol di squadre diverse (' . $assister . ' → ' . $scorer . '): non ho salvato niente';
        }
        $stats['goals'][$ids[$scorer]]++;
        $score[$sideOf[$scorer]]++;
        if ($assister !== null) {
            $stats['assists'][$ids[$assister]]++;
            $k = $ids[$assister] . '>' . $ids[$scorer];
            $links[$k] = ($links[$k] ?? 0) + 1;
        }
    }
    $match = get_match($mid);
    $actor = (int) q("SELECT id FROM users WHERE role = 'admin' AND status = 'attivo' ORDER BY id LIMIT 1")->fetchColumn();
    $err = match_apply_result($match, $stats, $score['A'], $score['B'], true, $actor);
    if ($err) {
        return 'partita ' . $mid . ': ' . $err;
    }
    match_replace_links($mid, $links);
    return 'partita ' . $mid . ' fatta: ' . $score['A'] . '–' . $score['B'] . ', ' . array_sum($stats['goals']) . ' gol, ' . array_sum($stats['assists'])
        . ' assist, ' . count($links) . ' coppie; votazioni aperte';
}
