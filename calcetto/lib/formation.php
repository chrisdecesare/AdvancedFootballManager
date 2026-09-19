<?php
/*
 * Moduli e disposizione in campo.
 *
 * Un modulo si scrive come in TV, portiere compreso: "1-2-1-1" = portiere,
 * 2 difensori, 1 centrocampista, 1 attaccante. Le linee dopo il portiere sono,
 * dalla difesa all'attacco: 1 linea = centrocampo; 2 = difesa e attacco;
 * 3 = difesa, centrocampo, attacco; 4+ = con più linee di centrocampo.
 *
 * L'admin può scegliere il modulo di ogni squadra; se non lo fa (o non torna con
 * il numero di giocatori) si usa quello standard. Ogni giocatore va nel ruolo che
 * preferisce: la disposizione è quella di costo minimo, calcolata esattamente.
 */

const ROLE_ORDER = ['POR', 'DIF', 'CEN', 'ATT'];
const ROLE_NAMES = ['POR' => 'Portiere', 'DIF' => 'Difensore', 'CEN' => 'Centrocampista', 'ATT' => 'Attaccante', 'JOL' => 'Jolly'];

/** Modulo standard per una squadra di $n giocatori. */
function default_formation(int $n): string
{
    $std = [1 => '0-1', 2 => '0-1-1', 3 => '1-1-1', 4 => '1-1-1-1', 5 => '1-1-2-1', 6 => '1-2-1-2',
        7 => '1-3-2-1', 8 => '1-3-3-1', 9 => '1-3-3-2', 10 => '1-4-3-2', 11 => '1-4-4-2'];
    if ($n <= 0) {
        return '';
    }
    return $std[$n] ?? '1-4-' . ($n - 7) . '-2';
}

/** "1-2-1-1" → [1, 2, 1, 1]; null se non è un modulo valido. */
function parse_formation(?string $f): ?array
{
    if (!$f || !preg_match('/^[01](-[1-9][0-9]?){1,5}$/', $f)) {
        return null;
    }
    return array_map('intval', explode('-', $f));
}

/** Giocatori richiesti dal modulo. */
function formation_size(string $f): int
{
    return array_sum(parse_formation($f) ?? []);
}

/** Modulo di una squadra in una partita: quello scelto se torna col numero di giocatori, altrimenti lo standard. */
function formation_for(array $match, string $team, int $n): string
{
    $chosen = $match['formation_' . strtolower($team)] ?? '';
    return ($chosen !== '' && parse_formation($chosen) && formation_size($chosen) === $n) ? $chosen : default_formation($n);
}

/** Tutti i moduli sensati per $n giocatori (portiere + da 2 a 4 linee, max 5 per linea). */
function formation_options(int $n): array
{
    if ($n < 3) {
        return [default_formation($n)];
    }
    $out = [];
    $outfield = $n - 1;
    $build = function (array $lines, int $left) use (&$build, &$out) {
        if ($left === 0) {
            if (count($lines) >= 2) {
                $out[] = '1-' . implode('-', $lines);
            }
            return;
        }
        if (count($lines) === 4) {
            return;
        }
        for ($k = 1; $k <= min(5, $left); $k++) {
            $build(array_merge($lines, [$k]), $left - $k);
        }
    };
    $build([], $outfield);
    // prima i moduli a 3 linee (i più comuni), poi 4 e 2; dentro ogni gruppo i più equilibrati
    $key = function (string $f): array {
        $lines = array_slice(explode('-', $f), 1);
        $avg = array_sum($lines) / count($lines);
        $spread = array_sum(array_map(fn($x) => abs($x - $avg), $lines));
        return [[3 => 0, 4 => 1, 2 => 2][count($lines)] ?? 3, $spread, $f];
    };
    usort($out, fn($a, $b) => $key($a) <=> $key($b));
    $def = default_formation($n);
    return array_values(array_unique(array_merge([$def], $out)));
}

/** Posti del modulo: [['role', 'x' 0-100, 'depth' 0 = propria porta … 1 = metà campo], ...] dal portiere all'attacco. */
function formation_layout(string $f): array
{
    $parts = parse_formation($f) ?? [];
    $gk = array_shift($parts);
    $k = count($parts);
    $slots = [];
    if ($gk) {
        $slots[] = ['role' => 'POR', 'x' => 50, 'depth' => 0.045];
    }
    foreach ($parts as $i => $cnt) {
        if ($k === 1) {
            $role = 'CEN';
        } elseif ($i === 0) {
            $role = 'DIF';
        } elseif ($i === $k - 1) {
            $role = 'ATT';
        } else {
            $role = 'CEN';
        }
        $depth = $k === 1 ? 0.55 : 0.34 + $i * (0.88 - 0.34) / ($k - 1);
        for ($j = 0; $j < $cnt; $j++) {
            $slots[] = ['role' => $role, 'x' => ($j + 1) / ($cnt + 1) * 100, 'depth' => $depth];
        }
    }
    return $slots;
}

/** Ruoli del modulo nell'ordine dei posti. */
function formation_roles(string $f): array
{
    return array_column(formation_layout($f), 'role');
}

/** Quanti posti per ruolo chiede il modulo. */
function formation_needs(string $f): array
{
    return array_count_values(formation_roles($f)) + ['POR' => 0, 'DIF' => 0, 'CEN' => 0, 'ATT' => 0];
}

/** Quanto "costa" mettere un giocatore con preferenze $prefs nel ruolo $slot (0 = ruolo preferito). */
function role_cost(array $prefs, string $slot): float
{
    [$p1, $p2] = $prefs + [null, null];
    if ($p1 === $slot) {
        return 0;
    }
    if ($p2 === $slot) {
        return 1;
    }
    if ($slot === 'POR') {
        return ($p1 === 'JOL' || $p2 === 'JOL') ? 3 : 5;
    }
    if ($p1 === 'JOL') {
        return 1;
    }
    if ($p2 === 'JOL') {
        return 1.5;
    }
    if ($p1 === 'POR') {
        return 4;
    }
    $dist = abs(array_search($p1, ROLE_ORDER, true) - array_search($slot, ROLE_ORDER, true));
    return 2 + 0.75 * $dist;
}

/**
 * Assegna i giocatori ai posti ($roles) con il costo totale minimo.
 * $players: lista di ['id' => int, 'prefs' => [..]]. Ritorna [player_id => indice del posto].
 */
function assign_slots(array $players, array $roles): array
{
    $players = array_values($players);
    $n = count($players);
    if ($n === 0 || count($roles) !== $n) {
        return [];
    }
    $cost = [];
    foreach ($players as $i => $p) {
        foreach ($roles as $j => $role) {
            $cost[$i][$j] = role_cost($p['prefs'], $role);
        }
    }

    if ($n > 14) {
        // squadre enormi: posto per posto, il giocatore più adatto tra quelli rimasti
        $free = range(0, $n - 1);
        $res = [];
        foreach ($roles as $j => $_) {
            usort($free, fn($a, $b) => $cost[$a][$j] <=> $cost[$b][$j]);
            $i = array_shift($free);
            $res[(int) $players[$i]['id']] = $j;
        }
        return $res;
    }

    // programmazione dinamica: dp[mask] = costo minimo mettendo i primi popcount(mask) giocatori nei posti di mask
    $full = (1 << $n) - 1;
    $dp = array_fill(0, $full + 1, INF);
    $from = array_fill(0, $full + 1, -1);
    $bits = array_fill(0, $full + 1, 0);
    for ($m = 1; $m <= $full; $m++) {
        $bits[$m] = $bits[$m >> 1] + ($m & 1);
    }
    $dp[0] = 0;
    for ($mask = 0; $mask < $full; $mask++) {
        if ($dp[$mask] === INF) {
            continue;
        }
        $i = $bits[$mask];
        for ($j = 0; $j < $n; $j++) {
            if ($mask >> $j & 1) {
                continue;
            }
            $nm = $mask | (1 << $j);
            $c = $dp[$mask] + $cost[$i][$j];
            if ($c < $dp[$nm] - 1e-9) {
                $dp[$nm] = $c;
                $from[$nm] = $j;
            }
        }
    }
    $res = [];
    $mask = $full;
    for ($i = $n - 1; $i >= 0; $i--) {
        $j = $from[$mask];
        $res[(int) $players[$i]['id']] = $j;
        $mask &= ~(1 << $j);
    }
    return $res;
}

/** Ricalcola e salva la disposizione in campo di entrambe le squadre di una partita. */
function assign_formation(int $match_id): void
{
    $match = get_match($match_id);
    $rows = q('SELECT mp.player_id, mp.team, p.position, p.position2
               FROM match_players mp JOIN players p ON p.id = mp.player_id
               WHERE mp.match_id = ? AND mp.team IS NOT NULL ORDER BY p.name', [$match_id])->fetchAll();
    q('UPDATE match_players SET slot = NULL WHERE match_id = ?', [$match_id]);
    foreach (['A', 'B'] as $t) {
        $pl = [];
        foreach ($rows as $r) {
            if ($r['team'] === $t) {
                $pl[] = ['id' => (int) $r['player_id'], 'prefs' => player_prefs($r)];
            }
        }
        $roles = formation_roles(formation_for($match, $t, count($pl)));
        foreach (assign_slots($pl, $roles) as $pid => $slot) {
            q('UPDATE match_players SET slot = ? WHERE match_id = ? AND player_id = ?', [$slot, $match_id, $pid]);
        }
    }
}

/** Posti salvati se validi, altrimenti calcolati al volo (senza salvarli). */
function team_slots(array $teamRows, array $roles): array
{
    $n = count($teamRows);
    $slots = [];
    foreach ($teamRows as $r) {
        if ($r['slot'] === null || (int) $r['slot'] >= $n || in_array((int) $r['slot'], $slots, true)) {
            $pl = array_map(fn($x) => ['id' => (int) $x['player_id'], 'prefs' => player_prefs($x)], $teamRows);
            return assign_slots($pl, $roles);
        }
        $slots[(int) $r['player_id']] = (int) $r['slot'];
    }
    return $slots;
}

/** Nomi brevi per le pedine: il nome, più l'iniziale del cognome se due giocatori si chiamano uguale. */
function short_names(array $rows): array
{
    $first = [];
    foreach ($rows as $r) {
        $parts = preg_split('/\s+/', trim($r['name']));
        $first[(int) $r['player_id']] = $parts;
    }
    $count = array_count_values(array_map(fn($p) => mb_strtolower($p[0]), $first));
    $out = [];
    foreach ($first as $pid => $parts) {
        $name = $parts[0];
        if ($count[mb_strtolower($parts[0])] > 1 && count($parts) > 1) {
            $name .= ' ' . mb_substr(end($parts), 0, 1) . '.';
        }
        $out[$pid] = $name;
    }
    return $out;
}

/**
 * Campo verde con le pedine. La squadra A difende in basso, la B in alto.
 * Se $editable, l'admin tocca due pedine per scambiarle e sceglie il modulo.
 */
function render_pitch(array $match, array $roster, bool $editable = false): string
{
    $teams = ['A' => [], 'B' => []];
    foreach ($roster as $r) {
        if ($r['team']) {
            $teams[$r['team']][] = $r;
        }
    }
    if (!$teams['A'] && !$teams['B']) {
        return '';
    }
    $short = short_names(array_merge($teams['A'], $teams['B']));

    $forms = [];
    $labels = [];
    foreach (['A', 'B'] as $t) {
        $n = count($teams[$t]);
        if (!$n) {
            continue;
        }
        $forms[$t] = formation_for($match, $t, $n);
        $label = '<div class="pitch-label team-' . strtolower($t) . '">' . h(team_name($t, $match));
        if ($editable && $n >= 3) {
            $chosen = $match['formation_' . strtolower($t)] ?? '';
            $label .= ' <form method="post" class="inline">' . csrf_field() .
                '<input type="hidden" name="do" value="set_formation"><input type="hidden" name="team" value="' . $t . '">' .
                '<select name="formation" class="mini-select form-select" data-autosubmit aria-label="Modulo ' . h(team_name($t, $match)) . '">' .
                '<option value="">Auto (' . default_formation($n) . ')</option>';
            foreach (formation_options($n) as $f) {
                $label .= '<option value="' . $f . '"' . ($chosen === $f && $forms[$t] === $f ? ' selected' : '') . '>' . $f . '</option>';
            }
            $label .= '</select></form>';
        } else {
            $label .= ' <small>' . $forms[$t] . '</small>';
        }
        $labels[$t] = $label . '</div>';
    }

    $h = '<div class="pitch-wrap">' . ($labels['B'] ?? '') . '<div class="pitch' . ($editable ? ' is-editable' : '') . '" data-pitch>';
    $h .= '<div class="pl pl-half"></div><div class="pl pl-circle"></div><div class="pl pl-spot"></div>'
        . '<div class="pl pl-box pl-box-top"></div><div class="pl pl-box pl-box-bottom"></div>'
        . '<div class="pl pl-goal pl-goal-top"></div><div class="pl pl-goal pl-goal-bottom"></div>';

    foreach ($forms as $t => $f) {
        $layout = formation_layout($f);
        $roles = array_column($layout, 'role');
        $slots = team_slots($teams[$t], $roles);
        foreach ($teams[$t] as $r) {
            $pid = (int) $r['player_id'];
            $c = $layout[$slots[$pid] ?? 0];
            // A in basso (porta a y=100%), B in alto e specchiata
            $top = $t === 'A' ? 94 - $c['depth'] * 42 : 6 + $c['depth'] * 42;
            $left = $t === 'A' ? $c['x'] : 100 - $c['x'];
            $role = $c['role'];
            [$p1, $p2] = player_prefs($r);
            $off = $role !== $p1 && $role !== $p2 && $p1 !== 'JOL' && $p2 !== 'JOL';
            $title = $r['name'] . ' · ' . ROLE_NAMES[$role] . ($off ? ' (fuori ruolo)' : '');
            $inner = avatar($r, 'token') .
                ($r['shirt_number'] !== null ? '<span class="token-num">' . (int) $r['shirt_number'] . '</span>' : '') .
                ($off ? '<span class="token-off" title="Fuori ruolo">!</span>' : '') .
                '<span class="token-name">' . h($short[$pid]) . '</span>';
            $style = 'left:' . round($left, 2) . '%;top:' . round($top, 2) . '%';
            if ($editable) {
                $h .= '<button type="button" class="token team-' . strtolower($t) . '" style="' . $style . '" data-swap="' . $pid .
                    '" title="' . h($title) . '">' . $inner . '</button>';
            } else {
                $h .= '<a class="token team-' . strtolower($t) . '" style="' . $style . '" href="player.php?id=' . $pid .
                    '" title="' . h($title) . '">' . $inner . '</a>';
            }
        }
    }
    $h .= '</div>' . ($labels['A'] ?? '');
    if ($editable) {
        $h .= '<form method="post" id="swap-form" hidden>' . csrf_field() .
            '<input type="hidden" name="do" value="swap_slots"><input type="hidden" name="p1"><input type="hidden" name="p2"></form>' .
            '<p class="pitch-hint"><i class="ti ti-hand-finger"></i> Scegli il modulo dal menu accanto al nome della squadra. Tocca due pedine per scambiarle di posto (anche tra squadre).</p>';
    }
    return $h . '</div>';
}
