<?php
/*
 * Importa la cronaca di una partita (match.php, solo chi gestisce la lega): si incolla il testo con i gol («Gol Paolo assist Davide»,
 * «Assist Giovanni gol Luigi», «Gol Luigi»...), il sito riconosce i giocatori, mostra un'anteprima e, alla conferma, scrive gol,
 * assist, chi ha fatto assist a chi (match_links, per l'intesa) e punteggio, e se si vuole conclude la partita.
 *
 * Il testo di una riga può avere un'intestazione di WhatsApp ("[08/10, 19:15] Nome:") che si toglie. Dove una riga ha «(alias X)»,
 * X prende il posto del nome che lo precede: quel nome non viene mai mostrato né salvato (neppure nei campi nascosti della pagina,
 * che contengono solo il testo già ripulito, vedi import_clean_text).
 */

/** Minuscole, senza accenti né punteggiatura, spazi singoli: per confrontare i nomi. */
function import_norm(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i',
        'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', $s)));
}

/**
 * Legge il testo e ne ricava gli eventi, uno per riga con un gol: ['n' => numero riga, 'own' => autogol, 'scorer' => nome o null,
 * 'assist' => nome o null, 'hint' => alternativa tra parentesi o null]. I nomi sono già ripuliti dagli «alias».
 * Le righe senza un gol (chiacchiere) si saltano; ne ritorna anche il conteggio.
 * @return array{0: array<int, array>, 1: int}
 */
function import_parse(string $text): array
{
    $events = [];
    $skipped = 0;
    $n = 0;
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $n++;
        $line = preg_replace('/^\[[^\]]{4,30}\]\s*[^:]{1,60}:\s*/u', '', $line);   // intestazione di WhatsApp: [08/10, 19:15] Nome:
        $parts = preg_split('/\b(autogol|gol|goal|rete|assist)\b/iu', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $ev = ['n' => $n, 'own' => false, 'scorer' => null, 'assist' => null, 'hint' => null];
        $seenGoal = false;
        for ($i = 0; $i < count($parts); $i++) {
            $kw = mb_strtolower($parts[$i], 'UTF-8');
            if (!in_array($kw, ['autogol', 'gol', 'goal', 'rete', 'assist'], true)) {
                continue;
            }
            [$name, $hint] = import_phrase($parts[$i + 1] ?? '');
            if ($name === '') {
                continue;
            }
            if ($kw === 'assist') {
                $ev['assist'] = $name;
            } elseif (!$seenGoal) {
                $seenGoal = true;
                $ev['own'] = $kw === 'autogol';
                $ev['scorer'] = $name;
                $ev['hint'] = $hint;
            }
        }
        if ($ev['scorer'] === null) {
            $skipped++;
            continue;
        }
        if ($ev['own']) {
            $ev['assist'] = null;   // un autogol non ha assist
        }
        $events[] = $ev;
    }
    return [$events, $skipped];
}

/**
 * Il nome in un pezzo di riga, senza parole di contorno. Con «(alias X)» il nome è X e il resto si scarta del tutto; con un'altra
 * parentesi «(X)» il nome resta quello prima e X è un'alternativa da provare per prima.
 * @return array{0: string, 1: ?string} [nome, alternativa]
 */
function import_phrase(string $s): array
{
    if (preg_match('/\(\s*alias\s+([^)]+?)\s*\)/iu', $s, $m)) {
        return [trim(preg_replace('/\s+/u', ' ', $m[1])), null];
    }
    $hint = null;
    if (preg_match('/\(\s*([^)]+?)\s*\)/u', $s, $m)) {
        $hint = trim(preg_replace('/\s+/u', ' ', $m[1]));
        $s = str_replace($m[0], ' ', $s);
    }
    $s = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\s,;:.\-]+$/u', '', $s)));
    return [$s, $hint];
}

/** Il testo ripulito (un evento per riga, solo con i nomi buoni): è quello che resta nei campi nascosti dell'anteprima. */
function import_clean_text(array $events): string
{
    $out = [];
    foreach ($events as $e) {
        $out[] = ($e['own'] ? 'autogol ' : 'gol ') . $e['scorer'] . ($e['hint'] !== null ? ' (' . $e['hint'] . ')' : '')
            . ($e['assist'] !== null ? ' assist ' . $e['assist'] : '');
    }
    return implode("\n", $out);
}

/**
 * Trova il giocatore di un nome tra chi ha giocato: nome intero, solo nome o solo cognome, se è uno solo.
 * @param array<int, array> $players righe con player_id e name
 * @return int|null null se non c'è o se sono più d'uno
 */
function import_match_name(string $name, array $players): ?int
{
    $p = import_norm($name);
    if ($p === '') {
        return null;
    }
    $found = [];
    foreach ($players as $r) {
        $full = import_norm($r['name']);
        $tok = explode(' ', $full);
        if ($p === $full || $p === $tok[0] || $p === end($tok)) {
            $found[(int) $r['player_id']] = true;
        }
    }
    return count($found) === 1 ? (int) array_key_first($found) : null;
}

/**
 * Risolve gli eventi: per ogni nome il giocatore (da solo, oppure scelto da chi importa in $picks[riga]['s'|'a']; 0 = ignora la riga).
 * Ritorna gli eventi con 'scorer_id' e 'assist_id' (null se mancano), gli errori e le scelte ancora da fare.
 * @param array<int, array> $players chi ha giocato la partita (con team)
 * @param array<int, array<string, int>> $picks scelte di chi importa
 * @return array{events: array, errors: string[], todo: array}
 */
function import_resolve(array $events, array $players, array $picks): array
{
    $team = [];
    foreach ($players as $r) {
        $team[(int) $r['player_id']] = $r['team'];
    }
    $out = [];
    $errors = [];
    $todo = [];
    foreach ($events as $e) {
        $n = $e['n'];
        if (isset($picks[$n]['s']) && (int) $picks[$n]['s'] === 0) {
            continue;   // riga ignorata da chi importa
        }
        $sid = isset($picks[$n]['s']) ? (int) $picks[$n]['s']
            : (($e['hint'] !== null ? import_match_name($e['hint'], $players) : null) ?? import_match_name($e['scorer'], $players));
        $aid = null;
        if ($e['assist'] !== null && isset($picks[$n]['a']) && (int) $picks[$n]['a'] === 0) {
            $e['assist'] = null;   // chi importa ha scelto «senza assist»
        }
        if ($e['assist'] !== null) {
            $aid = isset($picks[$n]['a']) ? (int) $picks[$n]['a'] : import_match_name($e['assist'], $players);
        }
        $e['scorer_id'] = $sid && isset($team[$sid]) ? $sid : null;
        $e['assist_id'] = $aid && isset($team[$aid]) ? $aid : null;
        // le scelte si limitano alla squadra dell'altro giocatore, se è già noto (l'assist è di un compagno)
        $e['team_s'] = !$e['own'] && $e['assist_id'] ? $team[$e['assist_id']] : null;
        $e['team_a'] = $e['scorer_id'] ? $team[$e['scorer_id']] : null;
        if ($e['scorer_id'] === null) {
            $todo[] = ['n' => $n, 'role' => 's', 'label' => $e['scorer'], 'team' => $e['team_s']];
        }
        if ($e['assist'] !== null && $e['assist_id'] === null) {
            $todo[] = ['n' => $n, 'role' => 'a', 'label' => $e['assist'], 'team' => $e['team_a']];
        }
        if ($e['scorer_id'] && $e['assist_id']) {
            if ($e['scorer_id'] === $e['assist_id']) {
                $errors[] = 'Riga ' . $n . ': chi fa l\'assist e chi segna sono la stessa persona.';
            } elseif ($team[$e['scorer_id']] !== $team[$e['assist_id']]) {
                $errors[] = 'Riga ' . $n . ': assist e gol sono di squadre diverse.';
            }
        }
        $out[] = $e;
    }
    return ['events' => $out, 'errors' => $errors, 'todo' => $todo];
}

/**
 * Totali degli eventi risolti: gol, assist, autogol per giocatore, coppie assist→gol e punteggio.
 * @return array{goals: array<int,int>, assists: array<int,int>, own: array<int,int>, links: array<string,int>, score: array{A:int,B:int}}
 */
function import_totals(array $events, array $players): array
{
    $team = [];
    foreach ($players as $r) {
        $team[(int) $r['player_id']] = $r['team'];
    }
    $t = ['goals' => [], 'assists' => [], 'own' => [], 'links' => [], 'score' => ['A' => 0, 'B' => 0]];
    foreach ($events as $e) {
        if (!$e['scorer_id']) {
            continue;
        }
        $s = $e['scorer_id'];
        if ($e['own']) {
            $t['own'][$s] = ($t['own'][$s] ?? 0) + 1;
            $t['score'][$team[$s] === 'A' ? 'B' : 'A']++;
            continue;
        }
        $t['goals'][$s] = ($t['goals'][$s] ?? 0) + 1;
        $t['score'][$team[$s]]++;
        if ($e['assist_id']) {
            $t['assists'][$e['assist_id']] = ($t['assists'][$e['assist_id']] ?? 0) + 1;
            $k = $e['assist_id'] . '>' . $s;
            $t['links'][$k] = ($t['links'][$k] ?? 0) + 1;
        }
    }
    return $t;
}

/**
 * Salva gol, assist e autogol dei giocatori indicati e il punteggio; con $finish conclude la partita (voti aperti, scommesse su esito
 * e marcatori pagate, premi per gol e assist, credito fanta). È la procedura del pulsante «Salva» di match.php, usata anche
 * dall'importazione.
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
