<?php
/*
 * Scommesse goliardiche sulle partite: si punta con gettoni finti (nessun euro), per l'onore e per sfottere gli amici.
 * I gettoni servono poi per le personalizzazioni del profilo (vedi lib/shop.php).
 *
 * Quote calcolate come le fanno i bookmaker: si stimano le probabilità dei risultati con un modello statistico (gol come
 * distribuzione di Poisson) e la quota "di apertura" è 1 / (probabilità x (1 + margine)). Il margine (in gergo "overround") è il
 * guadagno del banco: per questo la somma delle probabilità implicite (1 / quota) di tutti gli esiti di un mercato supera il 100%.
 * Poi, sempre come nella vita reale, la quota si abbassa un po' per ogni gettone già puntato su quella stessa scelta in quella
 * partita (bet_demand_shorten): chi punta per primo su una scelta prende la quota piena, chi arriva dopo su una scelta già
 * affollata ne prende una più bassa (il banco si protegge, non paga tutti alla stessa quota). La quota si salva con la
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
const BET_DEMAND_K = 0.5;        // forza con cui la quota si abbassa in base ai gettoni già puntati sulla stessa scelta
const BET_DEMAND_REF = 120.0;    // scala di riferimento (gettoni): con questa cifra già puntata la quota scende di circa un terzo
const BET_DEMAND_FLOOR = 0.55;   // la domanda da sola non può mai abbassare una quota sotto il 55% di quella "di apertura"

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

/** Gettoni puntati su scommesse (singole e multiple) non ancora decise. */
function wallet_in_play(int $playerId): int
{
    return (int) q("SELECT COALESCE(SUM(stake), 0) FROM bets WHERE player_id = ? AND status = 'aperta'", [$playerId])->fetchColumn()
        + (int) q("SELECT COALESCE(SUM(stake), 0) FROM combo_bets WHERE player_id = ? AND status = 'aperta'", [$playerId])->fetchColumn();
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

/** Classifica di chi ha un portafoglio: gettoni disponibili + in gioco (singole e multiple), dal più ricco. */
function bet_leaderboard(): array
{
    return q('SELECT p.id, p.name, p.photo,
                     (SELECT COALESCE(SUM(delta), 0) FROM wallet_moves w WHERE w.player_id = p.id) AS balance,
                     (SELECT COALESCE(SUM(stake), 0) FROM bets b WHERE b.player_id = p.id AND b.status = \'aperta\')
                       + (SELECT COALESCE(SUM(stake), 0) FROM combo_bets c WHERE c.player_id = p.id AND c.status = \'aperta\') AS in_play,
                     (SELECT COUNT(*) FROM bets b WHERE b.player_id = p.id AND b.status = \'vinta\')
                       + (SELECT COUNT(*) FROM combo_bets c WHERE c.player_id = p.id AND c.status = \'vinta\') AS wins,
                     (SELECT COUNT(*) FROM bets b WHERE b.player_id = p.id AND b.status IN (\'vinta\', \'persa\'))
                       + (SELECT COUNT(*) FROM combo_bets c WHERE c.player_id = p.id AND c.status IN (\'vinta\', \'persa\')) AS decided
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
function bet_quotes(array $match, ?int $excludePlayerId = null): array
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

    // il banco si protegge: la quota di ogni scelta scende un po' per ogni gettone già puntato su di lei in questa partita
    // (tranne la propria puntata aperta, se si sta cambiando: cambiare idea non deve penalizzare la nuova quota)
    $demand = bet_market_demand($id, $excludePlayerId);
    foreach ($out as $mk => $picks) {
        $out[$mk] = bet_demand_shorten($picks, $demand[$mk] ?? []);
    }
    return $out;
}

/**
 * Gettoni già puntati (scommesse aperte, singole e dentro le multiple) su ogni scelta di una partita, per mercato:
 * ['esito' => ['A' => 40, ...], 'gol' => [...], 'mvp' => [...]]. Di una multipla conta l'intera puntata su ogni sua gamba
 * (se quella gamba perde, il banco tiene comunque tutta la puntata): è una stima prudente dell'esposizione, non un conto esatto.
 * $excludePlayerId esclude le puntate singole aperte di quel giocatore (per non penalizzare chi sta solo cambiando la sua).
 */
function bet_market_demand(int $matchId, ?int $excludePlayerId = null): array
{
    $out = [];
    $sql = "SELECT market, pick, SUM(stake) AS s FROM bets WHERE match_id = ? AND status = 'aperta'";
    $params = [$matchId];
    if ($excludePlayerId) {
        $sql .= ' AND player_id <> ?';
        $params[] = $excludePlayerId;
    }
    foreach (q($sql . ' GROUP BY market, pick', $params)->fetchAll() as $r) {
        $out[$r['market']][$r['pick']] = ($out[$r['market']][$r['pick']] ?? 0) + (float) $r['s'];
    }
    foreach (q("SELECT cl.market, cl.pick, SUM(cb.stake) AS s FROM combo_legs cl JOIN combo_bets cb ON cb.id = cl.combo_id
                WHERE cl.match_id = ? AND cl.status = 'aperta' AND cb.status = 'aperta' GROUP BY cl.market, cl.pick",
        [$matchId])->fetchAll() as $r) {
        $out[$r['market']][$r['pick']] = ($out[$r['market']][$r['pick']] ?? 0) + (float) $r['s'];
    }
    return $out;
}

/**
 * Abbassa le quote di un mercato in base a quanto è già puntato su ogni scelta: quota_finale = quota / (1 + K x gettoni / riferimento),
 * mai sotto BET_DEMAND_FLOOR della quota di apertura. Chi punta per primo su una scelta (0 gettoni già sopra) prende la quota piena.
 */
function bet_demand_shorten(array $odds, array $demand): array
{
    foreach ($odds as $pick => $o) {
        $staked = $demand[$pick] ?? 0;
        if ($staked > 0) {
            $adj = $o / (1 + BET_DEMAND_K * $staked / BET_DEMAND_REF);
            $odds[$pick] = round(max($o * BET_DEMAND_FLOOR, $adj), 2);
        }
    }
    return $odds;
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
    $odds = bet_quotes($match, $playerId)[$market][$pick] ?? null;   // la quota la decide il sito, non chi punta
    if ($odds === null) {
        return 'Su questa scelta non ci sono quote.';
    }
    return bet_atomic(function () use ($match, $playerId, $market, $pick, $stake, $odds) {
        q('SELECT id FROM players WHERE id = ? FOR UPDATE', [$playerId]);   // due puntate insieme non possono spendere due volte gli stessi gettoni
        // si può avere una puntata aperta per scelta: su "chi segna" o "chi è MVP" si punta su più giocatori insieme, ognuno la sua;
        // ripuntare sulla STESSA scelta la sostituisce (cambia importo/quota) invece di sommarsi.
        $old = q("SELECT id, stake FROM bets WHERE match_id = ? AND player_id = ? AND market = ? AND pick = ? AND status = 'aperta'",
            [$match['id'], $playerId, $market, $pick])->fetch();
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

/** Ritira una puntata (una precisa scelta di un mercato) prima del fischio d'inizio (i gettoni tornano). Ritorna il messaggio d'errore oppure null. */
function bet_cancel(array $match, int $playerId, string $market, string $pick): ?string
{
    if (!bets_open_for($match)) {
        return 'Ormai è tardi: le scommesse sono chiuse.';
    }
    q("DELETE FROM bets WHERE match_id = ? AND player_id = ? AND market = ? AND pick = ? AND status = 'aperta'",
        [$match['id'], $playerId, $market, $pick]);
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
        combo_legs_settle_for_match($matchId, $market, $win);   // le stesse selezioni contano anche dentro le multiple
    }
}

/**
 * Paga le puntate rimaste indietro (una richiesta interrotta, scommesse fatte prima di un aggiornamento...): partite giocate con puntate
 * ancora aperte, esclusi gli MVP finché le votazioni sono aperte. Si chiama a ogni richiesta, costa una query.
 */
function bets_settle_pending(): void
{
    $ids = q("SELECT DISTINCT b.match_id FROM bets b JOIN matches m ON m.id = b.match_id
              WHERE b.status = 'aperta' AND m.status = 'giocata' AND (m.voting_open = 0 OR b.market <> 'mvp')")->fetchAll(PDO::FETCH_COLUMN);
    $ids2 = q("SELECT DISTINCT cl.match_id FROM combo_legs cl JOIN combo_bets cb ON cb.id = cl.combo_id JOIN matches m ON m.id = cl.match_id
               WHERE cl.status = 'aperta' AND cb.status = 'aperta' AND m.status = 'giocata' AND (m.voting_open = 0 OR cl.market <> 'mvp')")->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_unique(array_merge($ids, $ids2)) as $id) {
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
        // le multiple che avevano una gamba su questa partita/mercato tornano in gioco: si ripagano alla prossima chiusura
        q("DELETE w FROM wallet_moves w JOIN combo_legs cl ON cl.combo_id = w.combo_id
           WHERE cl.match_id = ? AND cl.market IN ($in) AND w.kind IN ('vincita', 'rimborso')", array_merge([$matchId], $markets));
        q("UPDATE combo_legs SET status = 'aperta' WHERE match_id = ? AND market IN ($in) AND status <> 'aperta'",
            array_merge([$matchId], $markets));
        q("UPDATE combo_bets cb SET status = 'aperta', payout = 0, settled_at = NULL
           WHERE status <> 'aperta' AND EXISTS (SELECT 1 FROM combo_legs cl WHERE cl.combo_id = cb.id AND cl.match_id = ? AND cl.market IN ($in))",
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

/* ---------------------------------------------------------------- multiple (combo) */

const COMBO_MIN_LEGS = 2;    // sotto sono solo scommesse singole
const COMBO_MAX_LEGS = 8;

/**
 * Valida le selezioni di una multipla dal carrello (array di "match_id:market:pick") e calcola le rispettive quote.
 * @return array{0: array|null, 1: string|null} [gambe pronte, null] oppure [null, messaggio d'errore]
 */
function combo_prepare(array $raw, int $playerId): array
{
    $seen = [];
    $legs = [];
    foreach ($raw as $r) {
        [$matchId, $market, $pick] = array_pad(explode(':', (string) $r, 3), 3, null);
        $matchId = (int) $matchId;
        $match = $matchId ? get_match($matchId) : null;
        if (!$match || !match_access($match) || $market === null || !isset(bet_markets()[$market]) || $pick === null || $pick === '') {
            return [null, 'Una delle selezioni della multipla non è valida.'];
        }
        if (!is_admin() && !player_in_group($playerId, (int) $match['group_id'])) {
            return [null, 'Puoi scommettere solo sulle partite del tuo gruppo.'];
        }
        if (!bets_open_for($match)) {
            return [null, 'Una delle partite scelte è già chiusa: rifai la multipla.'];
        }
        // stessa scelta due volte non ha senso (raddoppierebbe l'esposizione sulla stessa gamba); scelte diverse dello
        // stesso mercato della stessa partita invece sì (es. due marcatori diversi nella stessa multipla).
        $dup = $matchId . '|' . $market . '|' . $pick;
        if (isset($seen[$dup])) {
            return [null, 'Hai messo due volte la stessa selezione nella multipla.'];
        }
        $seen[$dup] = true;
        if ($market === 'esito') {
            if (!in_array($pick, ['A', 'X', 'B'], true)) {
                return [null, 'Scelta non valida su «chi vince».'];
            }
        } elseif (!array_filter(bet_candidates($matchId), fn($c) => (string) $c['player_id'] === $pick)) {
            return [null, 'Scegli un giocatore che gioca quella partita.'];
        }
        $odds = bet_quotes($match)[$market][$pick] ?? null;
        if ($odds === null) {
            return [null, 'Su una delle selezioni non ci sono quote.'];
        }
        $legs[] = ['match_id' => $matchId, 'market' => $market, 'pick' => $pick, 'odds' => (float) $odds];
    }
    if (count($legs) < COMBO_MIN_LEGS) {
        return [null, 'Una multipla serve almeno ' . COMBO_MIN_LEGS . ' selezioni: per una sola, punta normale.'];
    }
    if (count($legs) > COMBO_MAX_LEGS) {
        return [null, 'Al massimo ' . COMBO_MAX_LEGS . ' selezioni in una multipla.'];
    }
    return [$legs, null];
}

/** Quota combinata di una multipla: il prodotto delle quote delle sue gambe. */
function combo_odds(array $legs): float
{
    $o = 1.0;
    foreach ($legs as $l) {
        $o *= (float) $l['odds'];
    }
    return round($o, 2);
}

/** Fa una multipla. Ritorna il messaggio d'errore oppure null se è andata. */
function combo_place(int $playerId, array $legs, int $stake): ?string
{
    if ($stake < 1) {
        return 'Punta almeno 1 gettone.';
    }
    $odds = combo_odds($legs);
    return bet_atomic(function () use ($playerId, $legs, $stake, $odds) {
        q('SELECT id FROM players WHERE id = ? FOR UPDATE', [$playerId]);
        $available = wallet_balance($playerId);
        if ($stake > $available) {
            return 'Non hai abbastanza gettoni: te ne restano ' . $available . '.';
        }
        q('INSERT INTO combo_bets (player_id, stake, odds) VALUES (?, ?, ?)', [$playerId, $stake, $odds]);
        $comboId = (int) db()->lastInsertId();
        foreach ($legs as $l) {
            q('INSERT INTO combo_legs (combo_id, match_id, market, pick, odds) VALUES (?, ?, ?, ?, ?)',
                [$comboId, $l['match_id'], $l['market'], $l['pick'], $l['odds']]);
        }
        q("INSERT INTO wallet_moves (player_id, combo_id, delta, kind) VALUES (?, ?, ?, 'puntata')", [$playerId, $comboId, -$stake]);
        return null;
    });
}

/** Ritira una multipla, se nessuna delle sue partite è ancora iniziata (i gettoni tornano). */
function combo_cancel(int $comboId, int $playerId): ?string
{
    $combo = q('SELECT * FROM combo_bets WHERE id = ? AND player_id = ?', [$comboId, $playerId])->fetch();
    if (!$combo) {
        return 'Multipla non trovata.';
    }
    if ($combo['status'] !== 'aperta') {
        return 'Questa multipla è già stata decisa.';
    }
    foreach (q('SELECT cl.match_id FROM combo_legs cl WHERE cl.combo_id = ?', [$comboId])->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        $m = get_match((int) $mid);
        if (!$m || !bets_open_for($m)) {
            return 'Una delle partite della multipla è già chiusa: non si può più ritirare.';
        }
    }
    q('DELETE FROM combo_bets WHERE id = ?', [$comboId]);   // gambe e mossa spariscono con lei (rimborso)
    return null;
}

/** Multiple aperte di un giocatore, con le loro gambe. */
function combo_open(int $playerId): array
{
    $combos = q("SELECT * FROM combo_bets WHERE player_id = ? AND status = 'aperta' ORDER BY id DESC", [$playerId])->fetchAll();
    return combo_with_legs($combos);
}

/** Ultime multiple decise di un giocatore, con le loro gambe. */
function combo_history(int $playerId, int $limit = 8): array
{
    $combos = q("SELECT * FROM combo_bets WHERE player_id = ? AND status <> 'aperta' ORDER BY settled_at DESC, id DESC LIMIT " . $limit,
        [$playerId])->fetchAll();
    return combo_with_legs($combos);
}

/** Attacca a ogni combo le sue gambe, con l'etichetta leggibile della scelta. */
function combo_with_legs(array $combos): array
{
    if (!$combos) {
        return [];
    }
    $ids = array_column($combos, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $legs = q("SELECT cl.*, m.match_date, m.team_a_name, m.team_b_name FROM combo_legs cl JOIN matches m ON m.id = cl.match_id
               WHERE cl.combo_id IN ($in) ORDER BY cl.id", $ids)->fetchAll();
    $byCombo = [];
    $markets = bet_markets();
    foreach ($legs as $l) {
        $byCombo[(int) $l['combo_id']][] = $l + ['label' => bet_pick_label($l, $l['market'], $l['pick']), 'market_label' => $markets[$l['market']]['label'] ?? $l['market']];
    }
    foreach ($combos as &$c) {
        $c['legs'] = $byCombo[(int) $c['id']] ?? [];
    }
    return $combos;
}

/** Aggiorna lo stato delle gambe di una partita/mercato appena deciso, poi salda le multiple che così sono complete. */
function combo_legs_settle_for_match(int $matchId, string $market, array|false $win): void
{
    bet_atomic(function () use ($matchId, $market, $win) {
        $legs = q("SELECT * FROM combo_legs WHERE match_id = ? AND market = ? AND status = 'aperta' FOR UPDATE", [$matchId, $market])->fetchAll();
        foreach ($legs as $l) {
            $status = $win === false ? 'rimborsata' : (in_array($l['pick'], $win, true) ? 'vinta' : 'persa');
            q('UPDATE combo_legs SET status = ? WHERE id = ?', [$status, $l['id']]);
        }
    });
    $ids = q("SELECT DISTINCT cb.id FROM combo_bets cb JOIN combo_legs cl ON cl.combo_id = cb.id
              WHERE cb.status = 'aperta' AND cl.match_id = ? AND cl.market = ?", [$matchId, $market])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $cid) {
        combo_maybe_settle((int) $cid);
    }
}

/** Se una gamba ha perso la multipla è persa subito; se sono tutte decise (vinte o rimborsate) si paga. Altrimenti aspetta. */
function combo_maybe_settle(int $comboId): void
{
    bet_atomic(function () use ($comboId) {
        $combo = q("SELECT * FROM combo_bets WHERE id = ? AND status = 'aperta' FOR UPDATE", [$comboId])->fetch();
        if (!$combo) {
            return;
        }
        $legs = q('SELECT * FROM combo_legs WHERE combo_id = ?', [$comboId])->fetchAll();
        if (!$legs) {
            return;
        }
        if (array_filter($legs, fn($l) => $l['status'] === 'persa')) {
            q("UPDATE combo_bets SET status = 'persa', payout = 0, settled_at = NOW() WHERE id = ?", [$comboId]);
            return;
        }
        if (array_filter($legs, fn($l) => $l['status'] === 'aperta')) {
            return;   // qualche partita non è ancora decisa: si aspetta
        }
        $won = array_filter($legs, fn($l) => $l['status'] === 'vinta');
        if (!$won) {   // tutte le gambe rimborsate (mancava sempre il dato): si riprendono i gettoni
            q("UPDATE combo_bets SET status = 'rimborsata', payout = ?, settled_at = NOW() WHERE id = ?", [(int) $combo['stake'], $comboId]);
            q("INSERT INTO wallet_moves (player_id, combo_id, delta, kind) VALUES (?, ?, ?, 'rimborso')", [$combo['player_id'], $comboId, (int) $combo['stake']]);
            return;
        }
        // le gambe rimborsate escono dal conto (come i mercati saltati dai bookmaker veri): la quota resta quella delle altre
        $odds = 1.0;
        foreach ($won as $l) {
            $odds *= (float) $l['odds'];
        }
        $pay = (int) floor((int) $combo['stake'] * $odds + 1e-9);
        q("UPDATE combo_bets SET status = 'vinta', payout = ?, settled_at = NOW() WHERE id = ?", [$pay, $comboId]);
        q("INSERT INTO wallet_moves (player_id, combo_id, delta, kind) VALUES (?, ?, ?, 'vincita')", [$combo['player_id'], $comboId, $pay]);
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
