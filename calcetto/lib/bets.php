<?php
/*
 * Scommesse goliardiche sulle partite: si punta con gettoni finti (nessun euro), per l'onore e per sfottere gli amici.
 * I gettoni servono poi per le personalizzazioni del profilo (vedi lib/shop.php).
 *
 * Quote fisse, calcolate come le fanno i bookmaker: si stimano le probabilità dei risultati con un modello statistico (gol come
 * distribuzione di Poisson) e la quota è 1 / (probabilità x (1 + margine)). Il margine (in gergo "overround") è il guadagno del banco:
 * per questo la somma delle probabilità implicite (1 / quota) di tutti gli esiti di un mercato supera il 100%. La quota si salva con la
 * puntata: vincita = puntata x quota, chi sbaglia perde la puntata. Se manca il dato (per esempio nessuno ha votato l'MVP) tutti
 * riprendono i propri gettoni.
 *
 * Il portafoglio non è un numero salvato ma la somma delle mosse (tabella wallet_moves): puntata, vincita, rimborso, acquisto...
 * Così annullare una puntata, cancellare una partita o rifare un pagamento significa solo togliere delle mosse.
 *
 * Mercati (uno per giocatore e partita):
 *  - esito: chi vince (squadra 1, pareggio, squadra 2), si paga a fine partita;
 *  - gol: un giocatore segna almeno un gol, si paga a fine partita;
 *  - mvp: chi sarà l'MVP, si paga alla chiusura delle votazioni.
 */

const BET_START = 100;       // gettoni di benvenuto
const BET_DOLE_BELOW = 20;   // chi scende sotto questa cifra (e non ha puntate in corso)...
const BET_DOLE = 30;         // ...riceve il "sussidio" (una volta a settimana)
const BET_MARGIN = ['esito' => 0.06, 'gol' => 0.12, 'mvp' => 0.15];   // margine del banco (overround) per mercato, come nei bookmaker veri
const BET_RATING_K = 0.25;       // quanto pesa la differenza di rating tra le squadre sui gol attesi
const BET_FORM = ['hot' => 1.12, 'ok' => 1.0, 'cold' => 0.88, 'none' => 1.0];   // effetto dello stato di forma (ultime 5 partite) su gol attesi e MVP
const BET_DRAW_BOOST = 1.15;     // i pareggi sono più frequenti di quanto dica Poisson puro (correzione tipo Dixon-Coles)
const BET_PRIOR_GOALS = 4.0;     // gol a squadra per partita finché il gruppo ha giocato poco...
const BET_PRIOR_MATCHES = 3;     // ...pesano come tante partite

function bet_markets(): array
{
    return [
        'esito' => ['label' => 'Chi vince?', 'icon' => 'trophy', 'when' => 'a fine partita'],
        'gol' => ['label' => 'Chi segna?', 'icon' => 'ball-football', 'when' => 'segna almeno un gol'],
        'mvp' => ['label' => 'Chi sarà l\'MVP?', 'icon' => 'star', 'when' => 'alla chiusura dei voti'],
    ];
}

/* ---------------------------------------------------------------- portafoglio */

/** Gettoni disponibili (le puntate in corso sono già scalate). */
function wallet_balance(int $playerId): int
{
    return (int) q('SELECT COALESCE(SUM(delta), 0) FROM wallet_moves WHERE player_id = ?', [$playerId])->fetchColumn();
}

/** Gettoni puntati su scommesse non ancora decise. */
function wallet_in_play(int $playerId): int
{
    return (int) q("SELECT COALESCE(SUM(stake), 0) FROM bets WHERE player_id = ? AND status = 'aperta'", [$playerId])->fetchColumn();
}

/**
 * Apre il portafoglio (gettoni di benvenuto la prima volta) e dà il sussidio a chi è al verde: una volta a settimana,
 * solo se non ha nulla in gioco. Ritorna un messaggio da mostrare se è appena arrivato qualcosa.
 */
function wallet_open(int $playerId): ?string
{
    $msg = null;
    if (q("INSERT IGNORE INTO wallet_moves (player_id, delta, kind, ref) VALUES (?, ?, 'benvenuto', 'welcome')", [$playerId, BET_START])->rowCount()) {
        $msg = 'Benvenuto al banco! Ti abbiamo regalato ' . BET_START . ' gettoni: spendili male.';
    } elseif (wallet_balance($playerId) + wallet_in_play($playerId) < BET_DOLE_BELOW) {
        $ref = 'dole-' . date('o\WW');
        if (q("INSERT IGNORE INTO wallet_moves (player_id, delta, kind, ref) VALUES (?, ?, 'sussidio', ?)", [$playerId, BET_DOLE, $ref])->rowCount()) {
            $msg = 'Sei al verde: lo Stato del Calcetto ti passa il sussidio di ' . BET_DOLE . ' gettoni. Non farti riconoscere.';
        }
    }
    return $msg;
}

/** Titolo goliardico in base ai gettoni. */
function bet_title(int $balance): string
{
    foreach ([10 => 'Nullatenente', 50 => 'Squattrinato', 100 => 'Scommettitore della domenica', 200 => 'Habitué del bar sport',
              400 => 'Faccendiere', PHP_INT_MAX => 'Lupo di Wall Street'] as $limit => $title) {
        if ($balance < $limit) {
            return $title;
        }
    }
    return 'Lupo di Wall Street';
}

/** Classifica di chi ha un portafoglio: gettoni disponibili + in gioco, dal più ricco. */
function bet_leaderboard(): array
{
    return q('SELECT p.id, p.name, p.photo,
                     (SELECT COALESCE(SUM(delta), 0) FROM wallet_moves w WHERE w.player_id = p.id) AS balance,
                     (SELECT COALESCE(SUM(stake), 0) FROM bets b WHERE b.player_id = p.id AND b.status = \'aperta\') AS in_play,
                     (SELECT COUNT(*) FROM bets b WHERE b.player_id = p.id AND b.status = \'vinta\') AS wins,
                     (SELECT COUNT(*) FROM bets b WHERE b.player_id = p.id AND b.status IN (\'vinta\', \'persa\')) AS decided
              FROM players p
              WHERE EXISTS (SELECT 1 FROM wallet_moves w WHERE w.player_id = p.id) AND ' . player_scope_sql('p.id') . '
              ORDER BY (balance + in_play) DESC, p.name')->fetchAll();
}

/* ---------------------------------------------------------------- quote */

/** Quota decimale da una probabilità: 1 / (p x (1 + margine del mercato)), tenuta tra un minimo e un massimo. */
function bet_odds(float $p, string $market, float $min, float $max): float
{
    $p = max(0.001, min(0.999, $p));
    return round(max($min, min($max, 1 / ($p * (1 + BET_MARGIN[$market])))), 2);
}

/** Gol a partita attesi da un giocatore che non ha ancora giocato, in base al ruolo (poi pesano i suoi dati). */
function bet_goal_prior(?string $position): float
{
    return ['POR' => 0.03, 'DIF' => 0.18, 'CEN' => 0.35, 'ATT' => 0.65, 'JOL' => 0.35][position_abbr((string) $position)] ?? 0.35;
}

/** Gol medi segnati da una squadra in una partita, dalle partite giocate dal gruppo (con un valore di partenza finché sono poche). */
function bet_goals_per_team(int $groupId): float
{
    $r = q("SELECT COUNT(*) AS n, COALESCE(SUM(score_a + score_b), 0) AS g FROM matches
            WHERE status = 'giocata' AND group_id = ? AND score_a IS NOT NULL AND score_b IS NOT NULL", [$groupId])->fetch();
    return ((float) $r['g'] + 2 * BET_PRIOR_MATCHES * BET_PRIOR_GOALS) / (2 * ((int) $r['n'] + BET_PRIOR_MATCHES));
}

/**
 * Probabilità di vittoria della prima squadra, pareggio e vittoria della seconda, dai gol attesi delle due squadre:
 * ogni squadra segna un numero di gol con distribuzione di Poisson, si sommano le probabilità di tutti i punteggi possibili.
 * @return array{0: float, 1: float, 2: float} sommano 1
 */
function bet_poisson_1x2(float $la, float $lb): array
{
    $n = 30;
    $pa = [exp(-$la)];
    $pb = [exp(-$lb)];
    for ($k = 1; $k <= $n; $k++) {
        $pa[$k] = $pa[$k - 1] * $la / $k;
        $pb[$k] = $pb[$k - 1] * $lb / $k;
    }
    $w = $d = $l = 0.0;
    for ($i = 0; $i <= $n; $i++) {
        for ($j = 0; $j <= $n; $j++) {
            $p = $pa[$i] * $pb[$j];
            if ($i > $j) {
                $w += $p;
            } elseif ($i === $j) {
                $d += $p * BET_DRAW_BOOST;
            } else {
                $l += $p;
            }
        }
    }
    $t = $w + $d + $l;
    return [$w / $t, $d / $t, $l / $t];
}

/**
 * Quote di una partita: ['esito' => [A, X, B], 'gol' => [id giocatore], 'mvp' => [id giocatore]] => quota decimale.
 *
 *  - esito: dalla differenza di rating medio delle due squadre (se non sono ancora fatte, partita in equilibrio) si ricavano i gol
 *    attesi di ciascuna, poi il modello di Poisson dà le probabilità di 1, X e 2 (con più pareggi del Poisson puro);
 *  - gol (segna almeno un gol): i gol attesi della partita (o della squadra) si ripartiscono tra i giocatori in proporzione ai loro gol
 *    a partita (stagione + ultime 5 partite + stato di forma); probabilità = 1 - e^(-gol attesi del giocatore). Chi segna spesso ed è
 *    in forma ha quota bassa, chi non segna mai quota alta;
 *  - mvp: chi vince un premio tra tanti: pesa lo storico MVP, la media voto (stagione e ultime partite), la forma, i gol attesi e la probabilità che la sua squadra vinca;
 *    le probabilità si normalizzano a 100% prima del margine.
 * Chi non ha ancora confermato vale meno: potrebbe non esserci.
 */
function bet_quotes(array $match): array
{
    $id = (int) $match['id'];
    $stats = compute_stats([(int) $match['group_id']]);
    sync_match_players($id);
    $roster = array_values(array_filter(match_roster($id), fn($r) => $r['availability'] !== 'assente'));
    $mu = bet_goals_per_team((int) $match['group_id']);

    // gol attesi delle due squadre dal rating medio
    $sum = ['A' => 0.0, 'B' => 0.0];
    $n = ['A' => 0, 'B' => 0];
    foreach ($roster as $r) {
        if ($r['team'] === 'A' || $r['team'] === 'B') {
            $sum[$r['team']] += $stats[(int) $r['player_id']]['ovr'] ?? 6.0;
            $n[$r['team']]++;
        }
    }
    $d = ($n['A'] && $n['B']) ? $sum['A'] / $n['A'] - $sum['B'] / $n['B'] : 0.0;
    $lam = ['A' => $mu * exp(0.5 * BET_RATING_K * $d), 'B' => $mu * exp(-0.5 * BET_RATING_K * $d)];
    [$pw, $pd, $pl] = bet_poisson_1x2($lam['A'], $lam['B']);
    $winish = ['A' => $pw + $pd / 2, 'B' => $pl + $pd / 2];   // probabilità di "non perdere" pesata: serve al peso dell'MVP

    $out = ['esito' => [
        'A' => bet_odds($pw, 'esito', 1.05, 30),
        'X' => bet_odds($pd, 'esito', 1.05, 30),
        'B' => bet_odds($pl, 'esito', 1.05, 30),
    ], 'gol' => [], 'mvp' => []];

    // gol e MVP
    $rows = [];
    $den = 0.0;
    foreach ($roster as $r) {
        $pid = (int) $r['player_id'];
        $s = $stats[$pid] ?? ['apps' => 0, 'goals' => 0, 'mvp' => 0, 'avg_vote' => null, 'last5' => [], 'goals_last5' => 0, 'avg_vote_last5' => null, 'form' => 'none'];
        $here = $r['availability'] === 'confermato' ? 1.0 : 0.7;
        // gol a partita: media stagionale "stabilizzata" (chi ha giocato poco si avvicina al valore del suo ruolo), mescolata al rendimento
        // delle ultime 5 partite (tirato verso la media stagionale) e corretta dallo stato di forma. Chi segna spesso ed è in forma ha
        // un peso alto (quota bassa), chi non segna mai un peso minimo (quota alta).
        $season = ($s['goals'] + bet_goal_prior($r['position']) * 3) / ($s['apps'] + 3);
        $recent = ($s['goals_last5'] + $season * 2) / (count($s['last5']) + 2);
        $w = (0.65 * $season + 0.35 * $recent) * BET_FORM[$s['form'] ?? 'none'];
        $rows[$pid] = ['s' => $s, 'here' => $here, 'w' => $w, 'team' => in_array($r['team'], ['A', 'B'], true) ? $r['team'] : null];
        $den += $here * $w;
    }
    $mvpW = [];
    foreach ($rows as $pid => $x) {
        // gol attesi del giocatore: quota dei 2*mu gol della partita, aggiustata dalla forza della sua squadra
        $goals = $den > 0 ? $x['here'] * 2 * $mu * $x['w'] / $den * ($x['team'] ? $lam[$x['team']] / $mu : 1.0) : 0.0;
        $out['gol'][$pid] = bet_odds(1 - exp(-$goals), 'gol', 1.05, 50);
        $rate = ($x['s']['mvp'] + 3 / max(2, count($rows))) / ($x['s']['apps'] + 3);
        $v = (float) $x['s']['avg_vote'];
        $v5 = $x['s']['avg_vote_last5'];
        $vote = ($v5 !== null && $v > 0) ? 0.5 * $v + 0.5 * (float) $v5 : $v;   // media voto: metà stagione, metà ultime partite
        $form = exp(0.4 * ($vote > 0 ? $vote - 6 : 0)) * BET_FORM[$x['s']['form'] ?? 'none'];
        $mvpW[$pid] = $rate * $form * (1 + $goals) * (0.6 + 0.8 * ($x['team'] ? $winish[$x['team']] : 0.5)) * ($x['here'] >= 1 ? 1.0 : 0.6);
    }
    $tot = array_sum($mvpW);
    foreach ($mvpW as $pid => $x) {
        $out['mvp'][$pid] = bet_odds($x / $tot, 'mvp', 1.10, 60);
    }
    return $out;
}

/** Vincita di una puntata (comprende i gettoni puntati). */
function bet_payout(int $stake, $odds): int
{
    return (int) floor($stake * (float) $odds + 1e-9);
}

/* ---------------------------------------------------------------- puntare */

/** Le scommesse per una partita sono aperte fino al fischio d'inizio. */
function bets_open_for(array $match): bool
{
    return $match['status'] === 'programmata' && strtotime($match['match_date']) > time();
}

/** Giocatori su cui si può puntare (gol, MVP): chi non ha già detto di non esserci. */
function bet_candidates(int $matchId): array
{
    sync_match_players($matchId);
    return array_values(array_filter(match_roster($matchId), fn($r) => $r['availability'] !== 'assente'));
}

/** Puntate di una partita e mercato, con chi le ha fatte. */
function match_bets(int $matchId): array
{
    $out = [];
    foreach (q('SELECT b.*, p.name FROM bets b JOIN players p ON p.id = b.player_id WHERE b.match_id = ? ORDER BY b.created_at', [$matchId])->fetchAll() as $b) {
        $out[$b['market']][] = $b;
    }
    return $out;
}

/** Esegue $fn in una transazione (o dentro quella già aperta). */
function bet_atomic(callable $fn)
{
    if (db()->inTransaction()) {
        return $fn();
    }
    db()->beginTransaction();
    try {
        $r = $fn();
        db()->commit();
        return $r;
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

/**
 * Fa (o rifà, sostituendo la precedente) una puntata. Ritorna il messaggio d'errore oppure null se è andata.
 */
function bet_place(array $match, int $playerId, string $market, string $pick, int $stake): ?string
{
    if (!isset(bet_markets()[$market])) {
        return 'Mercato non valido.';
    }
    if (!bets_open_for($match)) {
        return 'Le scommesse su questa partita sono chiuse: il calcio d\'inizio è passato.';
    }
    if ($market === 'esito') {
        if (!in_array($pick, ['A', 'X', 'B'], true)) {
            return 'Scegli chi vince.';
        }
    } else {
        $ok = array_filter(bet_candidates((int) $match['id']), fn($r) => (string) $r['player_id'] === $pick);
        if (!$ok) {
            return 'Scegli un giocatore della partita.';
        }
    }
    if ($stake < 1) {
        return 'Punta almeno 1 gettone.';
    }
    $odds = bet_quotes($match)[$market][$pick] ?? null;   // la quota la decide il sito, non chi punta
    if ($odds === null) {
        return 'Su questa scelta non ci sono quote.';
    }
    return bet_atomic(function () use ($match, $playerId, $market, $pick, $stake, $odds) {
        q('SELECT id FROM players WHERE id = ? FOR UPDATE', [$playerId]);   // due puntate insieme non possono spendere due volte gli stessi gettoni
        $old = q("SELECT id, stake FROM bets WHERE match_id = ? AND player_id = ? AND market = ? AND status = 'aperta'",
            [$match['id'], $playerId, $market])->fetch();
        $available = wallet_balance($playerId) + ($old ? (int) $old['stake'] : 0);
        if ($stake > $available) {
            return 'Non hai abbastanza gettoni: te ne restano ' . $available . '.';
        }
        if ($old) {
            q('DELETE FROM bets WHERE id = ?', [$old['id']]);   // le sue mosse spariscono con lei (rimborso)
        }
        q('INSERT INTO bets (match_id, player_id, market, pick, stake, odds) VALUES (?, ?, ?, ?, ?, ?)', [$match['id'], $playerId, $market, $pick, $stake, $odds]);
        q("INSERT INTO wallet_moves (player_id, bet_id, delta, kind) VALUES (?, ?, ?, 'puntata')", [$playerId, db()->lastInsertId(), -$stake]);
        return null;
    });
}

/** Ritira una puntata prima del fischio d'inizio (i gettoni tornano). Ritorna il messaggio d'errore oppure null. */
function bet_cancel(array $match, int $playerId, string $market): ?string
{
    if (!bets_open_for($match)) {
        return 'Ormai è tardi: le scommesse sono chiuse.';
    }
    q("DELETE FROM bets WHERE match_id = ? AND player_id = ? AND market = ? AND status = 'aperta'", [$match['id'], $playerId, $market]);
    return null;
}

/* ---------------------------------------------------------------- pagare */

/**
 * Cosa ha vinto in un mercato: elenco delle scelte vincenti (anche vuoto), null se ancora non si può decidere,
 * false se il mercato è da annullare (mancano i dati: tutti riprendono i gettoni).
 * @return string[]|false|null
 */
function bet_winning_picks(array $match, string $market): array|false|null
{
    $id = (int) $match['id'];
    if ($match['status'] !== 'giocata') {
        return null;
    }
    if ($market === 'esito') {
        if ($match['score_a'] === null || $match['score_b'] === null) {
            return null;
        }
        return [(int) $match['score_a'] > (int) $match['score_b'] ? 'A' : ((int) $match['score_a'] < (int) $match['score_b'] ? 'B' : 'X')];
    }
    if ($market === 'gol') {
        return array_map('strval', q('SELECT player_id FROM match_players WHERE match_id = ? AND goals > 0', [$id])->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($market === 'mvp') {
        if ($match['voting_open']) {
            return null;   // si decide alla chiusura delle votazioni
        }
        $mvp = match_mvp($id);
        return $mvp ? [(string) $mvp] : false;   // nessuno ha votato: puntate annullate
    }
    return null;
}

/** Paga le puntate ancora aperte dei mercati indicati (quelli già decisi restano com'è). Si può richiamare senza danni. */
function bets_settle(int $matchId, array $markets = ['esito', 'gol', 'mvp']): void
{
    $match = get_match($matchId);
    if (!$match) {
        return;
    }
    foreach ($markets as $market) {
        $win = bet_winning_picks($match, $market);
        if ($win === null) {
            continue;
        }
        bet_atomic(function () use ($matchId, $market, $win) {
            $bets = q("SELECT * FROM bets WHERE match_id = ? AND market = ? AND status = 'aperta' FOR UPDATE", [$matchId, $market])->fetchAll();
            if (!$bets) {
                return;
            }
            foreach ($bets as $b) {
                if ($win === false) {
                    [$status, $pay, $kind] = ['rimborsata', (int) $b['stake'], 'rimborso'];
                } elseif (in_array($b['pick'], $win, true)) {
                    [$status, $pay, $kind] = ['vinta', bet_payout((int) $b['stake'], $b['odds']), 'vincita'];
                } else {
                    [$status, $pay, $kind] = ['persa', 0, null];
                }
                q('UPDATE bets SET status = ?, payout = ?, settled_at = NOW() WHERE id = ?', [$status, $pay, $b['id']]);
                if ($kind) {
                    q('INSERT INTO wallet_moves (player_id, bet_id, delta, kind) VALUES (?, ?, ?, ?)', [$b['player_id'], $b['id'], $pay, $kind]);
                }
            }
        });
    }
}

/**
 * Paga le puntate rimaste indietro (una richiesta interrotta, scommesse fatte prima di un aggiornamento...): partite giocate con puntate
 * ancora aperte, esclusi gli MVP finché le votazioni sono aperte. Si chiama a ogni richiesta, costa una query.
 */
function bets_settle_pending(): void
{
    foreach (q("SELECT DISTINCT b.match_id FROM bets b JOIN matches m ON m.id = b.match_id
                WHERE b.status = 'aperta' AND m.status = 'giocata' AND (m.voting_open = 0 OR b.market <> 'mvp')")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        bets_settle((int) $id);
    }
}

/** Annulla i pagamenti dei mercati indicati (le puntate tornano aperte): serve se la partita o i voti vengono riaperti. */
function bets_unsettle(int $matchId, array $markets = ['esito', 'gol', 'mvp']): void
{
    $in = implode(',', array_fill(0, count($markets), '?'));
    bet_atomic(function () use ($matchId, $markets, $in) {
        q("DELETE w FROM wallet_moves w JOIN bets b ON b.id = w.bet_id
           WHERE b.match_id = ? AND b.market IN ($in) AND w.kind IN ('vincita', 'rimborso')", array_merge([$matchId], $markets));
        q("UPDATE bets SET status = 'aperta', payout = 0, settled_at = NULL WHERE match_id = ? AND market IN ($in) AND status <> 'aperta'",
            array_merge([$matchId], $markets));
    });
}

/** Il risultato è stato salvato o corretto: rifà i pagamenti di "chi vince" e "chi segna". */
function bets_resettle_result(int $matchId): void
{
    bet_atomic(function () use ($matchId) {
        bets_unsettle($matchId, ['esito', 'gol']);
        bets_settle($matchId, ['esito', 'gol']);
    });
}

/* ---------------------------------------------------------------- parole */

/** "Blu", "Pareggio", "Arancio" o il nome del giocatore, a partire dalla scelta salvata. */
function bet_pick_label(array $match, string $market, string $pick, array $names = []): string
{
    if ($market === 'esito') {
        return $pick === 'X' ? 'Pareggio' : team_name($pick, $match);
    }
    return $names[(int) $pick] ?? (get_player((int) $pick)['name'] ?? '?');
}
