<?php
/*
 * Avatar in pixel art: le parti disegnate a mano, pixel per pixel (lettere come in lib/avatar_pixel.php).
 * Teste e facce, acconciature, barbe, occhiali, copricapi, pet ed effetti. Ogni pezzo della testa ha una vista "front" (di fronte)
 * e una "side" (di profilo verso destra), come [dx, dy, righe] rispetto all'angolo in alto a sinistra della testa (16×13).
 * px_m() prende la metà sinistra e la specchia, per i pezzi simmetrici.
 */

/** Pezzo simmetrico: metà sinistra specchiata. */
function px_m(int $dx, int $dy, array $half): array
{
    return [$dx, $dy, px_rows($half, true)];
}

/** Pezzo disegnato per intero. */
function px_l(int $dx, int $dy, array $rows): array
{
    return [$dx, $dy, $rows];
}

/** Le teste (senza capelli): 16×13. */
function px_heads(): array
{
    static $h = null;
    return $h ??= [
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

/** Espressioni: ritocchi alla faccia [riga, colonna, pixel] (di fronte le colonne 4-5 e 10-11 sono gli occhi, 7-8 la bocca). */
function px_faces(): array
{
    return [
        'shout' => [
            'front' => [[9, 6, 'mMMm'], [10, 7, 'MM']],
            'side' => [[9, 11, 'MM'], [10, 12, 'M']],
        ],
        'tongue' => [
            'front' => [[9, 6, 'mMMm'], [10, 7, 'tt'], [11, 7, 'tt'], [12, 7, 'oo']],
            'side' => [[9, 11, 'MM'], [10, 12, 'tt'], [11, 13, 'o']],
        ],
        'kiss' => [
            'front' => [[9, 7, 'mm'], [10, 7, 'mm']],
            'side' => [[9, 12, 'mm'], [10, 12, 'm']],
        ],
        'closed' => [
            'front' => [[5, 4, 'ss'], [6, 4, 'ee'], [5, 10, 'ss'], [6, 10, 'ee']],
            'side' => [[5, 11, 'ss'], [6, 11, 'ee']],
        ],
        'smile' => [
            'front' => [[9, 6, 'm..m'], [10, 7, 'mm']],
            'side' => [[9, 12, 'm'], [10, 11, 'm']],
        ],
    ];
}

/**
 * Acconciature (chiavi di shop_items.php 'hair'). Col copricapo i capelli alti diventano "classic" (px_hair_under_hat).
 */
function px_hair(): array
{
    static $h = null;
    return $h ??= [
        'bald' => [],
        'classic' => [
            'front' => px_m(0, -2, [
                '...ooooo',
                '..ohhhhh',
                '.ohhhhhh',
                'ohhHhhhh',
                'ohHhhhhh',
                'ohhhh...',
                'oh......',
            ]),
            'side' => px_l(0, -2, [
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
            ]),
        ],
        'buzz' => [
            'front' => px_m(0, 0, [
                '....oooo',
                '..oohhhh',
                '.ohhHhhh',
                '.ohhhhhh',
                '.oh.....',
            ]),
            'side' => px_l(0, 0, [
                '.....oooooo.....',
                '...oohhhhhhoo...',
                '..ohhhhhhhhhho..',
                '.ohhhhhhhhh.....',
                '.ohhhh..........',
                '.ohhh...........',
                '.ohh............',
            ]),
        ],
        'side' => [
            'front' => px_l(-1, -2, [
                '....oooooooooo....',
                '..oohhhhhhhhhhoo..',
                '.ohhhhhhhhhhhhhho.',
                'ohhhhhhhHhhhhhhhho',
                'ohhHhhhhhHhhhhhhho',
                'ohhhh..hhhhhhhhhho',
                'ohh.........hhhhho',
                'oh............hho.',
                'oh.............ho.',
            ]),
            'side' => px_l(0, -2, [
                '....ooooooo.....',
                '..oohhhhhhhoo...',
                '.ohhhhhhhhhhhhoo',
                'ohhhhhhhhhhhhhhho',
                'ohhhhhhhhhhhhhho',
                'ohhhhhhhhhhhh...',
                'ohhhhhh.........',
                'ohhhh...........',
                '.ohhh...........',
                '..oo............',
            ]),
        ],
        'fade' => [
            'front' => px_m(0, -2, [
                '...ooooo',
                '..ohhhhh',
                '.ohhhhhh',
                '.ohhHhhh',
                'oHhhhhhh',
                'oHH.....',
                'oH......',
            ]),
            'side' => px_l(0, -2, [
                '....ooooooo.....',
                '..oohhhhhhhoo...',
                '.ohhhhhhhhhhhho.',
                'ohhhhhhhhhhhhhho',
                'oHhhhhhhhhhhhho.',
                'oHHHhhhhhhh.....',
                'oHHHHHH.........',
                'oHHHH...........',
                '.oHHH...........',
                '..oo............',
            ]),
        ],
        'spiky' => [
            'front' => px_m(-1, -4, [
                '...o...o.',
                '..oho.oho',
                '.ohhhohhh',
                '.ohhhhhhh',
                'oohhhhhhh',
                '.ohhHhhhh',
                '.ohhhhhhh',
                '.ohhh.hh.',
                '.oh......',
            ]),
            'side' => px_l(0, -4, [
                '.....o...o..o...',
                '....oho.oho.oho.',
                '...ohhhohhhohhho',
                '..oohhhhhhhhhhoo',
                '.ohhhhhhhhhhhhho',
                'ohhhhhhhhhhhhhho',
                'ohhhhhhhhhhhhho.',
                'ohhhhhhhhhh.....',
                'ohhhhhh.........',
                'ohhhh...........',
                '.ohhh...........',
                '..oo............',
            ]),
        ],
        'quiff' => [
            'front' => px_m(-1, -5, [
                '.....oooo',
                '...oohhhh',
                '..ohhhhhh',
                '..ohhhhhh',
                '.ohhhhhhh',
                '.ohhHhhhh',
                'oohhhhhhh',
                'ohhhh....',
                'ohh......',
            ]),
            'side' => px_l(0, -5, [
                '........ooooo...',
                '......oohhhhhoo.',
                '....oohhhhhhhhho',
                '..oohhhhhhhhhhho',
                '.ohhhhhhhhhhhhho',
                'ohhhhhhhhhhhhhho',
                'ohhhhhhhhhhhhho.',
                'ohhhhhhhhhh.....',
                'ohhhhhh.........',
                'ohhhh...........',
                '.ohhh...........',
                '..oo............',
            ]),
        ],
        'curly' => [
            'front' => px_m(-2, -5, [
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
            ]),
            'side' => px_l(-2, -5, [
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
            ]),
        ],
        'bun' => [
            'front' => px_m(-1, -6, [
                '.......oo',
                '......ohh',
                '......ohH',
                '...oooooo',
                '..ohhhhhh',
                '.ohhhhhhh',
                '.ohHhhhhh',
                'oohhhhhhh',
                '.ohh.....',
                '.oh......',
            ]),
            'side' => px_l(0, -5, [
                '.ooo............',
                'ohhho...........',
                'ohHho...........',
                '.oooooooooo.....',
                '..ohhhhhhhhoo...',
                '.ohhhhhhhhhhhho.',
                'ohhhhhhhhhhhhhho',
                'ohhhhhhhhhhhhho.',
                'ohhhhhhhhhh.....',
                'ohhhhhh.........',
                'ohhhh...........',
                '.ohhh...........',
                '..oo............',
            ]),
        ],
        'long' => [
            'front' => px_m(-1, -2, [
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
            ]),
            'side' => px_l(0, -2, [
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
            ]),
        ],
        'mullet' => [
            'front' => px_m(-1, -2, [
                '....ooooo',
                '...ohhhhh',
                '..ohhhhhh',
                '.ohhHhhhh',
                '.ohHhhhhh',
                '.ohhhh...',
                '.oh......',
                'ooh......',
                'ohh......',
                'ohh......',
                'ohh......',
                'ohhh.....',
                'ohhh.....',
                'oHho.....',
                '.oo......',
            ]),
            'side' => px_l(-1, -2, [
                '.....ooooooo.....',
                '...oohhhhhhhoo...',
                '..ohhhhhhhhhhhho.',
                '.ohhhhhhhhhhhhhho',
                '.ohhhhhhhhhhhhho.',
                '.ohhhhhhhhhh.....',
                '.ohhhhhh.........',
                'ohhhhh...........',
                'ohhhh............',
                'ohhhh............',
                'ohhhh............',
                'ohhhho...........',
                'ohHhho...........',
                'ohhhho...........',
                '.ohho............',
                '..oo.............',
            ]),
        ],
        'braids' => [
            'front' => px_m(-1, -2, [
                '...ooooo.',
                '..ohhhhhh',
                '.ohHhHhHh',
                'ohHhHhHhH',
                'ohhhhhhhh',
                'ohHhH....',
                'ohhh.....',
                'oHhH.....',
                'ohhh.....',
                'oHhH.....',
                'ohhh.....',
                'oHhH.....',
                'ohhh.....',
                'oyoy.....',
                '.o.o.....',
            ]),
            'side' => px_l(0, -2, [
                '....ooooooo.....',
                '..oohhhhhhhoo...',
                '.ohHhHhHhHhHhho.',
                'ohHhHhHhHhHhHhho',
                'ohhhhhhhhhhhhho.',
                'ohHhHhHhHhh.....',
                'ohhhhhh.........',
                'oHhHhh..........',
                'ohhhhh..........',
                'oHhHhh..........',
                'ohhhhh..........',
                'oHhHho..........',
                'ohhhho..........',
                'oyoyo...........',
                '.o.o............',
            ]),
        ],
        'mohawk' => [
            'front' => px_m(0, -6, [
                '......oo',
                '.....ohh',
                '.....ohh',
                '.....ohH',
                '.....ohh',
                '.....ohh',
                '....oohh',
                '......hh',
                '......hh',
            ]),
            'side' => px_l(0, -5, [
                '......oooo......',
                '....oohhhhoo....',
                '...ohhhhhhhhoo..',
                '..ohhHhhhhhhhho.',
                '..ohhhhhhhhhhho.',
                '...ohhhhhhhhh...',
            ]),
        ],
        // premi del Fanta (lib/fanta.php): non si comprano, si vincono arrivando nei primi della classifica
        // cresta ribelle: più alta della cresta normale, a tre punte
        'crest_punk' => [
            'front' => px_m(0, -10, [
                '......oo',
                '.....ohh',
                '......oh',
                '.....ohh',
                '....ohhh',
                '.....ohh',
                '....ohhH',
                '....ohhh',
                '...oohhh',
                '.....ohh',
                '.....ohh',
                '......hh',
                '......hh',
            ]),
            'side' => px_l(0, -6, [
                '....o...o...o...',
                '...oho.oho.oho..',
                '...ohhoohhoohho.',
                '..ohhhhhhhhhhho.',
                '..ohhHhhhhhhhho.',
                '..ohhhhhhhhhhho.',
                '...ohhhhhhhhh...',
            ]),
        ],
        // cresta d'oro: la cresta con le punte dorate (g/G sono l'oro, non il colore dei capelli)
        'crest_gold' => [
            'front' => px_m(0, -7, [
                '......oo',
                '.....ogg',
                '.....ogG',
                '.....ogg',
                '.....ohg',
                '.....ohh',
                '.....ohh',
                '....oohh',
                '......hh',
                '......hh',
            ]),
            'side' => px_l(0, -6, [
                '......oooo......',
                '....oogggGoo....',
                '...ohhggggghoo..',
                '..ohhHhhhhhhhho.',
                '..ohhhhhhhhhhho.',
                '...ohhhhhhhhh...',
            ]),
        ],
        // coda alta: come 'long', con una fascia (colore 'y', quello del copricapo anche a mani vuote) a legarla
        'ponytail' => [
            'front' => px_m(-1, -2, [
                '...ooooo.',
                '..ohhhhhh',
                '.ohhhhhhh',
                'ohhHhhhhh',
                'ohhhhhhhh',
                'ohhhhh...',
                'ohhh.....',
                'oyyy.....',
                'ohhh.....',
                'ohHh.....',
                'ohhh.....',
                'ohhh.....',
                'ohhh.....',
                '.oho.....',
                '..o......',
            ]),
            'side' => px_l(0, -2, [
                '....ooooooo.....',
                '..oohhhhhhhoo...',
                '.ohhhhhhhhhhhho.',
                'ohhhhhhhhhhhhhho',
                'ohhhhhhhhhhhhho.',
                'ohhhhhhhhhh.....',
                'ohhhhhh.........',
                'oyyyyy..........',
                'ohhhhh..........',
                'ohhHhh..........',
                'ohhhhh..........',
                'ohhhho..........',
                'ohhhho..........',
                '.ohho...........',
                '..oo............',
            ]),
        ],
        // afro: un grande volume tondo, staccato dalla testa
        'afro' => [
            'front' => px_m(-2, -8, [
                '....ooo',
                '..oohhho',
                '.ohhhhhho',
                'ohhhHhhho',
                'ohhhhhhho',
                'ohhhHhhho',
                'ohhhhhhho',
                '.ohhhhho',
                '..ohhho',
                '...ooo',
            ]),
            'side' => px_l(-1, -8, [
                '.......oooo......',
                '.....oohhhhoo....',
                '....ohhhhhhhhoo..',
                '...ohhhhhhhhhhho.',
                '..ohhhHhhhhhhhho.',
                '..ohhhhhhhhhhhho.',
                '...ohhhHhhhhhho..',
                '....ohhhhhhhho...',
                '.....oohhhhoo....',
                '.......oooo......',
            ]),
        ],
    ];
}

/**
 * Copricapi che non schiacciano i capelli: stanno sospesi sopra la testa, di lato o la cingono appena (aureola, bandiere, cuffie...),
 * così codino, ricci, afro e creste restano come sono.
 */
const PX_HATS_KEEP_HAIR = ['halo', 'ball', 'trophy', 'laurel', 'flowers', 'headphones', 'bandana',
    'flag', 'flag_h', 'flag_h2', 'flag_pt', 'flag_br'];
/** ... di questi, quelli sospesi o appoggiati in cima: con capelli alti salgono sopra i capelli invece di coprirli (px_hair_lift). */
const PX_HATS_FLOAT = ['halo', 'ball', 'trophy'];

/** Di quante righe un'acconciatura sale sopra il taglio classico, in una vista (0 se non sale). */
function px_hair_lift(string $style, string $view): int
{
    $top = function (?array $hair) use ($view): ?int {
        $d = $hair[$view] ?? ($hair['front'] ?? null);
        if (!$d) {
            return null;
        }
        foreach ($d[2] as $i => $row) {
            if (trim($row, '.') !== '') {
                return $d[1] + $i;
            }
        }
        return null;
    };
    $ref = $top(px_hair()['classic'] ?? null);
    $mine = $top(px_hair()[$style] ?? null);
    return $ref !== null && $mine !== null ? max(0, $ref - $mine) : 0;
}

/** Col copricapo i capelli alti non ci stanno sotto: restano quelli lunghi, il resto diventa un taglio corto (tranne PX_HATS_KEEP_HAIR). */
function px_hair_under_hat(string $style, ?string $hat = null): string
{
    if ($hat !== null && in_array($hat, PX_HATS_KEEP_HAIR, true)) {
        return $style;
    }
    return in_array($style, ['bald', 'buzz', 'long', 'mullet', 'braids', 'fade', 'classic', 'side', 'ponytail'], true)
        ? $style : (in_array($style, ['mohawk', 'crest_punk', 'crest_gold'], true) ? 'buzz' : 'classic');
}

/** Barbe (chiavi 'beard' di shop_items: none, stubble, moustache, goatee, captain, full). "1" è la barba di tre giorni. */
function px_beards(): array
{
    static $b = null;
    $moustache = ['front' => px_m(0, 8, ['.....hhh', '.....h.m']), 'side' => px_l(0, 8, ['..........hhhh..', '..........h.m...'])];
    $goatee = ['front' => px_m(0, 10, ['......hh', '......hh', '......hh', '......oo']),
        'side' => px_l(0, 10, ['...........hh...', '..........hhh...', '..........hhho..', '...........oo...'])];
    return $b ??= [
        'none' => [],
        'stubble' => [
            'front' => px_m(0, 8, ['..1.....', '.o11111m', '..o11111', '...oo111']),
            'side' => px_l(0, 8, ['.......11111....', '..o111111111mo..', '..o1111111111o..', '...oo111111oo...']),
        ],
        'moustache' => $moustache,
        'goatee' => $goatee,
        'captain' => [
            'front' => [0, 8, array_merge($moustache['front'][2], $goatee['front'][2])],
            'side' => [0, 8, array_merge($moustache['side'][2], $goatee['side'][2])],
        ],
        'full' => [
            'front' => px_m(0, 5, [
                '........',
                '.o......',
                '.oh.....',
                '.ohh....',
                '.ohhhhhm',
                '..ohhhhh',
                '...ohhhh',
                '.....ooo',
            ]),
            'side' => px_l(0, 5, [
                '................',
                '.........h......',
                '........hh......',
                '.......hhhhh....',
                '..ohhhhhhhhhmo..',
                '..ohhhhhhhhhho..',
                '...oohhhhhhoo...',
                '......oooooo....',
            ]),
        ],
    ];
}

/** Occhiali (chiavi 'glasses': none, round, sun, sport, stars, hearts, monocle). */
function px_glasses(): array
{
    static $g = null;
    return $g ??= [
        'none' => [],
        'round' => [
            'front' => px_m(0, 4, ['...oooo.', 'oooo..oo', '...o..o.', '...oooo.']),
            'side' => px_l(0, 4, ['..........oooo..', '.....oooooo..o..', '..........o..o..', '..........oooo..']),
        ],
        'sun' => [
            'front' => px_m(0, 4, ['........', 'oooooooo', '..oeeeoo', '...oeo..']),
            'side' => px_l(0, 4, ['................', '.......ooooooooo', '..........oeeeo.', '...........oeo..']),
        ],
        'sport' => [
            'front' => px_m(0, 4, ['.ooooooo', 'o333l333', '.o333333', '..oooooo']),
            'side' => px_l(0, 4, ['.....ooooooooo..', '.....oo33l3333o.', '..........33333o', '..........ooooo.']),
        ],
        'stars' => [
            'front' => px_m(0, 3, ['....o...', '...ogo..', 'ooogggoo', '...ogo..', '..o...o.']),
            'side' => px_l(0, 3, ['...........o....', '..........ogo...', '.....ooooogggo..', '..........ogo...', '.........o...o..']),
        ],
        'hearts' => [
            'front' => px_m(0, 4, ['..oo.oo.', 'oo44444o', '..o444o.', '...o4o..', '....o...']),
            'side' => px_l(0, 4, ['..........oo.oo.', '.....oooo44444o.', '..........o444o.', '...........o4o..', '............o...']),
        ],
        'monocle' => [
            'front' => px_l(0, 4, ['...oooo.........', '...o..o.........', '...o..o.........', '...oooo.........', '......g.........', '.......g........']),
            'side' => px_l(0, 4, ['..........oooo..', '..........o..o..', '..........o..o..', '..........oooo..', '..........g.....', '.........g......']),
        ],
    ];
}

/**
 * Copricapi pixel per modello (shop_items 'hat': tpl) coi tre colori del negozio in x, y, z. Di profilo, se manca una vista "side",
 * si usa la stessa di fronte (i cappelli sono quasi tutti simmetrici).
 */
function px_hats(): array
{
    static $h = null;
    if ($h !== null) {
        return $h;
    }
    $h = [
        'cap' => [
            'front' => px_m(-1, -4, ['....ooooo', '..ooxxxxx', '.oxxxxxxx', '.oxXxxxxy', 'oxxxxxxxy', 'oxxxxxxxx', 'ooooooooo', '.oXXXXXXX', '..ooooooo']),
            'side' => px_l(0, -4, ['....ooooooo.........', '..ooxxxxxxxoo.......', '.oxxxxxxxxxxxo......', 'oxxXxxxxxxxxxxo.....',
                'oxxxxxxxxxxxxxxooooo', 'oxxxxxxxxxxxxxXXXXXo', 'oooooooooooooooooooo']),
        ],
        'visor' => [
            'front' => px_m(-1, 0, ['..ooooooo', '.oxxxxxxx', 'oxxxxxxxx', 'oXXXXXXXX', '.oooooooo']),
            'side' => px_l(0, 0, ['...ooooooooo......', '..oxxxxxxxxxoooooo', '..oXXXXXXXXXXXXXXo', '...ooooooooooooo..']),
        ],
        'beanie' => ['front' => px_m(-1, -5, ['.......oo', '......ozz', '....oooZz', '..ooxxxxx', '.oxxxXxxx', '.oxXxxxxx', 'oxxxxxxxx',
            'oyyyyyyyy', 'oyyyyyyyy', 'ooooooooo'])],
        'bandana' => ['front' => px_m(-1, 1, ['.oooooooo', 'oxxxxyxxx', 'oxxxxxxxx', 'ooooooooo'])],
        'beret' => ['front' => px_l(-1, -3, ['.........o........', '....oooooxooooo...', '..ooxxxxxxxxxxxoo.', '.oxxxxxxxxxxxxxxxo',
            'oxxXxxxxxxxxxxxxxo', 'oXXXXXXXXXXXXXXXo.', '.oooooooooooooooo.'])],
        'bucket' => ['front' => px_m(-2, -3, ['.....ooooo', '....oxxxxx', '...oxxxxxx', '...oxxXxxx', '...oxxxxxx', '.ooooooooo', 'oyyyyyyyyy',
            '.ooooooooo'])],
        'pilot' => ['front' => px_m(-1, -2, ['....ooooo', '..ooxxxxx', '.oxxxxxxx', '.oxoooooo', 'oxoyyyyoo', 'oxooooooo', 'oxx......',
            'oxx......', 'oxx......', 'oxx......', '.oo......'])],
        'headphones' => ['front' => px_m(-1, -3, ['....ooooo', '..ooxxxxx', '.oxxooooo', '.oxo.....', 'oxo......', 'oxo......', 'oooo.....',
            'oyyo.....', 'oyyo.....', 'oyyo.....', 'oooo.....'])],
        'tophat' => ['front' => px_m(-2, -12, ['...ooooooo', '...oxxxxxx', '...oxXxxxx', '...oxxxxxx', '...oxxxxxx', '...oxxxxxx',
            '...oyyyyyy', '...oyyyyyy', '.ooooooooo', 'oxxxxxxxxx', '.ooooooooo'])],
        'bowler' => ['front' => px_m(-1, -6, ['.....oooo', '...ooxxxx', '..oxxxxxx', '..oxXxxxx', '..oxxxxxx', '..oyyyyyy', 'ooooooooo',
            'oxxxxxxxx', '.oooooooo'])],
        'fedora' => ['front' => px_m(-2, -6, ['.....ooooo', '....oxxxxX', '....oxxxxx', '....oxXxxx', '....oyyyyy', 'oooooooooo',
            'oxxxxxxxxx', '.ooooooooo'])],
        'cowboy' => ['front' => px_m(-4, -6, ['.......ooooo', '......oxxxxX', '......oxxxxx', '......oxXxxx', 'oo....oyyyyy',
            'oxo..ooooooo', '.oxooxxxxxxx', '..oxxxxxxxxx', '...ooooooooo'])],
        'sombrero' => ['front' => px_m(-6, -8, ['..........oooo', '.........oxxxx', '.........oxxxx', '........oxxXxx', '........ozzzzz',
            '..ooooooozzzzz', '.oxxxxxxxxxxxx', 'oxxyxxyxxyxxyx', 'oxxxxxxxxxxxxx', '.ooooooooooooo'])],
        'straw' => ['front' => px_m(-3, -5, ['......ooooo', '.....oxxxxx', '.....oxxXxx', '.....oyyyyy', 'ooooooooooo', 'oxxXxxxXxxx',
            '.oooooooooo'])],
        'party' => ['front' => px_m(3, -12, ['....o', '...oy', '...ox', '..oxx', '..oyy', '..oxx', '.oxxx', '.oyyy', '.oxxx', 'oxxxx',
            'oyyyy', 'ooooo'])],
        'wizard' => ['front' => px_l(-2, -14, ['............oo......', '..........ooxo......', '.........oxxo.......',
            '........oxxo........', '........oxxxo.......', '.......oxxyxo.......', '.......oxxxxxo......', '......oxyxxxxo......',
            '......oxxxxxyxo.....', '.....oxxxxxxxxo.....', '.....oxxxyxxxxxo....', '....ozzzzzzzzzzo....', 'oooozzzzzzzzzzzzoooo',
            'oxxxxxxxxxxxxxxxxxxo', '.oooooooooooooooooo.'])],
        'santa' => ['front' => px_l(-2, -8, ['.......oooooo.......', '.....ooxxxxxxoo.....', '....oxxxxxxxxxxo....',
            '...oxxxxxxxxxxxxoo..', '..oxxXxxxxxxxxxxxxo.', '..oxxxxxxxxxxxxxoyyo', '..oxxxxxxxxxxxxxoyyo', '.oyyyyyyyyyyyyyyooo.',
            'oyyyyyyyyyyyyyyyyo..', '.oooooooooooooooo...'])],
        'jester' => ['front' => px_m(-3, -8, ['oo.........', 'ozo........', '.oxo.......', '.oxxoo.....', '..oxxxo..oo', '..oxxxxooyy',
            '...oxxxxxyy', '...oxxxxyyy', '..ozzzzzzzz', '..ooooooooo'])],
        'pumpkin' => ['front' => px_m(-1, -8, ['........o', '.......oy', '....ooooy', '..ooxxXxx', '.oxxxXxxx', 'oxxxXxxxX', 'oxxxXxxxX',
            'oxxxXxxxX', '.oxxxXxxx', '..ooooooo'])],
        'flowers' => ['front' => px_m(-1, -2, ['.oo..oo..', 'oxxooyyoo', 'oxzxoyzyo', '.oxooxyxo', '..oo.oxo.'])],
        'laurel' => ['front' => px_m(-1, 0, ['...ox....', '..oxxo...', '.oxxo....', 'oxxo.....', 'oxo......', 'oxxo.....', '.oxo.....'])],
        'crown' => ['front' => px_m(1, -6, ['.o...o.', 'oxo.oxo', 'oxxoxxx', 'oxxxxxx', 'oxzxxzx', 'oxxxxxx', 'ooooooo'])],
        'halo' => ['front' => px_m(1, -8, ['..ooooo', '.oxxxxx', 'oxooooo', '.oxxxxx', '..ooooo'])],
        'trophy' => ['front' => px_m(1, -13, ['..ooooo', 'oooxxxx', 'oxoxxxx', 'oxoxXxx', '.ooxxxx', '...oxxx', '....oxx', '.....ox',
            '.....ox', '...oooo', '...oyyy', '..ooooo'])],
        'ball' => ['front' => px_l(4, -7, ['..ooo..', '.olelo.', 'olleelo', 'oellleo', 'olleelo', '.olllo.', '..ooo..'])],
        'flame' => ['front' => px_m(0, -9, ['......o.', '...o.oxo', '..oxoxxo', '..oxxxyx', '.oxxyxyx', '.oxyyyyy', 'oxxyyyyy', 'oxyyyyyy',
            'oxxyyyyy'])],
        'grad' => ['front' => px_m(-3, -5, ['....ooooooo', '.ooooxxxxxx', 'oxxxxxxxxxx', '.oooooooooo', '....oxxxxxx', '....oxxxxxx',
            '....ooooooo'])],
        'viking' => ['front' => px_m(-3, -7, ['o..........', 'zo.........', 'zzo...ooooo', '.zzoooxxxxx', '..ozzoxxXxx', '...oooxxxxx',
            '....oxxxxxx', '....oyyyyyy', '....ooooooo'])],
        'pirate' => ['front' => px_m(-3, -6, ['.......oooo', '.....ooxxxx', 'oo..oxxxxxx', 'oxxoxxxxxxy', '.oxxxxxxxxx', '..oyyyyyyyy',
            '...oooooooo'])],
        'chef' => ['front' => px_m(-1, -9, ['...oo.ooo', '..oxxoxxx', '.oxxxxxxx', '.oxxXxxxx', '.oxxxxxxx', '..oxxxxxx', '..oyyyyyy',
            '..oyyyyyy', '..ooooooo'])],
        'propeller' => ['front' => px_m(-1, -7, ['..oooooo.', '.oyyyyyyo', '..ooooooo', '.......oo', '...oooooo', '..oxxxxxx',
            '.oxxXxxxx', '.oxxxxxxx', 'ooooooooo'])],
        'military' => ['front' => px_m(-1, -4, ['....ooooo', '..ooxxxxx', '.oxxxxxxx', '.oxXxxxxx', 'oxxxxxxxx', 'oxxxxxxxx', 'oyyyyyyyy',
            'ooooooooo'])],
        'hardhat' => ['front' => px_m(-1, -4, ['....ooooo', '..ooxxxxy', '.oxxxxxxy', '.oxXxxxxy', '.oxxxxxxy', 'ooooooooo', 'oxxxxxxxx',
            '.oooooooo'])],
        'police' => ['front' => px_m(-1, -4, ['...oooooo', '.ooxxxxxx', 'oxxxxxxxx', 'oxxxxxxzz', 'oyyyyyyyy', 'ooooooooo', '.oyyyyyyy',
            '..ooooooo'])],
        'sailor' => ['front' => px_m(-1, -4, ['....ooooo', '..ooxxxxx', '.oxxxxxxx', 'oyyyyyyyy', 'oxxxxxxxx', 'ooooooooo'])],
        'astronaut' => ['front' => px_m(-2, -3, ['.....ooooo', '...ooxxxxx', '..oxxooooo', '.oxol.....', '.oxo......', 'oxo.......',
            'oxo.......', 'oxo.......', 'oxo.......', 'oxo.......', 'oxo.......', 'oxo.......', '.oxo......', '.oxxo.....', '..oyyyyyyy',
            '..oooooooo'])],
        'knight' => ['front' => px_m(-1, -6, ['......oo.', '.....oyyo', '.....oyyo', '...oooooo', '..oxxxxxx', '.oxxxxxxx', '.oxXxxxxx',
            'oxxxxxxxx', 'oxxxxxxxx', 'oxxxxxxxx', 'oxxoooooo', 'oxxeeeeee', 'oxxxxxxxx', 'oxxxxxxxx', 'oxXxxxxxx', 'oxxxxxxxx',
            '.oxxxxxxx', '..oxxxxxx', '...oooooo'])],
        'turban' => ['front' => px_m(-1, -5, ['....ooooo', '..ooxxxxx', '.oxxXxxxx', 'oxxxxXxxx', 'oxXxxxXxx', 'oxxXxxxyy', 'oxxxXxxyy',
            'ooooooooo'])],
        'kippah' => ['front' => px_m(3, -2, ['..ooo', '.oxxx', 'oxXxx', '.oooo'])],
        'fez' => ['front' => px_m(3, -6, ['.oooo', 'oxxxx', 'oxxxx', 'oxXxx', 'oxxxx', 'ooooo'])],
        // bandiere (lib/shop_items.php 'f_'): un'asta accanto alla testa con la bandierina, i colori della nazione nell'ordine giusto
        'flag' => ['front' => px_l(15, -12, ['.oo...........', 'obbooooooooooo', 'obboxxxyyyzzzo', 'obboxxxyyyzzzo', 'obboxxxyyyzzzo', 'obboxxxyyyzzzo', 'obboxxxyyyzzzo', 'obbooooooooooo', 'obbo', 'obbo', 'obbo', 'obbo', 'obbo', '.oo.'])],
        'flag_h' => ['front' => px_l(15, -12, ['.oo...........', 'obbooooooooooo', 'obboxxxxxxxxxo', 'obboxxxxxxxxxo', 'obboyyyyyyyyyo', 'obboyyyyyyyyyo', 'obbozzzzzzzzzo', 'obbozzzzzzzzzo', 'obbooooooooooo', 'obbo', 'obbo', 'obbo', 'obbo', 'obbo', '.oo.'])],
        'flag_h2' => ['front' => px_l(15, -12, ['.oo...........', 'obbooooooooooo', 'obboxxxxxxxxxo', 'obboyyyyyyyyyo', 'obboyyyyyyyyyo', 'obboyyyyyyyyyo', 'obboxxxxxxxxxo', 'obbooooooooooo', 'obbo', 'obbo', 'obbo', 'obbo', 'obbo', '.oo.'])],
        'flag_pt' => ['front' => px_l(15, -12, ['.oo...........', 'obbooooooooooo', 'obboxxxxyyyyyo', 'obboxxxxyyyyyo', 'obboxxxgyyyyyo', 'obboxxxxyyyyyo', 'obboxxxxyyyyyo', 'obbooooooooooo', 'obbo', 'obbo', 'obbo', 'obbo', 'obbo', '.oo.'])],
        'flag_br' => ['front' => px_l(15, -12, ['.oo...........', 'obbooooooooooo', 'obboxxxxyxxxxo', 'obboxxxyyyxxxo', 'obboxxyyzyyxxo', 'obboxxxyyyxxxo', 'obboxxxxyxxxxo', 'obbooooooooooo', 'obbo', 'obbo', 'obbo', 'obbo', 'obbo', '.oo.'])],
        'captain' => ['front' => px_m(-1, -4, ['....ooooo', '..ooxxxxx', '.oxxxxxxx', 'oxxXxxxxx', 'oyyyyyyyy', 'ooooooooo', '.oeeeeeee',
            '..ooooooo'])],
        'fireman' => ['front' => px_m(-2, -5, ['......oooo', '....ooxxxx', '...oxxxxoo', '..oxxXxoyy', '..oxxxxoyy', '.oxxxxxxoo', 'oxxxxxxxxx',
            'oooooooooo', 'oxxxxxxxxx', '.ooooooooo'])],
        'cone' => ['front' => px_m(3, -12, ['....o', '...ox', '...ol', '..oxx', '..oll', '..oxx', '.oxxx', '.olll', '.oxxx', 'oyyyy', 'ooooo'])],
        'catears' => ['front' => px_m(0, -4, ['..o.....', '.oxo....', '.oyxo...', 'oyyxo...', 'oxxxo...'])],
        'rabbit' => ['front' => px_m(0, -10, ['...oo...', '..oxxo..', '..oyxo..', '..oyxo..', '..oyxo..', '..oyxo..', '..oyxo..',
            '..oxxo..', '...oxo..'])],
        'bear' => ['front' => px_m(0, -3, ['.ooo....', 'oxxxo...', 'oxyxo...', '.oxo....'])],
        'antlers' => ['front' => px_m(0, -8, ['o..o....', 'xo.xo...', 'oxoxo...', '.oxxo...', '..oxo...', '..oxo...', '...oo...'])],
        'devil' => ['front' => px_m(0, -4, ['..o.....', '.oxo....', '.oxxo...', '..oxxo..'])],
        'unicorn' => ['front' => px_m(3, -9, ['....o', '...ox', '...oy', '..oxx', '..oyy', '..oxx', '.oyyy', '.oxxx', 'ooooo'])],
        'alien' => ['front' => px_m(0, -8, ['.ooo....', '.oxo....', '.ooo....', '...o....', '...o....', '....o...'])],
        'mushroom' => ['front' => px_m(-2, -7, ['.....ooooo', '...ooxxxxx', '..oxxyyxxx', '.oxxxyyxxy', '.oxxxxxxxy', 'oxyyxxxxxx',
            'oxyyxxxxxx', 'oooooooooo'])],
        'icecream' => ['front' => px_m(3, -14, ['..ooo', '.oxxx', 'oxxXx', 'oxxxx', 'oxXxx', '.oooo', '.oyyy', '.oyyy', '..oyy', '..oyy',
            '...oy', '...oo'])],
    ];
    $h['fireman'] = $h['hardhat'];
    $h['captain'] = $h['police'];
    return $h;
}

/** Pet a bordo campo (chiavi 'pet'): due fotogrammi (il secondo saltella), u = primo colore, i = secondo. */
function px_pets(): array
{
    return [
        'ball' => ['..ooo..', '.olelo.', 'olleelo', 'oellleo', 'olleelo', '.olllo.', '..ooo..'],
        'chick' => ['...ooo...', '..ouuuo..', '.ouuueuo.', '.ouuuuiio', 'ouuuuuuo.', 'ouUuuuuo.', '.ouuuuo..', '..oioio..'],
        'cat' => ['.o...o.....', 'ouo.ouo....', 'ouuuuuo....', 'oueuueo....', 'ouuiuuo..o.', '.ouuuo..ouo', '.ouiiuoouo.', '.ouiiuuuo..',
            '.ouuouuo...', '..oo.oo....'],
        'dog' => ['..oooo......', '.ouuuuo.....', 'oiueuuio....', 'oiuuuuio....', '.ouiieo.....', '.ouuuuo..o..', 'ouuiiuuoouo.',
            'ouuiiuuuuo..', 'ouuouuouo...', '.oo.oo.o....'],
        'pogona' => ['...o.o.o.o.....', '.ooouuuuuuoo...', 'ouuuuuuuuuuuoo.', 'oeuuuuuuuuuuuoo', 'oiiuuuuuuuuuuo.', '.oiioouuuuuoo..',
            '..oo.oo.oo.oo..'],
        'penguin' => ['..ooooo..', '.ouuuuuo.', '.ouieieuo', '.ouiggiuo', 'ouuiiiuuo', 'ouiiiiiuo', 'ouiiiiiuo', '.ouiiiuo.', '..oiiio..',
            '.oggoggo.'],
    ];
}

/** Effetti e oggetti delle esultanze: [x, y, righe] in coordinate dello sprite. */
function px_fx(): array
{
    return [
        'd1' => [4, 52, ['........dd.......dd', '.......dqqd.....dqqd', '......dqqqqd...dqqqqd', '.......dddd.....dddd']],
        'd2' => [0, 50, ['........dd.............dd', '.......dqqd...........dqqd', '.......dqqd...........dqqd',
            '........dd.............dd', '...........d..........d']],
        'd3' => [-3, 48, ['.......d...................d', '......dqd.................dqd', '.......d...................d']],
        'heart' => [11, -4, ['.oo.oo.', 'orrorro', 'orrrrlo', '.orrro.', '..oro..', '...o...']],
        'heart2' => [9, -8, ['..oo.oo..', '.orrorro.', 'orrrrrrlo', 'orrrrrrro', '.orrrrro.', '..orrro..', '...oro...', '....o....']],
        'flash' => [-2, 8, ['..l..', '.lll.', 'lllll', '.lll.', '..l..']],
        'muzzle1' => [30, 33, ['.g.g', 'gggg', '.gg.', 'g..g']],
        'muzzle2' => [31, 34, ['g.g', '.g.', 'g.g']],
        'bow' => [30, 19, ['.oo', 'obo', 'obo', '.obo', '.obo', '.obo', '.obo', '.obo', '.obo', '.obo', '.obo', '.obo', 'obo', 'obo', '.oo']],
        'arrow1' => [14, 25, ['oooooooooooooooooo.', 'ovvvvvvvvvvvvvvvvvo', 'oooooooooooooooooo.']],
        'arrow2' => [30, 21, ['oooooooo..', 'obbbbbbbvo', 'oooooooo..']],
        'arrow3' => [36, 17, ['oooo', 'obbo', 'oooo']],
        'phone' => [2, 12, ['oooo', 'oeeo', 'o3eo', 'oeeo', 'oooo']],
        'flag' => [-7, 26, ['oooooo', 'orrrrlo', 'orrrrlo', 'orrrro.', 'ooooo..', 'o.', 'o.', 'o.', 'o.', 'o.', 'o.', 'o.', 'o.', 'o.', 'o.',
            'o.', 'o.', 'o.', 'o.', 'o.', 'o.', 'o.', 'o.', 'o.', 'o.', 'o.', 'o.', 'o.', 'o.', 'oo']],
        // premi del Fanta: la coppa alzata sopra la testa (esultanza «Alza la coppa»)
        'cup' => [9, -12, [
            '..oooooooooooo..',
            '.oggggggggggggGo',
            'oooggglgggggGooo',
            'ogoggglggggggoGo',
            'ogoogglgggggGoGo',
            '.ooogggggggGooo.',
            '...oogggggGGo...',
            '.....ogggGo.....',
            '......oggo......',
            '......oGGo......',
            '....ooggggoo....',
            '....obbbbbbo....',
            '....oooooooo....',
        ]],
        'cup2' => [9, -14, [
            '..oooooooooooo..',
            '.oggggggggggggGo',
            'oooggglgggggGooo',
            'ogoggglggggggoGo',
            'ogoogglgggggGoGo',
            '.ooogggggggGooo.',
            '...oogggggGGo...',
            '.....ogggGo.....',
            '......oggo......',
            '......oGGo......',
            '....ooggggoo....',
            '....obbbbbbo....',
            '....oooooooo....',
        ]],
        // effetti delle varianti (px_celebration_mods): fuochi d'artificio, stelle, fulmini, KOIN, cuori
        'fw1' => [-6, -8, [
            '..........................................',
            '....g..........................r..........',
            '...ggg........................rrr.........',
            '....g..........................r..........',
        ]],
        'fw2' => [-7, -8, [
            '...g..g..g......................r..r..r...',
            '....g.g.g........................r.r.r....',
            '..gg.ggg.gg....................rr.rrr.rr..',
            '....g.g.g........................r.r.r....',
            '...g..g..g......................r..r..r...',
            '...........................3..............',
            '..........................333.............',
            '...........................3..............',
        ]],
        'fw3' => [-8, -8, [
            '..g.....g.....g................r.....r...',
            '.........................................',
            '.g...........g................r.......r..',
            '............................3..3..3......',
            '..g.....g.....g................r.....r...',
            '............................33.3.33......',
            '..........................3...3...3......',
            '............................33.3.33......',
            '............................3..3..3......',
        ]],
        'st1' => [-6, -6, ['.o..........................o..', 'olo........................olo.', '.o...........o..............o..',
            '............olo.................', '.............o..................']],
        'st2' => [-4, -9, ['......o...................o.....', '.....ogo.................ogo....', 'oo..ogggo..............oogggoo..',
            'ogooogggooo...........ooggggggoo', '.oggggggggo............oggggggo.', '..ogggggo...............ogggo...', '..ogo.ogo..............ogo.ogo..',
            '..oo...oo..............oo...oo..']],
        'bo1' => [26, -8, ['..oooo', '.oggo.', '.ogo..', 'oggooo', 'ogggo.', '.oogo.', '..ogo.', '..oo..', '..o...']],
        'bo2' => [-6, -10, ['...oooo', '..oggo.', '..ogo..', '.oggooo', '.ogggo.', '..oogo.', '...ogo.', '...oo..', '...o...']],
        'co1' => [-4, -8, ['.ooo..........ooo..........ooo..', 'oggGo........oggGo........oggGo.', 'oggGo........oggGo........oggGo.',
            '.ooo..........ooo..........ooo..']],
        'co2' => [-7, -2, ['......ooo..........ooo..........ooo', '.....oggGo........oggGo........oggGo', '.....oggGo........oggGo........oggGo',
            '......ooo..........ooo..........ooo']],
        'co3' => [-4, 8, ['.ooo..........ooo..........ooo..', 'oggGo........oggGo........oggGo.', 'oggGo........oggGo........oggGo.',
            '.ooo..........ooo..........ooo..']],
        'he1' => [-6, -8, ['.oo.oo..................oo.oo.', 'o44o44o................o44o44o', 'o44444o................o44444o',
            '.o444o..................o444o.', '..o4o....................o4o..', '...o......................o...']],
        'he2' => [-3, 2, ['.oo.oo..................oo.oo.', 'orrorro................orrorro', 'orrrrro................orrrrro',
            '.orrro..................orrro.', '..oro....................oro..', '...o......................o...']],
        'note' => [26, 6, ['...oo', '..ogo', '..oo.', '..o..', 'ooo..', 'ogo..', 'oo...']],
        'z' => [24, 4, ['oooo', '..o.', '.o..', 'oooo']],
    ];
}
