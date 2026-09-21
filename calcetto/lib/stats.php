<?php
/*
 * Statistiche calcolate dalle partite giocate + eventuali correzioni manuali
 * dell'admin (campi adj_* della tabella players, per lo storico pre-sito).
 */

/** Giocatori dei gruppi visibili ora (o dell'ambito indicato: null = tutti). */
function all_players(bool $only_active = false, $scope = 'current'): array
{
    $s = $scope === 'current' ? scope_ids() : $scope;
    $sql = 'SELECT p.* FROM players p WHERE ' . player_scope_sql('p.id', $s, true)
        . ($only_active ? ' AND p.active = 1' : '') . ' ORDER BY p.name';
    $out = [];
    foreach (q($sql)->fetchAll() as $p) {
        $out[(int) $p['id']] = $p;
    }
    return $out;
}

function get_player(int $id): ?array
{
    return q('SELECT * FROM players WHERE id = ?', [$id])->fetch() ?: null;
}

function get_match(int $id): ?array
{
    return q('SELECT * FROM matches WHERE id = ?', [$id])->fetch() ?: null;
}

/** Partite giocate dei gruppi visibili ora (o dell'ambito indicato). */
function played_matches($scope = 'current'): array
{
    static $c = [];
    $s = $scope === 'current' ? scope_ids() : $scope;
    $k = scope_key($s);
    if (!isset($c[$k])) {
        $c[$k] = q("SELECT * FROM matches WHERE status = 'giocata' AND " . scope_sql('group_id', $s, true)
            . ' ORDER BY match_date DESC, id DESC')->fetchAll();
    }
    return $c[$k];
}

/** Prossima partita: la prima programmata a partire da 3 ore fa (così resta visibile mentre si gioca). */
function next_match(): ?array
{
    return q("SELECT * FROM matches WHERE status = 'programmata' AND match_date >= ? AND " . scope_sql('group_id')
        . ' ORDER BY match_date ASC LIMIT 1', [date('Y-m-d H:i:s', time() - 3 * 3600)])->fetch() ?: null;
}

function last_played_match(): ?array
{
    return played_matches()[0] ?? null;
}

/** Aggiunge a una partita programmata i giocatori attivi DEL SUO GRUPPO che non ci sono ancora (stato "in attesa"). */
function sync_match_players(int $match_id): void
{
    q("INSERT IGNORE INTO match_players (match_id, player_id)
       SELECT m.id, p.id FROM matches m
       JOIN player_groups pg ON pg.group_id = m.group_id
       JOIN players p ON p.id = pg.player_id
       WHERE m.id = ? AND p.active = 1", [$match_id]);
}

/**
 * Azzera le risposte "ci sono / non ci sono" di una partita (tutti tornano "in attesa", squadre e posizioni saltano)
 * e i promemoria già mandati: serve quando la partita cambia giorno e le vecchie risposte non valgono più.
 */
function reset_match_responses(int $match_id): void
{
    q("UPDATE match_players SET availability = 'in_attesa', team = NULL, slot = NULL WHERE match_id = ?", [$match_id]);
    q('DELETE FROM push_log WHERE match_id = ?', [$match_id]);
}

function match_roster(int $match_id): array
{
    return q('SELECT mp.*, p.name, p.photo, p.shirt_number, p.position, p.position2, p.foot
              FROM match_players mp JOIN players p ON p.id = mp.player_id
              WHERE mp.match_id = ? ORDER BY p.name', [$match_id])->fetchAll();
}

function result_for(array $m, string $team): string
{
    $a = (int) $m['score_a'];
    $b = (int) $m['score_b'];
    if ($a === $b) {
        return 'N';
    }
    return (($team === 'A') === ($a > $b)) ? 'V' : 'S';
}

/** [match_id][player_id] => ['avg' => media dei voti ricevuti, 'n' => numero voti] */
function match_vote_averages(): array
{
    static $c = null;
    if ($c === null) {
        $c = [];
        $rows = q('SELECT match_id, rated_id, AVG(vote) AS avg_vote, COUNT(*) AS n
                   FROM ratings GROUP BY match_id, rated_id')->fetchAll();
        foreach ($rows as $r) {
            $c[(int) $r['match_id']][(int) $r['rated_id']] = ['avg' => (float) $r['avg_vote'], 'n' => (int) $r['n']];
        }
    }
    return $c;
}

/** Orario di fine votazioni proposto quando si aprono: adesso + VOTING_HOURS. */
function default_voting_end(): string
{
    return date('Y-m-d H:i:s', time() + (int) VOTING_HOURS * 3600);
}

/**
 * Chiude le votazioni di una partita (una volta sola, anche se due richieste arrivano insieme): voti d'ufficio a chi non ha votato
 * e notifica di chiusura. Ritorna null se erano già chiuse, altrimenti i nomi di chi non ha votato.
 * @return string[]|null
 */
function close_voting_now(int $matchId, ?int $actorUser = null): ?array
{
    $n = q("UPDATE matches SET voting_open = 0, voting_ends_at = NULL WHERE id = ? AND status = 'giocata' AND voting_open = 1", [$matchId])->rowCount();
    if ($n !== 1) {
        return null;
    }
    $late = apply_default_votes($matchId);
    push_notify_voting($matchId, false, $actorUser);
    return $late;
}

/** Chiude le votazioni il cui orario di fine è passato (a ogni richiesta, e da cron.php). */
function close_due_votings(): void
{
    foreach (q("SELECT id FROM matches WHERE status = 'giocata' AND voting_open = 1 AND voting_ends_at IS NOT NULL AND voting_ends_at <= ?",
        [date('Y-m-d H:i:s')])->fetchAll(PDO::FETCH_COLUMN) as $id) {
        close_voting_now((int) $id);
    }
}

/** Il voto d'ufficio come si scrive a schermo: "6" (o "6,5"). */
function default_vote_label(): string
{
    return rtrim(rtrim(fmt_num(DEFAULT_VOTE), '0'), ',');
}

/**
 * Alla chiusura delle votazioni: chi ha giocato e non ha votato (nessun MVP scelto) riceve DEFAULT_VOTE come voto d'ufficio
 * a ogni altro giocatore della partita (mai a se stesso). I voti veri non si toccano.
 * @return string[] nomi di chi non ha votato
 */
function apply_default_votes(int $matchId): array
{
    $played = q('SELECT mp.player_id, p.name FROM match_players mp JOIN players p ON p.id = mp.player_id
                 WHERE mp.match_id = ? AND mp.team IS NOT NULL ORDER BY p.name', [$matchId])->fetchAll();
    $voted = array_map('intval', q('SELECT voter_id FROM mvp_votes WHERE match_id = ?', [$matchId])->fetchAll(PDO::FETCH_COLUMN));
    $names = [];
    db()->beginTransaction();
    foreach ($played as $voter) {
        $vid = (int) $voter['player_id'];
        if (in_array($vid, $voted, true)) {
            continue;
        }
        $names[] = $voter['name'];
        foreach ($played as $rated) {
            if ((int) $rated['player_id'] !== $vid) {
                q('INSERT IGNORE INTO ratings (match_id, voter_id, rated_id, vote, is_auto) VALUES (?, ?, ?, ?, 1)',
                    [$matchId, $vid, (int) $rated['player_id'], DEFAULT_VOTE]);
            }
        }
    }
    db()->commit();
    return $names;
}

/** [match_id][player_id] => numero di voti MVP */
function match_mvp_counts(): array
{
    static $c = null;
    if ($c === null) {
        $c = [];
        foreach (q('SELECT match_id, voted_id, COUNT(*) AS n FROM mvp_votes GROUP BY match_id, voted_id')->fetchAll() as $r) {
            $c[(int) $r['match_id']][(int) $r['voted_id']] = (int) $r['n'];
        }
    }
    return $c;
}

/**
 * MVP di una partita: più voti MVP; a parità vince la media voto più alta, poi più gol.
 * Ritorna null se nessuno ha votato.
 */
function match_mvp(int $match_id): ?int
{
    $counts = match_mvp_counts()[$match_id] ?? [];
    if (!$counts) {
        return null;
    }
    $avgs = match_vote_averages()[$match_id] ?? [];
    static $goals = null;
    if ($goals === null) {
        $goals = [];
        foreach (q('SELECT match_id, player_id, goals FROM match_players WHERE goals > 0')->fetchAll() as $r) {
            $goals[(int) $r['match_id']][(int) $r['player_id']] = (int) $r['goals'];
        }
    }
    $best = null;
    $bestKey = null;
    foreach ($counts as $pid => $n) {
        $key = [$n, $avgs[$pid]['avg'] ?? 0, $goals[$match_id][$pid] ?? 0, -$pid];
        if ($bestKey === null || $key > $bestKey) {
            $bestKey = $key;
            $best = $pid;
        }
    }
    return $best;
}

/**
 * Statistiche complete di tutti i giocatori: [player_id => [...]].
 * Gli MVP contano solo a votazioni chiuse.
 */
function compute_stats($scope = 'current'): array
{
    static $cache = [];
    $sc = $scope === 'current' ? scope_ids() : $scope;
    $ck = scope_key($sc);
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    $players = all_players(false, $sc);
    $played = played_matches($sc);
    $byId = [];
    foreach ($played as $m) {
        $byId[(int) $m['id']] = $m;
    }
    $totalPlayed = count($played);
    $avgs = match_vote_averages();
    $mvps = [];
    foreach ($played as $m) {
        if (!(int) $m['voting_open']) {
            $mvps[(int) $m['id']] = match_mvp((int) $m['id']);
        }
    }

    $stats = [];
    foreach ($players as $id => $p) {
        $stats[$id] = ['history' => []];
    }
    $parts = q("SELECT mp.* FROM match_players mp JOIN matches m ON m.id = mp.match_id
                WHERE m.status = 'giocata' AND mp.team IS NOT NULL AND " . scope_sql('m.group_id', $sc, true) . "
                ORDER BY m.match_date DESC, m.id DESC")->fetchAll();
    foreach ($parts as $r) {
        $pid = (int) $r['player_id'];
        $mid = (int) $r['match_id'];
        if (!isset($stats[$pid])) {
            continue;
        }
        $m = $byId[$mid];
        $stats[$pid]['history'][] = [
            'match_id' => $mid,
            'date' => $m['match_date'],
            'location' => $m['location'],
            'team' => $r['team'],
            'team_label' => team_name($r['team'], $m),
            'score_a' => (int) $m['score_a'],
            'score_b' => (int) $m['score_b'],
            'result' => result_for($m, $r['team']),
            'goals' => (int) $r['goals'],
            'assists' => (int) $r['assists'],
            'own_goals' => (int) $r['own_goals'],
            // a votazioni aperte la media non si mostra e non conta
            'vote' => (int) $m['voting_open'] ? null : ($avgs[$mid][$pid]['avg'] ?? null),
            'mvp' => ($mvps[$mid] ?? null) === $pid,
            'voting_open' => (int) $m['voting_open'],
        ];
    }

    foreach ($stats as $pid => $s) {
        $p = $players[$pid];
        $h = $s['history'];
        $count = fn(string $res) => count(array_filter($h, fn($x) => $x['result'] === $res));
        $sum = fn(string $k) => array_sum(array_column($h, $k));

        $apps = count($h) + (int) $p['adj_apps'];
        $wins = $count('V') + (int) $p['adj_wins'];
        $draws = $count('N') + (int) $p['adj_draws'];
        $losses = $count('S') + (int) $p['adj_losses'];
        $goals = $sum('goals') + (int) $p['adj_goals'];
        $assists = $sum('assists') + (int) $p['adj_assists'];
        $own = $sum('own_goals') + (int) $p['adj_own_goals'];
        $mvp = count(array_filter($h, fn($x) => $x['mvp'])) + (int) $p['adj_mvp'];

        $votes = array_values(array_filter(array_column($h, 'vote'), fn($v) => $v !== null));
        $avgVote = $votes ? array_sum($votes) / count($votes) : null;

        $last5 = array_slice($h, 0, 5);
        $l5votes = array_values(array_filter(array_column($last5, 'vote'), fn($v) => $v !== null));
        $l5avg = $l5votes ? array_sum($l5votes) / count($l5votes) : null;
        $l5points = 0;
        foreach ($last5 as $x) {
            $l5points += $x['result'] === 'V' ? 3 : ($x['result'] === 'N' ? 1 : 0);
        }

        // Forma: punti medi nelle ultime 5 (0-3) + andamento del voto rispetto alla media
        $form = 'none';
        if ($last5) {
            $ppg = $l5points / count($last5);
            $trend = ($l5avg !== null && $avgVote !== null) ? $l5avg - $avgVote : 0;
            $score = $ppg + $trend;
            $form = $score >= 2.0 ? 'hot' : ($score < 1.0 ? 'cold' : 'ok');
        }

        $out = [
            'apps' => $apps,
            'wins' => $wins,
            'draws' => $draws,
            'losses' => $losses,
            'goals' => $goals,
            'assists' => $assists,
            'own_goals' => $own,
            'mvp' => $mvp,
            'points' => $wins * POINTS_WIN + $draws * POINTS_DRAW,
            'avg_vote' => $avgVote,
            'vote_count' => count($votes),
            'gpg' => $apps ? $goals / $apps : 0,
            'apg' => $apps ? $assists / $apps : 0,
            'win_pct' => $apps ? $wins / $apps * 100 : 0,
            'participation_pct' => $totalPlayed ? count($h) / $totalPlayed * 100 : 0,
            'last5' => array_column($last5, 'result'),
            'goals_last5' => array_sum(array_column($last5, 'goals')),
            'avg_vote_last5' => $l5avg,
            'form' => $form,
            'history' => $h,
        ];
        $out['ovr'] = player_ovr($p, $out);
        $stats[$pid] = $out;
    }
    $cache[$ck] = $stats;
    return $stats;
}

/**
 * Rating complessivo usato per bilanciare le squadre (scala 1-10).
 *  - parte dal "rating base" deciso dall'admin;
 *  - con almeno 3 partite votate pesa 50% base + 50% media voto (25% se votate 1-2);
 *  - aggiunge fino a ±0,5 in base alla % vittorie (peso pieno da 10 presenze in su).
 */
function player_ovr(array $p, array $s): float
{
    $base = (float) $p['base_rating'];
    if ($s['vote_count'] >= 3) {
        $r = 0.5 * $base + 0.5 * $s['avg_vote'];
    } elseif ($s['vote_count'] >= 1) {
        $r = 0.75 * $base + 0.25 * $s['avg_vote'];
    } else {
        $r = $base;
    }
    if ($s['apps'] > 0) {
        $weight = min($s['apps'], 10) / 10;
        $r += max(-0.5, min(0.5, ($s['win_pct'] - 50) / 100)) * $weight;
    }
    return round($r, 2);
}

/** Classifica: punti, poi % vittorie, poi gol. Solo giocatori con almeno una presenza. */
function standings(string $sort = 'points'): array
{
    $stats = compute_stats();
    $players = all_players();
    $rows = [];
    foreach ($stats as $pid => $s) {
        if ($s['apps'] > 0) {
            $rows[] = ['player' => $players[$pid], 'id' => $pid] + $s;
        }
    }
    $keys = [
        'points' => ['points', 'win_pct', 'goals'],
        'apps' => ['apps', 'points'],
        'goals' => ['goals', 'gpg'],
        'assists' => ['assists', 'apg'],
        'gpg' => ['gpg', 'goals'],
        'win_pct' => ['win_pct', 'apps'],
        'mvp' => ['mvp', 'avg_vote'],
        'avg_vote' => ['avg_vote', 'vote_count'],
        'ovr' => ['ovr', 'avg_vote'],
        'own_goals' => ['own_goals', 'apps'],
    ][$sort] ?? ['points', 'win_pct', 'goals'];
    usort($rows, function ($a, $b) use ($keys) {
        foreach ($keys as $k) {
            $c = ($b[$k] ?? -1) <=> ($a[$k] ?? -1);
            if ($c !== 0) {
                return $c;
            }
        }
        return strcmp($a['player']['name'], $b['player']['name']);
    });
    return $rows;
}

/**
 * Momenti salienti di una partita giocata, per lo slider della home.
 * Ogni voce: ['kind', 'title', 'players' => [righe roster], 'big', 'text'].
 */
function match_highlights(array $m): array
{
    $mid = (int) $m['id'];
    $roster = array_values(array_filter(match_roster($mid), fn($r) => $r['team']));
    if (!$roster) {
        return [];
    }
    $closed = !(int) $m['voting_open'];
    $stats = compute_stats();
    $players = all_players();
    $avgs = match_vote_averages()[$mid] ?? [];
    $byPid = [];
    foreach ($roster as $r) {
        $byPid[(int) $r['player_id']] = $r;
    }
    $top = function (string $k) use ($roster): array {
        $max = max(array_map(fn($r) => (int) $r[$k], $roster));
        return $max > 0 ? [$max, array_values(array_filter($roster, fn($r) => (int) $r[$k] === $max))] : [0, []];
    };
    $out = [];

    // MVP
    if ($closed && ($mvp = match_mvp($mid)) && isset($byPid[$mvp])) {
        $v = $avgs[$mvp]['avg'] ?? null;
        $n = match_mvp_counts()[$mid][$mvp] ?? 0;
        $out[] = ['kind' => 'mvp', 'icon' => 'star-filled', 'title' => 'MVP della partita', 'players' => [$byPid[$mvp]],
            'big' => $v !== null ? fmt_num($v) : '', 'text' => $n . ($n === 1 ? ' voto' : ' voti') . ' come migliore in campo'];
    }

    // Bomber: doppietta, tripletta, poker…
    [$g, $who] = $top('goals');
    if ($g > 0) {
        $label = [1 => 'In gol', 2 => 'Doppietta!', 3 => 'Tripletta!', 4 => 'Poker!', 5 => 'Manita!'][$g] ?? "$g gol!";
        $out[] = ['kind' => 'goal', 'icon' => 'ball-football', 'title' => count($who) > 1 ? 'Bomber di giornata (a pari merito)' : 'Bomber di giornata',
            'players' => $who, 'big' => $label, 'text' => $g . ($g === 1 ? ' gol' : ' gol') . ' segnati'];
    }

    // Uomo assist
    [$a, $who] = $top('assists');
    if ($a > 0) {
        $out[] = ['kind' => 'assist', 'icon' => 'hand-finger-right', 'title' => 'Uomo assist', 'players' => $who,
            'big' => $a . ' assist', 'text' => count($who) > 1 ? 'A pari merito' : 'Il re dei passaggi'];
    }

    // Voto più alto (se non è già l'MVP)
    if ($closed && $avgs) {
        $bestPid = null;
        foreach ($avgs as $pid => $x) {
            if (isset($byPid[$pid]) && ($bestPid === null || $x['avg'] > $avgs[$bestPid]['avg'])) {
                $bestPid = $pid;
            }
        }
        if ($bestPid !== null && $bestPid !== ($mvp ?? null)) {
            $out[] = ['kind' => 'vote', 'icon' => 'trophy', 'title' => 'Voto più alto', 'players' => [$byPid[$bestPid]],
                'big' => fmt_num($avgs[$bestPid]['avg']), 'text' => 'Media dei voti dei compagni'];
        }
    }

    // Esordi: prima partita in assoluto (e nessuno storico inserito a mano)
    $debut = [];
    foreach ($roster as $r) {
        $pid = (int) $r['player_id'];
        $h = $stats[$pid]['history'] ?? [];
        if ($h && end($h)['match_id'] === $mid && (int) ($players[$pid]['adj_apps'] ?? 0) === 0) {
            $debut[] = $r;
        }
    }
    if ($debut) {
        $out[] = ['kind' => 'debut', 'icon' => 'confetti', 'title' => count($debut) > 1 ? 'Esordi' : 'Esordio',
            'players' => $debut, 'big' => 'Benvenut' . (count($debut) > 1 ? 'i' : 'o') . '!', 'text' => 'Prima partita nel gruppo'];
    }

    // Traguardi: presenze e gol tondi raggiunti in questa partita
    foreach ($roster as $r) {
        $pid = (int) $r['player_id'];
        $s = $stats[$pid] ?? null;
        if (!$s || ($s['history'][0]['match_id'] ?? 0) !== $mid) {
            continue; // conta solo se è la sua ultima partita
        }
        foreach ([10, 25, 50, 75, 100, 150, 200] as $ms) {
            if ($s['apps'] === $ms) {
                $out[] = ['kind' => 'milestone', 'icon' => 'medal', 'title' => 'Traguardo', 'players' => [$r],
                    'big' => $ms . ' presenze', 'text' => 'Una bandiera del gruppo'];
            }
            $before = $s['goals'] - (int) $r['goals'];
            if ($before < $ms && $s['goals'] >= $ms) {
                $out[] = ['kind' => 'milestone', 'icon' => 'medal', 'title' => 'Traguardo', 'players' => [$r],
                    'big' => $ms . ' gol', 'text' => 'Raggiunti in questa partita'];
            }
        }
        // striscia di vittorie
        $streak = 0;
        foreach ($s['history'] as $x) {
            if ($x['result'] !== 'V') {
                break;
            }
            $streak++;
        }
        if ($streak >= 3) {
            $out[] = ['kind' => 'streak', 'icon' => 'flame', 'title' => 'Striscia vincente', 'players' => [$r],
                'big' => $streak . ' vittorie di fila', 'text' => 'Chi lo ferma più?'];
        }
    }

    // Autogol, con affetto
    $og = array_values(array_filter($roster, fn($r) => (int) $r['own_goals'] > 0));
    if ($og) {
        $out[] = ['kind' => 'owngoal', 'icon' => 'mood-sad', 'title' => 'Sfortunato di giornata', 'players' => $og,
            'big' => 'Autogol', 'text' => 'Capita anche ai migliori'];
    }
    return $out;
}
