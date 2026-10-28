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
 * Inserimento una tantum dei gol e degli assist di una partita (lo chiama ensure_schema, una volta sola per partita).
 * Cerca tra le partite di $date (in programma o giocate) quella in cui tutti i giocatori dei dati sono in squadra, ognuno
 * riconosciuto dal nome (la prima parola, tolti simboli ed emoji, o uno degli $aliases), con gli schieramenti $sides dentro
 * due squadre diverse già fatte: se è una sola scrive gol, assist, coppie assist→gol e punteggio e conclude la partita come
 * «Salva e concludi» (voti aperti, scommesse su esito e marcatori pagate, premi per gol e assist). Chi in squadra non compare nei
 * dati torna a zero e non serve trovarlo. Se qualcosa non torna non salva niente.
 * @param array<int, array{0: string, 1: ?string}> $events [chi segna, chi fa l'assist (o null)], nell'ordine in cui sono arrivati
 * @param array<int, string[]> $sides i nomi dei due schieramenti (devono stare in due squadre diverse)
 * @param array<string, string[]> $aliases nome => altri modi in cui compare nell'app (minuscoli, senza simboli)
 * @return string cosa è successo (va nel registro delle attività)
 */
function match_oneoff_goals(string $date, array $events, array $sides, array $aliases = []): string
{
    $word = function (string $name): array {   // prima e ultima parola, minuscole e senza simboli
        $n = mb_strtolower(trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N} ]+/u', '', $name))), 'UTF-8');
        $t = $n === '' ? [''] : explode(' ', $n);
        return [$t[0], count($t) > 1 ? end($t) : null];
    };
    // quali giocatori del dato compaiono davvero (chi non segna e non fa assist non va cercato)
    $named = [];
    foreach ($events as [$scorer, $assister]) {
        $named[$scorer] = true;
        if ($assister !== null) {
            $named[$assister] = true;
        }
    }

    $found = [];
    $why = [];
    foreach (q("SELECT id FROM matches WHERE DATE(match_date) = ? AND status IN ('programmata', 'giocata') ORDER BY id", [$date])->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        $mid = (int) $mid;
        $roster = q('SELECT mp.player_id, mp.team, p.name FROM match_players mp JOIN players p ON p.id = mp.player_id WHERE mp.match_id = ? AND mp.team IS NOT NULL', [$mid])->fetchAll();
        $team = [];
        $ids = [];
        $problem = null;
        foreach ($sides as $i => $names) {
            foreach ($names as $name) {
                if (!isset($named[$name])) {
                    continue;
                }
                $want = array_merge([mb_strtolower($name, 'UTF-8')], $aliases[$name] ?? []);
                $hit = [];
                $exact = [];   // chi si chiama proprio così (nome intero) vince su chi ha solo la stessa prima parola: «Giovanni» e «Giovanni Mannara»
                foreach ($roster as $r) {
                    [$first, $last] = $word($r['name']);
                    $full = mb_strtolower(trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N} ]+/u', '', $r['name']))), 'UTF-8');
                    if (in_array($full, $want, true) || in_array($first, $want, true) || ($last !== null && mb_strlen($last) >= 4 && in_array($last, $want, true))) {
                        $hit[] = $r;
                        if ($full === mb_strtolower($name, 'UTF-8') || in_array($full, $aliases[$name] ?? [], true)) {
                            $exact[] = $r;
                        }
                    }
                }
                if (count($hit) > 1 && count($exact) === 1) {
                    $hit = $exact;
                }
                if (count($hit) !== 1) {
                    $problem = $name . ' ' . (count($hit) ? 'è più di uno' : 'non è in squadra');
                    break 2;
                }
                $ids[$name] = (int) $hit[0]['player_id'];
                $team[$i][$hit[0]['team']] = true;
            }
        }
        if (!$problem && (count($team[0] ?? []) !== 1 || count($team[1] ?? []) !== 1 || array_key_first($team[0]) === array_key_first($team[1]))) {
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

/** La partita dell'8 ottobre 2026 (schema 54): cinque contro cinque. */
function match_oneoff_goals_oct8(): string
{
    return match_oneoff_goals('2026-10-08', [['Giovanni', 'Cristian'], ['Davide', 'Danilo'], ['Cristian', 'Angelo'], ['Paolo', 'Adriano'],
        ['Angelo', null], ['Cristian', 'Luigi'], ['Luigi', 'Giovanni'], ['Angelo', null], ['Luigi', null], ['Adolfo', null],
        ['Paolo', 'Adriano'], ['Angelo', 'Adolfo'], ['Adriano', null], ['Giovanni', null], ['Paolo', 'Davide'], ['Luigi', null],
        ['Paolo', 'Vincenzo'], ['Luigi', 'Adolfo']],
        [['Luigi', 'Angelo', 'Giovanni', 'Cristian', 'Adolfo'], ['Paolo', 'Adriano', 'Davide', 'Danilo', 'Vincenzo']]);
}

/**
 * La partita del 15 ottobre 2026 (schema 61): Smarcati (Angelo, Agozz, Adriano, Luigi, Paolo, Chris) contro Sgamati (Davide, che in
 * campo compare come Ruokowski, Giovanni, Vincenzo, Adolfo, Agostino, Nicola, che compare come «N⚡»). 35 gol, 26 assist, 20–15.
 */
function match_oneoff_goals_oct15(): string
{
    return match_oneoff_goals('2026-10-15', [
        ['Vincenzo', null], ['Vincenzo', 'Davide'], ['Angelo', 'Luigi'], ['Paolo', 'Angelo'], ['Angelo', 'Luigi'], ['Agozz', 'Paolo'],
        ['Vincenzo', 'Nicola'], ['Chris', 'Paolo'], ['Adriano', 'Chris'], ['Chris', 'Luigi'], ['Luigi', 'Angelo'], ['Paolo', 'Luigi'],
        ['Chris', 'Angelo'], ['Agozz', null], ['Vincenzo', 'Nicola'], ['Luigi', 'Chris'], ['Giovanni', 'Adolfo'], ['Luigi', 'Chris'],
        ['Adolfo', null], ['Adolfo', null], ['Davide', 'Vincenzo'], ['Vincenzo', 'Giovanni'], ['Luigi', 'Paolo'], ['Giovanni', 'Adolfo'],
        ['Chris', 'Luigi'], ['Adriano', null], ['Paolo', 'Luigi'], ['Vincenzo', 'Giovanni'], ['Paolo', null], ['Vincenzo', 'Adolfo'],
        ['Giovanni', null], ['Angelo', 'Luigi'], ['Adolfo', null], ['Angelo', 'Luigi'], ['Davide', null]],
        [['Angelo', 'Agozz', 'Adriano', 'Luigi', 'Paolo', 'Chris'], ['Davide', 'Giovanni', 'Vincenzo', 'Adolfo', 'Nicola']],
        ['Davide' => ['ruokowski'], 'Nicola' => ['n'], 'Agozz' => ['agoz']]);
}

/**
 * La partita del 28 ottobre 2026 (schema 69): Ricercati (Marco, Luigi, Fabrizio, Vincenzo, Giovanni M) contro Ricercatori (Paolo, Prisco,
 * Giovanni DS, Danilo, Adriano). 23 gol, 9–14.
 */
function match_oneoff_goals_oct28(): string
{
    return match_oneoff_goals('2026-10-28', [
        ['Marco', 'Fabrizio'], ['Luigi', 'Vincenzo'], ['Marco', 'Fabrizio'], ['Fabrizio', 'Vincenzo'], ['Giovanni M', 'Luigi'],
        ['Marco', 'Vincenzo'], ['Vincenzo', 'Fabrizio'], ['Marco', 'Vincenzo'], ['Luigi', 'Fabrizio'],
        ['Paolo', 'Giovanni DS'], ['Prisco', 'Giovanni DS'], ['Danilo', 'Prisco'], ['Paolo', 'Prisco'], ['Danilo', 'Prisco'], ['Paolo', null],
        ['Adriano', null], ['Prisco', 'Danilo'], ['Danilo', 'Prisco'], ['Giovanni DS', 'Paolo'], ['Paolo', 'Giovanni DS'], ['Paolo', 'Danilo'],
        ['Prisco', 'Danilo'], ['Prisco', 'Paolo']],
        [['Marco', 'Luigi', 'Fabrizio', 'Vincenzo', 'Giovanni M'], ['Paolo', 'Prisco', 'Giovanni DS', 'Danilo', 'Adriano']],
        ['Giovanni M' => ['mannara'], 'Giovanni DS' => ['giovanni']]);
}

/**
 * Correzione una tantum delle scommesse a soglia (over/under) dell'admin sulla partita del 28 ottobre 2026, giocate leggendo male i
 * numeri: ogni selezione sbagliata sposta la soglia al valore più vicino che vince (over 9,5 con 8 gol → over 7,5; under 10,5 con 11 gol
 * → under 11,5), nelle singole e nelle gambe delle multiple. Quota e puntata restano quelle giocate. Le altre selezioni (esito, marcatori...)
 * non si toccano. Dopo le modifiche la partita si ripaga da capo (bets_resettle_result). Ogni cambio finisce nel messaggio, cioè nel registro.
 */
function bets_oneoff_admin_lines_oct28(): string
{
    $mid = (int) q("SELECT id FROM matches WHERE DATE(match_date) = '2026-10-28' AND status = 'giocata' ORDER BY id LIMIT 1")->fetchColumn();
    $admin = (int) q("SELECT player_id FROM users WHERE role = 'admin' AND status = 'attivo' AND player_id IS NOT NULL ORDER BY id LIMIT 1")->fetchColumn();
    if (!$mid || !$admin) {
        return 'partita del 28 ottobre giocata o giocatore admin non trovati: non ho cambiato niente';
    }
    $m = get_match($mid);
    $count = ['overunder' => [null => (int) $m['score_a'] + (int) $m['score_b']], 'ousq' => ['A' => (int) $m['score_a'], 'B' => (int) $m['score_b']],
        'ouassq' => ['A' => 0, 'B' => 0], 'ougol' => [], 'ouassist' => []];
    foreach (q('SELECT player_id, team, goals, assists FROM match_players WHERE match_id = ? AND team IS NOT NULL', [$mid])->fetchAll() as $r) {
        $count['ougol'][(string) $r['player_id']] = (int) $r['goals'];
        $count['ouassist'][(string) $r['player_id']] = (int) $r['assists'];
        $count['ouassq'][$r['team']] += (int) $r['assists'];
    }
    /** La scelta spostata sul valore che vince, o null se è già vincente o non si può valutare. */
    $fix = function (string $market, string $pick) use ($count): ?string {
        $l = bet_line_parse($market, $pick);
        $n = $l ? ($count[$market][$l['subject']] ?? null) : null;
        if ($n === null) {
            return null;
        }
        $wins = $l['side'] === 'O' ? $l['line'] < $n : $l['line'] > $n;
        if ($wins) {
            return null;
        }
        if ($l['side'] === 'O') {
            return $n >= 1 ? bet_line_pick($l['subject'], 'O', $n - 0.5) : bet_line_pick($l['subject'], 'U', 0.5);
        }
        return bet_line_pick($l['subject'], 'U', $n + 0.5);
    };
    $lines = implode("','", array_keys(BET_LINE_MARKETS));
    $done = [];
    bet_atomic(function () use ($mid, $admin, $fix, $lines, &$done) {
        foreach (q("SELECT id, market, pick FROM bets WHERE match_id = ? AND player_id = ? AND market IN ('$lines') AND status <> 'rimborsata'", [$mid, $admin])->fetchAll() as $b) {
            if (($new = $fix($b['market'], $b['pick'])) !== null) {
                q('UPDATE IGNORE bets SET pick = ? WHERE id = ?', [$new, $b['id']]);
                $done[] = 'singola ' . $b['id'] . ' ' . $b['market'] . ' ' . $b['pick'] . '→' . $new;
            }
        }
        foreach (q("SELECT cl.id, cl.combo_id, cl.market, cl.pick FROM combo_legs cl JOIN combo_bets cb ON cb.id = cl.combo_id
                    WHERE cl.match_id = ? AND cb.player_id = ? AND cl.market IN ('$lines') AND cl.status <> 'rimborsata'", [$mid, $admin])->fetchAll() as $g) {
            if (($new = $fix($g['market'], $g['pick'])) !== null) {
                q('UPDATE IGNORE combo_legs SET pick = ? WHERE id = ?', [$new, $g['id']]);
                $done[] = 'multipla ' . $g['combo_id'] . ' ' . $g['market'] . ' ' . $g['pick'] . '→' . $new;
            }
        }
        bets_resettle_result($mid);
    });
    return $done ? 'partita ' . $mid . ', ' . count($done) . ' selezioni spostate: ' . implode('; ', $done) : 'partita ' . $mid . ': niente da spostare';
}

/** I gol del 28 ottobre sono entrati? (il segnaposto di ensure_schema comincia con «partita N fatta») */
function match_oneoff_goals_oct28_ok(): bool
{
    return (bool) preg_match('/^partita \d+ fatta:/', (string) meta_get('oneoff_oct28'));
}
