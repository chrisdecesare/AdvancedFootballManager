<?php
/*
 * Personaggio: il giocatore disegnato a figura intera in pixel art 16-bit (motore e fotogrammi in lib/avatar_pixel.php, disegni in
 * lib/avatar_pixel_art.php e lib/avatar_pixel_parts.php, animazioni in assets/avatar_px.js).
 *
 * Cosa indossa sta nella colonna players.avatar_look (JSON: una chiave del catalogo per tipo, vedi avatar_defaults()); il copricapo è
 * invece quello del profilo (players.hat_key), così comprato o indossato qui o nel Negozio si vede in tutti e due i posti: in testa
 * al personaggio va la sua versione pixel (px_hats(), per modello). Catalogo e prezzi in lib/shop_items.php, acquisti in lib/shop.php.
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

/* ---------------------------------------------------------------- disegno (pixel art: lib/avatar_pixel.php) */

/** Inquadrature, in coordinate dello sprite: il palco (con lo spazio per salti e rincorse), la figura intera e le miniature. */
const AVATAR_VIEWBOX = '-12 -14 56 70';
const AVATAR_VIEWBOX_FIG = '-6 -10 44 67';
const AVATAR_VIEWBOX_PITCH = '-2 -10 36 67';
const AVATAR_CROPS = ['head' => '3 -9 26 33', 'torso' => '1 19 30 25', 'legs' => '4 36 24 21', 'feet' => '6 47 20 10'];

/**
 * Colori e pezzi del personaggio per il motore pixel: [colori, pezzi, motivo, pet, posa, esultanza].
 * $jersey: ['a' =>, 'b' =>, 'pattern' =>] al posto della maglia indossata.
 */
function avatar_px_look(array $look, ?array $jersey = null): array
{
    $cat = shop_catalog();
    $defs = avatar_defaults();
    $it = fn(string $kind) => $cat[$kind][$look[$kind] ?? ''] ?? $cat[$kind][$defs[$kind]];
    $j = $it('jersey');
    $hat = !empty($look['hat']) ? ($cat['hat'][$look['hat']] ?? null) : null;
    $pet = $it('pet');
    $pattern = $jersey['pattern'] ?? ($j['pattern'] ?? 'solid');
    $colors = [
        'skin' => $it('skin')['color'], 'hair' => $it('hair_color')['color'],
        'ja' => $jersey['a'] ?? $j['colors']['a'], 'jb' => $jersey['b'] ?? $j['colors']['b'], 'pattern' => $pattern,
        'shorts' => $it('shorts')['color'], 'shoes' => $it('shoes')['color'],
        'hat' => $hat ? array_values($hat['colors']) : [], 'pet' => array_values($pet['colors'] ?? []),
    ];
    $parts = ['hair' => $it('hair')['style'], 'beard' => $it('beard')['style'], 'glasses' => $it('glasses')['style'],
        'hat' => $hat && isset(px_hats()[$hat['tpl']]) ? $hat['tpl'] : null, 'number' => ''];
    return [$colors, $parts, $pattern, $pet['style'] ?? 'none', $it('pose')['pose'] ?? 'rest', $it('celebration')['anim'] ?? 'fist-pump'];
}

/**
 * Il personaggio in pixel art, come <svg class="avf">. Opzioni:
 *  - number: numero di maglia;   - crop: un'inquadratura di AVATAR_CROPS (miniature del negozio);
 *  - stage: sul palco della pagina Personaggio (posa animata ed esultanza pronta per «Esulta!»);
 *  - idle: posa animata anche fuori dal palco (il campo della Home);
 *  - ring: sotto i piedi un disco del colore della squadra (var(--tc) di chi lo contiene) invece dell'ombra;
 *  - pose: posa del catalogo al posto di quella scelta;   - hint: esultanza da mostrare ferma nel suo momento più riconoscibile;
 *  - jersey: ['a' =>, 'b' =>, 'pattern' =>] al posto della maglia;   - all_patterns: tutti i motivi coi colori della maglia in
 *    variabili CSS, per cambiarli da JS (crea maglia);   - label: testo per i lettori di schermo.
 */
function avatar_figure(array $look, array $o = []): string
{
    static $n = 0;
    $id = 'pxa' . (++$n);
    [$colors, $parts, $pattern, $pet, $pose, $anim] = avatar_px_look($look, $o['jersey'] ?? null);
    $parts['number'] = isset($o['number']) && $o['number'] !== null ? (string) $o['number'] : '';
    $poseName = $o['pose'] ?? $pose;
    $poseSteps = px_poses()[$poseName] ?? px_poses()['rest'];
    $cels = px_celebrations();

    $idle = null;
    $seq = null;
    $static = null;
    if (isset($o['hint'])) {
        $c = $cels[$o['hint']] ?? $cels['fist-pump'];
        $s = $c['steps'][$c['hint']];
        $static = [$s[0], 0, 0, $s[3], $s[4], $s[5]];
    } elseif (!empty($o['stage'])) {
        $idle = $poseSteps;
        $seq = ($cels[$anim] ?? $cels['fist-pump'])['steps'];
    } elseif (!empty($o['idle'])) {
        $idle = $poseSteps;
    }
    $static ??= [($idle ?? $poseSteps)[0][0], 0, 0, 0, 0, ''];

    $names = [$static[0]];
    foreach (array_merge($idle ?? [], $seq ?? []) as $s) {
        $names[] = $s[0];
    }
    $names = array_values(array_unique($names));

    $pal = px_palette($colors);
    $live = !empty($o['all_patterns']);
    $g = '';
    if ($live) {
        // crea maglia: la stessa figura per ogni motivo, coi colori della maglia che arrivano da variabili CSS (--pj0 ... --pn)
        $sent = ['j' => ['#000101', '#000102', '#000103'], 'k' => ['#000104', '#000105', '#000106']];
        $lp = ['j' => $sent['j'], 'a' => $sent['j'], 'c' => $sent['j'], 'k' => $sent['k'], 'n' => '#000107'] + $pal;
        $vars = ['#000101' => 'var(--pj0)', '#000102' => 'var(--pj1)', '#000103' => 'var(--pj2)', '#000104' => 'var(--pk0)',
            '#000105' => 'var(--pk1)', '#000106' => 'var(--pk2)', '#000107' => 'var(--pn)'];
        foreach (array_keys(avatar_patterns()) as $pk) {
            $g .= '<g data-pat="' . $pk . '"' . ($pk === $pattern ? '' : ' display="none"') . '>'
                . strtr(px_svg_paths(px_colorize(px_letters($static[0], $parts), $lp, $pk)), $vars) . '</g>';
        }
        $tj = px_tones($colors['ja']);
        $tk = px_tones($colors['jb']);
        $style = '--pj0:' . $tj[0] . ';--pj1:' . $tj[1] . ';--pj2:' . $tj[2] . ';--pk0:' . $tk[0] . ';--pk1:' . $tk[1] . ';--pk2:' . $tk[2]
            . ';--pn:' . px_number_color($colors['ja'], $colors['jb'], $pattern);
        $body = $g;
    } else {
        $style = '';
        foreach ($names as $f) {
            $g .= '<g id="' . $id . '-' . h($f) . '">' . px_svg_paths(px_colorize(px_letters($f, $parts), $pal, $pattern)) . '</g>';
        }
        $body = '<defs>' . $g . '</defs>';
        $fx = [];
        foreach (array_merge([$static], $seq ?? []) as $s) {
            foreach (array_filter(explode('+', (string) ($s[5] ?? ''))) as $k) {
                if ($k !== 'trail' && isset(px_fx()[$k])) {
                    $fx[$k] = true;
                }
            }
        }
        $staticFx = array_filter(explode('+', (string) $static[5]));
        foreach (array_keys($fx) as $k) {
            $body .= '<g data-fx="' . $k . '"' . (in_array($k, $staticFx, true) ? '' : ' display="none"') . '>' . px_fx_svg($k, $pal) . '</g>';
        }
        if ($seq) {
            $body .= '<use data-ghost="2" opacity=".15" display="none"/><use data-ghost="1" opacity=".32" display="none"/>';
        }
        $t = px_step_transform($static);
        $body .= '<use data-sprite href="#' . $id . '-' . h($static[0]) . '"' . ($t !== '' ? ' transform="' . $t . '"' : '') . '/>';
    }

    $ground = !empty($o['ring'])
        ? '<ellipse class="avf-ring" cx="16" cy="55.5" rx="11" ry="2.4"/>'
        : '<ellipse cx="16" cy="55.6" rx="9" ry="1.5" fill="#1f1a2e" opacity=".22" data-shadow/>';
    $petSvg = empty($o['ring']) && empty($o['crop']) ? px_pet_svg($pet, $pal) : '';
    $vb = AVATAR_CROPS[$o['crop'] ?? ''] ?? ($o['viewBox'] ?? (!empty($o['stage']) ? AVATAR_VIEWBOX : (!empty($o['ring']) ? AVATAR_VIEWBOX_PITCH : AVATAR_VIEWBOX_FIG)));
    $steps = fn(array $list, bool $withFx) => h(json_encode(array_map(
        fn($s) => $withFx ? [$s[0], $s[1], px_step_transform($s), $s[5] ?? ''] : [$s[0], $s[1], ''], $list)));
    $label = isset($o['label']) ? ' role="img" aria-label="' . h($o['label']) . '"' : ' aria-hidden="true"';
    return '<svg class="avf pxa" viewBox="' . $vb . '" shape-rendering="crispEdges" data-id="' . $id . '"'
        . ($style !== '' ? ' style="' . h($style) . '"' : '')
        . ($idle && count($idle) > 1 ? ' data-idle="' . $steps($idle, false) . '"' : '')
        . ($idle ? ' data-rest="' . h($idle[0][0]) . '"' : '')
        . ($seq ? ' data-seq="' . $steps($seq, true) . '"' : '')
        . $label . ' focusable="false">' . $ground . $petSvg . $body . '</svg>';
}
