<?php
/*
 * Intesa tra giocatori ("chimica" come nei vecchi FIFA), ricavata dalle partite già giocate INSIEME
 * (nella stessa squadra) nell'ambito dei gruppi indicato.
 *
 * Per ogni coppia (con almeno CHEM_MIN_TOGETHER partite insieme) si valuta:
 *   - RENDIMENTO: punti fatti dalla squadra quando giocano insieme (V=1, N=0,5, S=0) contro la media dei due;
 *   - AFFINITÀ: assist da uno all'altro (registrati dall'admin nel tabellino, in una o nell'altra direzione);
 *   - GOL: quanti gol a partita fanno quando sono insieme rispetto a quando non lo sono.
 * Il risultato è un bonus (o malus) in "punti rating" da aggiungere alla forza della squadra in cui giocano
 * insieme. È prudente: pesa n/(n+CHEM_SHRINK), quindi con poche partite conta poco.
 */

const CHEM_MIN_TOGETHER = 2;     // partite insieme prima che la coppia conti
const CHEM_SHRINK = 4;           // prudenza: con n partite il peso è n / (n + 4)
const CHEM_TEAM_CAP = 2.5;       // limite al bonus/malus totale di una squadra

function chem_key(int $a, int $b): string
{
    return $a < $b ? "$a-$b" : "$b-$a";
}

function chem_clamp(float $x, float $lo, float $hi): float
{
    return max($lo, min($hi, $x));
}

/**
 * Intesa di tutte le coppie che hanno giocato insieme.
 * @return array{pairs: array<string, array>, players: array<int, array>}
 */
function chemistry($scope = 'current'): array
{
    static $cache = [];
    $sc = $scope === 'current' ? scope_ids() : $scope;
    $ck = scope_key($sc);
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    $where = scope_sql('m.group_id', $sc, true);
    $matches = [];
    foreach (q("SELECT * FROM matches m WHERE m.status = 'giocata' AND m.score_a IS NOT NULL AND m.score_b IS NOT NULL AND $where")->fetchAll() as $m) {
        $matches[(int) $m['id']] = $m;
    }
    $teams = [];      // [match][team][] = [player, goals]
    $teamOf = [];     // [match][player] = squadra
    $players = [];    // [player] => apps, pts, goals
    foreach (q("SELECT mp.match_id, mp.player_id, mp.team, mp.goals FROM match_players mp
                JOIN matches m ON m.id = mp.match_id JOIN players gp ON gp.id = mp.player_id AND gp.is_guest = 0
                WHERE m.status = 'giocata' AND mp.team IS NOT NULL AND $where")->fetchAll() as $r) {
        $mid = (int) $r['match_id'];
        if (!isset($matches[$mid])) {
            continue;
        }
        $pid = (int) $r['player_id'];
        $pts = ['V' => 1.0, 'N' => 0.5, 'S' => 0.0][result_for($matches[$mid], $r['team'])];
        $teams[$mid][$r['team']][] = [$pid, (int) $r['goals'], $pts];
        $teamOf[$mid][$pid] = $r['team'];
        $players[$pid] ??= ['apps' => 0, 'pts' => 0.0, 'goals' => 0];
        $players[$pid]['apps']++;
        $players[$pid]['pts'] += $pts;
        $players[$pid]['goals'] += (int) $r['goals'];
    }
    $pairs = [];
    foreach ($teams as $mid => $byTeam) {
        foreach ($byTeam as $members) {
            $n = count($members);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    [$pa, $ga, $pts] = $members[$i];
                    [$pb, $gb] = $members[$j];
                    if ($pa > $pb) {
                        [$pa, $ga, $pb, $gb] = [$pb, $gb, $pa, $ga];
                    }
                    $k = "$pa-$pb";
                    $pairs[$k] ??= ['a' => $pa, 'b' => $pb, 'n' => 0, 'w' => 0, 'd' => 0, 'l' => 0, 'pts' => 0.0,
                        'ga' => 0, 'gb' => 0, 'ab' => 0, 'ba' => 0];
                    $pairs[$k]['n']++;
                    $pairs[$k]['pts'] += $pts;
                    $pairs[$k][$pts == 1.0 ? 'w' : ($pts == 0.5 ? 'd' : 'l')]++;
                    $pairs[$k]['ga'] += $ga;
                    $pairs[$k]['gb'] += $gb;
                }
            }
        }
    }
    // assist da uno all'altro (registrati nel tabellino): contano solo se in QUELLA partita erano compagni di squadra
    foreach (q("SELECT ml.* FROM match_links ml JOIN matches m ON m.id = ml.match_id WHERE m.status = 'giocata' AND $where")->fetchAll() as $r) {
        $x = (int) $r['assister_id'];
        $y = (int) $r['scorer_id'];
        $k = chem_key($x, $y);
        $mid = (int) $r['match_id'];
        if (!isset($pairs[$k]) || !isset($teamOf[$mid][$x], $teamOf[$mid][$y]) || $teamOf[$mid][$x] !== $teamOf[$mid][$y]) {
            continue;
        }
        $pairs[$k][$x < $y ? 'ab' : 'ba'] += (int) $r['n'];
    }
    foreach ($pairs as $k => $p) {
        $pairs[$k] += chem_score($p, $players);
    }
    return $cache[$ck] = ['pairs' => $pairs, 'players' => $players];
}

/** Punteggio di una coppia + i dati per mostrarlo. */
function chem_score(array $p, array $players): array
{
    $n = $p['n'];
    $out = ['score' => 0.0, 'ppg' => $p['pts'] / max(1, $n), 'base' => null, 'gpg_a' => null, 'gpg_b' => null,
        'gpg_a_without' => null, 'gpg_b_without' => null, 'links' => $p['ab'] + $p['ba']];
    $pa = $players[$p['a']] ?? null;
    $pb = $players[$p['b']] ?? null;
    if (!$pa || !$pb) {
        return $out;
    }
    $out['base'] = ($pa['pts'] / max(1, $pa['apps']) + $pb['pts'] / max(1, $pb['apps'])) / 2;
    $out['gpg_a'] = $p['ga'] / $n;
    $out['gpg_b'] = $p['gb'] / $n;
    $wa = $pa['apps'] - $n;
    $wb = $pb['apps'] - $n;
    $out['gpg_a_without'] = $wa >= 1 ? ($pa['goals'] - $p['ga']) / $wa : null;
    $out['gpg_b_without'] = $wb >= 1 ? ($pb['goals'] - $p['gb']) / $wb : null;
    if ($n < CHEM_MIN_TOGETHER) {
        return $out;
    }
    $k = $n / ($n + CHEM_SHRINK);
    $win = 0.5 * ($out['ppg'] - $out['base']) * $k;
    $link = (min(0.3, 0.2 * ($out['links'] / $n)) + (($p['ab'] > 0 && $p['ba'] > 0) ? 0.05 : 0.0)) * ($out['links'] > 0 ? $k : 0);
    $goal = 0.0;
    if ($out['gpg_a_without'] !== null && $out['gpg_b_without'] !== null) {
        $uplift = (($out['gpg_a'] - $out['gpg_a_without']) + ($out['gpg_b'] - $out['gpg_b_without'])) / 2;
        $goal = chem_clamp(0.25 * $uplift * $k, -0.2, 0.2);
    }
    $out['score'] = round(chem_clamp($win + $link + $goal, -0.35, 0.8), 2);
    $out['parts'] = ['win' => round($win, 2), 'link' => round($link, 2), 'goal' => round($goal, 2)];
    return $out;
}

/** I compagni con cui un giocatore ha più intesa (dal migliore), con il "lato" del giocatore già orientato. */
function chemistry_partners(array $chem, int $pid, int $limit = 6): array
{
    $out = [];
    foreach ($chem['pairs'] as $p) {
        if ($p['a'] !== $pid && $p['b'] !== $pid) {
            continue;
        }
        $me = $p['a'] === $pid;
        $out[] = $p + [
            'partner' => $me ? $p['b'] : $p['a'],
            'my_gpg' => $me ? $p['gpg_a'] : $p['gpg_b'],
            'my_gpg_without' => $me ? $p['gpg_a_without'] : $p['gpg_b_without'],
            'their_gpg' => $me ? $p['gpg_b'] : $p['gpg_a'],
            'their_gpg_without' => $me ? $p['gpg_b_without'] : $p['gpg_a_without'],
            'assists_to' => $me ? $p['ab'] : $p['ba'],        // assist di $pid verso il compagno
            'assists_from' => $me ? $p['ba'] : $p['ab'],      // assist del compagno verso $pid
        ];
    }
    usort($out, fn($x, $y) => [$y['score'], $y['n']] <=> [$x['score'], $x['n']]);
    return array_slice($out, 0, $limit);
}

/** Coppie [idA, idB, punteggio] tra i giocatori indicati, senza quelle a zero. Per il bilanciamento. */
function chemistry_pairs_for(array $chem, array $playerIds): array
{
    $set = array_flip(array_map('intval', $playerIds));
    $out = [];
    foreach ($chem['pairs'] as $p) {
        if ($p['score'] != 0.0 && isset($set[$p['a']], $set[$p['b']])) {
            $out[] = [$p['a'], $p['b'], (float) $p['score']];
        }
    }
    return $out;
}

/**
 * Intesa complessiva di una squadra (con il limite CHEM_TEAM_CAP) e le coppie più affiatate.
 * @return array{sum: float, raw: float, pairs: array}
 */
function team_chemistry(array $chem, array $memberIds): array
{
    $ids = array_map('intval', $memberIds);
    $raw = 0.0;
    $pairs = [];
    foreach ($chem['pairs'] as $p) {
        if ($p['score'] != 0.0 && in_array($p['a'], $ids, true) && in_array($p['b'], $ids, true)) {
            $raw += $p['score'];
            $pairs[] = $p;
        }
    }
    usort($pairs, fn($x, $y) => $y['score'] <=> $x['score']);
    return ['sum' => round(chem_clamp($raw, -CHEM_TEAM_CAP, CHEM_TEAM_CAP), 2), 'raw' => round($raw, 2), 'pairs' => $pairs];
}
