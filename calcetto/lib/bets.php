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
 *  - doppietta / tripletta: un giocatore segna almeno 2 / almeno 3 gol, si paga a fine partita;
 *  - overunder: i gol totali della partita stanno sopra o sotto una soglia scelta da chi punta (es. 8,5), si paga a fine partita.
 *    Il sito dà una quota per ogni soglia possibile e la soglia viene salvata dentro la scelta ("O8.5" / "U8.5");
 *  - mvp: chi sarà l'MVP, si paga alla chiusura delle votazioni.
 */

const BET_START = 100;       // gettoni di benvenuto
const BET_DOLE_BELOW = 20;   // chi scende sotto questa cifra (e non ha puntate in corso)...
const BET_DOLE = 30;         // ...riceve il "sussidio" (una volta a settimana)
const BET_MARGIN = ['esito' => 0.06, 'gol' => 0.12, 'doppietta' => 0.15, 'tripletta' => 0.18, 'overunder' => 0.06, 'mvp' => 0.15];   // margine del banco (overround) per mercato, come nei bookmaker veri
const BET_RATING_K = 0.25;       // quanto pesa la differenza di rating tra le squadre sui gol attesi
const BET_FORM = ['hot' => 1.12, 'ok' => 1.0, 'cold' => 0.88, 'none' => 1.0];   // effetto dello stato di forma (ultime 5 partite) su gol attesi e MVP
const BET_DRAW_BOOST = 1.15;     // i pareggi sono più frequenti di quanto dica Poisson puro (correzione tipo Dixon-Coles)
const BET_PRIOR_GOALS = 4.0;     // gol a squadra per partita finché il gruppo ha giocato poco...
const BET_PRIOR_MATCHES = 3;     // ...pesano come tante partite
const BET_DEMAND_K = 0.5;        // forza con cui la quota si abbassa in base ai gettoni già puntati sulla stessa scelta
const BET_DEMAND_REF = 120.0;    // scala di riferimento (gettoni): con questa cifra già puntata la quota scende di circa un terzo
const BET_DEMAND_FLOOR = 0.55;   // la domanda da sola non può mai abbassare una quota sotto il 55% di quella "di apertura"
const BET_MIN_ODDS = 1.01;       // nessuna quota scende mai sotto ×1,01: chi indovina deve sempre guadagnare almeno qualcosa
const BET_FLATTEN = 0.3;         // quanto i gol attesi dei giocatori vengono avvicinati alla media della partita (0 = niente, 1 = tutti uguali):
                                 // a calcetto (portieri volanti) tutti prima o poi tirano, le differenze non devono essere estreme
const BET_REWARD_GOAL = 25;      // gettoni a chi segna, per ogni gol (fuori dalle scommesse: premio per la partita)
const BET_REWARD_ASSIST = 10;    // e per ogni assist
const BET_BOOST_MULT = 1.17;     // prime partite (più incertezza): quota finale = quota x 1,17 + c...
const BET_BOOST_C = [0.2, 0.5];  // ...con c tra 0,2 e 0,5, diverso per ogni scelta (vedi bet_boost)
const BET_OPEN_HOURS = 48;       // le scommesse su una partita si aprono 48 ore prima del calcio d'inizio (e da lì i ruoli sono bloccati)

function bet_markets(): array
{
    return [
        'esito' => ['label' => 'Chi vince?', 'icon' => 'trophy', 'when' => 'a fine partita'],
        'gol' => ['label' => 'Chi segna?', 'icon' => 'ball-football', 'when' => 'segna almeno un gol'],
        'doppietta' => ['label' => 'Chi fa doppietta?', 'icon' => 'square-number-2', 'when' => 'segna almeno 2 gol'],
        'tripletta' => ['label' => 'Chi fa tripletta?', 'icon' => 'square-number-3', 'when' => 'segna almeno 3 gol'],
        'overunder' => ['label' => 'Over/Under', 'icon' => 'arrows-up-down', 'when' => 'gol totali della partita'],
        'mvp' => ['label' => 'Chi sarà l\'MVP?', 'icon' => 'star', 'when' => 'alla chiusura dei voti'],
    ];
}

/** Mercati in cui si punta su un giocatore (le scelte sono id di giocatori). */
const BET_PLAYER_MARKETS = ['gol', 'doppietta', 'tripletta', 'mvp'];
/** Mercati sui gol di un giocatore: stesso giocatore in due di questi nella stessa multipla non si può (uno implica l'altro). */
const BET_SCORER_MARKETS = ['gol', 'doppietta', 'tripletta'];
const BET_OU_MIN_LINES = 25;   // over/under: soglie proposte almeno da 0,5 a 25,5 gol (di più se la partita promette tanti gol)
const BET_OU_PLAYERS_W = 0.7;  // quanto pesa "chi gioca" sui gol attesi totali (0 = solo media del gruppo, 1 = pieno)
const BET_OU_FULL_ROSTER = 10; // con almeno tanti giocatori in lista quel peso vale in pieno, con meno scala (la lista è ancora incompleta)

/** Mercati che si pagano col risultato (gli altri, cioè l'MVP, alla chiusura dei voti). */
const BET_RESULT_MARKETS = ['esito', 'gol', 'doppietta', 'tripletta', 'overunder'];

/** Scelta dell'over/under: "O8.5" / "U8.5" => ['O', 8.5], null se non valida. */
function bet_ou_parse(string $pick): ?array
{
    return preg_match('/^([OU])(\d{1,2}\.5)$/', $pick, $m) ? [$m[1], (float) $m[2]] : null;
}

/** Scelta dell'over/under dalla direzione e dalla soglia. */
function bet_ou_pick(string $side, float $line): string
{
    return $side . number_format($line, 1, '.', '');
}

/** Probabilità che una variabile di Poisson di media $lam valga almeno $k. */
function bet_poisson_at_least(float $lam, int $k): float
{
    $term = exp(-$lam);
    $below = 0.0;
    for ($i = 0; $i < $k; $i++) {
        $below += $term;
        $term *= $lam / ($i + 1);
    }
    return max(0.0, 1 - $below);
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
    return round(max(BET_MIN_ODDS, $min, min($max, 1 / ($p * (1 + BET_MARGIN[$market])))), 2);
}

/**
 * Gol a partita attesi da un giocatore che non ha ancora giocato, in base al ruolo (poi pesano i suoi dati).
 * Valori vicini tra loro con i portieri volanti: a calcetto chi è in porta prima o poi va anche in attacco, quindi chi si segna
 * portiere o difensore gioca di fatto da difensore/centrocampista e segna di poco meno degli attaccanti.
 * Con i portieri fissi (opzione della partita) il portiere non segna quasi mai e i difensori molto meno degli attaccanti.
 */
function bet_goal_prior(?string $position, string $keepers = 'volanti'): float
{
    $prior = $keepers === 'fissi'
        ? ['POR' => 0.03, 'DIF' => 0.18, 'CEN' => 0.35, 'ATT' => 0.65, 'JOL' => 0.35]
        : ['POR' => 0.30, 'DIF' => 0.34, 'CEN' => 0.40, 'ATT' => 0.50, 'JOL' => 0.40];
    return $prior[position_abbr((string) $position)] ?? $prior['JOL'];
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
 * Quote di una partita: ['esito' => [A, X, B], 'gol' / 'doppietta' / 'tripletta' / 'mvp' => [id giocatore],
 * 'overunder' => ["O8.5", "U8.5"]] => quota decimale.
 *
 *  - esito: dalla differenza di rating medio delle due squadre (se non sono ancora fatte, partita in equilibrio) si ricavano i gol
 *    attesi di ciascuna, poi il modello di Poisson dà le probabilità di 1, X e 2 (con più pareggi del Poisson puro);
 *  - gol (segna almeno un gol): i gol attesi della partita (o della squadra) si ripartiscono tra i giocatori in proporzione ai loro gol
 *    a partita (stagione + ultime 5 partite + stato di forma); probabilità = 1 - e^(-gol attesi del giocatore). Chi segna spesso ed è
 *    in forma ha quota bassa, chi non segna mai quota alta;
 *  - doppietta / tripletta: con gli stessi gol attesi, probabilità di Poisson di segnarne almeno 2 / almeno 3;
 *  - overunder: gol attesi totali = gol attesi delle due squadre (media gol del gruppo + rating) x un fattore "chi gioca": quanto i
 *    giocatori in lista segnano più (o meno) della media del gruppo, da stagione, ultime 5 partite e forma. Chi ha giocato poco vale
 *    come la media; il fattore pesa di più man mano che la lista si riempie.
 *    La somma di due Poisson è una Poisson: per ogni soglia ",5" la probabilità dell'over viene da lì. All'inizio, con pochi dati,
 *    le quote sono approssimative (pesano i valori di partenza), poi si affinano partita dopo partita;
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
    $betRoster = array_values(array_filter($roster, fn($r) => !$r['is_guest']));   // gol e MVP: gli ospiti non sono in lista

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
    ], 'gol' => [], 'doppietta' => [], 'tripletta' => [], 'overunder' => [], 'mvp' => []];


    // gol e MVP
    $rows = [];
    $den = 0.0;
    foreach ($betRoster as $r) {
        $pid = (int) $r['player_id'];
        $s = $stats[$pid] ?? ['apps' => 0, 'goals' => 0, 'mvp' => 0, 'avg_vote' => null, 'last5' => [], 'goals_last5' => 0, 'avg_vote_last5' => null, 'form' => 'none'];
        $here = $r['availability'] === 'confermato' ? 1.0 : 0.7;
        // gol a partita: media stagionale "stabilizzata" (chi ha giocato poco si avvicina al valore del suo ruolo), mescolata al rendimento
        // delle ultime 5 partite (tirato verso la media stagionale) e corretta dallo stato di forma. Chi segna spesso ed è in forma ha
        // un peso alto (quota bassa), chi non segna mai un peso minimo (quota alta).
        $season = ($s['goals'] + bet_goal_prior($r['position'], match_keepers($match)) * 3) / ($s['apps'] + 3);
        $recent = ($s['goals_last5'] + $season * 2) / (count($s['last5']) + 2);
        $w = (0.65 * $season + 0.35 * $recent) * BET_FORM[$s['form'] ?? 'none'];
        $rows[$pid] = ['s' => $s, 'here' => $here, 'w' => $w, 'team' => in_array($r['team'], ['A', 'B'], true) ? $r['team'] : null];
    }
    // differenze attenuate: ognuno si avvicina un po' alla media della partita (nessuno a quote assurde solo per il ruolo o
    // per qualche partita storta)
    $avgW = $rows ? array_sum(array_column($rows, 'w')) / count($rows) : 0.0;
    foreach ($rows as $pid => $x) {
        $rows[$pid]['w'] = (1 - BET_FLATTEN) * $x['w'] + BET_FLATTEN * $avgW;
        $den += $x['here'] * $rows[$pid]['w'];
    }
    // over/under: gol attesi totali, poi una quota over e una under per ogni soglia
    $apps = $goals = 0;
    foreach ($stats as $st) {
        $apps += (int) ($st['apps'] ?? 0);
        $goals += (int) ($st['goals'] ?? 0);
    }
    $factor = 1.0;
    if ($apps > 0 && $goals > 0) {
        $g = $goals / $apps;   // gol a partita del giocatore medio del gruppo
        $num = $cnt = 0.0;
        foreach ($roster as $r) {
            $here = $r['availability'] === 'confermato' ? 1.0 : 0.7;
            $rate = $g;   // ospiti e chi non ha mai giocato: come la media
            if (!$r['is_guest'] && ($st = $stats[(int) $r['player_id']] ?? null)) {
                $season = ($st['goals'] + $g * 3) / ($st['apps'] + 3);
                $recent = ($st['goals_last5'] + $season * 2) / (count($st['last5']) + 2);
                $rate = (0.65 * $season + 0.35 * $recent) * BET_FORM[$st['form'] ?? 'none'];
            }
            $num += $here * $rate;
            $cnt += $here;
        }
        if ($cnt > 0) {
            $factor = max(0.6, min(1.6, $num / ($cnt * $g)));
        }
    }
    $lamTot = max(0.5, ($lam['A'] + $lam['B']) * $factor ** (BET_OU_PLAYERS_W * min(1.0, count($roster) / BET_OU_FULL_ROSTER)));
    $maxLine = min(60, max(BET_OU_MIN_LINES, (int) ceil(2.5 * $lamTot)));
    for ($k = 0; $k <= $maxLine; $k++) {
        $pOver = bet_poisson_at_least($lamTot, $k + 1);
        $out['overunder'][bet_ou_pick('O', $k + 0.5)] = bet_odds($pOver, 'overunder', 1.02, 50);
        $out['overunder'][bet_ou_pick('U', $k + 0.5)] = bet_odds(1 - $pOver, 'overunder', 1.02, 50);
    }

    $mvpW = [];
    foreach ($rows as $pid => $x) {
        // gol attesi del giocatore: quota dei 2*mu gol della partita, aggiustata dalla forza della sua squadra
        $goals = $den > 0 ? $x['here'] * 2 * $mu * $x['w'] / $den * ($x['team'] ? $lam[$x['team']] / $mu : 1.0) : 0.0;
        // tetti più bassi di prima (erano 50 / 100 / 200): una quota da 200 su un giocatore che comunque tira non ha senso
        $out['gol'][$pid] = bet_odds(1 - exp(-$goals), 'gol', 1.05, 15);
        $out['doppietta'][$pid] = bet_odds(bet_poisson_at_least($goals, 2), 'doppietta', 1.20, 35);
        $out['tripletta'][$pid] = bet_odds(bet_poisson_at_least($goals, 3), 'tripletta', 1.50, 75);
        $rate = ($x['s']['mvp'] + 3 / max(2, count($rows))) / ($x['s']['apps'] + 3);
        $v = (float) $x['s']['avg_vote'];
        $v5 = $x['s']['avg_vote_last5'];
        $vote = ($v5 !== null && $v > 0) ? 0.5 * $v + 0.5 * (float) $v5 : $v;   // media voto: metà stagione, metà ultime partite
        $form = exp(0.4 * ($vote > 0 ? $vote - 6 : 0)) * BET_FORM[$x['s']['form'] ?? 'none'];
        $mvpW[$pid] = $rate * $form * (1 + $goals) * (0.6 + 0.8 * ($x['team'] ? $winish[$x['team']] : 0.5)) * ($x['here'] >= 1 ? 1.0 : 0.6);
    }
    $tot = array_sum($mvpW);
    foreach ($mvpW as $pid => $x) {
        $out['mvp'][$pid] = bet_odds($x / $tot, 'mvp', 1.10, 40);
    }

    // il banco si protegge: la quota di ogni scelta scende un po' per ogni gettone già puntato su di lei in questa partita
    // (tranne la propria puntata aperta, se si sta cambiando: cambiare idea non deve penalizzare la nuova quota)
    $demand = bet_market_demand($id, $excludePlayerId);
    foreach ($out as $mk => $picks) {
        $out[$mk] = bet_demand_shorten($picks, $demand[$mk] ?? []);
    }
    return bet_boost_applies($match) ? bet_boost($out, $id) : $out;
}

/**
 * Le quote di questa partita vanno alzate? Sì per le prime partite, quando i dati sono pochi e l'incertezza è di più:
 * finché il gruppo non ha ancora nessuna partita giocata, e per le partite già in programma quando è stata introdotta la
 * regola (meta 'bet_boost_until' = id più alto di allora, fissato dalla migrazione 28).
 */
function bet_boost_applies(array $match): bool
{
    static $played = [];
    if ((int) $match['id'] <= (int) (meta_get('bet_boost_until') ?? 0)) {
        return true;
    }
    $gid = (int) $match['group_id'];
    $played[$gid] ??= (bool) q("SELECT 1 FROM matches WHERE group_id = ? AND status = 'giocata' LIMIT 1", [$gid])->fetchColumn();
    return !$played[$gid];
}

/**
 * Quota finale = quota x BET_BOOST_MULT + c, con c tra 0,2 e 0,5. La c "oscilla" tra una scelta e l'altra ma è sempre la stessa
 * per la stessa scelta della stessa partita (viene da un'impronta di partita, mercato e scelta): così la quota non cambia a ogni
 * caricamento della pagina e nessuno può ricaricare finché esce la c più alta.
 */
function bet_boost(array $quotes, int $matchId): array
{
    [$lo, $hi] = BET_BOOST_C;
    foreach ($quotes as $mk => $picks) {
        foreach ($picks as $pick => $o) {
            $c = $lo + ($hi - $lo) * (crc32($matchId . '|' . $mk . '|' . $pick) / 0xFFFFFFFF);
            $quotes[$mk][$pick] = round((float) $o * BET_BOOST_MULT + $c, 2);
        }
    }
    return $quotes;
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
            // mai sotto il 55% dell'apertura, e comunque mai sotto ×1,01 (una quota di ×1,05 molto puntata scendeva sotto ×1: si perdeva vincendo)
            $odds[$pick] = round(max(BET_MIN_ODDS, $o * BET_DEMAND_FLOOR, $adj), 2);
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

/** Quando si aprono le scommesse di una partita (timestamp): BET_OPEN_HOURS prima del calcio d'inizio. */
function bets_open_at(array $match): int
{
    return strtotime($match['match_date']) - BET_OPEN_HOURS * 3600;
}

/** Le scommesse per una partita sono aperte da BET_OPEN_HOURS prima fino al fischio d'inizio. */
function bets_open_for(array $match): bool
{
    return bets_before_kickoff($match) && time() >= bets_open_at($match);
}

/** Partita non ancora cominciata: le puntate già fatte si possono ancora ritirare. */
function bets_before_kickoff(array $match): bool
{
    return $match['status'] === 'programmata' && strtotime($match['match_date']) > time();
}

/** Partita in programma tra più di BET_OPEN_HOURS: le scommesse non sono ancora aperte. */
function bets_not_yet_open(array $match): bool
{
    return bets_before_kickoff($match) && time() < bets_open_at($match);
}

/** Giocatori su cui si può puntare (gol, MVP): chi non ha già detto di non esserci. */
function bet_candidates(int $matchId): array
{
    sync_match_players($matchId);
    return array_values(array_filter(match_roster($matchId), fn($r) => $r['availability'] !== 'assente' && !$r['is_guest']));
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
    if (q('SELECT is_guest FROM players WHERE id = ?', [$playerId])->fetchColumn()) {
        return 'Gli ospiti non possono scommettere.';
    }
    if (bets_not_yet_open($match)) {
        return 'Le scommesse su questa partita si aprono ' . push_when(date('Y-m-d H:i:s', bets_open_at($match))) . ' (' . BET_OPEN_HOURS . ' ore prima).';
    }
    if (!bets_open_for($match)) {
        return 'Le scommesse su questa partita sono chiuse: il calcio d\'inizio è passato.';
    }
    if ($market === 'esito') {
        if (!in_array($pick, ['A', 'X', 'B'], true)) {
            return 'Scegli chi vince.';
        }
    } elseif ($market === 'overunder') {
        if (!bet_ou_parse($pick)) {
            return 'Scegli over o under.';
        }
    } else {
        $ok = array_filter(bet_candidates((int) $match['id']), fn($r) => (string) $r['player_id'] === $pick);
        if (!$ok) {
            return 'Scegli un giocatore della partita.';
        }
        if ((int) $pick === $playerId) {
            return 'Non puoi scommettere su te stesso.';
        }
    }
    if ($stake < 1) {
        return 'Punta almeno 1 gettone.';
    }
    $odds = bet_quotes($match, $playerId)[$market][$pick] ?? null;   // la quota la decide il sito, non chi punta
    if ($odds === null) {
        return 'Su questa scelta non ci sono quote.';
    }
    try {
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
    } catch (PDOException $e) {
        error_log('bet_place: ' . $e->getMessage());
        if ($e->getCode() === '23000') {   // vincolo del database non ancora aggiornato: capita se la migrazione dello schema non è andata a buon fine
            return 'Il sito non è ancora del tutto aggiornato per puntare su più scelte dello stesso mercato: avvisa l\'admin.';
        }
        return 'La puntata non è andata a buon fine: riprova (se continua, avvisa l\'admin).';
    }
}

/** Ritira una puntata (una precisa scelta di un mercato) prima del fischio d'inizio (i gettoni tornano). Ritorna il messaggio d'errore oppure null. */
function bet_cancel(array $match, int $playerId, string $market, string $pick): ?string
{
    if (!bets_before_kickoff($match)) {
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
    $minGoals = ['gol' => 1, 'doppietta' => 2, 'tripletta' => 3][$market] ?? null;
    if ($minGoals) {
        return array_map('strval', q('SELECT player_id FROM match_players WHERE match_id = ? AND goals >= ?', [$id, $minGoals])->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($market === 'overunder') {
        if ($match['score_a'] === null || $match['score_b'] === null) {
            return null;
        }
        // tutte le scelte vincenti per ogni soglia possibile: la soglia è salvata nella scelta, quindi si confronta così
        $tot = (int) $match['score_a'] + (int) $match['score_b'];
        $win = [];
        for ($k = 0; $k < 100; $k++) {
            $win[] = bet_ou_pick($k + 0.5 < $tot ? 'O' : 'U', $k + 0.5);
        }
        return $win;
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
function bets_settle(int $matchId, array $markets = [...BET_RESULT_MARKETS, 'mvp']): void
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
function bets_unsettle(int $matchId, array $markets = [...BET_RESULT_MARKETS, 'mvp']): void
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

/** Il risultato è stato salvato o corretto: rifà i pagamenti dei mercati legati a risultato e marcatori, e i premi per gol e assist. */
function bets_resettle_result(int $matchId): void
{
    bet_atomic(function () use ($matchId) {
        bets_unsettle($matchId, BET_RESULT_MARKETS);
        bets_settle($matchId, BET_RESULT_MARKETS);
        match_rewards_sync($matchId);
    });
}

/**
 * Premi della partita: BET_REWARD_GOAL gettoni per ogni gol e BET_REWARD_ASSIST per ogni assist, a chi li ha fatti (ospiti esclusi).
 * Una mossa del portafoglio per giocatore e partita (ref "premio-m<id>"), che si aggiorna se il risultato viene corretto e sparisce
 * se la partita torna "programmata" o viene eliminata. Si può richiamare quante volte si vuole.
 */
function match_rewards_sync(int $matchId): void
{
    $ref = 'premio-m' . $matchId;
    $m = get_match($matchId);
    $want = [];
    if ($m && $m['status'] === 'giocata') {
        foreach (q('SELECT mp.player_id, mp.goals, mp.assists FROM match_players mp JOIN players p ON p.id = mp.player_id
                    WHERE mp.match_id = ? AND mp.team IS NOT NULL AND p.is_guest = 0 AND (mp.goals > 0 OR mp.assists > 0)', [$matchId])->fetchAll() as $r) {
            $want[(int) $r['player_id']] = (int) $r['goals'] * BET_REWARD_GOAL + (int) $r['assists'] * BET_REWARD_ASSIST;
        }
    }
    foreach ($want as $pid => $delta) {
        q("INSERT INTO wallet_moves (player_id, delta, kind, ref) VALUES (?, ?, 'premio', ?) ON DUPLICATE KEY UPDATE delta = VALUES(delta)", [$pid, $delta, $ref]);
    }
    $keep = array_keys($want);
    q('DELETE FROM wallet_moves WHERE ref = ?' . ($keep ? ' AND player_id NOT IN (' . implode(',', array_map('intval', $keep)) . ')' : ''), [$ref]);
}

/** Gettoni vinti da un giocatore con gol e assist (premi di tutte le partite). */
function player_rewards_total(int $playerId): int
{
    return (int) q("SELECT COALESCE(SUM(delta), 0) FROM wallet_moves WHERE player_id = ? AND kind = 'premio'", [$playerId])->fetchColumn();
}

/**
 * Riprezza le puntate ancora aperte con il modello di quote attuale (serve quando il modello cambia): le singole con le quote di
 * adesso (senza contare le proprie puntate nella domanda, come quando si punta), le gambe delle multiple idem, e la quota della
 * multipla torna il prodotto delle gambe. Le puntate già decise non si toccano. Le puntate su se stessi vengono annullate e
 * rimborsate (non sono più ammesse). Ritorna [singole riprezzate, multiple riprezzate, annullate].
 */
function bets_requote_open(): array
{
    $cache = [];
    $quotes = function (int $matchId, ?int $exclude) use (&$cache) {
        $k = $matchId . '|' . (int) $exclude;
        if (!isset($cache[$k])) {
            $m = get_match($matchId);
            $cache[$k] = $m ? bet_quotes($m, $exclude) : [];
        }
        return $cache[$k];
    };
    $singles = $combos = $cancelled = 0;
    // prima via le puntate su se stessi (singole e multiple che ne contengono una): i gettoni tornano indietro
    foreach (q("SELECT id FROM bets WHERE status = 'aperta' AND market IN ('" . implode("','", BET_PLAYER_MARKETS) . "') AND pick = CAST(player_id AS CHAR)")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        q('DELETE FROM bets WHERE id = ?', [$id]);
        $cancelled++;
    }
    foreach (q("SELECT DISTINCT cb.id FROM combo_bets cb JOIN combo_legs cl ON cl.combo_id = cb.id
                WHERE cb.status = 'aperta' AND cl.market IN ('" . implode("','", BET_PLAYER_MARKETS) . "') AND cl.pick = CAST(cb.player_id AS CHAR)")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        q('DELETE FROM combo_bets WHERE id = ?', [$id]);
        $cancelled++;
    }
    foreach (q("SELECT b.id, b.match_id, b.player_id, b.market, b.pick, b.odds FROM bets b JOIN matches m ON m.id = b.match_id
                WHERE b.status = 'aperta' AND m.status = 'programmata'")->fetchAll() as $b) {
        $new = $quotes((int) $b['match_id'], (int) $b['player_id'])[$b['market']][$b['pick']] ?? null;
        if ($new !== null && abs((float) $new - (float) $b['odds']) > 0.001) {
            q('UPDATE bets SET odds = ? WHERE id = ?', [$new, $b['id']]);
            $singles++;
        }
    }
    foreach (q("SELECT id, player_id FROM combo_bets WHERE status = 'aperta'")->fetchAll(PDO::FETCH_KEY_PAIR) as $cid => $owner) {
        $changed = false;
        $odds = 1.0;
        foreach (q("SELECT cl.id, cl.match_id, cl.market, cl.pick, cl.odds, cl.status, m.status AS mstatus FROM combo_legs cl JOIN matches m ON m.id = cl.match_id
                    WHERE cl.combo_id = ?", [$cid])->fetchAll() as $l) {
            $o = (float) $l['odds'];
            if ($l['status'] === 'aperta' && $l['mstatus'] === 'programmata') {
                $new = $quotes((int) $l['match_id'], (int) $owner)[$l['market']][$l['pick']] ?? null;   // come quando l'ha giocata
                if ($new !== null && abs((float) $new - $o) > 0.001) {
                    q('UPDATE combo_legs SET odds = ? WHERE id = ?', [$new, $l['id']]);
                    $o = (float) $new;
                    $changed = true;
                }
            }
            $odds *= max(BET_MIN_ODDS, $o);
        }
        if ($changed) {
            q('UPDATE combo_bets SET odds = ? WHERE id = ?', [round(max(BET_MIN_ODDS, $odds), 2), $cid]);
            $combos++;
        }
    }
    return [$singles, $combos, $cancelled];
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
        if (bets_not_yet_open($match)) {
            return [null, 'Le scommesse di una delle partite scelte non sono ancora aperte (si aprono ' . BET_OPEN_HOURS . ' ore prima).'];
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
        // «chi vince» e «MVP» hanno un solo esito vincente: due scelte della stessa partita in una multipla si escludono
        // a vicenda (sarebbe persa di sicuro), come nei bookmaker veri. I marcatori invece possono segnare in tanti.
        if (!in_array($market, BET_SCORER_MARKETS, true)) {
            $excl = $matchId . '|' . $market;
            if (isset($seen[$excl])) {
                return [null, 'Nella multipla puoi mettere una sola scelta di «' . bet_markets()[$market]['label'] . '» per partita: si escludono a vicenda. Due marcatori invece sì.'];
            }
            $seen[$excl] = true;
        } else {
            // stesso giocatore su «segna», «doppietta» e «tripletta» nella stessa multipla no: una implica l'altra (la tripletta
            // basterebbe da sola), moltiplicarne le quote sarebbe pagare due volte lo stesso evento
            $scorer = $matchId . '|scorer|' . $pick;
            if (isset($seen[$scorer])) {
                return [null, 'Nella multipla lo stesso giocatore può stare in uno solo tra «segna», «doppietta» e «tripletta»: una comprende l\'altra.'];
            }
            $seen[$scorer] = true;
        }
        if ($market === 'esito') {
            if (!in_array($pick, ['A', 'X', 'B'], true)) {
                return [null, 'Scelta non valida su «chi vince».'];
            }
        } elseif ($market === 'overunder') {
            if (!bet_ou_parse($pick)) {
                return [null, 'Scelta non valida su «over/under».'];
            }
        } elseif (!array_filter(bet_candidates($matchId), fn($c) => (string) $c['player_id'] === $pick)) {
            return [null, 'Scegli un giocatore che gioca quella partita.'];
        } elseif ((int) $pick === $playerId) {
            return [null, 'Non puoi scommettere su te stesso: togli la tua selezione dalla multipla.'];
        }
        $odds = bet_quotes($match, $playerId)[$market][$pick] ?? null;   // le stesse quote che la persona vede nella schedina
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
        $o *= max(BET_MIN_ODDS, (float) $l['odds']);
    }
    return round(max(BET_MIN_ODDS, $o), 2);
}

/** Fa una multipla. Ritorna il messaggio d'errore oppure null se è andata. */
function combo_place(int $playerId, array $legs, int $stake): ?string
{
    if (q('SELECT is_guest FROM players WHERE id = ?', [$playerId])->fetchColumn()) {
        return 'Gli ospiti non possono scommettere.';
    }
    if ($stake < 1) {
        return 'Punta almeno 1 gettone.';
    }
    $odds = combo_odds($legs);
    try {
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
    } catch (PDOException $e) {
        error_log('combo_place: ' . $e->getMessage());
        if ($e->getCode() === '23000') {
            return 'Il sito non è ancora del tutto aggiornato per le multiple con più scelte dello stesso mercato: avvisa l\'admin.';
        }
        return 'La multipla non è andata a buon fine: riprova (se continua, avvisa l\'admin).';
    }
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
        if (!$m || !bets_before_kickoff($m)) {
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
            $odds *= max(BET_MIN_ODDS, (float) $l['odds']);
        }
        $pay = (int) floor((int) $combo['stake'] * $odds + 1e-9);
        q("UPDATE combo_bets SET status = 'vinta', payout = ?, settled_at = NOW() WHERE id = ?", [$pay, $comboId]);
        q("INSERT INTO wallet_moves (player_id, combo_id, delta, kind) VALUES (?, ?, ?, 'vincita')", [$combo['player_id'], $comboId, $pay]);
    });
}

/* ---------------------------------------------------------------- parole */

/** "Blu", "Pareggio", "Arancio", "Over 8,5 gol" o il nome del giocatore, a partire dalla scelta salvata. */
function bet_pick_label(array $match, string $market, string $pick, array $names = []): string
{
    if ($market === 'esito') {
        return $pick === 'X' ? 'Pareggio' : team_name($pick, $match);
    }
    if ($market === 'overunder') {
        $ou = bet_ou_parse($pick);
        return $ou ? ($ou[0] === 'O' ? 'Over ' : 'Under ') . number_format($ou[1], 1, ',', '') . ' gol' : '?';
    }
    return $names[(int) $pick] ?? (get_player((int) $pick)['name'] ?? '?');
}
