<?php
/*
 * Personaggio in pixel art 16-bit.
 *
 * Ogni fotogramma del corpo è una mappa di caratteri su una griglia di 32×56 (il terreno è la riga 55); ogni lettera è una "zona" di
 * colore che prende il colore dal look del giocatore:
 *   o contorno   s pelle   h capelli   j maglia   a maniche   k finiture (colletto, polsini, calzettoni)   p pantaloncini
 *   c calzettoni   f scarpe   w bianco degli occhi   e pupilla   m bocca   M interno della bocca   t lingua
 *   x y z   colori del copricapo o del pet   g oro   l bianco
 * Una lettera maiuscola (S, H, J, ...) è la stessa zona in ombra; le altre ombre e le luci si aggiungono da sole (la luce arriva da
 * sinistra, in alto). "." non disegna niente, "_" cancella quello che c'era sotto (serve ai copricapi).
 * La testa, i capelli, la barba, gli occhiali e il copricapo sono livelli a parte, agganciati alla testa di ogni fotogramma.
 */

const PX_W = 32;
const PX_H = 56;
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

/** I tre toni di una zona: [luce, base, ombra]. L'ombra tende al viola del contorno, come nelle palette dei 16 bit. */
function px_tones(string $base): array
{
    $l = px_luma($base);
    $shade = px_mix($base, '#2a1f4a', $l > .85 ? .22 : .32);
    $light = px_mix($base, '#fff8e6', $l > .85 ? 0 : ($l < .2 ? .28 : .22));
    return [$light, $base, $shade];
}

/* ------------------------------------------------------------------ disegni */

/** Espande una mappa: le righe "a metà" (m) sono il lato sinistro, specchiato a destra. */
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

/** Le teste (senza capelli): 16×13, "front" di fronte e "side" di profilo verso destra. */
function px_heads(): array
{
    return [
        'front' => px_rows([
            '....oooo',
            '..oossss',
            '.ossssss',
            '.ossssss',
            '.osshhss',
            'oSsswess',
            'oSsswess',
            'oSssssss',
            '.ossssss',
            '.osssssm',
            '..osssss',
            '...oosss',
            '.....ooo',
        ], true),
        'side' => [
            '.....oooooo.....',
            '...oossssssoo...',
            '..osssssssssso..',
            '.osssssssssssso.',
            '.ossssssssshhso.',
            '.ossssSSsssweso.',
            '.ossssSSssssesso',
            '.ossssSsssssssso',
            '.osssssssssssoo.',
            '..osssssssssmo..',
            '..osssssssssso..',
            '...oossssssoo...',
            '......oooooo....',
        ],
    ];
}

/**
 * Acconciature: per ogni vista [dx, dy, righe] rispetto all'angolo della testa; "back" va dietro al corpo (capelli lunghi).
 * Le viste di fronte sono metà specchiate.
 */
function px_hair(): array
{
    return [
        'bald' => [],
        'classic' => [
            'front' => [0, -2, px_rows([
                '...ooooo',
                '..ohhhhh',
                '.ohhhhhh',
                'ohhHhhhh',
                'ohHhhhhh',
                'ohhhh...',
                'oh......',
            ], true)],
            'side' => [0, -2, [
                '....ooooooo.....',
                '..oohhhhhhhoo...',
                '.ohhhhhhhhhhhho.',
                'ohhhhhhhhhhhhhho',
                'ohhhhhhhhhhhhho.',
                'ohhhhhhhhhh.....',
                'ohhhhhh.........',
                'ohhhh...........',
                '.ohhh...........',
                '..oo............',
            ]],
        ],
        'curly' => [
            'front' => [-2, -5, px_rows([
                '.....ooo..',
                '...oohhhoo',
                '..ohhhhhhh',
                '.ohhhHhhhh',
                'ohhHhhhhHh',
                'ohhhhhhhhh',
                'ohHhhhHhhh',
                'ohhhhhhhhh',
                'ohhhhhh...',
                'ohhhh.....',
                '.ohh......',
                '..o.......',
            ], true)],
            'side' => [-2, -5, [
                '......ooo.ooo.......',
                '....oohhhohhhoo.....',
                '...ohhhhhhhhhhho....',
                '..ohhhHhhhhHhhhhoo..',
                '.ohhHhhhhhhhhhHhhho.',
                'ohhhhhhhHhhhhhhhhho.',
                'ohhHhhhhhhhhhhhhho..',
                'ohhhhhhhhhhhhh......',
                'ohhhhHhhhh..........',
                'ohhhhhhh............',
                '.ohhhhh.............',
                '..ohhh..............',
                '...oo...............',
            ]],
        ],
        'long' => [
            'front' => [-1, -2, px_rows([
                '...ooooo.',
                '..ohhhhhh',
                '.ohhhhhhh',
                'ohhHhhhhh',
                'ohhhhhhhh',
                'ohhhhh...',
                'ohhh.....',
                'ohhh.....',
                'ohhh.....',
                'ohHh.....',
                'ohhh.....',
                'ohhh.....',
                'ohhh.....',
                '.oho.....',
                '..o......',
            ], true)],
            'side' => [0, -2, [
                '....ooooooo.....',
                '..oohhhhhhhoo...',
                '.ohhhhhhhhhhhho.',
                'ohhhhhhhhhhhhhho',
                'ohhhhhhhhhhhhho.',
                'ohhhhhhhhhh.....',
                'ohhhhhh.........',
                'ohhhhh..........',
                'ohhhhh..........',
                'ohhHhh..........',
                'ohhhhh..........',
                'ohhhho..........',
                'ohhhho..........',
                '.ohho...........',
                '..oo............',
            ]],
        ],
    ];
}

/** Barbe: [dx, dy, righe] rispetto alla testa (solo pelle e capelli, niente contorno esterno nuovo). */
function px_beards(): array
{
    return [
        'none' => [],
        'full' => [
            'front' => [0, 5, px_rows([
                '........',
                '.o......',
                '.oh.....',
                '.ohh....',
                '.ohhhhhm',
                '..ohhhhh',
                '...ohhhh',
                '.....ooo',
            ], true)],
            'side' => [0, 5, [
                '................',
                '.........h......',
                '........hh......',
                '.......hhhhh....',
                '..ohhhhhhhhhmo..',
                '..ohhhhhhhhhho..',
                '...oohhhhhhoo...',
                '......oooooo....',
            ]],
        ],
    ];
}

/** Occhiali: [dx, dy, righe] rispetto alla testa. */
function px_glasses(): array
{
    return [
        'none' => [],
        'sun' => [
            'front' => [0, 4, px_rows([
                '........',
                'oooooooo',
                '..oeeeoo',
                '...oeo..',
            ], true)],
            'side' => [0, 4, [
                '................',
                '.......ooooooooo',
                '..........oeeeo.',
                '...........oeo..',
            ]],
        ],
    ];
}

/** Copricapi pixel: [dx, dy, righe] rispetto alla testa, x/y/z sono i tre colori del copricapo. */
function px_hats(): array
{
    return [
        'cap' => [
            'front' => [-1, -4, px_rows([
                '....ooooo',
                '..ooxxxxx',
                '.oxxxxxxx',
                '.oxXxxxxy',
                'oxxxxxxxy',
                'oxxxxxxxx',
                'oooooooo.',
                '.oyyyyyyy',
                '..ooooooo',
            ], true)],
            'side' => [0, -4, [
                '....ooooooo.........',
                '..ooxxxxxxxoo.......',
                '.oxxxxxxxxxxxo......',
                'oxxXxxxxxxxxxxo.....',
                'oxxxxxxxxxxxxxxooooo',
                'oxxxxxxxxxxxxxyyyyyo',
                'oooooooooooooooooooo',
            ]],
        ],
    ];
}

/** Busto e gambe di fronte, senza braccia (metà sinistra, dalla riga 23). */
function px_front_body(): array
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
        '..........ossso.',
        '..........oSsso.',
        '..........okkko.',
        '..........occco.',
        '..........occco.',
        '..........occco.',
        '..........occco.',
        '..........occco.',
        '..........occco.',
        '..........occco.',
        '.........offffo.',
        '........offfffo.',
        '........offfffo.',
        '.........oooooo.',
    ];
}

/** Braccio sinistro di fronte (quello destro è lo stesso specchiato): [prima riga, righe]. */
function px_front_arms(): array
{
    return [
        'down' => [25, [
            '......ooaa',
            '.....oaaaa',
            '....oaaaao',
            '....oaaaao',
            '....oaaaao',
            '....okkkko',
            '....ossss',
            '....ossss',
            '....ossss',
            '....ossss',
            '....ossss',
            '....ossss',
            '....ossss',
            '.....oooo',
        ]],
        'up' => [5, [
            '.ooo',
            'osssoo',
            'ossssso',
            'osssso',
            '.ossso',
            '.ossso',
            '.ossso',
            '..ossso',
            '..ossso',
            '..ossso',
            '...ossso',
            '...ossso',
            '...okkkko',
            '....oaaao',
            '....oaaaao',
            '.....oaaaao',
            '.....oaaaaoo',
            '......oaaaaoo',
            '.......oaaaao',
            '........oaaao',
            '........oaaaj',
            '.........oaj',
        ]],
    ];
}

/** Corpi di profilo (verso destra): [prima riga, righe]; "_over" sono le braccia, disegnate davanti alla testa. */
function px_side_parts(): array
{
    return [
        'crouch_base' => [36, [
            '..............ooo...ooo',
            '.............ojjjo.ojjjo',
            '............ojjjo...ojjo',
            '..........ooAooo....ojo',
            '.........oAAAo......ojo',
            '.......ooASSo......ojjo',
            '....oooSSSoo......ojjjo',
            '..ooSSSSoo......oojjjjo',
            '.oSSSooo.......ojjjjjo',
            '.oSSo......oooojjjjjjooo',
            '.oSSo.....opppppppppppppo',
            '..oo.....opppppppppppssspo',
            '.........oppppppppppsssssso',
            '.........oppppppppppsscccso',
            '.........oppppppppppcccccco',
            '..........ooopoooppccccccco',
            '.............oFFFoofffffffo',
            '.............oFFFofffffffffo',
            '.............oFFFofffffffffo',
            '..............ooooooooooooo',
        ]],
        'crouch_over' => [36, [
            '.................ooo',
            '................oaaao',
            '..............ooaaaaao',
            '.............oaaaaaaao',
            '............oaaaaaaaao',
            '..........ooassaaaaao',
            '........oossssssaaao',
            '.....ooossssssssaao',
            '....ossssssssssaoo',
            '...osssssssssaao',
            '...osssssssoooo',
            '...ossssooo',
            '....osso',
            '.....oo',
            '',
            '',
            '',
            '',
            '',
            '',
        ]],
        'land_base' => [36, [
            '..............ooo...ooo',
            '.............ojjjo.ojjjo',
            '............ojjjo...ojjo',
            '............ojjjo....oooooooo',
            '............ojjjo.....oSSSSSSo',
            '...........ojjjjjo.....oooooo',
            '...........ojjjjjjo',
            '..........ojjjjjjjo',
            '..........ojjjjjjjjoo',
            '..........ojjjjjjjjjjooo',
            '.........oppppppppppppppo',
            '.........opppppppppppssspo',
            '.........oppppppppppsssssso',
            '.........oppppppppppsscccso',
            '.........oppppppppppcccccco',
            '..........ooopoooppccccccco',
            '.............oFFFoofffffffo',
            '.............oFFFofffffffffo',
            '.............oFFFofffffffffo',
            '..............ooooooooooooo',
        ]],
        'land_over' => [36, [
            '.................ooo',
            '................oaaao',
            '...............oaaaaao',
            '...............oaaaaaao',
            '...............oaaaaaaaoooooo',
            '................oaasssssssssso',
            '.................oassssssssssso',
            '.................oasssssssssssso',
            '..................oaassssssssso',
            '...................oooooooosso',
            '...........................oo',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ]],
        'stretch_base' => [5, [
            '...................ooo',
            '..................oSSSo',
            '.................oSSSSo',
            '.................oSSSSo',
            '.................oSSSSo',
            '.................oSSSSo',
            '.................oSSSSo',
            '.................oSSSSo',
            '.................oSSSo',
            '.................oSSSo',
            '.................oSSSo',
            '.................oSSSo',
            '.................oSSSo',
            '.................oSSSo',
            '.................oSSSo',
            '................oSSSSo',
            '................oSSSSo',
            '................oSSSSo',
            '.................oSSSo',
            '............o....oSSAo',
            '...........ojo...ooooo',
            '...........ojo...ojjjo',
            '...........ojo...ojjjo',
            '...........ojo...ojjjo',
            '...........ojoo.oojjjo',
            '...........ojjjojjjjjo',
            '...........ojjjjjjjjjo',
            '...........ojjjjjjjjjo',
            '...........ojjjjjjjjjo',
            '...........ojjjjjjjjjo',
            '...........ojjjjjjjjjo',
            '...........ojjjjjjjjjo',
            '...........opppppppppo',
            '...........opppppppppo',
            '...........opppppppppo',
            '...........opppppppppo',
            '...........opppppppppo',
            '...........ooppppppppo',
            '...........oPoppspppo',
            '...........oPossssspo',
            '...........oPossssspo',
            '...........oPPocccso',
            '............oCocccco',
            '............oCocccco',
            '............oCocccco',
            '............oCocccco',
            '............oCofffco',
            '............oCoffffo',
            '............oCFoffffo',
            '.............ooFofffo',
            '...............oFoffo',
        ]],
        'stretch_over' => [4, [
            '.............oo',
            '............osso',
            '...........osssso',
            '...........osssso',
            '...........osssso',
            '...........osssso',
            '...........osssso',
            '...........osssso',
            '...........osssso',
            '...........osssso',
            '...........osssso',
            '............ossso',
            '............ossso',
            '............ossso',
            '............osssso',
            '............osssso',
            '............osssso',
            '............osssso',
            '............osssso',
            '...........oassssao',
            '...........oassssao',
            '............oaaaaao',
            '............oaaaaao',
            '............oaaaaao',
            '............oaaaaao',
            '............oaaaaao',
            '.............ooaoo',
            '...............o',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ]],
        'tuck_base' => [36, [
            '..........oooo....o',
            '.........ojjjjo..ojooooo',
            '.........ojjjjo...oopssso',
            '.........ojjjo....opssssso',
            '.........ojjjjo....osssssso',
            '.........ojjjjo.....oscccso',
            '........ojjjjjjo....occccco',
            '........ojjjjpppo....ooccco',
            '........ojjjpppppoo....oco',
            '........oppppppppppo....o',
            '........opppppppppopoo.o',
            '........oppppppppoCoccoco',
            '........oopppppppoCocccco',
            '........oPoppppooooffffffo',
            '.........oppppoFFofffffffo',
            '..........oooooFFoffoooffo',
            '...............oooooFFFoo',
            '....................ooo',
            '',
            '',
        ]],
        'tuck_over' => [36, [
            '..............oooo',
            '.............oaaaao',
            '.............oaaaaao',
            '............oaaaaaao',
            '.............oaaaaaao',
            '.............oaaasssao',
            '..............oaassssoo',
            '...............oasssssso',
            '................oasssssso',
            '.................oosssssso',
            '...................osssso',
            '....................ooso',
            '......................o',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ]],
    ];
}

/**
 * Fotogrammi: view = vista della testa, layers = livelli [righe, x, y, specchiato], head = angolo della testa,
 * num = dove va il numero di maglia (centro, riga in alto), c = centro di rotazione.
 */
function px_frames(): array
{
    $arm = fn(string $k) => [px_front_arms()[$k][1], 0, px_front_arms()[$k][0], true];
    $front = fn(string $arms) => ['view' => 'front', 'layers' => [[px_front_body(), 0, 23, true], $arm($arms)],
        'head' => [8, 10], 'num' => [16, 28], 'c' => [16, 32]];
    $side = function (string $k, array $head) {
        $p = px_side_parts();
        return ['view' => 'side', 'layers' => [[$p[$k . '_base'][1], 0, $p[$k . '_base'][0], false], [$p[$k . '_over'][1], 0, $p[$k . '_over'][0], false, true]],
            'head' => $head, 'c' => [17, 38]];
    };
    return [
        'rest' => $front('down'),
        'up' => $front('up'),
        's_crouch' => $side('crouch', [13, 24]),
        's_land' => $side('land', [13, 24]),
        's_stretch' => $side('stretch', [9, 12]),
        's_tuck' => $side('tuck', [10, 24]),
    ];
}
/* ---------------------------------------------------------------- composizione */

/** Disegna le righe sulla griglia a partire da (x, y); con $flip specchia orizzontalmente il livello rispetto alla sua larghezza. */
function px_paint(array &$g, array $rows, int $x, int $y): void
{
    foreach ($rows as $dy => $row) {
        $len = strlen($row);
        for ($dx = 0; $dx < $len; $dx++) {
            $ch = $row[$dx];
            if ($ch === '.') {
                continue;
            }
            $gy = $y + $dy;
            $gx = $x + $dx;
            if ($gy < 0 || $gy >= PX_H || $gx < 0 || $gx >= PX_W) {
                continue;
            }
            $g[$gy][$gx] = $ch === '_' ? '.' : $ch;
        }
    }
}

/** Griglia di lettere di un fotogramma col look indicato (capelli, barba, occhiali, copricapo, numero). */
function px_letters(string|array $frame, array $parts): array
{
    $f = is_array($frame) ? $frame : px_frames()[$frame];
    $view = $f['view'];
    $g = array_fill(0, PX_H, array_fill(0, PX_W, '.'));
    [$hx, $hy] = $f['head'];
    $layer = function (?array $def) use (&$g, $view, $hx, $hy) {
        if (!$def || empty($def[$view])) {
            return;
        }
        [$dx, $dy, $rows] = $def[$view];
        px_paint($g, $rows, $hx + $dx, $hy + $dy);
    };
    $hair = px_hair()[$parts['hair']] ?? [];
    if ($parts['hat'] && $hair) {   // sotto a un copricapo i capelli alti restano un taglio corto
        $hair = in_array($parts['hair'], ['long'], true) ? $hair : px_hair()['classic'];
    }
    $over = [];   // livelli davanti alla testa (braccia alzate)
    foreach ($f['layers'] as $l) {
        if (!empty($l[4])) {
            $over[] = $l;
            continue;
        }
        px_paint($g, px_rows($l[0], $l[3], 16), $l[1], $l[2]);
    }
    px_paint($g, px_heads()[$view], $hx, $hy);
    $layer(px_beards()[$parts['beard']] ?? null);
    $layer($hair);
    $layer(px_glasses()[$parts['glasses']] ?? null);
    $layer($parts['hat'] ? (px_hats()[$parts['hat']] ?? null) : null);
    foreach ($over as $l) {
        px_paint($g, px_rows($l[0], $l[3], 16), $l[1], $l[2]);
    }
    if (isset($f['num']) && $parts['number'] !== '') {
        px_number($g, $parts['number'], $f['num'][0], $f['num'][1]);
    }
    return $g;
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
    $w = strlen($num) * 4 - 1;
    $x = $cx - intdiv($w + 1, 2);
    foreach (str_split($num) as $i => $d) {
        foreach (px_digits()[$d] as $dy => $row) {
            for ($dx = 0; $dx < 3; $dx++) {
                if ($row[$dx] === '#') {
                    $g[$y + $dy][$x + $i * 4 + $dx] = 'n';
                }
            }
        }
    }
}

/**
 * Da lettere a colori. Le zone prendono tre toni: ombra lungo il bordo destro e in basso, luce lungo il bordo sinistro in alto.
 * Le maglie a motivo cambiano colore in base alla posizione (strisce, metà, fascia).
 */
function px_colorize(array $g, array $pal, string $pattern = 'solid'): array
{
    $zones = 'shjakpcfxyz';
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
            $forced = $ch !== $lower && str_contains($zones, $lower);
            if ($lower === 'j' || $lower === 'a') {
                $lower = px_pattern_zone($lower, $x, $y, $pattern);
            }
            if (!isset($pal[$lower])) {
                $out[$y][$x] = $pal[$ch] ?? PX_INK;
                continue;
            }
            $t = $pal[$lower];
            if (!is_array($t)) {
                $out[$y][$x] = $t;
                continue;
            }
            $tone = 1;
            if ($forced) {
                $tone = 2;
            } elseif (str_contains($zones, $lower)) {
                if ($edge($x + 1, $y) || ($edge($x, $y + 1) && !$edge($x - 1, $y))) {
                    $tone = 2;
                } elseif ($edge($x - 1, $y) && $edge($x, $y - 1) || ($edge($x, $y - 1) && !$edge($x + 1, $y) && $lower !== 's')) {
                    $tone = 0;
                }
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
        'stripes_v' => $z === 'j' && ($x % 4) >= 2 ? 'k' : 'j',
        'stripes_h' => ($y % 4) >= 2 ? 'k' : 'j',
        'halves' => $z === 'j' ? ($x < 16 ? 'j' : 'k') : ($x < 16 ? 'j' : 'k'),
        'sleeves' => $z === 'a' ? 'k' : 'j',
        'sash' => $z === 'j' && abs(($x + $y) - 44) <= 1 ? 'k' : 'j',
        default => 'j',
    };
}

/**
 * Palette del look: ogni zona ha i tre toni. $c ha skin, hair, ja (maglia), jb (secondo colore), shorts, shoes, hat (x, y, z).
 */
function px_palette(array $c): array
{
    // numero: il secondo colore sulle maglie a tinta unita, altrimenti bianco o scuro (sulle strisce il secondo colore si confonde)
    $plain = ($c['pattern'] ?? 'solid') === 'solid' && abs(px_luma($c['ja']) - px_luma($c['jb'])) > .25;
    $numColor = $plain ? $c['jb'] : (px_luma($c['ja']) > .6 ? PX_INK : '#ffffff');
    return [
        's' => px_tones($c['skin']), 'h' => px_tones($c['hair']), 'j' => px_tones($c['ja']), 'a' => px_tones($c['ja']),
        'k' => px_tones($c['jb']), 'p' => px_tones($c['shorts']), 'c' => px_tones($c['socks'] ?? $c['ja']), 'f' => px_tones($c['shoes']),
        'x' => px_tones($c['hat'][0] ?? '#c0392b'), 'y' => px_tones($c['hat'][1] ?? '#ffffff'), 'z' => px_tones($c['hat'][2] ?? '#ffd23f'),
        'o' => PX_INK, 'w' => '#ffffff', 'e' => PX_INK, 'm' => '#8a2d3b', 'M' => '#5a1a2a', 't' => '#ff6b8b',
        'g' => ['#fff1a8', '#ffd23f', '#c98a1b'], 'l' => '#ffffff', 'n' => $numColor,
    ];
}

/** SVG di una griglia di colori: un <path> per colore, una riga alla volta, pixel vicini uguali uniti. */
function px_svg_paths(array $colors): string
{
    $by = [];
    foreach ($colors as $y => $row) {
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
            $by[$c] = ($by[$c] ?? '') . 'M' . $x0 . ' ' . $y . 'h' . ($x - $x0) . 'v1h-' . ($x - $x0) . 'z';
        }
    }
    $s = '';
    foreach ($by as $c => $d) {
        $s .= '<path fill="' . $c . '" d="' . $d . '"/>';
    }
    return $s;
}

/* ---------------------------------------------------------------- animazioni */

/**
 * Esultanze a fotogrammi: ogni passo è [fotogramma, durata in ms, dx, dy, rotazione]. Spostamenti in pixel dello sprite e rotazioni
 * a scatti di 90° (attorno al centro "c" del fotogramma), così i pixel restano sempre sulla griglia.
 * Il backflip è di profilo: visto di fronte una rotazione nel piano sembrerebbe una ruota.
 */
function px_celebrations(): array
{
    return [
        'backflip' => [
            ['rest', 350, 0, 0, 0],
            ['s_crouch', 260, 0, 0, 0],
            ['s_stretch', 110, 0, -5, 0],
            ['s_tuck', 90, 0, -17, 0],
            ['s_tuck', 90, -1, -22, -90],
            ['s_tuck', 90, -1, -23, -180],
            ['s_tuck', 90, 0, -20, -270],
            ['s_tuck', 80, 0, -10, 0],
            ['s_land', 280, 0, 0, 0],
            ['up', 800, 0, 0, 0],
        ],
    ];
}

/** Trasformazione SVG di un passo di animazione. */
function px_step_transform(array $step): string
{
    [$frame, , $dx, $dy, $rot] = $step;
    $c = px_frames()[$frame]['c'] ?? [16, 32];
    return 'translate(' . $dx . ' ' . $dy . ')' . ($rot ? ' rotate(' . $rot . ' ' . $c[0] . ' ' . $c[1] . ')' : '');
}

/**
 * SVG del personaggio: un <g data-f> per ogni fotogramma usato (visibile solo il primo), dentro un <g data-sprite> che il player
 * sposta e ruota. $o: frames (nomi), seq (passi di un'esultanza), step (passo da mostrare fermo), viewBox, class.
 */
function px_svg(array $colors, array $parts, string $pattern, array $o = []): string
{
    $seq = $o['seq'] ?? null;
    $names = $o['frames'] ?? ($seq ? array_values(array_unique(array_column($seq, 0))) : ['rest']);
    $show = isset($o['step']) ? $o['step'][0] : $names[0];
    $pal = px_palette($colors + ['pattern' => $pattern]);
    $g = '';
    foreach ($names as $n) {
        $g .= '<g data-f="' . $n . '"' . ($n === $show ? '' : ' display="none"') . '>' . px_svg_paths(px_colorize(px_letters($n, $parts), $pal, $pattern)) . '</g>';
    }
    $t = isset($o['step']) ? ' transform="' . px_step_transform($o['step']) . '"' : '';
    $data = $seq ? ' data-seq="' . htmlspecialchars(json_encode(array_map(fn($s) => [$s[0], $s[1], px_step_transform($s)], $seq)), ENT_QUOTES) . '"' : '';
    return '<svg class="' . ($o['class'] ?? 'pxa') . '" viewBox="' . ($o['viewBox'] ?? '-6 -10 44 66') . '" shape-rendering="crispEdges"'
        . $data . (!empty($o['autoplay']) ? ' data-autoplay' : '') . ' aria-hidden="true"><ellipse cx="16.5" cy="55.5" rx="9" ry="1.6" fill="#1f1a2e" opacity=".22" data-shadow/><g data-sprite' . $t . '>' . $g . '</g></svg>';
}
