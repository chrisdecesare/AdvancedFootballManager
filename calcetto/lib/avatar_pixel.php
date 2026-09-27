<?php
/*
 * Personaggio in pixel art 16-bit: motore, fotogrammi, pose ed esultanze.
 *
 * Ogni fotogramma è una griglia di lettere, una per pixel; ogni lettera è una "zona" che prende il colore dal look del giocatore:
 *   o contorno   s pelle   h capelli   j maglia   a maniche   k finiture (colletto, polsini, calzettoni)   p pantaloncini
 *   c calzettoni   f scarpe   w bianco degli occhi   e pupilla/nero   m bocca   M interno della bocca   t lingua   n numero
 *   x y z   i tre colori del copricapo   u i   i due colori del pet   1 barba di tre giorni
 *   g oro   l bianco   r rosso   b legno   v metallo   d q polvere   3 lente blu   4 rosa
 * Una maiuscola è la stessa zona in ombra; le altre ombre e le luci si aggiungono da sole (la luce arriva da sinistra, in alto).
 * "." non disegna niente, "_" cancella quello che c'era sotto.
 *
 * Coordinate dello sprite: x da -8 a 39, y da -8 a 55; il corpo sta tra x 0 e 31 (centro a 15,5) e i piedi toccano terra alla riga 55.
 * Le parti disegnate a mano sono in lib/avatar_pixel_art.php, quelle con le diagonali (braccia, gambe, corpi di profilo) in
 * lib/avatar_pixel_parts.php, generato da tools/pixel/gen_parts.php.
 */

const PX_OX = 8;
const PX_OY = 8;
const PX_W = 48;
const PX_H = 64;
const PX_INK = '#1f1a2e';

/** Colore esadecimale -> [r, g, b]. */
function px_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** Mescola due colori (t = 0 il primo, 1 il secondo). */
function px_mix(string $a, string $b, float $t): string
{
    [$r1, $g1, $b1] = px_rgb($a);
    [$r2, $g2, $b2] = px_rgb($b);
    return sprintf('#%02x%02x%02x', round($r1 + ($r2 - $r1) * $t), round($g1 + ($g2 - $g1) * $t), round($b1 + ($b2 - $b1) * $t));
}

function px_luma(string $hex): float
{
    [$r, $g, $b] = px_rgb($hex);
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
}

/** I tre toni di una zona: [luce, base, ombra]. L'ombra tende al viola del contorno, come nelle palette dei 16 bit (uguale in JS). */
function px_tones(string $base): array
{
    $l = px_luma($base);
    $shade = px_mix($base, '#2a1f4a', $l > .85 ? .22 : .32);
    $light = px_mix($base, '#fff8e6', $l > .85 ? 0 : ($l < .2 ? .28 : .22));
    return [$light, $base, $shade];
}

/** Espande una mappa: con $mirror le righe sono la metà sinistra, specchiata a destra. */
function px_rows(array $rows, bool $mirror, int $half = 0): array
{
    if (!$mirror) {
        return $rows;
    }
    $half = $half ?: max(array_map('strlen', $rows));
    return array_map(function (string $r) use ($half) {
        $r = str_pad($r, $half, '.');
        return $r . strrev($r);
    }, $rows);
}

/* ---------------------------------------------------------------- corpo di fronte */

/** Busto di fronte senza braccia (metà sinistra, dalla riga 23 alla 41). */
function px_front_torso(): array
{
    return [
        '............ooss',
        '........ooooookk',
        '........oajjjkss',
        '.........ojjjjkk',
        '.........ojjjjjj',
        '.........ojjjjjj',
        '.........ojjjjjj',
        '.........ojjjjjj',
        '.........ojjjjjj',
        '.........ojjjjjj',
        '.........oJjjjjj',
        '.........oJjjjjj',
        '.........oJJjjjj',
        '.........opppppp',
        '.........opppppp',
        '.........opppppp',
        '.........opppppp',
        '.........opppppo',
        '.........ooooooo',
    ];
}

/** Gamba sinistra in piedi (dalla riga 42 al terreno). */
function px_front_leg(): array
{
    return [
        '..........ossso',
        '..........oSsso',
        '..........okkko',
        '..........occco',
        '..........occco',
        '..........occco',
        '..........occco',
        '..........occco',
        '..........occco',
        '..........occco',
        '.........offffo',
        '........offfffo',
        '........offfffo',
        '.........oooooo',
    ];
}

/**
 * Un braccio sinistro di fronte: [x, y, righe, davanti alla testa]. "down" e "up" sono disegnati a mano, gli altri generati.
 */
function px_arm(string $name): array
{
    static $hand = null;
    $hand ??= [
        'down' => [0, 25, ['......ooaa', '.....oaaaa', '....oaaaao', '....oaaaao', '....oaaaao', '....okkkko', '....ossss', '....ossss',
            '....ossss', '....ossss', '....ossss', '....ossss', '....ossss', '.....oooo'], false],
        'up' => [0, 5, ['.ooo', 'osssoo', 'ossssso', 'osssso', '.ossso', '.ossso', '.ossso', '..ossso', '..ossso', '..ossso', '...ossso',
            '...ossso', '...okkkko', '....oaaao', '....oaaaao', '.....oaaaao', '.....oaaaaoo', '......oaaaaoo', '.......oaaaao',
            '........oaaao', '........oaaaj', '.........oaj'], true],
    ];
    if (isset($hand[$name])) {
        return $hand[$name];
    }
    $p = px_parts()['arm_' . $name];
    $over = in_array($name, ['sky', 'wave_b', 'ear', 'ear_b', 'mouth', 'salute', 'selfie'], true);
    return [$p[0], $p[1], $p[2], $over];
}

/** Specchia un livello rispetto al centro del corpo (x 15,5). */
function px_flip_layer(int $x, array $rows): array
{
    $w = max(array_map('strlen', $rows));
    return [32 - $x - $w, array_map(fn($r) => strrev(str_pad($r, $w, '.')), $rows)];
}

/** Livello di una gamba sinistra di fronte (variante): [x, y, righe]. */
function px_leg(string $v): array
{
    if ($v === 'stand') {
        return [0, 42, px_front_leg()];
    }
    return px_parts()['leg_' . $v];
}

/**
 * Fotogramma di fronte: braccio sinistro e destro (quello destro è la variante specchiata), gambe, espressione.
 * Gambe: stand, wide, jump, spread, crouch, kneel, lotus, step (alza la sinistra), step_r (alza la destra).
 */
function px_front(string $l, string $r, string $legs = 'stand', string $face = '', int $breath = 0): array
{
    $drop = ['kneel' => 6, 'lotus' => 10, 'crouch' => 5, 'wide' => 1][$legs] ?? 0;
    [$lv, $rv] = match ($legs) {
        'step' => ['step', 'stand'],
        'step_r' => ['stand', 'step'],
        default => [$legs, $legs],
    };
    $layers = [];
    [$x, $y, $rows] = px_leg($lv);
    $layers[] = [$rows, $x, $y, false];
    [$x, $y, $rows] = px_leg($rv);
    [$x, $rows] = px_flip_layer($x, $rows);
    $layers[] = [$rows, $x, $y, false];
    $dy = $drop + $breath;
    $layers[] = [px_rows(px_front_torso(), true), 0, 23 + $dy, false];
    [$x, $y, $rows, $over] = px_arm($l);
    $layers[] = [$rows, $x, $y + $dy, $over];
    [$x, $y, $rows, $over] = px_arm($r);
    [$x, $rows] = px_flip_layer($x, $rows);
    $layers[] = [$rows, $x, $y + $dy, $over];
    return ['view' => 'front', 'layers' => $layers, 'head' => [8, 10 + $dy], 'num' => [16, 28 + $dy], 'c' => [16, 30], 'face' => $face];
}

/** Fotogramma di profilo (verso destra) da una parte generata "side_<nome>". */
function px_side(string $name, array $head, string $face = '', array $c = [17, 38]): array
{
    $p = px_parts();
    $layers = [[$p['side_' . $name][2], $p['side_' . $name][0], $p['side_' . $name][1], false]];
    if (isset($p['side_' . $name . '.over'])) {
        $o = $p['side_' . $name . '.over'];
        $layers[] = [$o[2], $o[0], $o[1], true];
    }
    return ['view' => 'side', 'layers' => $layers, 'head' => $head, 'c' => $c, 'face' => $face];
}

/**
 * Tutti i fotogrammi. Un nome può avere dei suffissi: "<" = girato verso sinistra, "@45" = ruotato di 45° in senso orario
 * attorno al suo centro (i giri a scatti di 90° li fa l'SVG, che non sposta i pixel dalla griglia).
 */
function px_frames(): array
{
    static $f = null;
    if ($f !== null) {
        return $f;
    }
    $f = [
        // pose da fermo
        'rest' => px_front('down', 'down'),
        'rest_b' => px_front('down', 'down', 'stand', '', 1),
        'open' => px_front('out', 'out', 'stand', 'smile'),
        'wave_a' => px_front('down', 'wave_a', 'stand', 'smile'),
        'wave_b' => px_front('down', 'wave_b', 'stand', 'smile'),
        'point' => px_front('down', 'point'),
        'victory' => px_front('flex', 'flex', 'stand', 'smile'),
        'up' => px_front('up', 'up', 'stand', 'smile'),
        // esultanze di fronte
        'up_shout' => px_front('up', 'up', 'stand', 'shout'),
        'pump_up' => px_front('down', 'flex', 'wide', 'shout'),
        'pump_down' => px_front('down', 'fist_low', 'wide', 'shout'),
        'pump_sky' => px_front('down', 'up', 'wide', 'shout'),
        'crouch_f' => px_front('out_low', 'out_low', 'crouch'),
        'jump_up' => px_front('up', 'up', 'jump', 'shout'),
        'kiss_grab' => px_front('chest', 'chest'),
        'kiss' => px_front('mouth', 'mouth', 'stand', 'kiss'),
        'salute' => px_front('down', 'salute'),
        'sky' => px_front('sky', 'sky', 'stand', 'closed'),
        'sky_b' => px_front('sky', 'sky', 'stand', 'closed', 1),
        'dance_a' => px_front('flex', 'out', 'step', 'smile'),
        'tongue_a' => px_front('flex', 'out_low', 'step', 'tongue'),
        'tongue_hold' => px_front('out', 'out', 'wide', 'tongue'),
        'plane_a' => px_front('out', 'out', 'step', 'smile'),
        'plane_b' => px_front('out', 'out', 'step_r', 'smile'),
        'cradle' => px_front('cradle', 'cradle', 'stand', 'smile'),
        'ear_a' => px_front('down', 'ear', 'wide', 'shout'),
        'ear_b' => px_front('down', 'ear_b', 'wide', 'shout'),
        'thumb' => px_front('hip', 'mouth', 'stand', 'closed'),
        'robot_a' => px_front('robot_a', 'flex'),
        'robot_b' => px_front('flex', 'robot_a'),
        'robot_c' => px_front('out', 'robot_a', 'wide'),
        'crossed' => px_front('cross', 'cross'),
        'heart' => px_front('heart', 'heart', 'stand', 'smile'),
        'why' => px_front('out_low', 'out_low', 'wide'),
        'milla_a' => px_front('hip', 'wave_a', 'step', 'smile'),
        'milla_b' => px_front('hip', 'wave_b', 'step_r', 'smile'),
        'selfie' => px_front('selfie', 'flex', 'stand', 'smile'),
        'tard_a' => px_front('flex', 'fist_low', 'step', 'shout'),
        'zen' => px_front('out_low', 'out_low', 'lotus', 'closed'),
        'star' => px_front('up', 'up', 'spread', 'smile'),
        'siu_air' => px_front('out_low', 'out_low', 'jump', 'shout'),
        'siu' => px_front('out_low', 'out_low', 'wide', 'shout'),
        // di profilo
        's_stand' => px_side('stand', [9, 12]),
        's_swing' => px_side('swing', [10, 14]),
        's_crouch' => px_side('crouch', [13, 24]),
        's_land' => px_side('land', [13, 24]),
        's_stretch' => px_side('stretch', [9, 12]),
        's_arch' => px_side('arch', [7, 11]),
        's_tuck' => px_side('tuck', [10, 24]),
        's_open' => px_side('open', [11, 16]),
        's_win' => px_side('win', [9, 12], 'shout'),
        's_run1' => px_side('run1', [10, 15]),
        's_run2' => px_side('run2', [10, 15]),
        's_run1s' => px_side('run1', [10, 15], 'shout'),
        's_run2s' => px_side('run2', [10, 15], 'shout'),
        's_kneel' => px_side('kneel', [9, 21], 'shout'),
        's_dive' => px_side('dive', [18, 42], 'shout', [17, 46]),
        's_gun' => px_side('gun', [9, 12], 'shout'),
        's_archer' => px_side('archer', [9, 12]),
    ];
    $f['star']['c'] = [16, 30];
    return $f;
}

/* ---------------------------------------------------------------- composizione */

/** Disegna le righe sulla griglia a partire da (x, y) in coordinate dello sprite. */
function px_paint(array &$g, array $rows, int $x, int $y): void
{
    foreach ($rows as $dy => $row) {
        $gy = $y + $dy + PX_OY;
        if ($gy < 0 || $gy >= PX_H) {
            continue;
        }
        $len = strlen($row);
        for ($dx = 0; $dx < $len; $dx++) {
            $ch = $row[$dx];
            $gx = $x + $dx + PX_OX;
            if ($ch === '.' || $gx < 0 || $gx >= PX_W) {
                continue;
            }
            $g[$gy][$gx] = $ch === '_' ? '.' : $ch;
        }
    }
}

function px_blank(): array
{
    return array_fill(0, PX_H, array_fill(0, PX_W, '.'));
}

/**
 * Griglia di lettere di un fotogramma col look ($parts: hair, beard, glasses, hat (modello pixel), number). Con cache per richiesta.
 */
function px_letters(string $name, array $parts): array
{
    static $cache = [];
    $ck = $name . '|' . implode('|', array_map(fn($v) => (string) $v, $parts));
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    if (preg_match('/^(.+)@(-?\d+)$/', $name, $m)) {   // ruotato
        $base = $m[1];
        $c = px_frames()[rtrim($base, '<')]['c'] ?? [16, 30];
        return $cache[$ck] = px_rotate(px_letters($base, $parts), $c, (int) $m[2]);
    }
    if (str_ends_with($name, '<')) {   // girato verso sinistra (il numero si rimette dopo, se no si leggerebbe al contrario)
        $base = substr($name, 0, -1);
        $g = array_map('array_reverse', px_letters($base, ['number' => ''] + $parts));
        $num = px_frames()[$base]['num'] ?? null;
        if ($num && $parts['number'] !== '') {
            px_number($g, (string) $parts['number'], 31 - $num[0] + 1, $num[1]);
        }
        return $cache[$ck] = $g;
    }
    $f = px_frames()[$name];
    $view = $f['view'];
    $g = px_blank();
    [$hx, $hy] = $f['head'];
    $piece = function (?array $def) use (&$g, $view, $hx, $hy) {
        $d = $def[$view] ?? ($def['front'] ?? null);
        if ($d) {
            px_paint($g, $d[2], $hx + $d[0], $hy + $d[1]);
        }
    };
    $style = $parts['hat'] ? px_hair_under_hat($parts['hair']) : $parts['hair'];
    $over = [];
    foreach ($f['layers'] as $l) {
        if (!empty($l[3])) {
            $over[] = $l;
            continue;
        }
        px_paint($g, $l[0], $l[1], $l[2]);
    }
    $head = px_heads()[$view];
    foreach ((px_faces()[$f['face'] ?? ''] ?? [])[$view] ?? [] as [$r, $col, $px]) {
        $head[$r] = substr_replace($head[$r], $px, $col, strlen($px));
    }
    px_paint($g, $head, $hx, $hy);
    $piece(px_beards()[$parts['beard']] ?? null);
    $piece(px_hair()[$style] ?? null);
    $piece(px_glasses()[$parts['glasses']] ?? null);
    if ($parts['hat']) {
        $piece(px_hats()[$parts['hat']] ?? null);
    }
    foreach ($over as $l) {
        px_paint($g, $l[0], $l[1], $l[2]);
    }
    if (isset($f['num']) && $parts['number'] !== '') {
        px_number($g, (string) $parts['number'], $f['num'][0], $f['num'][1]);
    }
    return $cache[$ck] = $g;
}

/**
 * Griglia ruotata di $deg gradi in senso orario attorno a $c (pixel più vicino): serve ai fotogrammi intermedi dei giri, che restano
 * a schermo pochi centesimi di secondo, e alle piccole inclinazioni (l'aeroplanino).
 */
function px_rotate(array $g, array $c, int $deg): array
{
    $out = px_blank();
    $a = deg2rad($deg);
    $cos = cos($a);
    $sin = sin($a);
    $cx = $c[0] + PX_OX;
    $cy = $c[1] + PX_OY;
    for ($y = 0; $y < PX_H; $y++) {
        for ($x = 0; $x < PX_W; $x++) {
            $px = $x + .5 - $cx;
            $py = $y + .5 - $cy;
            $sx = (int) floor($cx + $px * $cos + $py * $sin);
            $sy = (int) floor($cy - $px * $sin + $py * $cos);
            if ($sx >= 0 && $sy >= 0 && $sx < PX_W && $sy < PX_H) {
                $out[$y][$x] = $g[$sy][$sx];
            }
        }
    }
    return $out;
}

/** Numeri 3×5. */
function px_digits(): array
{
    return [
        '0' => ['###', '#.#', '#.#', '#.#', '###'], '1' => ['.#.', '##.', '.#.', '.#.', '###'],
        '2' => ['###', '..#', '###', '#..', '###'], '3' => ['###', '..#', '.##', '..#', '###'],
        '4' => ['#.#', '#.#', '###', '..#', '..#'], '5' => ['###', '#..', '###', '..#', '###'],
        '6' => ['###', '#..', '###', '#.#', '###'], '7' => ['###', '..#', '.#.', '.#.', '.#.'],
        '8' => ['###', '#.#', '###', '#.#', '###'], '9' => ['###', '#.#', '###', '..#', '###'],
    ];
}

function px_number(array &$g, string $num, int $cx, int $y): void
{
    $num = substr(preg_replace('/\D/', '', $num), 0, 2);
    if ($num === '') {
        return;
    }
    $w = strlen($num) * 4 - 1;
    $x = $cx - intdiv($w + 1, 2);
    foreach (str_split($num) as $i => $d) {
        foreach (px_digits()[$d] as $dy => $row) {
            for ($dx = 0; $dx < 3; $dx++) {
                if ($row[$dx] === '#') {
                    $g[$y + $dy + PX_OY][$x + $i * 4 + $dx + PX_OX] = 'n';
                }
            }
        }
    }
}

/** Zone con tre toni (ombra e luce automatiche). */
const PX_ZONES = 'shjakpcfxyzuibr';

/**
 * Da lettere a colori. Ombra lungo il bordo destro e in basso, luce lungo il bordo sinistro in alto.
 * Le maglie a motivo cambiano colore in base alla posizione (strisce, metà, fascia).
 */
function px_colorize(array $g, array $pal, string $pattern = 'solid'): array
{
    $out = [];
    $edge = fn(int $x, int $y) => $x < 0 || $y < 0 || $x >= PX_W || $y >= PX_H || $g[$y][$x] === '.' || $g[$y][$x] === 'o';
    for ($y = 0; $y < PX_H; $y++) {
        for ($x = 0; $x < PX_W; $x++) {
            $ch = $g[$y][$x];
            if ($ch === '.') {
                $out[$y][$x] = null;
                continue;
            }
            $lower = strtolower($ch);
            $zone = str_contains(PX_ZONES, $lower);
            if (!$zone) {
                $out[$y][$x] = $pal[$ch] ?? ($pal[$lower] ?? PX_INK);
                if (is_array($out[$y][$x])) {
                    $out[$y][$x] = $out[$y][$x][1];
                }
                continue;
            }
            if ($lower === 'j' || $lower === 'a') {
                $lower = px_pattern_zone($lower, $x - PX_OX, $y - PX_OY, $pattern);
            }
            $t = $pal[$lower];
            if ($ch !== strtolower($ch)) {
                $tone = 2;
            } elseif ($edge($x + 1, $y) || ($edge($x, $y + 1) && !$edge($x - 1, $y))) {
                $tone = 2;
            } elseif (($edge($x - 1, $y) && $edge($x, $y - 1)) || ($edge($x, $y - 1) && !$edge($x + 1, $y) && $lower !== 's')) {
                $tone = 0;
            } else {
                $tone = 1;
            }
            $out[$y][$x] = $t[$tone];
        }
    }
    return $out;
}

/** Zona della maglia in un punto: primo colore (j) o secondo (k), secondo il motivo. */
function px_pattern_zone(string $z, int $x, int $y, string $pattern): string
{
    return match ($pattern) {
        'stripes_v' => $z === 'j' && (($x + 64) % 4) >= 2 ? 'k' : 'j',
        'stripes_h' => (($y + 64) % 4) >= 2 ? 'k' : 'j',
        'halves' => $x < 16 ? 'j' : 'k',
        'sleeves' => $z === 'a' ? 'k' : 'j',
        'sash' => $z === 'j' && abs(($x + $y) - 44) <= 1 ? 'k' : 'j',
        default => 'j',
    };
}

/** Colore del numero: il secondo colore sulle maglie a tinta unita, altrimenti bianco o scuro. */
function px_number_color(string $a, string $b, string $pattern): string
{
    $plain = $pattern === 'solid' && abs(px_luma($a) - px_luma($b)) > .25;
    return $plain ? $b : (px_luma($a) > .6 ? PX_INK : '#ffffff');
}

/**
 * Palette del look. $c: skin, hair, ja (maglia), jb (secondo colore), shorts, shoes, pattern, hat [x, y, z], pet [u, i].
 */
function px_palette(array $c): array
{
    $hat = array_values(array_filter($c['hat'] ?? [])) + ['#c0392b', '#ffffff', '#ffd23f'];
    $pet = array_values(array_filter($c['pet'] ?? [])) + ['#ffd23f', '#ffffff'];
    return [
        's' => px_tones($c['skin']), 'h' => px_tones($c['hair']), 'j' => px_tones($c['ja']), 'a' => px_tones($c['ja']),
        'k' => px_tones($c['jb']), 'p' => px_tones($c['shorts']), 'c' => px_tones($c['ja']), 'f' => px_tones($c['shoes']),
        'x' => px_tones($hat[0]), 'y' => px_tones($hat[1] ?? $hat[0]), 'z' => px_tones($hat[2] ?? '#ffd23f'),
        'u' => px_tones($pet[0]), 'i' => px_tones($pet[1]),
        'r' => px_tones('#e63946'), 'b' => px_tones('#a86a3a'),
        'o' => PX_INK, 'w' => '#ffffff', 'e' => PX_INK, 'm' => '#8a2d3b', 'M' => '#5a1a2a', 't' => '#ff6b8b',
        'g' => '#ffd23f', 'G' => '#c98a1b', 'l' => '#ffffff', 'v' => '#c9ced8', 'd' => '#f6f1de', 'q' => '#cdc3a3', '3' => '#3a63ff',
        '4' => '#ff5a8a', '1' => px_mix($c['skin'], $c['hair'], .45),
        'n' => px_number_color($c['ja'], $c['jb'], $c['pattern'] ?? 'solid'),
    ];
}

/** SVG di una griglia di colori: un <path> per colore, pixel vicini uguali uniti per riga. Coordinate dello sprite. */
function px_svg_paths(array $colors): string
{
    $by = [];
    foreach ($colors as $gy => $row) {
        $y = $gy - PX_OY;
        $x = 0;
        while ($x < PX_W) {
            $c = $row[$x] ?? null;
            if ($c === null) {
                $x++;
                continue;
            }
            $x0 = $x;
            while ($x < PX_W && ($row[$x] ?? null) === $c) {
                $x++;
            }
            $by[$c] = ($by[$c] ?? '') . 'M' . ($x0 - PX_OX) . ' ' . $y . 'h' . ($x - $x0) . 'v1h-' . ($x - $x0) . 'z';
        }
    }
    $s = '';
    foreach ($by as $c => $d) {
        $s .= '<path fill="' . $c . '" d="' . $d . '"/>';
    }
    return $s;
}

/* ---------------------------------------------------------------- pose ed esultanze */

/** Pose da fermo (shop_items 'pose'): passi [fotogramma, ms] ripetuti all'infinito (il respiro, il saluto). */
function px_poses(): array
{
    return [
        'rest' => [['rest', 1500], ['rest_b', 600]],
        'open' => [['open', 1500]],
        'wave' => [['wave_a', 280], ['wave_b', 280]],
        'point' => [['point', 1500]],
        'victory' => [['victory', 1500]],
        'up' => [['up', 1500]],
    ];
}

/**
 * Esultanze a fotogrammi (shop_items 'celebration': anim). Ogni passo è [fotogramma, ms, dx, dy, rotazione, effetti]: spostamenti in
 * pixel e rotazioni a scatti di 90° (attorno al centro "c" del fotogramma); le posizioni intermedie sono fotogrammi "@45".
 * Effetti (px_fx, anche più d'uno con "+"): d1/d2/d3 polvere, trail scia dei due passi prima, heart, flash, muzzle, bow, arrow, ...
 * "hint" è il passo mostrato nelle miniature del catalogo.
 */
function px_celebrations(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $run = fn(int $from, string $a = 's_run1', string $b = 's_run2', int $n = 3, int $step = 3, int $ms = 100) =>
        array_map(fn($i) => [$i % 2 ? $b : $a, $ms, $from + $i * $step, 0, 0, ''], range(0, $n - 1));
    $flip = function (string $f, int $x0, int $sign, int $ms = 55, string $fx = 'trail', array $h = [-17, -22, -26, -27, -26, -22, -16, -9]) {
        $out = [];
        for ($i = 0; $i < 8; $i++) {
            $q = intdiv($i, 2) * 90 * $sign;
            $out[] = [$f . ($i % 2 ? '@' . (45 * $sign) : ''), $ms, $x0 + ($sign > 0 ? $i : -intdiv($i, 3)), $h[$i], $q, $i ? $fx : 'd3'];
        }
        return $out;
    };
    $alt = function (array $frames, int $n, int $ms, array $dy = [0], string $fx = '') {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [$frames[$i % count($frames)], $ms, 0, $dy[$i % count($dy)], 0, $fx];
        }
        return $out;
    };
    $c = [
        'fist-pump' => ['hint' => 1, 'steps' => array_merge([['rest', 200, 0, 0, 0, '']],
            $alt(['pump_up', 'pump_down'], 5, 150), [['pump_sky', 900, 0, 0, 0, ''], ['rest', 120, 0, 0, 0, '']])],
        'celebration' => ['hint' => 3, 'steps' => [['rest', 180, 0, 0, 0, ''], ['crouch_f', 160, 0, 0, 0, ''], ['jump_up', 110, 0, -6, 0, 'd1'],
            ['jump_up', 170, 0, -12, 0, 'd2'], ['jump_up', 130, 0, -7, 0, 'd3'], ['crouch_f', 120, 0, 0, 0, 'd1'], ['up_shout', 900, 0, 0, 0, 'd2']]],
        'shirt-kiss' => ['hint' => 2, 'steps' => [['rest', 180, 0, 0, 0, ''], ['kiss_grab', 260, 0, 0, 0, ''], ['kiss', 500, 0, 0, 0, ''],
            ['kiss', 500, 0, 0, 0, 'heart'], ['kiss', 500, 0, 0, 0, 'heart2'], ['rest', 150, 0, 0, 0, '']]],
        'salute' => ['hint' => 1, 'steps' => [['rest', 250, 0, 0, 0, ''], ['salute', 1500, 0, 0, 0, ''], ['rest', 120, 0, 0, 0, '']]],
        'skyfingers' => ['hint' => 1, 'steps' => [['rest', 200, 0, 0, 0, ''], ['sky', 700, 0, 0, 0, ''], ['sky_b', 400, 0, 0, 0, ''],
            ['sky', 700, 0, 0, 0, ''], ['rest', 120, 0, 0, 0, '']]],
        'kneel' => ['hint' => 6, 'steps' => array_merge($run(-12, 's_run1s', 's_run2s'), [['s_kneel', 100, -3, 0, 0, 'd1'],
            ['s_kneel', 100, 0, 0, 0, 'd2'], ['s_kneel', 100, 2, 0, 0, 'd3'], ['s_kneel', 1100, 3, 0, 0, '']])],
        'dance' => ['hint' => 0, 'steps' => array_merge($alt(['dance_a', 'dance_a<'], 10, 190, [0, -1]), [['victory', 500, 0, 0, 0, '']])],
        'tongue' => ['hint' => 5, 'steps' => array_merge($alt(['tongue_a', 'tongue_a<'], 8, 140, [0, -1]), [['tongue_hold', 900, 0, 0, 0, '']])],
        'plane' => ['hint' => 2, 'steps' => array_merge(array_map(fn($i) => [$i % 2 ? 'plane_b' : 'plane_a', 140, [-8, -5, -2, 1, 4, 6, 4, 1, -2][$i],
            $i % 2 ? -1 : 0, 0, ''], range(0, 8)), [['plane_b', 140, 0, 0, 0, ''], ['open', 600, 0, 0, 0, '']])],
        'cradle' => ['hint' => 1, 'steps' => array_merge([['rest', 150, 0, 0, 0, '']], array_map(fn($i) => ['cradle', 230, [-1, 0, 1, 0][$i % 4], 0, 0, ''], range(0, 8)),
            [['rest', 120, 0, 0, 0, '']])],
        'ear' => ['hint' => 1, 'steps' => array_merge([['rest', 150, 0, 0, 0, '']], $alt(['ear_a', 'ear_b'], 8, 170), [['ear_a', 500, 0, 0, 0, '']])],
        'thumb' => ['hint' => 1, 'steps' => [['rest', 150, 0, 0, 0, ''], ['thumb', 600, 0, 0, 0, ''], ['thumb', 500, 1, 0, 0, ''],
            ['thumb', 600, 0, 0, 0, ''], ['rest', 120, 0, 0, 0, '']]],
        'robot' => ['hint' => 1, 'steps' => array_merge($alt(['robot_a', 'robot_b', 'robot_c', 'robot_b'], 8, 210), [['rest', 120, 0, 0, 0, '']])],
        'inzaghi' => ['hint' => 3, 'steps' => array_merge($run(-12, 's_run1s', 's_run2s', 8, 3, 90),
            array_map(fn($i) => [$i % 2 ? 's_run2s<' : 's_run1s<', 90, 12 - $i * 3, 0, 0, ''], range(0, 3)), [['up_shout', 800, 0, 0, 0, '']])],
        'crossed' => ['hint' => 1, 'steps' => [['rest', 200, 0, 0, 0, ''], ['crossed', 1600, 0, 0, 0, ''], ['rest', 120, 0, 0, 0, '']]],
        'heart' => ['hint' => 1, 'steps' => array_merge([['rest', 150, 0, 0, 0, '']],
            array_map(fn($i) => ['heart', 300, 0, 0, 0, $i % 2 ? 'heart2' : 'heart'], range(0, 5)), [['rest', 120, 0, 0, 0, '']])],
        'machinegun' => ['hint' => 2, 'steps' => array_merge([['s_stand', 200, 0, 0, 0, '']],
            array_map(fn($i) => ['s_gun', 80, $i % 2 ? -1 : 0, 0, 0, $i % 2 ? 'muzzle2' : 'muzzle1'], range(0, 11)), [['s_gun', 500, 0, 0, 0, '']])],
        'archer' => ['hint' => 1, 'steps' => [['s_stand', 250, 0, 0, 0, ''], ['s_archer', 700, 0, 0, 0, 'bow+arrow1'], ['s_archer', 90, 0, 0, 0, 'bow+arrow2'],
            ['s_archer', 90, 0, 0, 0, 'bow+arrow3'], ['s_archer', 700, 0, 0, 0, 'bow'], ['s_stand', 150, 0, 0, 0, '']]],
        'why' => ['hint' => 1, 'steps' => [['rest', 200, 0, 0, 0, ''], ['why', 1800, 0, 0, 0, ''], ['rest', 120, 0, 0, 0, '']]],
        'milla' => ['hint' => 1, 'steps' => array_merge(array_map(fn($i) => [$i % 2 ? 'milla_b' : 'milla_a', 210, $i % 2 ? 1 : -1, 0, 0, 'flag'], range(0, 9)),
            [['rest', 120, 0, 0, 0, '']])],
        'selfie' => ['hint' => 1, 'steps' => [['rest', 200, 0, 0, 0, ''], ['selfie', 700, 0, 0, 0, 'phone'], ['selfie', 120, 0, 0, 0, 'phone+flash'],
            ['selfie', 800, 0, 0, 0, 'phone'], ['rest', 120, 0, 0, 0, '']]],
        'dive' => ['hint' => 6, 'steps' => array_merge($run(-12), [['s_crouch', 110, -3, 0, 0, ''], ['s_stretch', 90, -3, -6, 90, 'd1'],
            ['s_dive', 110, 0, 0, 0, 'd1'], ['s_dive', 110, 3, 0, 0, 'd2'], ['s_dive', 110, 5, 0, 0, 'd3'], ['s_dive', 1000, 6, 0, 0, '']])],
        'tardelli' => ['hint' => 2, 'steps' => array_merge($alt(['tard_a', 'tard_a<'], 10, 130, [0, -1]), [['up_shout', 800, 0, 0, 0, '']])],
        'zen' => ['hint' => 2, 'steps' => [['rest', 200, 0, 0, 0, ''], ['crouch_f', 180, 0, 0, 0, ''], ['zen', 900, 0, 0, 0, ''], ['zen', 900, 0, 0, 0, 'z'],
            ['rest', 120, 0, 0, 0, '']]],
        'cartwheel' => ['hint' => 2, 'steps' => array_merge([['rest', 150, -8, 0, 0, ''], ['star', 130, -8, 0, 0, '']],
            array_map(fn($i) => ['star' . ($i % 2 ? '' : '@45'), 75, -6 + $i * 2, 0, (intdiv($i + 1, 2)) * 90, $i ? 'trail' : 'd1'], range(0, 6)),
            [['star', 250, 8, 0, 0, 'd1'], ['up', 700, 8, 0, 0, '']])],
        'backflip' => ['hint' => 8, 'steps' => array_merge(
            [['s_stand', 450, 0, 0, 0, ''], ['s_swing', 150, 0, 0, 0, ''], ['s_crouch', 230, 0, 0, 0, ''], ['s_stretch', 70, 0, -4, 0, 'd1'],
                ['s_arch', 70, 0, -13, 0, 'd2']],
            array_map(fn($s) => [$s[0], $s[1], $s[2] - 1, $s[3] - 4, $s[4], $s[5]], $flip('s_tuck', 0, -1)),
            [['s_open', 70, -2, -5, 0, ''], ['s_land', 90, -2, 0, 0, 'd1'], ['s_crouch', 160, -2, 0, 0, 'd2'], ['s_swing', 110, -2, 0, 0, 'd3'],
                ['s_win', 1000, -2, 0, 0, '']])],
        'siu' => ['hint' => 6, 'steps' => array_merge($run(-12), [['s_crouch', 110, -3, 0, 0, ''], ['s_stretch', 90, -2, -8, 0, 'd1'],
            ['siu_air', 150, 0, -13, 0, 'd2'], ['siu_air', 110, 0, -6, 0, ''], ['siu', 1100, 0, 0, 0, 'd1']])],
        'hernanes' => ['hint' => 9, 'steps' => array_merge($run(-14), [['s_crouch', 100, -5, 0, 0, ''], ['s_stretch', 70, -4, -5, 0, 'd1']],
            $flip('s_tuck', -3, 1, 60), [['s_open', 70, 5, -4, 0, ''], ['s_land', 100, 5, 0, 0, 'd1'], ['s_crouch', 150, 5, 0, 0, 'd2'],
                ['s_win', 900, 5, 0, 0, '']])],
    ];
    return $c;
}

/** Trasformazione SVG di un passo di animazione. */
function px_step_transform(array $step): string
{
    [$frame, , $dx, $dy, $rot] = $step + [0, 0, 0, 0, 0];
    $base = rtrim(preg_replace('/@-?\d+$/', '', $frame), '<');
    $c = px_frames()[$base]['c'] ?? [16, 30];
    return ($dx || $dy ? 'translate(' . $dx . ' ' . $dy . ')' : '') . ($rot ? ' rotate(' . $rot . ' ' . $c[0] . ' ' . $c[1] . ')' : '');
}

/** Effetti (px_fx) come griglia colorata. */
function px_fx_svg(string $key, array $pal): string
{
    [$x, $y, $rows] = px_fx()[$key];
    $g = px_blank();
    px_paint($g, $rows, $x, $y);
    return px_svg_paths(px_colorize($g, $pal));
}

/** Pet a bordo campo, accanto ai piedi. */
function px_pet_svg(string $kind, array $pal): string
{
    $rows = px_pets()[$kind] ?? null;
    if (!$rows) {
        return '';
    }
    $g = px_blank();
    px_paint($g, $rows, 28, 56 - count($rows));
    return '<g class="pxa-pet">' . px_svg_paths(px_colorize($g, $pal)) . '</g>';
}
