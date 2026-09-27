<?php
/*
 * Personaggio: il giocatore disegnato a figura intera, in SVG e nello stile fumetto del sito (contorni scuri spessi, colori pieni),
 * come i copricapi di lib/hats.php, che infatti gli si mettono in testa così come sono.
 *
 * Cosa indossa sta nella colonna players.avatar_look (JSON: una chiave del catalogo per tipo, vedi avatar_defaults()); il copricapo è
 * invece quello del profilo (players.hat_key), così comprato o indossato qui o nel Negozio si vede in tutti e due i posti.
 * Catalogo e prezzi in lib/shop_items.php, acquisti in lib/shop.php. I colori arrivano all'SVG come variabili CSS (--av-skin, ...)
 * e le classi dei pezzi (f-sk, o, ...) stanno in assets/style.css, insieme alle animazioni di pose ed esultanze.
 */

function avatar_kinds(): array
{
    return ['hair' => 'Capelli', 'hair_color' => 'Colore capelli', 'skin' => 'Carnagione', 'beard' => 'Barba', 'glasses' => 'Occhiali',
        'hat' => 'Copricapi', 'jersey' => 'Maglie', 'shorts' => 'Pantaloncini', 'shoes' => 'Scarpette', 'pet' => 'Pet',
        'pose' => 'Pose', 'celebration' => 'Esultanze'];
}

/** Cosa indossa chi non ha ancora scelto niente (tutto gratis). Il copricapo non c'è: sta in players.hat_key. */
function avatar_defaults(): array
{
    return ['hair' => 'ha_classico', 'hair_color' => 'hc_castano', 'skin' => 'sk_3', 'beard' => 'be_nessuna', 'glasses' => 'gl_nessuno',
        'jersey' => 'j_casa', 'shorts' => 'p_bianchi', 'shoes' => 's_nere', 'pet' => 'pe_nessuno', 'pose' => 'po_riposo',
        'celebration' => 'c_pugno'];
}

function avatar_patterns(): array
{
    return ['solid' => 'Tinta unita', 'stripes_v' => 'Strisce verticali', 'stripes_h' => 'Strisce orizzontali', 'halves' => 'A metà',
        'sleeves' => 'Maniche a contrasto', 'sash' => 'Fascia diagonale'];
}

/** Rarità (solo un'etichetta, dal prezzo): base = gratis. */
function avatar_rarity(?int $price): array
{
    $p = (int) $price;
    return match (true) {
        $p <= 0 => ['base', 'Base'],
        $p < 100 => ['comune', 'Comune'],
        $p < 250 => ['raro', 'Raro'],
        $p < 500 => ['epico', 'Epico'],
        default => ['leggendario', 'Leggendario'],
    };
}

/** Cosa indossa adesso: una chiave valida del catalogo per ogni tipo (quelle sparite dal catalogo tornano al valore di base). */
function avatar_look(array $p): array
{
    $saved = json_decode((string) ($p['avatar_look'] ?? ''), true);
    $look = [];
    foreach (avatar_defaults() as $kind => $def) {
        $k = is_array($saved) && is_string($saved[$kind] ?? null) ? $saved[$kind] : $def;
        $look[$kind] = shop_item($kind, $k) ? $k : $def;
    }
    $look['hat'] = shop_worn($p, 'hat');
    return $look;
}

/** Indossa (o, con $key = null, torna al valore di base) un oggetto del personaggio. Il copricapo va in hat_key come nel Negozio. */
function avatar_set(int $playerId, string $kind, ?string $key): void
{
    $saved = json_decode((string) q('SELECT avatar_look FROM players WHERE id = ?', [$playerId])->fetchColumn(), true);
    $saved = is_array($saved) ? $saved : [];
    if ($key === null || $key === avatar_defaults()[$kind]) {
        unset($saved[$kind]);
    } else {
        $saved[$kind] = $key;
    }
    q('UPDATE players SET avatar_look = ? WHERE id = ?', [$saved ? json_encode($saved) : null, $playerId]);
}

/* ---------------------------------------------------------------- disegno */

/** Angoli delle braccia (sinistro, destro) per ogni posa: positivo = il sinistro si alza verso l'esterno, negativo il destro. */
function avatar_pose_angles(string $pose): array
{
    return ['rest' => [0, 0], 'wave' => [0, -140], 'up' => [140, -140], 'point' => [0, -95], 'open' => [50, -50],
        'victory' => [120, -120]][$pose] ?? [0, 0];
}

/**
 * Capelli: 'back' sta dietro la testa (e le spalle), 'front' sopra. Con un copricapo le acconciature alte ('tall') usano il davanti
 * del taglio classico, sennò bucherebbero il cappello; il codino non ha dietro.
 */
function avatar_hair_styles(): array
{
    $classic = '<path class="f-ha o" d="M33 46 C31 24 44 13 60 13 C77 13 90 24 87 46 C84 38 79 33 72 32 C66 36 58 36 52 32 C44 33 37 38 33 46 Z"/>'
        . '<path d="M47 32 Q49.5 26.5 54 23 M59.5 35 Q61 28.5 66 25 M71 32.5 Q73 28.5 77.5 27" fill="none" stroke="#1f1a2e" stroke-width="1.3" opacity=".28" stroke-linecap="round"/>';
    $buzz = '<path class="f-ha" opacity=".55" d="M34.5 41 C35.5 23 47 17.5 60 17.5 C73 17.5 84.5 23 85.5 41 C78 32 70 29.5 60 29.5 C50 29.5 42 32 34.5 41 Z"/>';
    $cloud = function (array $circles, float $pad): string {
        $ink = $fill = '';
        foreach ($circles as [$x, $y, $r]) {
            $ink .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . ($r + $pad) . '"/>';
            $fill .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . $r . '"/>';
        }
        return '<g fill="#1f1a2e">' . $ink . '</g><g class="f-ha">' . $fill . '</g>';
    };
    return [
        'classic' => ['front' => $classic],
        'buzz' => ['front' => $buzz],
        'bald' => [],
        'side' => ['front' => '<path class="f-ha o" d="M33 47 C30 24 44 12 62 13 C79 14 90 26 87 46 C85 36 80 31 70 29 C58 27 44 32 33 47 Z"/>'
            . '<path d="M51 15.5 Q46 21 45 29" fill="none" stroke="#1f1a2e" stroke-width="2" stroke-linecap="round"/>'],
        'fade' => ['front' => $buzz . '<path class="f-ha o" d="M39 33 C38 16 50 9.5 61 9.5 C73 9.5 83 16 81.5 32 C76 27.5 68 25.5 60 26 C52 25.5 44 28 39 33 Z"/>'],
        'spiky' => ['tall' => true, 'front' => '<path class="f-ha o" d="M33 44 L30 30 L39 32 L38 18 L48 23 L52 10 L60 19 L68 10 L72 23 L82 18 L81 32 L90 30 L87 44 C82 36 74 32 60 33 C46 32 38 36 33 44 Z"/>'],
        'quiff' => ['tall' => true, 'front' => $classic . '<path class="f-ha o" d="M42 29 C40 12 58 1 82 7 C74 9 71 15 73 24 C64 21 52 23 42 29 Z"/>'],
        'curly' => ['tall' => true,
            'back' => $cloud([[35, 44, 11], [36, 28, 11], [46, 16, 12], [60, 11, 12], [74, 16, 12], [84, 28, 11], [85, 44, 11]], 3),
            'front' => $cloud([[45, 29, 7.5], [54, 25, 8], [64, 25, 8], [73, 29, 7.5]], 2.5)],
        'bun' => ['tall' => true, 'back' => '<circle class="f-ha o" cx="60" cy="12" r="9"/>',
            'front' => $classic . '<path d="M53 20.5 Q60 23.5 67 20.5" fill="none" stroke="#1f1a2e" stroke-width="2.4" stroke-linecap="round"/>'],
        'long' => ['back' => '<path class="f-ha o" d="M31 46 C29 20 44 11 60 11 C76 11 91 20 89 46 L90 84 Q84 90 77 84 L76 62 H44 L43 84 Q36 90 30 84 Z"/>',
            'front' => '<path class="f-ha o" d="M33 48 C31 24 44 13 60 13 C77 13 90 24 87 48 C82 36 74 32 64 31 C56 34 46 40 33 48 Z"/>'],
        'mullet' => ['back' => '<path class="f-ha o" d="M36 50 L34 78 Q42 84 50 76 L70 76 Q78 84 86 78 L84 50 Z"/>', 'front' => $classic],
        'braids' => ['back' => '<g class="f-ha o"><rect x="29" y="34" width="8" height="46" rx="4"/><rect x="38" y="44" width="7" height="40" rx="3.5"/>'
            . '<rect x="75" y="44" width="7" height="40" rx="3.5"/><rect x="83" y="34" width="8" height="46" rx="4"/></g>',
            'front' => '<path class="f-ha o" d="M33 44 C32 22 45 13 60 13 C75 13 88 22 87 44 C82 36 74 31 60 31 C46 31 38 36 33 44 Z"/>'
                . '<path d="M45 16 L42 34 M53 13.5 L51 31 M60 13 V31 M67 13.5 L69 31 M75 16 L78 34" stroke="#1f1a2e" stroke-width="1.6" opacity=".45"/>'],
        'mohawk' => ['tall' => true, 'front' => $buzz . '<path class="f-ha o" d="M51 31 L48 8 L55 13 L60 1 L65 13 L72 8 L69 31 Q60 27 51 31 Z"/>'],
        '_classic' => ['front' => $classic],
    ];
}

/** Barba e baffi (colore dei capelli): 'under' va sotto la bocca, 'over' sopra. */
function avatar_beards(): array
{
    $mous = '<path class="f-ha o o2" d="M51.5 55.5 Q55 51 60 54 Q65 51 68.5 55.5 Q64.5 57.5 60 56.3 Q55.5 57.5 51.5 55.5 Z"/>';
    $goat = '<path class="f-ha o o2" d="M55.5 62 Q60 60.5 64.5 62 Q64 69 60 70.5 Q56 69 55.5 62 Z"/>';
    return [
        'none' => [],
        'stubble' => ['under' => '<path class="f-ha" opacity=".3" d="M37 52 Q40 69 60 70.5 Q80 69 83 52 Q78 61 70 62.5 Q60 65.5 50 62.5 Q42 61 37 52 Z"/>'],
        'moustache' => ['over' => $mous],
        'goatee' => ['under' => $goat],
        'captain' => ['under' => $goat, 'over' => $mous],
        'full' => ['under' => '<path class="f-ha o" d="M35 48 Q35 73 60 75 Q85 73 85 48 Q81 58 72 60.5 Q66 58 60 58.5 Q54 58 48 60.5 Q39 58 35 48 Z"/>', 'over' => $mous],
    ];
}

function avatar_star(float $cx, float $cy, float $r): string
{
    $pts = [];
    for ($i = 0; $i < 10; $i++) {
        $a = -M_PI / 2 + $i * M_PI / 5;
        $rr = $i % 2 ? $r * 0.45 : $r;
        $pts[] = round($cx + cos($a) * $rr, 1) . ',' . round($cy + sin($a) * $rr, 1);
    }
    return '<polygon points="' . implode(' ', $pts) . '"/>';
}

function avatar_glasses(): array
{
    $temples = '<path d="M44.2 45 L35.5 43 M75.8 45 L84.5 43" fill="none" stroke="#1f1a2e" stroke-width="2.4" stroke-linecap="round"/>';
    $heart = fn($x) => '<path d="M' . $x . ' 50.5 C' . ($x - 9) . ' 44 ' . ($x - 7) . ' 37.5 ' . $x . ' 42 C' . ($x + 7) . ' 37.5 ' . ($x + 9) . ' 44 ' . $x . ' 50.5 Z"/>';
    return [
        'none' => '',
        'round' => '<g fill="#fff" fill-opacity=".3" stroke="#1f1a2e" stroke-width="2.4"><circle cx="51" cy="46.5" r="6.8"/><circle cx="69" cy="46.5" r="6.8"/></g>'
            . '<path d="M57.8 46 Q60 44 62.2 46" fill="none" stroke="#1f1a2e" stroke-width="2.4"/>' . $temples,
        'sun' => '<path d="M43 41 H58 V47 Q58 53 50.5 53 Q43 53 43 47 Z M62 41 H77 V47 Q77 53 69.5 53 Q62 53 62 47 Z" fill="#1f1a2e"/>'
            . '<path d="M58 43 H62 M43 42 L35 41 M77 42 L85 41" stroke="#1f1a2e" stroke-width="2.4" stroke-linecap="round"/>'
            . '<path d="M46 44.5 H50 M65 44.5 H69" stroke="#fff" stroke-width="1.8" stroke-linecap="round" opacity=".8"/>',
        'sport' => '<path d="M37 43 Q60 36.5 83 43 L82 51 Q60 45.5 38 51 Z" fill="#ff8c42" fill-opacity=".92" stroke="#1f1a2e" stroke-width="2.4" stroke-linejoin="round"/>'
            . '<path d="M44 45 Q60 40.5 76 45" stroke="#fff" stroke-width="1.8" fill="none" opacity=".75" stroke-linecap="round"/>',
        'stars' => '<g fill="#ffd23f" stroke="#1f1a2e" stroke-width="2" stroke-linejoin="round">' . avatar_star(51, 46.5, 9) . avatar_star(69, 46.5, 9) . '</g>'
            . '<path d="M58.5 46 H61.5" stroke="#1f1a2e" stroke-width="2.4"/>' . $temples,
        'hearts' => '<g fill="#ff6b9a" stroke="#1f1a2e" stroke-width="2" stroke-linejoin="round">' . $heart(51) . $heart(69) . '</g>'
            . '<path d="M58.5 45 H61.5" stroke="#1f1a2e" stroke-width="2.4"/>' . $temples,
        'monocle' => '<circle cx="69" cy="46.5" r="7.5" fill="#fff" fill-opacity=".3" stroke="#c9a227" stroke-width="2.6"/>'
            . '<path d="M75.5 50.5 Q84 62 78 76" fill="none" stroke="#c9a227" stroke-width="1.5"/>',
    ];
}

/** Animaletti (disegnati in un riquadro 32 x 34, a terra accanto ai piedi): colori --av-pa e --av-pb. */
function avatar_pets(): array
{
    $eye = fn($x, $y) => '<ellipse cx="' . $x . '" cy="' . $y . '" rx="1.5" ry="2" fill="#1f1a2e"/>';
    return [
        'none' => '',
        'ball' => '<ellipse cx="10" cy="32" rx="4" ry="2" fill="#ff5a5f" class="o o2"/><ellipse cx="22" cy="32" rx="4" ry="2" fill="#ff5a5f" class="o o2"/>'
            . '<circle cx="16" cy="19" r="12" fill="#fff" class="o"/>'
            . '<path d="M16 8.5 L19.5 11 L18 14.5 H14 L12.5 11 Z M5.5 21 L8.5 18.5 L10.5 21 L9.5 25 L6.5 25.5 Z M26.5 21 L23.5 18.5 L21.5 21 L22.5 25 L25.5 25.5 Z" fill="#1f1a2e"/>'
            . $eye(13, 19) . $eye(19, 19) . '<path d="M13.5 23.5 Q16 25.5 18.5 23.5" fill="none" stroke="#1f1a2e" stroke-width="1.6" stroke-linecap="round"/>',
        'chick' => '<path d="M12 30 V34 M20 30 V34" stroke="#ff8c42" stroke-width="2.4" stroke-linecap="round"/>'
            . '<path d="M15 10 Q13 4 17 5 Q15.5 7.5 18 9" fill="none" class="o o2"/>'
            . '<circle cx="16" cy="21" r="11" class="f-pa o"/><path d="M22 19.5 L28.5 21.5 L22 23.5 Z" fill="#ff8c42" class="o o2"/>'
            . '<path d="M8.5 21 Q12 15.5 16 21 Q12 25.5 8.5 21 Z" class="f-pb o o2"/>' . $eye(19, 17.5),
        'cat' => '<path d="M6 27 Q-2 20 3 11" fill="none" stroke="#1f1a2e" stroke-width="7" stroke-linecap="round"/>'
            . '<path d="M6 27 Q-2 20 3 11" fill="none" class="s-pa" stroke-width="3.5" stroke-linecap="round"/>'
            . '<ellipse cx="14" cy="26" rx="10" ry="7" class="f-pa o"/><ellipse cx="9" cy="32" rx="3" ry="2" class="f-pb o o2"/><ellipse cx="18" cy="32" rx="3" ry="2" class="f-pb o o2"/>'
            . '<path d="M13.5 12 L14 3.5 L19.5 8.5 Z M21 8.5 L26.5 3.5 L27 12 Z" class="f-pa o o2"/>'
            . '<circle cx="20" cy="15" r="8" class="f-pa o"/>' . $eye(17, 15) . $eye(23, 15)
            . '<path d="M19 18 H21 L20 19.3 Z" fill="#ff6b9a"/><path d="M13 18 L8 17 M13 19.5 L8 20.5 M27 18 L32 17 M27 19.5 L32 20.5" stroke="#1f1a2e" stroke-width="1"/>',
        'dog' => '<path d="M5 22 Q0 16 4 12" fill="none" stroke="#1f1a2e" stroke-width="7" stroke-linecap="round"/>'
            . '<path d="M5 22 Q0 16 4 12" fill="none" class="s-pa" stroke-width="3.5" stroke-linecap="round"/>'
            . '<ellipse cx="13" cy="25" rx="10" ry="7" class="f-pa o"/><ellipse cx="8" cy="32" rx="3" ry="2" class="f-pb o o2"/><ellipse cx="18" cy="32" rx="3" ry="2" class="f-pb o o2"/>'
            . '<circle cx="21" cy="14" r="8.5" class="f-pa o"/><path d="M14 9 Q10 14 13 21 Q17 18 16.5 11 Z M28 9 Q32 14 29 21 Q25 18 25.5 11 Z" class="f-pb o o2"/>'
            . '<ellipse cx="21" cy="18.5" rx="4.5" ry="3.5" class="f-pb o o2"/><ellipse cx="21" cy="16.8" rx="1.8" ry="1.3" fill="#1f1a2e"/>'
            . $eye(18, 12.5) . $eye(24, 12.5) . '<path d="M21 21 Q21 24.5 22.5 24.5 Q24 24.5 23.5 21.5" fill="#ff6b9a" class="o o2"/>',
        'penguin' => '<ellipse cx="11" cy="32.5" rx="4" ry="1.8" fill="#ff8c42" class="o o2"/><ellipse cx="21" cy="32.5" rx="4" ry="1.8" fill="#ff8c42" class="o o2"/>'
            . '<ellipse cx="16" cy="19" rx="11" ry="13" class="f-pa o"/><ellipse cx="16" cy="22" rx="7" ry="9" class="f-pb"/>'
            . '<path d="M5.5 17 Q1 24 5 28 M26.5 17 Q31 24 27 28" fill="none" stroke="#1f1a2e" stroke-width="3" stroke-linecap="round"/>'
            . '<circle cx="12.5" cy="12" r="2.6" fill="#fff"/><circle cx="19.5" cy="12" r="2.6" fill="#fff"/>' . $eye(12.8, 12.2) . $eye(19.2, 12.2)
            . '<path d="M14 15.5 H18 L16 18.5 Z" fill="#ff8c42" class="o o2"/>',
    ];
}

/** Il disegno del motivo della maglia (colore --av-sb), ritagliato sulla forma del busto. */
function avatar_pattern_shapes(string $pattern): string
{
    return [
        'stripes_v' => '<rect x="44" y="60" width="6" height="56"/><rect x="57" y="60" width="6" height="56"/><rect x="70" y="60" width="6" height="56"/>',
        'stripes_h' => '<rect x="30" y="80" width="60" height="6"/><rect x="30" y="93" width="60" height="6"/>',
        'halves' => '<rect x="60" y="60" width="30" height="56"/>',
        'sash' => '<path d="M34 80 L44 66 L88 102 L80 114 Z"/>',
    ][$pattern] ?? '';
}

function avatar_hex_luma(string $hex): float
{
    $h = ltrim($hex, '#');
    if (strlen($h) !== 6) {
        return 1.0;
    }
    return (0.299 * hexdec(substr($h, 0, 2)) + 0.587 * hexdec(substr($h, 2, 2)) + 0.114 * hexdec(substr($h, 4, 2))) / 255;
}

/** Fumetto con una frase, sopra la spalla destra del personaggio (esultanze «Siuuu», «Why always me?»). */
function avatar_bubble(string $text): string
{
    $w = max(40, round(mb_strlen($text) * 5.6 + 12));
    $x = 108 - $w;
    return '<g class="avf-p avf-p-bubble"><path class="o o2" fill="#fff" d="M' . ($x + 4) . ' -6 H' . ($x + $w - 4) . ' Q' . ($x + $w) . ' -6 ' . ($x + $w) . ' -2 V10 Q'
        . ($x + $w) . ' 14 ' . ($x + $w - 4) . ' 14 H' . ($x + $w - 16) . ' L' . ($x + $w - 24) . ' 22 L' . ($x + $w - 23) . ' 14 H' . ($x + 4) . ' Q' . $x . ' 14 ' . $x . ' 10 V-2 Q' . $x . ' -6 ' . ($x + 4) . ' -6 Z"/>'
        . '<text class="avf-bubble-t" x="' . ($x + $w / 2) . '" y="7.6" text-anchor="middle">' . h($text) . '</text></g>';
}

/** Durata di ogni esultanza in millisecondi (le animazioni sono in assets/style.css): lo script di avatar.php toglie la classe dopo. */
function avatar_anim_ms(string $anim): int
{
    return ['backflip' => 1600, 'machinegun' => 2000, 'salute' => 2000, 'cartwheel' => 2000, 'inzaghi' => 2200,
        'siu' => 2600, 'plane' => 2600, 'selfie' => 2600, 'archer' => 2600, 'dive' => 2600, 'crossed' => 2600, 'milla' => 2600,
        'why' => 2800, 'zen' => 2800, 'skyfingers' => 2800][$anim] ?? 2400;
}

/** Accessori che servono a un'esultanza: si disegnano (nascosti) solo nelle figure che hanno quell'esultanza. */
function avatar_anim_props(string $anim): array
{
    return ['shirt-kiss' => ['heart'], 'heart' => ['bigheart'], 'selfie' => ['phone'], 'archer' => ['bow'], 'milla' => ['flag'],
        'tongue' => ['tongue'], 'siu' => ['bubble'], 'why' => ['bubble', 'flat'], 'crossed' => ['flat'], 'salute' => ['flat'],
        'robot' => ['flat']][$anim] ?? [];
}

const AVATAR_VIEWBOX = '0 -24 120 194';
/** Inquadrature delle miniature: solo la parte del corpo che conta per quell'oggetto. */
const AVATAR_CROPS = ['head' => '14 -16 92 92', 'torso' => '20 12 80 104', 'legs' => '20 62 80 104', 'feet' => '26 118 68 50'];

/**
 * Il personaggio in SVG. Opzioni:
 *  - number: numero di maglia (null = nessuno);   - crop: un'inquadratura di AVATAR_CROPS (miniature del negozio);
 *  - ring: sotto i piedi un disco del colore della squadra (var(--tc) di chi lo contiene) invece dell'ombra;
 *  - pose: posa al posto di quella scelta;   - jersey: ['a' =>, 'b' =>, 'pattern' =>] al posto della maglia (crea maglia);
 *  - all_patterns: disegna tutti i motivi della maglia (nascosti tranne quello scelto) per cambiarli al volo da JS;
 *  - hint: nome di un'esultanza da mostrare ferma a metà (miniature del negozio, vedi .av-item-fig in assets/style.css);
 *  - label: testo per i lettori di schermo.
 */
function avatar_figure(array $look, array $o = []): string
{
    static $n = 0;
    $n++;
    $cat = shop_catalog();
    $defs = avatar_defaults();
    $it = fn(string $kind) => $cat[$kind][$look[$kind] ?? ''] ?? $cat[$kind][$defs[$kind]];

    $jersey = $it('jersey');
    $ja = $o['jersey']['a'] ?? $jersey['colors']['a'];
    $jb = $o['jersey']['b'] ?? $jersey['colors']['b'];
    $pattern = $o['jersey']['pattern'] ?? ($jersey['pattern'] ?? 'solid');
    $pet = $it('pet');
    $vars = [
        '--av-skin' => $it('skin')['color'], '--av-hair' => $it('hair_color')['color'],
        '--av-sa' => $ja, '--av-sb' => $jb, '--av-sl' => $pattern === 'sleeves' ? $jb : $ja, '--av-so' => $ja,
        '--av-sh' => $it('shorts')['color'], '--av-fo' => $it('shoes')['color'],
        '--av-pa' => $pet['colors']['a'] ?? '#ffd23f', '--av-pb' => $pet['colors']['b'] ?? '#ffffff',
        '--av-num' => $pattern === 'solid' ? $jb : '#ffffff',
    ];
    $style = '';
    foreach ($vars as $k => $v) {
        $style .= $k . ':' . $v . ';';
    }
    $pose = $o['pose'] ?? ($it('pose')['pose'] ?? 'rest');
    [$aL, $aR] = avatar_pose_angles($pose);
    $hat = $look['hat'] ?? null;
    $styles = avatar_hair_styles();
    $hs = $styles[$it('hair')['style']] ?? [];
    if ($hat && !empty($hs['tall'])) {
        $hs = ['back' => ($it('hair')['style'] === 'curly' ? $hs['back'] : ''), 'front' => $styles['_classic']['front']];
    }
    $beard = avatar_beards()[$it('beard')['style']] ?? [];
    $torso = 'M41 73 Q60 67 79 73 L81 108 Q60 111 39 108 Z';
    $clip = 'avf' . $n;
    $clipHead = 'avh' . $n;
    $shade = 'fill="#1f1a2e" opacity=".13"';   // ombra a cel-shading: la luce arriva da sinistra, in alto
    $light = 'fill="#fff" opacity=".13"';

    $patterns = '';
    foreach (!empty($o['all_patterns']) ? array_keys(avatar_patterns()) : [$pattern] as $pk) {
        $shapes = avatar_pattern_shapes($pk);
        if ($shapes !== '' || !empty($o['all_patterns'])) {
            $patterns .= '<g data-pat="' . $pk . '"' . ($pk !== $pattern ? ' display="none"' : '') . '>' . $shapes . '</g>';
        }
    }

    $anim = $it('celebration')['anim'] ?? 'fist-pump';
    $props = array_flip(avatar_anim_props($anim));
    // braccio: manica col bordino del secondo colore, pollice verso l'interno; in mano il telefono o l'arco se l'esultanza li usa
    $arm = function (string $side, int $deg) use ($props): string {
        $m = $side === 'l' ? 1 : -1;
        $sx = 60 - 18 * $m;
        $hx = 60 - 22.5 * $m;
        $hand = 60 - 23 * $m;
        $at = fn(float $y) => round($sx + ($hx - $sx) * ($y - 77) / 26, 2) . ' ' . $y;
        $line = 'M' . $sx . ' 77 L' . $hx . ' 103';
        return '<g class="avf-arm avf-arm-' . $side . '"' . ($deg ? ' style="transform:rotate(' . $deg . 'deg)"' : '') . '>'
            . '<path class="s-ink" d="' . $line . '" stroke-width="12" stroke-linecap="round"/>'
            . '<path class="s-sk" d="' . $line . '" stroke-width="7" stroke-linecap="round"/>'
            . '<path d="M' . $at(90) . ' L' . $at(102) . '" stroke="#1f1a2e" stroke-width="2.2" opacity=".12" stroke-linecap="round" transform="translate(' . (-2.2 * $m) . ' 0)"/>'
            . '<circle class="f-sk o" cx="' . $hand . '" cy="106" r="5.5"/>'
            . '<circle class="f-sk o o2" cx="' . ($hand + 3.6 * $m) . '" cy="103.6" r="2.4"/>'
            . ($side === 'r' && isset($props['phone']) ? '<g class="avf-p avf-p-phone"><rect x="80.5" y="100" width="8" height="12.5" rx="1.8" fill="#1f1a2e"/>'
                . '<rect x="81.9" y="101.6" width="5.2" height="8.4" rx=".8" fill="#53c8f5"/><circle class="avf-p avf-p-flash" cx="84.5" cy="98" r="7" fill="#fff" stroke="#ffd23f" stroke-width="2"/></g>' : '')
            . ($side === 'l' && isset($props['bow']) ? '<g class="avf-p avf-p-bow"><path d="M26 107 Q37 120 48 107" fill="none" stroke="#1f1a2e" stroke-width="5.5" stroke-linecap="round"/>'
                . '<path d="M26 107 Q37 120 48 107" fill="none" stroke="#c98a3b" stroke-width="2.6" stroke-linecap="round"/><path d="M26 107 L48 107" stroke="#1f1a2e" stroke-width=".9"/></g>' : '')
            . '<path class="s-ink" d="M' . $sx . ' 77 L' . $at(86) . '" stroke-width="15.5" stroke-linecap="round"/>'
            . '<path class="s-sb" d="M' . $sx . ' 77 L' . $at(86) . '" stroke-width="10.5" stroke-linecap="round"/>'
            . '<path class="s-sl" d="M' . $sx . ' 77 L' . $at(82.8) . '" stroke-width="10.5" stroke-linecap="round"/></g>';
    };

    $hatSvg = '';
    if ($hat) {
        $inner = preg_replace('~^<svg[^>]*>|</svg>$~', '', hat_svg($hat));
        $hatSvg = '<svg x="21" y="-16" width="78" height="62.4" viewBox="0 0 100 80" overflow="visible">' . $inner . '</svg>';
    }
    $number = isset($o['number']) && $o['number'] !== null && $o['number'] !== ''
        ? '<text class="avf-num" x="60" y="101" text-anchor="middle">' . (int) $o['number'] . '</text>' : '';
    $petSvg = avatar_pets()[$pet['style'] ?? 'none'] ?? '';
    $ground = !empty($o['ring'])
        ? '<ellipse class="avf-ring o" cx="60" cy="162.5" rx="28" ry="6.5"/>'
        : '<ellipse cx="60" cy="162.5" rx="31" ry="5.5" fill="#1f1a2e" opacity=".12"/><ellipse cx="60" cy="162" rx="21" ry="3.6" fill="#1f1a2e" opacity=".14"/>';
    $label = isset($o['label']) ? ' role="img" aria-label="' . h($o['label']) . '"' : ' aria-hidden="true"';
    $hairStyle = $it('hair')['style'];
    $shine = $hat ? '' : ([
        'classic' => 'M41.5 26.5 Q48.5 18.5 58.5 17', 'side' => 'M41 27 Q48 18 57.5 16.5', 'quiff' => 'M47 20 Q56 8 70 6.5',
        'bun' => 'M41.5 26.5 Q48.5 18.5 58.5 17', 'long' => 'M40 27 Q47.5 17.5 58 16', 'mullet' => 'M41.5 26.5 Q48.5 18.5 58.5 17',
        'braids' => 'M41.5 26 Q48.5 18 58.5 16.5', 'fade' => 'M44 22 Q50.5 14 59 12.5', 'spiky' => 'M42 30 Q47 24.5 55 22.5',
        'curly' => 'M40 25 Q42.5 21.5 46.5 21.5 M51 19.5 Q53.5 16.5 57.5 16.5', 'mohawk' => 'M56.5 6 L58 13', 'bald' => 'M44 25 Q50 19.5 57.5 19',
    ][$hairStyle] ?? '');
    // occhio: bianco, iride, pupilla e due riflessi, con la palpebra superiore marcata
    $eye = fn(float $x) => '<ellipse cx="' . $x . '" cy="46.8" rx="3.9" ry="4.6" fill="#fff" stroke="#1f1a2e" stroke-width="1.3"/>'
        . '<ellipse cx="' . ($x + .3) . '" cy="47.4" rx="3.1" ry="3.75" fill="#3b2a20"/><ellipse cx="' . ($x + .3) . '" cy="48.6" rx="2.3" ry="2" fill="#6b4a33"/>'
        . '<circle cx="' . ($x + .35) . '" cy="47.5" r="1.55" fill="#1f1a2e"/>'
        . '<circle cx="' . ($x + 1.5) . '" cy="45.7" r="1.2" fill="#fff"/><circle cx="' . ($x - .8) . '" cy="49.3" r=".6" fill="#fff"/>'
        . '<path d="M' . ($x - 4.4) . ' 44.6 Q' . $x . ' 40.4 ' . ($x + 4.4) . ' 44.6" fill="none" stroke="#1f1a2e" stroke-width="2.1" stroke-linecap="round"/>';
    $leg = fn(float $x) => '<rect class="f-sk o" x="' . $x . '" y="118" width="11" height="34" rx="4"/>'
        . '<rect x="' . ($x + 6.8) . '" y="119.5" width="2.6" height="14" rx="1.3" ' . $shade . '/>'
        . '<rect class="f-so o" x="' . $x . '" y="133" width="11" height="15" rx="3"/>'
        . '<rect class="f-sb" x="' . ($x + 1.5) . '" y="136" width="8" height="2.6"/>'
        . '<rect x="' . ($x + 6.8) . '" y="139.5" width="2.6" height="6" rx="1.3" ' . $shade . '/>';
    // scarpetta sinistra (la destra è la stessa, specchiata): tomaia, suola chiara, tacchetti e la riga laterale
    $shoe = '<path class="f-fo o" d="M44 145 H58 V155.5 H38 Q36.3 155.5 36.8 152.8 Q38.2 146 44 145 Z"/>'
        . '<path d="M50 147.4 H56 M50 149.9 H56" stroke="#fff" stroke-width="1.3" stroke-linecap="round" opacity=".75"/>'
        . '<path class="o o2" fill="#f4efe4" d="M36.8 155.5 H58 V157.3 Q58 159.4 55.8 159.4 H39.2 Q36.4 159.4 36.8 156.8 Z"/>'
        . '<path d="M41 160.2 V161.6 M46.5 160.2 V161.6 M53 160.2 V161.6" stroke="#1f1a2e" stroke-width="2.3" stroke-linecap="round"/>'
        . '<path d="M40 153.6 Q47 151.6 57 152.8" fill="none" stroke="#fff" stroke-width="1.7" stroke-linecap="round" opacity=".85"/>'
        . '<ellipse cx="41.5" cy="149.6" rx="2.2" ry="1" fill="#fff" opacity=".4"/>';

    return '<svg class="avf pose-' . h($pose) . (isset($o['hint']) ? ' hint-' . h($o['hint']) : '') . '" viewBox="' . (AVATAR_CROPS[$o['crop'] ?? ''] ?? AVATAR_VIEWBOX) . '"'
        . ' style="' . h($style) . '" data-anim="' . h($anim) . '" data-anim-ms="' . avatar_anim_ms($anim) . '"' . $label . ' focusable="false">'
        . '<defs><clipPath id="' . $clip . '"><path d="' . $torso . '"/></clipPath><clipPath id="' . $clipHead . '"><circle cx="60" cy="44" r="26"/></clipPath></defs>'
        . $ground
        . '<g class="avf-body">'
        . ($hs['back'] ?? '')
        // collo, con l'ombra della testa
        . '<rect class="f-sk o" x="54" y="60" width="12" height="14" rx="3"/><path d="M55.5 62 H64.5 V68 Q60 70.5 55.5 68 Z" fill="#1f1a2e" opacity=".2"/>'
        . $leg(47) . $leg(62)
        . $shoe . '<g transform="matrix(-1 0 0 1 120 0)">' . $shoe . '</g>'
        // pantaloncini: bande laterali, elastico e la gamba in ombra
        . '<path class="f-sh o" d="M43 103 H77 L80.5 127 Q72 129.5 62.5 127.5 L60 115 L57.5 127.5 Q48 129.5 39.5 127 Z"/>'
        . '<path d="M62 104.5 H76 L79 126 Q72 128 63.5 126.5 L60.8 114.5 Z" ' . $shade . '/>'
        . '<path class="s-sb" d="M44.4 106.5 L41.8 125.2 M75.6 106.5 L78.2 125.2" stroke-width="2.2" stroke-linecap="round"/>'
        . '<path d="M44 107.6 H76" stroke="#1f1a2e" stroke-width="1.2" opacity=".28"/>'
        // maglia: motivo, ombre, orlo, stemma e colletto a V
        . '<path class="f-sa" d="' . $torso . '"/>'
        . '<g clip-path="url(#' . $clip . ')">'
        . '<g class="f-sb">' . $patterns . '</g>'
        . '<path d="M71 66 Q78.5 88 74 116 H92 V66 Z" ' . $shade . '/>'
        . '<path d="M36 103.5 Q60 99.5 84 103.5 V116 H36 Z" fill="#1f1a2e" opacity=".1"/>'
        . '<ellipse cx="48.5" cy="86" rx="5" ry="10" ' . $light . '/>'
        . '<path class="s-sb" d="M38 105.8 Q60 109.6 82 105.8" stroke-width="2.4"/>'
        . '</g>'
        . '<path class="o" fill="none" d="' . $torso . '"/>'
        . '<path class="f-sk" d="M55.3 69.2 L60 75 L64.7 69.2 Z"/><path d="M56.5 69.3 L60 73.6 L63.5 69.3 Z" fill="#1f1a2e" opacity=".18"/>'
        . '<path class="f-sb" d="M51.5 69.4 L60 80.4 L68.5 69.4 L64.9 68.9 L60 75.1 L55.1 68.9 Z" stroke="#1f1a2e" stroke-width="1.4" stroke-linejoin="round"/>'
        . '<path class="f-sb" d="M65.8 78.6 H71.2 V81.6 Q71.2 84.8 68.5 86.2 Q65.8 84.8 65.8 81.6 Z" stroke="#1f1a2e" stroke-width="1.3" stroke-linejoin="round"/>'
        . '<path d="M67.4 80.4 H69.6 M68.5 80.4 V84" stroke="#1f1a2e" stroke-width="1" opacity=".55"/>'
        . $number
        // testa: orecchie, ombra laterale, occhi, naso, guance, bocca; poi barba, occhiali, capelli e copricapo
        . '<g class="avf-head">'
        . '<circle class="f-sk o" cx="34.5" cy="47" r="6"/><circle class="f-sk o" cx="85.5" cy="47" r="6"/>'
        . '<path d="M33.2 44.2 Q31.2 47 33.6 50 M86.8 44.2 Q88.8 47 86.4 50" fill="none" stroke="#1f1a2e" stroke-width="1.4" opacity=".35" stroke-linecap="round"/>'
        . '<circle class="f-sk" cx="60" cy="44" r="26"/>'
        . '<g clip-path="url(#' . $clipHead . ')"><path d="M83 14 Q69.5 44 81 76 H100 V14 Z" ' . $shade . '/><ellipse cx="60" cy="72" rx="22" ry="6" fill="#1f1a2e" opacity=".08"/></g>'
        . '<circle class="o" fill="none" cx="60" cy="44" r="26"/>'
        . ($beard['under'] ?? '')
        . '<ellipse cx="44.5" cy="54.5" rx="4.4" ry="2.7" fill="#ff6b9a" opacity=".3"/><ellipse cx="75.5" cy="54.5" rx="4.4" ry="2.7" fill="#ff6b9a" opacity=".3"/>'
        . $eye(51) . $eye(69)
        . '<path class="s-ha" d="M45.5 37.8 Q50.5 35 55.5 37.4 M64.5 37.4 Q69.5 35 74.5 37.8" stroke-width="2.6" stroke-linecap="round"/>'
        . '<path d="M59.2 50.6 Q60.8 53.2 62.4 51" fill="none" stroke="#1f1a2e" stroke-width="1.5" opacity=".45" stroke-linecap="round"/>'
        . '<path class="avf-mouth" d="M54 56.8 Q60 62 66 56.8" fill="none" stroke="#1f1a2e" stroke-width="2.6" stroke-linecap="round"/>'
        . '<path class="avf-mouth-open" d="M53.5 55.2 Q60 56 66.5 55.2 Q65 64.2 60 64.2 Q55 64.2 53.5 55.2 Z" fill="#1f1a2e"/>'
        . (isset($props['tongue']) ? '<path class="avf-p avf-p-tongue" d="M56.5 60 Q56 68.5 60.5 68.5 Q65 68.5 64 60 Z" fill="#ff6b9a" stroke="#1f1a2e" stroke-width="1.6" stroke-linejoin="round"/>' : '')
        . (isset($props['flat']) ? '<path class="avf-p avf-mouth-flat" d="M54.5 58.2 Q60 57.2 65.5 58.2" fill="none" stroke="#1f1a2e" stroke-width="2.6" stroke-linecap="round"/>' : '')
        . ($beard['over'] ?? '')
        . (avatar_glasses()[$it('glasses')['style']] ?? '')
        . ($hs['front'] ?? '')
        . ($shine !== '' ? '<path d="' . $shine . '" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" opacity=".45"/>' : '')
        . $hatSvg
        . '</g>'
        . $arm('l', $aL) . $arm('r', $aR)
        . (isset($props['heart']) ? '<path class="avf-p avf-heart o" d="M93 20 C93 14 101 14 101 20 C101 14 109 14 109 20 C109 27 101 31 101 34 C101 31 93 27 93 20 Z" fill="#ff6b9a"/>' : '')
        . (isset($props['bigheart']) ? '<g class="avf-p avf-p-bigheart"><path class="o" d="M60 100 C47 91 47.5 80 54.5 80 C57.5 80 60 82.5 60 85.5 C60 82.5 62.5 80 65.5 80 C72.5 80 73 91 60 100 Z" fill="#ff5a5f"/>'
            . '<path d="M52.5 84.5 Q53.5 82.2 56 82.2" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/></g>' : '')
        . (isset($props['bubble']) ? avatar_bubble(['siu' => 'SIUUU!', 'why' => 'Why always me?'][$anim] ?? '') : '')
        . '</g>'
        . (isset($props['flag']) ? '<g class="avf-p avf-p-flag"><path d="M13 162 V94" stroke="#1f1a2e" stroke-width="3.2" stroke-linecap="round"/>'
            . '<path class="o o2" d="M13.5 95 L33 101.5 L13.5 108 Z" fill="#ff5a5f"/><path d="M13.5 101.5 L23 104.7 L13.5 108 Z" fill="#ffd23f"/></g>' : '')
        . ($petSvg !== '' ? '<g transform="translate(86 128)"><g class="avf-pet">' . $petSvg . '</g></g>' : '')
        . '</svg>';
}
