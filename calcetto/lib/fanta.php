<?php
/*
 * Fantacalcio della lega (fanta.php): ogni giocatore con un account è anche un fantallenatore e si compra le «figurine» dei
 * giocatori della sua lega. Più fantallenatori possono avere la stessa figurina.
 *
 *  - Stagioni: le apre e le chiude chi amministra la lega. All'apertura si fissano i prezzi delle figurine (fanta_prices), che
 *    restano quelli per tutta la stagione; alla chiusura i primi FANTA_PRIZE_RANKS della classifica ricevono i premi.
 *  - Rosa: FANTA_ROSTER figurine (FANTA_STARTERS titolari e una in panchina) con un budget di FANTA_BUDGET crediti fanta
 *    (separati dai KOIN del portafoglio). Si compra e si vende quando si vuole: chi vende riprende quello che la figurina gli
 *    è costata. Tra i titolari si sceglie un capitano, che raddoppia bonus e malus.
 *  - Scambi: un fantallenatore propone «ti do X, mi dai Y» a un altro, che accetta o rifiuta. Nessuno dei due può ritrovarsi
 *    due volte la stessa figurina. Ognuno tiene il costo della figurina che ha dato (così il budget di tutti e due non cambia).
 *  - Formazioni: al calcio d'inizio di ogni partita della lega la rosa di ognuno si «fotografa» (fanta_lineups) e per quella
 *    partita contano solo quelle figurine, anche se dopo si cambia. La foto si scatta alla prima richiesta dopo il calcio
 *    d'inizio, e sempre prima di qualsiasi cambio di rosa: così è identica alla rosa che c'era al fischio d'inizio.
 *  - Punti di una figurina in una partita (fanta_match_points): media dei voti ricevuti + bonus (FANTA_BONUS). Chi non ha
 *    giocato fa 0; se un titolare non ha giocato entra quello in panchina, se ha giocato. L'MVP conta a votazioni chiuse.
 *  - Premi: oggetti del Personaggio e nickname che non si comprano (fanta_reward_items, nel catalogo con 'fanta' => posizione):
 *    un oggetto con 'fanta' => N va a chi arriva tra i primi N. Chi ce l'ha già riceve FANTA_DUPLICATE_KOIN al suo posto.
 */

const FANTA_BUDGET = 10;
const FANTA_ROSTER = 6;
const FANTA_STARTERS = 5;
const FANTA_BONUS = ['goal' => 3, 'assist' => 1, 'mvp' => 3, 'win' => 1, 'own_goal' => -2];
const FANTA_PRIZE_RANKS = 5;
const FANTA_DUPLICATE_KOIN = 60;   // premio già vinto in una stagione precedente: al suo posto questi KOIN
const FANTA_MAX_OPEN_TRADES = 5;   // proposte di scambio in attesa per fantallenatore
// prezzi: la parte della lega (dal più forte in giù) che costa 4, 3 e 2 crediti; gli altri costano 1
const FANTA_PRICE_TIERS = [4 => .10, 3 => .25, 2 => .50];
const FANTA_PRICE_MATCHES = 12;    // i prezzi guardano le ultime partite della lega...
const FANTA_PRICE_PRIOR = 3;       // ...e chi ne ha giocate poche viene avvicinato alla media della lega

/* ---------------------------------------------------------------- premi */

/** I premi del Fanta, per tipo del catalogo (lib/shop.php li aggiunge a shop_catalog). 'fanta' => fino a che posizione si vince. */
function fanta_reward_items(): array
{
    $d = fn(int $n) => $n === 1 ? 'Premio del Fanta: solo per chi vince la stagione.' : 'Premio del Fanta: per chi chiude la stagione tra i primi ' . $n . '.';
    return [
        'celebration' => [
            'fx_koin' => ['name' => 'Pioggia di KOIN', 'price' => 999, 'anim' => 'koin-rain', 'fanta' => 5, 'desc' => $d(5)],
            'fx_giro' => ['name' => 'Giro d\'onore', 'price' => 999, 'anim' => 'honour-lap', 'fanta' => 3, 'desc' => $d(3)],
            'fx_coppa' => ['name' => 'Alza la coppa', 'price' => 999, 'anim' => 'cup-lift', 'fanta' => 1, 'desc' => $d(1)],
        ],
        'hair' => [
            'fx_cresta_punk' => ['name' => 'Cresta ribelle', 'price' => 999, 'style' => 'crest_punk', 'fanta' => 4, 'desc' => $d(4)],
            'fx_cresta_oro' => ['name' => 'Cresta d\'oro', 'price' => 999, 'style' => 'crest_gold', 'fanta' => 2, 'desc' => $d(2)],
        ],
        'hat' => [
            'fx_alloro' => ['name' => 'Alloro del Fanta', 'price' => 999, 'tpl' => 'laurel', 'colors' => ['a' => '#53c8f5', 'b' => '', 'c' => ''],
                'fanta' => 3, 'desc' => $d(3)],
            'fx_corona' => ['name' => 'Corona del Fanta', 'price' => 999, 'tpl' => 'crown', 'colors' => ['a' => '#1f1a2e', 'b' => '#ffd23f', 'c' => '#38d178'],
                'fanta' => 1, 'desc' => $d(1)],
        ],
        'jersey' => [
            'fx_maglia' => ['name' => 'Maglia del Campione', 'price' => 999, 'kind' => 'club', 'colors' => ['a' => '#1f1a2e', 'b' => '#ffd23f'],
                'pattern' => 'sash', 'fanta' => 2, 'desc' => $d(2)],
        ],
        'nick' => [
            'fx_re' => ['name' => 'Re del Fanta', 'price' => 999, 'fanta' => 1, 'desc' => $d(1)],
        ],
    ];
}

/** I premi che vince chi arriva $rank-esimo: [[tipo, chiave, oggetto], ...]. */
function fanta_prizes_for(int $rank): array
{
    $out = [];
    foreach (fanta_reward_items() as $kind => $items) {
        foreach ($items as $k => $item) {
            if ($rank >= 1 && $rank <= $item['fanta']) {
                $out[] = [$kind, $k, $item];
            }
        }
    }
    return $out;
}

/* ---------------------------------------------------------------- stagioni */

/** La stagione aperta della lega (o null). */
function fanta_season(int $gid): ?array
{
    $s = q("SELECT * FROM fanta_seasons WHERE group_id = ? AND status = 'aperta' ORDER BY id DESC LIMIT 1", [$gid])->fetch();
    return $s ?: null;
}

/** L'ultima stagione della lega, aperta o chiusa (o null). */
function fanta_last_season(int $gid): ?array
{
    $s = q('SELECT * FROM fanta_seasons WHERE group_id = ? ORDER BY id DESC LIMIT 1', [$gid])->fetch();
    return $s ?: null;
}

/** Apre una stagione nuova e ne fissa i prezzi. Ritorna l'errore, se c'è. */
function fanta_open_season(int $gid): ?string
{
    if (fanta_season($gid)) {
        return 'C\'è già una stagione aperta: prima va chiusa.';
    }
    $n = (int) q('SELECT COUNT(*) FROM fanta_seasons WHERE group_id = ?', [$gid])->fetchColumn() + 1;
    q("INSERT INTO fanta_seasons (group_id, n, status, started_at) VALUES (?, ?, 'aperta', ?)", [$gid, $n, date('Y-m-d H:i:s')]);
    $sid = (int) db()->lastInsertId();
    foreach (fanta_price_list($gid) as $pid => $price) {
        q('INSERT IGNORE INTO fanta_prices (season_id, player_id, price) VALUES (?, ?, ?)', [$sid, $pid, $price]);
    }
    log_activity('fanta', 'stagione ' . $n . ' aperta', $gid);
    return null;
}

/**
 * Chiude la stagione: ultima foto delle formazioni, classifica finale e premi ai primi FANTA_PRIZE_RANKS.
 * Ritorna l'errore, oppure null.
 */
function fanta_close_season(int $gid): ?string
{
    $s = fanta_season($gid);
    if (!$s) {
        return 'Non c\'è una stagione aperta.';
    }
    fanta_snapshot_due();
    $n = q("UPDATE fanta_seasons SET status = 'chiusa', closed_at = ? WHERE id = ? AND status = 'aperta'", [date('Y-m-d H:i:s'), $s['id']])->rowCount();
    if ($n !== 1) {
        return 'La stagione è già stata chiusa.';
    }
    $rows = fanta_standings((int) $s['id']);
    foreach ($rows as $r) {
        if ($r['rank'] > FANTA_PRIZE_RANKS || $r['total'] <= 0) {
            break;   // chi ha fatto 0 punti (nessuna partita) non vince niente
        }
        $won = [];
        foreach (fanta_prizes_for($r['rank']) as [$kind, $key, $item]) {
            if (q('INSERT IGNORE INTO player_items (player_id, item_key, price) VALUES (?, ?, 0)', [$r['manager_id'], $key])->rowCount()) {
                $won[] = $item['name'];
            } else {
                q("INSERT IGNORE INTO wallet_moves (player_id, delta, kind, ref) VALUES (?, ?, 'fanta', ?)",
                    [$r['manager_id'], FANTA_DUPLICATE_KOIN, 'fs' . $s['id'] . '-' . substr(md5($key), 0, 10)]);
                $won[] = FANTA_DUPLICATE_KOIN . ' KOIN (al posto di «' . $item['name'] . '», che avevi già)';
            }
        }
        q('INSERT IGNORE INTO fanta_awards (season_id, manager_id, rank_pos, points, prizes) VALUES (?, ?, ?, ?, ?)',
            [$s['id'], $r['manager_id'], $r['rank'], $r['total'], implode(' · ', $won)]);
        fanta_notify([$r['manager_id']], [
            'title' => 'Fanta: sei arrivato ' . $r['rank'] . '°!',
            'body' => 'Stagione ' . $s['n'] . ' chiusa con ' . fanta_fmt($r['total']) . ' punti. Premi: ' . implode(', ', $won) . '.',
            'url' => 'fanta.php?t=premi', 'tag' => 'fanta-season-' . $s['id'],
        ]);
    }
    log_activity('fanta', 'stagione ' . $s['n'] . ' chiusa', $gid);
    return null;
}

/* ---------------------------------------------------------------- figurine e prezzi */

/** Le figurine della lega: i giocatori attivi (non ospiti) del gruppo. [id => giocatore] */
function fanta_cards(int $gid): array
{
    $out = [];
    foreach (q('SELECT p.* FROM players p JOIN player_groups pg ON pg.player_id = p.id
                WHERE pg.group_id = ? AND p.is_guest = 0 AND p.active = 1 ORDER BY p.name', [$gid])->fetchAll() as $p) {
        $out[(int) $p['id']] = $p;
    }
    return $out;
}

/** I fantallenatori della lega: giocatori del gruppo con un account attivo. [id giocatore => true] */
function fanta_managers(int $gid): array
{
    $ids = q("SELECT DISTINCT p.id FROM players p JOIN player_groups pg ON pg.player_id = p.id JOIN users u ON u.player_id = p.id
              WHERE pg.group_id = ? AND p.is_guest = 0 AND u.status = 'attivo' AND u.role <> 'ospite'", [$gid])->fetchAll(PDO::FETCH_COLUMN);
    return array_fill_keys(array_map('intval', $ids), true);
}

/** Può giocare al Fanta di questa lega? */
function fanta_can_play(?int $playerId, int $gid): bool
{
    return $playerId !== null && !is_guest() && isset(fanta_managers($gid)[$playerId]);
}

/**
 * Rendimento delle figurine per fissare i prezzi: punti fanta attesi a partita della lega, cioè la media dei punti nelle partite
 * giocate (avvicinata alla media della lega per chi ne ha giocate poche) per quanto spesso gioca, sulle ultime FANTA_PRICE_MATCHES.
 * @return array<int, array{avg: float, apps: int, rate: float, value: float}>
 */
function fanta_form(int $gid): array
{
    $mids = array_map('intval', q("SELECT id FROM matches WHERE group_id = ? AND status = 'giocata' ORDER BY match_date DESC LIMIT " . FANTA_PRICE_MATCHES,
        [$gid])->fetchAll(PDO::FETCH_COLUMN));
    $sum = $apps = [];
    foreach ($mids as $mid) {
        foreach (fanta_match_points($mid) as $pid => $pt) {
            $sum[$pid] = ($sum[$pid] ?? 0) + $pt['pts'];
            $apps[$pid] = ($apps[$pid] ?? 0) + 1;
        }
    }
    $all = array_sum($apps);
    $mean = $all ? array_sum($sum) / $all : DEFAULT_VOTE;
    $rateMean = $mids && $sum ? $all / (count($mids) * max(1, count($sum))) : .5;
    $out = [];
    foreach (fanta_cards($gid) as $pid => $_) {
        $n = $apps[$pid] ?? 0;
        $avg = ($sum[$pid] ?? 0) / max(1, $n);
        $shrunkAvg = (($sum[$pid] ?? 0) + FANTA_PRICE_PRIOR * $mean) / ($n + FANTA_PRICE_PRIOR);
        $rate = $mids ? ($n + FANTA_PRICE_PRIOR * $rateMean) / (count($mids) + FANTA_PRICE_PRIOR) : $rateMean;
        $out[$pid] = ['avg' => $n ? $avg : 0.0, 'apps' => $n, 'rate' => $mids ? $n / count($mids) : 0.0, 'value' => $shrunkAvg * $rate];
    }
    return $out;
}

/** Prezzo di ogni figurina (1-4) dal rendimento: i più forti costano di più (FANTA_PRICE_TIERS). [id => prezzo] */
function fanta_price_list(int $gid): array
{
    $form = fanta_form($gid);
    uasort($form, fn($a, $b) => $b['value'] <=> $a['value']);
    $n = count($form);
    // dove sta in classifica (0 = il migliore, 1 = l'ultimo); chi ha lo stesso rendimento sta nello stesso punto (la media dei
    // posti che occupano insieme), così a lega appena nata, senza partite, costano tutti uguale
    $ties = [];
    $i = 0;
    foreach ($form as $pid => $f) {
        $ties[sprintf('%.3f', $f['value'])][] = [$pid, $i++];
    }
    $out = [];
    foreach ($ties as $group) {
        $share = (array_sum(array_column($group, 1)) / count($group) + .5) / max(1, $n);
        $price = 1;
        foreach (FANTA_PRICE_TIERS as $p => $upTo) {
            if ($share < $upTo) {
                $price = $p;
                break;
            }
        }
        foreach ($group as [$pid]) {
            $out[$pid] = $price;
        }
    }
    return $out;
}

/** Prezzi della stagione: [id => prezzo]. Chi è entrato nella lega a stagione iniziata prende il prezzo di adesso (e lo tiene). */
function fanta_prices(array $season): array
{
    $sid = (int) $season['id'];
    $out = [];
    foreach (q('SELECT player_id, price FROM fanta_prices WHERE season_id = ?', [$sid])->fetchAll() as $r) {
        $out[(int) $r['player_id']] = (int) $r['price'];
    }
    $missing = array_diff_key(fanta_cards((int) $season['group_id']), $out);
    if ($missing) {
        $now = fanta_price_list((int) $season['group_id']);
        foreach ($missing as $pid => $_) {
            $out[$pid] = $now[$pid] ?? 1;
            q('INSERT IGNORE INTO fanta_prices (season_id, player_id, price) VALUES (?, ?, ?)', [$sid, $pid, $out[$pid]]);
        }
    }
    return $out;
}

/* ---------------------------------------------------------------- punti */

/**
 * Punti fanta di chi ha giocato una partita: [id giocatore => ['vote', 'bonus', 'pts', 'events' => [...]]].
 * Vuoto se la partita non è ancora giocata. 'provisional' = votazioni ancora aperte (voti e MVP possono cambiare).
 */
function fanta_match_points(int $matchId): array
{
    static $cache = [];
    if (isset($cache[$matchId])) {
        return $cache[$matchId];
    }
    $m = get_match($matchId);
    if (!$m || $m['status'] !== 'giocata') {
        return $cache[$matchId] = [];
    }
    $closed = !(int) $m['voting_open'];
    $mvp = $closed ? match_mvp($matchId) : null;
    $votes = match_vote_averages()[$matchId] ?? [];
    $out = [];
    foreach (q('SELECT mp.player_id, mp.team, mp.goals, mp.assists, mp.own_goals FROM match_players mp JOIN players p ON p.id = mp.player_id
                WHERE mp.match_id = ? AND mp.team IS NOT NULL AND p.is_guest = 0', [$matchId])->fetchAll() as $r) {
        $pid = (int) $r['player_id'];
        $ev = ['goal' => (int) $r['goals'], 'assist' => (int) $r['assists'], 'own_goal' => (int) $r['own_goals'],
            'mvp' => $mvp === $pid ? 1 : 0, 'win' => result_for($m, $r['team']) === 'V' ? 1 : 0];
        $bonus = 0;
        foreach ($ev as $k => $n) {
            $bonus += $n * FANTA_BONUS[$k];
        }
        $vote = isset($votes[$pid]) ? round($votes[$pid]['avg'], 1) : (float) DEFAULT_VOTE;
        $out[$pid] = ['vote' => $vote, 'bonus' => $bonus, 'pts' => $vote + $bonus, 'events' => $ev, 'provisional' => !$closed];
    }
    return $cache[$matchId] = $out;
}

/**
 * Punteggio di una formazione in una partita: titolari che hanno giocato (il capitano raddoppia bonus e malus) e, al posto del
 * primo titolare che non ha giocato, la panchina se ha giocato lei. @param array $lineup righe di fanta_lineups
 * @return array{total: float, rows: array}
 */
function fanta_lineup_score(array $lineup, array $points): array
{
    $bench = null;
    foreach ($lineup as $l) {
        if ($l['role'] === 'P') {
            $bench = $l;
        }
    }
    $benchUsed = false;
    $rows = [];
    $total = 0.0;
    foreach ($lineup as $l) {
        if ($l['role'] !== 'T') {
            continue;
        }
        $pid = (int) $l['player_id'];
        $cap = (bool) $l['captain'];
        if (isset($points[$pid])) {
            $p = $points[$pid];
            $pts = $p['pts'] + ($cap ? $p['bonus'] : 0);
            $rows[] = ['player_id' => $pid, 'pts' => $pts, 'captain' => $cap, 'sub' => false, 'played' => true];
            $total += $pts;
        } else {
            $rows[] = ['player_id' => $pid, 'pts' => 0.0, 'captain' => $cap, 'sub' => false, 'played' => false];
            if (!$benchUsed && $bench && isset($points[(int) $bench['player_id']])) {
                $benchUsed = true;
                $bp = $points[(int) $bench['player_id']]['pts'];
                $rows[] = ['player_id' => (int) $bench['player_id'], 'pts' => $bp, 'captain' => false, 'sub' => true, 'played' => true];
                $total += $bp;
            }
        }
    }
    if ($bench && !$benchUsed) {
        $rows[] = ['player_id' => (int) $bench['player_id'], 'pts' => 0.0, 'captain' => false, 'sub' => false, 'played' => isset($points[(int) $bench['player_id']]),
            'bench' => true];
    }
    return ['total' => round($total, 1), 'rows' => $rows];
}

/** Le partite della stagione già «fotografate» (dal calcio d'inizio in poi), dalla più recente. */
function fanta_season_matches(int $seasonId): array
{
    return q('SELECT m.* FROM fanta_snapshots x JOIN matches m ON m.id = x.match_id WHERE x.season_id = ? ORDER BY m.match_date DESC',
        [$seasonId])->fetchAll();
}

/** Formazioni di una partita: [id fantallenatore => righe]. */
function fanta_lineups(int $matchId): array
{
    $out = [];
    foreach (q('SELECT * FROM fanta_lineups WHERE match_id = ? ORDER BY role DESC, captain DESC, player_id', [$matchId])->fetchAll() as $r) {
        $out[(int) $r['manager_id']][] = $r;
    }
    return $out;
}

/**
 * Classifica di una stagione: tutti contro tutti, somma dei punti di ogni partita.
 * @return array<int, array{manager_id: int, total: float, last: ?float, best: float, played: int, rank: int, per_match: array}>
 */
function fanta_standings(int $seasonId): array
{
    $sid = $seasonId;
    $tot = [];
    $matches = fanta_season_matches($sid);
    foreach (array_map('intval', q('SELECT DISTINCT manager_id FROM fanta_picks WHERE season_id = ?', [$sid])->fetchAll(PDO::FETCH_COLUMN)) as $mid) {
        $tot[$mid] = ['manager_id' => $mid, 'total' => 0.0, 'last' => null, 'best' => 0.0, 'played' => 0, 'per_match' => []];
    }
    foreach ($matches as $i => $m) {
        $points = fanta_match_points((int) $m['id']);
        if (!$points) {
            continue;   // non ancora giocata
        }
        foreach (fanta_lineups((int) $m['id']) as $mgr => $lineup) {
            $sc = fanta_lineup_score($lineup, $points)['total'];
            $tot[$mgr] ??= ['manager_id' => $mgr, 'total' => 0.0, 'last' => null, 'best' => 0.0, 'played' => 0, 'per_match' => []];
            $tot[$mgr]['total'] += $sc;
            $tot[$mgr]['per_match'][(int) $m['id']] = $sc;
            $tot[$mgr]['last'] ??= $sc;
            $tot[$mgr]['best'] = max($tot[$mgr]['best'], $sc);
            $tot[$mgr]['played']++;
        }
    }
    $names = [];
    if ($tot) {
        foreach (q('SELECT id, name FROM players WHERE id IN (' . implode(',', array_keys($tot)) . ')')->fetchAll() as $p) {
            $names[(int) $p['id']] = $p['name'];
        }
    }
    $rows = array_values($tot);
    usort($rows, fn($a, $b) => [$b['total'], $b['best'], $names[$a['manager_id']] ?? ''] <=> [$a['total'], $a['best'], $names[$b['manager_id']] ?? '']);
    foreach ($rows as $i => &$r) {
        $r['total'] = round($r['total'], 1);
        $r['rank'] = $i + 1;
    }
    return $rows;
}

/* ---------------------------------------------------------------- foto delle formazioni */

/**
 * Per ogni partita di una lega con la stagione aperta arrivata al calcio d'inizio e non ancora fotografata: salva la rosa di
 * ognuno. Si chiama a ogni richiesta (lib/bootstrap.php), da cron.php e prima di ogni cambio di rosa.
 */
function fanta_snapshot_due(): void
{
    $due = q("SELECT m.id, s.id AS sid FROM matches m JOIN fanta_seasons s ON s.group_id = m.group_id AND s.status = 'aperta'
              LEFT JOIN fanta_snapshots x ON x.match_id = m.id
              WHERE x.match_id IS NULL AND m.match_date <= ? AND m.match_date >= s.started_at", [date('Y-m-d H:i:s')])->fetchAll();
    foreach ($due as $d) {
        bet_atomic(function () use ($d) {
            if (!q('INSERT IGNORE INTO fanta_snapshots (match_id, season_id) VALUES (?, ?)', [$d['id'], $d['sid']])->rowCount()) {
                return;   // l'ha già fatta un'altra richiesta
            }
            q('INSERT IGNORE INTO fanta_lineups (match_id, manager_id, player_id, role, captain)
               SELECT ?, manager_id, player_id, role, captain FROM fanta_picks WHERE season_id = ?', [$d['id'], $d['sid']]);
        });
    }
}

/* ---------------------------------------------------------------- rosa */

/** La rosa di un fantallenatore: titolari (il capitano per primo) e poi la panchina. */
function fanta_roster(int $seasonId, int $managerId): array
{
    return q('SELECT * FROM fanta_picks WHERE season_id = ? AND manager_id = ? ORDER BY role DESC, captain DESC, cost DESC, created_at',
        [$seasonId, $managerId])->fetchAll();
}

/** Crediti spesi (il costo delle figurine che ha adesso). */
function fanta_spent(int $seasonId, int $managerId): int
{
    return (int) q('SELECT COALESCE(SUM(cost), 0) FROM fanta_picks WHERE season_id = ? AND manager_id = ?', [$seasonId, $managerId])->fetchColumn();
}

/** Rimette a posto ruoli e capitano: fino a FANTA_STARTERS titolari, il resto in panchina; un capitano tra i titolari. */
function fanta_fix_roles(int $seasonId, int $managerId): void
{
    $rows = fanta_roster($seasonId, $managerId);
    $starters = array_values(array_filter($rows, fn($r) => $r['role'] === 'T'));
    $bench = array_values(array_filter($rows, fn($r) => $r['role'] === 'P'));
    while (count($starters) < FANTA_STARTERS && $bench) {   // un titolare venduto: entra la panchina
        $b = array_shift($bench);
        q("UPDATE fanta_picks SET role = 'T' WHERE season_id = ? AND manager_id = ? AND player_id = ?", [$seasonId, $managerId, $b['player_id']]);
        $starters[] = $b;
    }
    q("UPDATE fanta_picks SET captain = 0 WHERE season_id = ? AND manager_id = ? AND role = 'P'", [$seasonId, $managerId]);
    $caps = array_filter($starters, fn($r) => (int) $r['captain'] === 1);
    if (!$caps && $starters) {   // senza capitano: la fascia va al titolare che costa di più
        usort($starters, fn($a, $b) => $b['cost'] <=> $a['cost']);
        q('UPDATE fanta_picks SET captain = 1 WHERE season_id = ? AND manager_id = ? AND player_id = ?', [$seasonId, $managerId, $starters[0]['player_id']]);
    }
}

/** Blocca il fantallenatore (e l'altro, negli scambi) per le scritture: niente doppie spese con due richieste insieme. */
function fanta_lock(int ...$managerIds): void
{
    sort($managerIds);
    foreach ($managerIds as $id) {
        q('SELECT id FROM players WHERE id = ? FOR UPDATE', [$id]);
    }
}

/** Compra una figurina. Ritorna l'errore, oppure null. */
function fanta_buy(array $season, int $managerId, int $playerId): ?string
{
    fanta_snapshot_due();
    $sid = (int) $season['id'];
    $prices = fanta_prices($season);
    if (!isset($prices[$playerId], fanta_cards((int) $season['group_id'])[$playerId])) {
        return 'Questa figurina non è della tua lega.';
    }
    return bet_atomic(function () use ($sid, $managerId, $playerId, $prices) {
        fanta_lock($managerId);
        $rows = fanta_roster($sid, $managerId);
        if (in_array($playerId, array_map(fn($r) => (int) $r['player_id'], $rows), true)) {
            return 'Ce l\'hai già in rosa.';
        }
        if (count($rows) >= FANTA_ROSTER) {
            return 'La rosa è piena (' . FANTA_ROSTER . ' figurine): prima vendine una.';
        }
        $left = FANTA_BUDGET - fanta_spent($sid, $managerId);
        if ($prices[$playerId] > $left) {
            return 'Costa ' . fanta_credits($prices[$playerId]) . ' e ne hai ' . $left . '.';
        }
        $starters = count(array_filter($rows, fn($r) => $r['role'] === 'T'));
        q('INSERT INTO fanta_picks (season_id, manager_id, player_id, cost, role, captain) VALUES (?, ?, ?, ?, ?, 0)',
            [$sid, $managerId, $playerId, $prices[$playerId], $starters < FANTA_STARTERS ? 'T' : 'P']);
        fanta_fix_roles($sid, $managerId);
        return null;
    });
}

/** Vende una figurina: si riprendono i crediti che è costata. */
function fanta_sell(array $season, int $managerId, int $playerId): ?string
{
    fanta_snapshot_due();
    $sid = (int) $season['id'];
    return bet_atomic(function () use ($sid, $managerId, $playerId) {
        fanta_lock($managerId);
        if (!q('DELETE FROM fanta_picks WHERE season_id = ? AND manager_id = ? AND player_id = ?', [$sid, $managerId, $playerId])->rowCount()) {
            return 'Non ce l\'hai in rosa.';
        }
        fanta_fix_roles($sid, $managerId);
        return null;
    });
}

/** Manda in panchina una figurina (quella che c'era prima entra titolare; se era il capitano, la fascia passa a chi entra). */
function fanta_set_bench(array $season, int $managerId, int $playerId): ?string
{
    fanta_snapshot_due();
    $sid = (int) $season['id'];
    return bet_atomic(function () use ($sid, $managerId, $playerId) {
        fanta_lock($managerId);
        $rows = fanta_roster($sid, $managerId);
        if (count($rows) < FANTA_ROSTER) {
            return 'La panchina c\'è quando hai ' . FANTA_ROSTER . ' figurine.';
        }
        $me = null;
        $old = null;
        foreach ($rows as $r) {
            if ((int) $r['player_id'] === $playerId) {
                $me = $r;
            }
            if ($r['role'] === 'P') {
                $old = $r;
            }
        }
        if (!$me) {
            return 'Non ce l\'hai in rosa.';
        }
        if ($me['role'] === 'P') {
            return null;
        }
        q("UPDATE fanta_picks SET role = 'P', captain = 0 WHERE season_id = ? AND manager_id = ? AND player_id = ?", [$sid, $managerId, $playerId]);
        if ($old) {
            q("UPDATE fanta_picks SET role = 'T', captain = ? WHERE season_id = ? AND manager_id = ? AND player_id = ?",
                [(int) $me['captain'], $sid, $managerId, $old['player_id']]);
        }
        fanta_fix_roles($sid, $managerId);
        return null;
    });
}

/** Dà la fascia di capitano a un titolare. */
function fanta_set_captain(array $season, int $managerId, int $playerId): ?string
{
    fanta_snapshot_due();
    $sid = (int) $season['id'];
    return bet_atomic(function () use ($sid, $managerId, $playerId) {
        fanta_lock($managerId);
        $r = q('SELECT role FROM fanta_picks WHERE season_id = ? AND manager_id = ? AND player_id = ?', [$sid, $managerId, $playerId])->fetch();
        if (!$r) {
            return 'Non ce l\'hai in rosa.';
        }
        if ($r['role'] !== 'T') {
            return 'Il capitano deve essere un titolare: prima fallo entrare dalla panchina.';
        }
        q('UPDATE fanta_picks SET captain = (player_id = ?) WHERE season_id = ? AND manager_id = ?', [$playerId, $sid, $managerId]);
        return null;
    });
}

/* ---------------------------------------------------------------- scambi */

/** Controlla che uno scambio si possa fare adesso: $from dà $give e riceve $want da $to. Ritorna l'errore, oppure null. */
function fanta_trade_check(int $sid, int $from, int $to, int $give, int $want): ?string
{
    if ($from === $to) {
        return 'Non puoi scambiare con te stesso.';
    }
    if ($give === $want) {
        return 'Si scambiano due figurine diverse.';
    }
    $has = fn(int $mgr, int $pid) => (bool) q('SELECT 1 FROM fanta_picks WHERE season_id = ? AND manager_id = ? AND player_id = ?', [$sid, $mgr, $pid])->fetch();
    if (!$has($from, $give)) {
        return 'La figurina che offri non è più nella rosa di chi la offre.';
    }
    if (!$has($to, $want)) {
        return 'La figurina richiesta non è più nella rosa dell\'altra squadra.';
    }
    if ($has($from, $want)) {
        return 'Chi propone ha già la figurina che chiede: si ritroverebbe due volte lo stesso giocatore.';
    }
    if ($has($to, $give)) {
        return 'L\'altra squadra ha già la figurina offerta: si ritroverebbe due volte lo stesso giocatore.';
    }
    return null;
}

/** Propone uno scambio. Ritorna l'errore, oppure null. */
function fanta_trade_propose(array $season, int $from, int $to, int $give, int $want): ?string
{
    $sid = (int) $season['id'];
    if (!isset(fanta_managers((int) $season['group_id'])[$to])) {
        return 'Questa squadra non è della tua lega.';
    }
    if ($err = fanta_trade_check($sid, $from, $to, $give, $want)) {
        return $err;
    }
    if (q("SELECT 1 FROM fanta_trades WHERE season_id = ? AND from_id = ? AND to_id = ? AND give_id = ? AND want_id = ? AND status = 'proposto'",
        [$sid, $from, $to, $give, $want])->fetch()) {
        return 'Hai già proposto questo scambio: aspetta la risposta.';
    }
    if ((int) q("SELECT COUNT(*) FROM fanta_trades WHERE season_id = ? AND from_id = ? AND status = 'proposto'", [$sid, $from])->fetchColumn() >= FANTA_MAX_OPEN_TRADES) {
        return 'Hai già ' . FANTA_MAX_OPEN_TRADES . ' proposte in attesa: ritirane una o aspetta le risposte.';
    }
    q("INSERT INTO fanta_trades (season_id, from_id, to_id, give_id, want_id, status) VALUES (?, ?, ?, ?, ?, 'proposto')", [$sid, $from, $to, $give, $want]);
    $n = fn(int $id) => (string) (get_player($id)['name'] ?? '?');
    fanta_notify([$to], ['title' => 'Fanta: proposta di scambio',
        'body' => $n($from) . ' ti offre ' . $n($give) . ' in cambio di ' . $n($want) . '.', 'url' => 'fanta.php?t=scambi', 'tag' => 'fanta-trade']);
    return null;
}

/** Risponde a uno scambio: 'accetta' / 'rifiuta' (chi lo riceve) o 'ritira' (chi l'ha proposto). */
function fanta_trade_answer(int $tradeId, int $me, string $do): ?string
{
    fanta_snapshot_due();
    $t = q("SELECT t.*, s.status AS season_status FROM fanta_trades t JOIN fanta_seasons s ON s.id = t.season_id WHERE t.id = ?", [$tradeId])->fetch();
    if (!$t || $t['status'] !== 'proposto') {
        return 'Questa proposta non c\'è più.';
    }
    if ($t['season_status'] !== 'aperta') {
        return 'La stagione è chiusa.';
    }
    $from = (int) $t['from_id'];
    $to = (int) $t['to_id'];
    if ($do === 'ritira') {
        if ($me !== $from) {
            return 'Non è una tua proposta.';
        }
        q("UPDATE fanta_trades SET status = 'ritirato', decided_at = ? WHERE id = ? AND status = 'proposto'", [date('Y-m-d H:i:s'), $tradeId]);
        return null;
    }
    if ($me !== $to) {
        return 'Non è una proposta per te.';
    }
    if ($do === 'rifiuta') {
        q("UPDATE fanta_trades SET status = 'rifiutato', decided_at = ? WHERE id = ? AND status = 'proposto'", [date('Y-m-d H:i:s'), $tradeId]);
        fanta_notify([$from], ['title' => 'Fanta: scambio rifiutato', 'body' => (get_player($to)['name'] ?? '?') . ' ha rifiutato il tuo scambio.',
            'url' => 'fanta.php?t=scambi', 'tag' => 'fanta-trade']);
        return null;
    }
    $sid = (int) $t['season_id'];
    $give = (int) $t['give_id'];
    $want = (int) $t['want_id'];
    $err = bet_atomic(function () use ($sid, $from, $to, $give, $want, $tradeId) {
        fanta_lock($from, $to);
        if ($err = fanta_trade_check($sid, $from, $to, $give, $want)) {
            q("UPDATE fanta_trades SET status = 'scaduto', decided_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $tradeId]);
            return $err;
        }
        // ognuno tiene il posto (titolare/panchina, capitano) e il costo della figurina che dà
        q('UPDATE fanta_picks SET player_id = ? WHERE season_id = ? AND manager_id = ? AND player_id = ?', [$want, $sid, $from, $give]);
        q('UPDATE fanta_picks SET player_id = ? WHERE season_id = ? AND manager_id = ? AND player_id = ?', [$give, $sid, $to, $want]);
        q("UPDATE fanta_trades SET status = 'accettato', decided_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $tradeId]);
        return null;
    });
    if ($err) {
        return 'Lo scambio non si può più fare: ' . lcfirst($err);
    }
    fanta_notify([$from], ['title' => 'Fanta: scambio accettato!', 'body' => (get_player($to)['name'] ?? '?') . ' ha accettato: '
        . (get_player($want)['name'] ?? '?') . ' è nella tua squadra.', 'url' => 'fanta.php', 'tag' => 'fanta-trade']);
    return null;
}

/** Proposte ancora in attesa che coinvolgono un fantallenatore (ricevute e fatte). */
function fanta_open_trades(int $seasonId, int $managerId): array
{
    return q("SELECT * FROM fanta_trades WHERE season_id = ? AND status = 'proposto' AND (from_id = ? OR to_id = ?) ORDER BY created_at DESC",
        [$seasonId, $managerId, $managerId])->fetchAll();
}

/* ---------------------------------------------------------------- varie */

function fanta_notify(array $playerIds, array $msg): void
{
    push_defer(function () use ($playerIds, $msg) {
        push_notify_users(push_users_of_players($playerIds), $msg, 'normal', 'fanta');
    });
}

/** "1 credito", "3 crediti". */
function fanta_credits(int $n): string
{
    return $n . ($n === 1 ? ' credito' : ' crediti');
}

/** Un punteggio come si scrive: "72,5" (o "72"). */
function fanta_fmt(float $n): string
{
    return rtrim(rtrim(fmt_num($n, 1), '0'), ',');
}

/** La lega del Fanta da mostrare: quella chiesta (?g=), se chi guarda la vede; sennò quella del filtro, sennò la prima. */
function fanta_group(?int $asked): ?int
{
    $ids = bar_group_ids();
    if ($asked && in_array($asked, $ids, true)) {
        return $asked;
    }
    $mine = my_player_id() ? player_group_ids((int) my_player_id()) : [];
    if (($f = group_filter()) && in_array($f, $ids, true)) {
        return $f;
    }
    foreach ($ids as $id) {
        if (in_array($id, $mine, true)) {
            return $id;
        }
    }
    return $ids[0] ?? null;
}
