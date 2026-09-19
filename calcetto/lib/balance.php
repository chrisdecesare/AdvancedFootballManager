<?php
/*
 * Bilanciamento automatico delle squadre.
 *
 * Costo di una divisione = |somma rating A − somma rating B|
 *   + 2   per ogni portiere di troppo in una squadra (chi ha "Portiere" tra le
 *         posizioni preferite va diviso tra le due squadre)
 *   + 0,4 per ogni posto del modulo (difesa, centrocampo, attacco) che la squadra
 *         non riesce a coprire con giocatori che lo preferiscono
 *
 * Fino a 22 giocatori prova TUTTE le combinazioni (la soluzione è quella ottima);
 * oltre usa una ricerca con scambi. Tra le soluzioni quasi ottime (entro 0,25 dal
 * migliore) ne sceglie una a caso, così "Rigenera" propone squadre diverse.
 */

/** $match serve per usare i moduli scelti dall'admin (se già impostati). */
function balance_teams(array $pool, int $variety = 6, array $match = []): array
{
    $pool = array_values($pool);
    $n = count($pool);
    if ($n < 2) {
        return balance_result($pool, range(0, $n - 1));
    }
    $r = array_map(fn($p) => (float) $p['rating'], $pool);
    // quanto ogni giocatore copre ciascun ruolo: 1ª scelta 1, 2ª scelta 0,6, jolly 0,5
    $cap = ['POR' => [], 'DIF' => [], 'CEN' => [], 'ATT' => []];
    foreach ($pool as $i => $p) {
        [$p1, $p2] = ($p['prefs'] ?? ['JOL', null]) + [null, null];
        foreach ($cap as $role => $_) {
            if ($role === 'POR') {
                $cap[$role][$i] = ($p1 === 'POR' || $p2 === 'POR') ? 1 : 0;
            } else {
                $cap[$role][$i] = $p1 === $role ? 1 : ($p2 === $role ? 0.6 : ($p1 === 'JOL' ? 0.5 : ($p2 === 'JOL' ? 0.3 : 0)));
            }
        }
    }
    $totR = array_sum($r);
    $totCap = array_map('array_sum', $cap);
    $kA = intdiv($n, 2);
    $needA = formation_needs(formation_for($match, 'A', $kA));
    $needB = formation_needs(formation_for($match, 'B', $n - $kA));
    $outfield = ['DIF', 'CEN', 'ATT'];

    $cost = function (array $idx) use ($r, $cap, $totR, $totCap, $needA, $needB, $outfield): float {
        $s = 0.0;
        $c = ['POR' => 0, 'DIF' => 0, 'CEN' => 0, 'ATT' => 0];
        foreach ($idx as $i) {
            $s += $r[$i];
            $c['POR'] += $cap['POR'][$i];
            $c['DIF'] += $cap['DIF'][$i];
            $c['CEN'] += $cap['CEN'][$i];
            $c['ATT'] += $cap['ATT'][$i];
        }
        $cost = abs($s - ($totR - $s));
        $cost += 2.0 * max(0, abs($c['POR'] - ($totCap['POR'] - $c['POR'])) - ($totCap['POR'] % 2));
        foreach ($outfield as $role) {
            $cost += 0.4 * max(0, $needA[$role] - $c[$role]);
            $cost += 0.4 * max(0, $needB[$role] - ($totCap[$role] - $c[$role]));
        }
        return $cost;
    };

    $tol = 0.25;
    $best = INF;
    $cands = [];
    $consider = function (array $idx) use (&$best, &$cands, $cost, $tol) {
        $c = $cost($idx);
        if ($c < $best) {
            $best = $c;
            $cands = array_values(array_filter($cands, fn($x) => $x[0] <= $best + $tol));
        }
        if ($c <= $best + $tol && count($cands) < 300) {
            $cands[] = [$c, $idx];
        }
    };

    if ($n <= 22) {
        // A squadre pari il giocatore 0 sta sempre in A: evita di contare due volte le divisioni speculari
        $fixFirst = ($n % 2 === 0);
        $items = $fixFirst ? range(1, $n - 1) : range(0, $n - 1);
        $k = $fixFirst ? $kA - 1 : $kA;
        foreach (combinations($items, $k) as $combo) {
            $consider($fixFirst ? array_merge([0], $combo) : $combo);
        }
    } else {
        for ($restart = 0; $restart < 40; $restart++) {
            $order = range(0, $n - 1);
            shuffle($order);
            $A = array_slice($order, 0, $kA);
            $B = array_slice($order, $kA);
            $cur = $cost($A);
            do {
                $improved = false;
                foreach ($A as $ia => $x) {
                    foreach ($B as $ib => $y) {
                        $tryA = $A;
                        $tryA[$ia] = $y;
                        $c = $cost($tryA);
                        if ($c + 1e-9 < $cur) {
                            $A = $tryA;
                            $B[$ib] = $x;
                            $cur = $c;
                            $improved = true;
                        }
                    }
                }
            } while ($improved);
            $consider($A);
        }
    }

    usort($cands, fn($a, $b) => $a[0] <=> $b[0]);
    $pick = $cands[random_int(0, min($variety, count($cands)) - 1)][1];
    return balance_result($pool, $pick);
}

/** Genera tutte le combinazioni di $k elementi di $items (iterativo, senza ricorsione). */
function combinations(array $items, int $k): Generator
{
    $n = count($items);
    if ($k === 0) {
        yield [];
        return;
    }
    if ($k > $n) {
        return;
    }
    $idx = range(0, $k - 1);
    while (true) {
        yield array_map(fn($i) => $items[$i], $idx);
        $i = $k - 1;
        while ($i >= 0 && $idx[$i] === $n - $k + $i) {
            $i--;
        }
        if ($i < 0) {
            return;
        }
        $idx[$i]++;
        for ($j = $i + 1; $j < $k; $j++) {
            $idx[$j] = $idx[$j - 1] + 1;
        }
    }
}

function balance_result(array $pool, array $idxA): array
{
    $inA = array_flip($idxA);
    $A = $B = [];
    $sA = $sB = 0.0;
    foreach ($pool as $i => $p) {
        if (isset($inA[$i])) {
            $A[] = (int) $p['id'];
            $sA += (float) $p['rating'];
        } else {
            $B[] = (int) $p['id'];
            $sB += (float) $p['rating'];
        }
    }
    return ['A' => $A, 'B' => $B, 'sumA' => $sA, 'sumB' => $sB];
}

/** Somma e media dei rating di una squadra salvata. */
function team_strength(array $roster, string $team, array $stats): array
{
    $sum = 0.0;
    $n = 0;
    foreach ($roster as $r) {
        if ($r['team'] === $team) {
            $sum += $stats[(int) $r['player_id']]['ovr'] ?? 6;
            $n++;
        }
    }
    return ['sum' => $sum, 'n' => $n, 'avg' => $n ? $sum / $n : 0];
}
