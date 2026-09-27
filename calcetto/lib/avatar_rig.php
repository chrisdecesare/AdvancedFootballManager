<?php
/*
 * Scheletro del Personaggio (disegno in lib/avatar.php, animazione in assets/avatar.js).
 *
 * Il corpo è una catena di articolazioni come quella vera: vita -> collo (testa), spalla -> gomito -> polso (mano), anca -> ginocchio
 * -> caviglia (piede). Ogni articolazione è un <g data-j="..."> che ruota attorno al suo perno (AV_PIVOTS, in coordinate della posa di
 * riposo). Gli arti destri sono il sinistro specchiato: il loro angolo si applica col segno invertito (vedi avatar_joint_transform).
 *
 * Una posa è un elenco di angoli in gradi (positivo = orario sullo schermo: il braccio sinistro che si alza verso l'esterno), più la
 * posizione del corpo (x, y, rotazione r, specchio sx), le mani (open, fist, point), la bocca (smile, open, flat, tongue) e gli accessori
 * visibili. Quando il personaggio non è in volo i piedi (o le ginocchia) restano appoggiati a terra: lo calcola avatar_ground().
 * Le esultanze sono sequenze di pose chiave nel tempo (anticipo, azione, tenuta, ritorno), interpolate da assets/avatar.js.
 */

const AV_PIVOTS = [
    'waist' => [60, 100], 'neck' => [60, 52],
    'shL' => [44.5, 62], 'elL' => [42.5, 81], 'wrL' => [41.5, 97.5],
    'shR' => [44.5, 62], 'elR' => [42.5, 81], 'wrR' => [41.5, 97.5],
    'hipL' => [51, 110], 'knL' => [50, 133], 'anL' => [50, 151],
    'hipR' => [51, 110], 'knR' => [50, 133], 'anR' => [50, 151],
];
const AV_ROOT_PIVOT = [60, 104];
const AV_GROUND = 160;
const AV_JOINTS = ['waist', 'neck', 'shL', 'elL', 'wrL', 'shR', 'elR', 'wrR', 'hipL', 'knL', 'anL', 'hipR', 'knR', 'anR'];

/** Pose di base: si combinano (le ultime vincono). Le chiavi senza L/R valgono per i due lati, specchiate. */
function avatar_pose_lib(): array
{
    return [
        'rest' => ['sh' => 5, 'el' => -6, 'hip' => 1.5, 'an' => -1.5, 'h' => 'open', 'm' => 'smile'],
        'straight' => ['hip' => 0, 'kn' => 0, 'an' => 0],
        'wide' => ['hip' => 12, 'kn' => 0, 'an' => -12],
        'crouch' => ['hip' => 24, 'kn' => -48, 'an' => 24],
        'crouchDeep' => ['hip' => 38, 'kn' => -78, 'an' => 40],
        'armsDown' => ['sh' => 2, 'el' => 0],
        'armsBack' => ['sh' => 30, 'el' => -4],
        'armsOut' => ['sh' => 88, 'el' => 0],
        'armsV' => ['sh' => 138, 'el' => -10],
        'armsUp' => ['sh' => 166, 'el' => -6],
        'fistsUp' => ['sh' => 146, 'el' => 22, 'h' => 'fist'],
        'hipsHands' => ['sh' => 34, 'el' => -86, 'wr' => -10],
        'crossed' => ['sh' => -16, 'el' => -110, 'wr' => 0],
        'tuck' => ['hip' => 100, 'kn' => -150, 'an' => 30, 'sh' => 60, 'el' => -120, 'h' => 'fist'],
        'star' => ['hip' => 30, 'kn' => 0, 'an' => -30, 'sh' => 128, 'el' => 0, 'h' => 'open'],
        'kneel' => ['hip' => 3, 'kn' => 174, 'an' => 0],
        'sitCross' => ['hip' => 74, 'kn' => -138, 'an' => 60],
    ];
}

/** Pose scelte nel Personaggio (catalogo 'pose'): il nome è quello del campo pose in lib/shop_items.php. */
function avatar_pose_items(): array
{
    return [
        'rest' => ['rest'],
        'open' => ['rest', ['sh' => 48, 'el' => -6, 'wide' => 1], 'wide'],
        'wave' => ['rest', ['shR' => -142, 'elR' => -38, 'wrR' => -10]],
        'point' => ['rest', ['shR' => -92, 'elR' => 0, 'hR' => 'point']],
        'victory' => ['rest', 'fistsUp', ['sh' => 132, 'el' => 30], 'wide'],
        'up' => ['rest', 'armsUp', 'wide'],
    ];
}

/**
 * Esultanze: durata, fotogramma per la miniatura del negozio e pose chiave [ms, posa, andamento (io, o, i, l, s = a scatto)].
 * Tutte sul posto: il corpo si sposta al massimo di pochi punti, salvo le acrobazie in volo ('air').
 */
function avatar_celebrations(): array
{
    $R = 'rest';
    return [
        'fist-pump' => ['hint' => 3, 'keys' => [
            [0, $R], [220, [$R, 'crouch', ['shR' => -40, 'elR' => 70, 'hR' => 'fist', 'hL' => 'fist', 'elL' => -40]], 'o'],
            [480, [$R, ['shR' => -150, 'elR' => -26, 'hR' => 'fist', 'hL' => 'fist', 'shL' => 20, 'elL' => -60, 'm' => 'open']], 'o'],
            [700, [$R, 'crouch', ['shR' => -120, 'elR' => -50, 'hR' => 'fist', 'hL' => 'fist', 'shL' => 20, 'elL' => -60, 'm' => 'open']]],
            [920, [$R, ['shR' => -152, 'elR' => -24, 'hR' => 'fist', 'hL' => 'fist', 'shL' => 20, 'elL' => -60, 'm' => 'open']], 'o'],
            [1140, [$R, 'crouch', ['shR' => -120, 'elR' => -50, 'hR' => 'fist', 'hL' => 'fist', 'shL' => 20, 'elL' => -60, 'm' => 'open']]],
            [1360, [$R, ['shR' => -154, 'elR' => -22, 'hR' => 'fist', 'hL' => 'fist', 'shL' => 20, 'elL' => -60, 'm' => 'open']], 'o'],
            [1900, [$R, ['shR' => -154, 'elR' => -22, 'hR' => 'fist', 'hL' => 'fist', 'shL' => 20, 'elL' => -60, 'm' => 'open']]],
            [2300, $R]]],
        'celebration' => ['hint' => 2, 'keys' => [
            [0, $R], [260, [$R, 'crouchDeep', 'armsBack', ['m' => 'open']], 'o'],
            [520, [$R, 'straight', 'armsV', ['air' => 1, 'y' => -26, 'm' => 'open']], 'o'],
            [760, [$R, 'straight', 'armsV', ['kn' => -20, 'hip' => 10, 'an' => 10, 'air' => 1, 'y' => -30, 'm' => 'open']]],
            [980, [$R, 'crouch', 'armsV', ['m' => 'open']], 'i'],
            [1180, [$R, 'armsV', 'wide', ['m' => 'open', 'h' => 'fist']], 'o'],
            [2000, [$R, 'armsV', 'wide', ['m' => 'open', 'h' => 'fist']]], [2400, $R]]],
        'shirt-kiss' => ['hint' => 2, 'keys' => [
            [0, $R], [380, [$R, ['shR' => 40, 'elR' => 128, 'hR' => 'fist']], 'o'],
            [700, [$R, ['shR' => 40, 'elR' => 134, 'hR' => 'fist', 'neck' => -8, 'm' => 'flat', 'p' => ['heart'], 'shL' => 30, 'elL' => -10]]],
            [1900, [$R, ['shR' => 40, 'elR' => 134, 'hR' => 'fist', 'neck' => -8, 'm' => 'flat', 'p' => ['heart'], 'shL' => 30, 'elL' => -10]]],
            [2400, $R]]],
        'salute' => ['hint' => 2, 'keys' => [
            [0, $R], [220, [$R, 'straight', ['shR' => -96, 'elR' => -84, 'wrR' => -10, 'shL' => 0, 'elL' => 0, 'm' => 'flat']], 's'],
            [300, [$R, 'straight', ['shR' => -98, 'elR' => -88, 'wrR' => -10, 'shL' => 0, 'elL' => 0, 'm' => 'flat']]],
            [1700, [$R, 'straight', ['shR' => -98, 'elR' => -88, 'wrR' => -10, 'shL' => 0, 'elL' => 0, 'm' => 'flat']]], [2000, $R]]],
        'skyfingers' => ['hint' => 2, 'keys' => [
            [0, $R], [900, [$R, 'armsUp', ['el' => -2, 'h' => 'point', 'neck' => 0, 'm' => 'smile']]],
            [1300, [$R, 'armsUp', ['sh' => 160, 'h' => 'point']]], [2300, [$R, 'armsUp', ['sh' => 160, 'h' => 'point']]], [2800, $R]]],
        'kneel' => ['hint' => 3, 'keys' => [
            [0, $R], [280, [$R, 'crouch', 'armsBack', ['m' => 'open']], 'o'],
            [620, [$R, 'kneel', 'armsBack', ['waist' => 0, 'm' => 'open']], 'o'],
            [900, [$R, 'kneel', 'fistsUp', ['m' => 'open']], 'o'],
            [2000, [$R, 'kneel', 'fistsUp', ['sh' => 150, 'm' => 'open']]], [2400, [$R, 'crouch']], [2700, $R]]],
        'dance' => ['hint' => 1, 'keys' => [
            [0, $R],
            [300, [$R, ['x' => -3, 'waist' => -8, 'hipL' => 12, 'knL' => -24, 'anL' => 12, 'shL' => 120, 'elL' => 40, 'shR' => -30, 'elR' => 60, 'h' => 'fist', 'm' => 'open']]],
            [600, [$R, ['x' => 3, 'waist' => 8, 'hipR' => -12, 'knR' => 24, 'anR' => -12, 'shR' => -120, 'elR' => -40, 'shL' => 30, 'elL' => -60, 'h' => 'fist', 'm' => 'open']]],
            [900, [$R, ['x' => -3, 'waist' => -8, 'hipL' => 12, 'knL' => -24, 'anL' => 12, 'shL' => 120, 'elL' => 40, 'shR' => -30, 'elR' => 60, 'h' => 'fist', 'm' => 'open']]],
            [1200, [$R, ['x' => 3, 'waist' => 8, 'hipR' => -12, 'knR' => 24, 'anR' => -12, 'shR' => -120, 'elR' => -40, 'shL' => 30, 'elL' => -60, 'h' => 'fist', 'm' => 'open']]],
            [1500, [$R, ['x' => -3, 'waist' => -8, 'hipL' => 12, 'knL' => -24, 'anL' => 12, 'shL' => 120, 'elL' => 40, 'shR' => -30, 'elR' => 60, 'h' => 'fist', 'm' => 'open']]],
            [1800, [$R, ['x' => 3, 'waist' => 8, 'hipR' => -12, 'knR' => 24, 'anR' => -12, 'shR' => -120, 'elR' => -40, 'shL' => 30, 'elL' => -60, 'h' => 'fist', 'm' => 'open']]],
            [2200, $R]]],
        'tongue' => ['hint' => 2, 'keys' => [
            [0, $R], [300, [$R, 'crouch', ['sh' => 40, 'el' => -80, 'h' => 'fist', 'm' => 'tongue', 'p' => ['tongue']]], 'o'],
            [600, [$R, ['hipL' => 40, 'knL' => -90, 'anL' => 20, 'shL' => 20, 'elL' => -100, 'shR' => -50, 'elR' => 90, 'h' => 'fist', 'm' => 'tongue', 'p' => ['tongue']]]],
            [900, [$R, ['hipR' => -40, 'knR' => 90, 'anR' => -20, 'shR' => -20, 'elR' => 100, 'shL' => 50, 'elL' => -90, 'h' => 'fist', 'm' => 'tongue', 'p' => ['tongue']]]],
            [1200, [$R, ['hipL' => 40, 'knL' => -90, 'anL' => 20, 'shL' => 20, 'elL' => -100, 'shR' => -50, 'elR' => 90, 'h' => 'fist', 'm' => 'tongue', 'p' => ['tongue']]]],
            [1500, [$R, ['hipR' => -40, 'knR' => 90, 'anR' => -20, 'shR' => -20, 'elR' => 100, 'shL' => 50, 'elL' => -90, 'h' => 'fist', 'm' => 'tongue', 'p' => ['tongue']]]],
            [1800, [$R, 'wide', ['sh' => 40, 'el' => -80, 'h' => 'fist', 'm' => 'tongue', 'p' => ['tongue']]]], [2300, $R]]],
        'plane' => ['hint' => 2, 'keys' => [
            [0, $R], [350, [$R, 'armsOut', 'wide'], 'o'],
            [800, [$R, 'armsOut', ['waist' => -14, 'x' => -3, 'hipR' => -30, 'knR' => 60, 'anR' => -30]]],
            [1300, [$R, 'armsOut', ['waist' => 14, 'x' => 3, 'hipL' => 30, 'knL' => -60, 'anL' => 30]]],
            [1800, [$R, 'armsOut', ['waist' => -14, 'x' => -3, 'hipR' => -30, 'knR' => 60, 'anR' => -30]]],
            [2200, [$R, 'armsOut', 'wide']], [2600, $R]]],
        'cradle' => ['hint' => 2, 'keys' => [
            [0, $R], [350, [$R, ['sh' => -8, 'el' => -96]], 'o'],
            [700, [$R, ['sh' => -8, 'el' => -96, 'waist' => -6, 'shL' => 4, 'shR' => 20]]],
            [1100, [$R, ['sh' => -8, 'el' => -96, 'waist' => 6, 'shL' => -20, 'shR' => -4]]],
            [1500, [$R, ['sh' => -8, 'el' => -96, 'waist' => -6, 'shL' => 4, 'shR' => 20]]],
            [1900, [$R, ['sh' => -8, 'el' => -96, 'waist' => 6, 'shL' => -20, 'shR' => -4]]], [2400, $R]]],
        'ear' => ['hint' => 2, 'keys' => [
            [0, $R], [300, [$R, ['shR' => -132, 'elR' => -60, 'wrR' => 20, 'shL' => 40, 'elL' => -10, 'm' => 'open']], 'o'],
            [600, [$R, ['shR' => -132, 'elR' => -64, 'wrR' => 30, 'neck' => 8, 'shL' => 40, 'elL' => -10, 'm' => 'open']]],
            [900, [$R, ['shR' => -132, 'elR' => -60, 'wrR' => 5, 'neck' => 8, 'shL' => 40, 'elL' => -10, 'm' => 'open']]],
            [1200, [$R, ['shR' => -132, 'elR' => -64, 'wrR' => 30, 'neck' => 8, 'shL' => 40, 'elL' => -10, 'm' => 'open']]],
            [1500, [$R, ['shR' => -132, 'elR' => -60, 'wrR' => 5, 'neck' => 8, 'shL' => 40, 'elL' => -10, 'm' => 'open']]],
            [2000, [$R, ['shR' => -132, 'elR' => -62, 'wrR' => 20, 'neck' => 8, 'shL' => 40, 'elL' => -10, 'm' => 'open']]], [2400, $R]]],
        'thumb' => ['hint' => 2, 'keys' => [
            [0, $R], [400, [$R, ['shR' => 40, 'elR' => 134, 'hR' => 'fist', 'm' => 'smile']], 'o'],
            [700, [$R, ['shR' => 40, 'elR' => 140, 'hR' => 'fist', 'neck' => -10, 'waist' => -3, 'shL' => 16, 'elL' => -16]]],
            [1300, [$R, ['shR' => 40, 'elR' => 140, 'hR' => 'fist', 'neck' => -10, 'waist' => 3, 'shL' => 16, 'elL' => -16]]],
            [1900, [$R, ['shR' => 40, 'elR' => 140, 'hR' => 'fist', 'neck' => -10, 'waist' => -3, 'shL' => 16, 'elL' => -16]]], [2400, $R]]],
        'robot' => ['hint' => 1, 'keys' => [
            [0, $R], [300, [$R, 'straight', ['shL' => 0, 'elL' => -90, 'shR' => 0, 'elR' => 0, 'neck' => 10, 'm' => 'flat', 'h' => 'open']], 's'],
            [600, [$R, 'straight', ['shL' => 0, 'elL' => -90, 'shR' => 0, 'elR' => 90, 'neck' => -10, 'm' => 'flat']], 's'],
            [900, [$R, 'straight', ['shL' => 90, 'elL' => -90, 'shR' => 0, 'elR' => 90, 'neck' => 10, 'm' => 'flat']], 's'],
            [1200, [$R, 'straight', ['shL' => 90, 'elL' => -90, 'shR' => -90, 'elR' => 90, 'neck' => -10, 'm' => 'flat']], 's'],
            [1500, [$R, 'straight', ['shL' => 0, 'elL' => 0, 'shR' => -90, 'elR' => 90, 'neck' => 0, 'm' => 'flat']], 's'],
            [1800, [$R, 'straight', ['shL' => 0, 'elL' => -90, 'shR' => 0, 'elR' => 90, 'neck' => 10, 'm' => 'flat']], 's'],
            [2400, $R, 's']]],
        'inzaghi' => ['hint' => 2, 'keys' => [
            [0, $R], [180, [$R, 'crouch', ['sh' => 30, 'el' => -40, 'm' => 'open']], 'o'],
            [380, [$R, ['air' => 1, 'y' => -10, 'shL' => 160, 'elL' => -30, 'shR' => -40, 'elR' => 20, 'hipL' => 30, 'knL' => -60, 'anL' => 30, 'neck' => -10, 'm' => 'open']]],
            [580, [$R, ['shL' => 40, 'elL' => -20, 'shR' => -160, 'elR' => 30, 'hipR' => -30, 'knR' => 60, 'anR' => -30, 'neck' => 10, 'm' => 'open']]],
            [780, [$R, ['air' => 1, 'y' => -12, 'shL' => 150, 'elL' => -60, 'shR' => -140, 'elR' => 60, 'hipL' => 30, 'knL' => -60, 'anL' => 30, 'neck' => -10, 'm' => 'open']]],
            [980, [$R, ['shL' => 60, 'elL' => 10, 'shR' => -160, 'elR' => 0, 'hipR' => -30, 'knR' => 60, 'anR' => -30, 'neck' => 10, 'm' => 'open']]],
            [1180, [$R, ['air' => 1, 'y' => -10, 'shL' => 165, 'elL' => 0, 'shR' => -30, 'elR' => 10, 'hipL' => 30, 'knL' => -60, 'anL' => 30, 'neck' => -8, 'm' => 'open']]],
            [1400, [$R, 'wide', 'armsV', ['h' => 'fist', 'm' => 'open']]], [1900, [$R, 'wide', 'armsV', ['h' => 'fist', 'm' => 'open']]], [2200, $R]]],
        'crossed' => ['hint' => 2, 'keys' => [
            [0, $R], [450, [$R, 'crossed', 'wide', ['m' => 'flat']], 'o'],
            [800, [$R, 'crossed', 'wide', ['m' => 'flat', 'neck' => -7, 'waist' => -3]]],
            [2100, [$R, 'crossed', 'wide', ['m' => 'flat', 'neck' => -7, 'waist' => -3]]], [2600, $R]]],
        'heart' => ['hint' => 3, 'keys' => [
            [0, $R], [400, [$R, ['sh' => -6, 'el' => -118, 'wr' => -20]], 'o'],
            [650, [$R, ['sh' => -6, 'el' => -118, 'wr' => -20, 'p' => ['bigheart']]]],
            [900, [$R, ['sh' => -6, 'el' => -118, 'wr' => -20, 'p' => ['bigheart'], 'neck' => -6]]],
            [1800, [$R, ['sh' => -6, 'el' => -118, 'wr' => -20, 'p' => ['bigheart'], 'neck' => 6]]], [2400, $R]]],
        'machinegun' => ['hint' => 2, 'keys' => [
            [0, $R], [250, [$R, 'crouch', ['shL' => -58, 'elL' => -30, 'shR' => -92, 'elR' => -2, 'h' => 'fist', 'm' => 'open']], 'o'],
            [320, [$R, 'crouch', ['shL' => -54, 'elL' => -34, 'shR' => -88, 'elR' => -2, 'h' => 'fist', 'm' => 'open', 'x' => 1]], 'l'],
            [400, [$R, 'crouch', ['shL' => -58, 'elL' => -30, 'shR' => -92, 'elR' => -2, 'h' => 'fist', 'm' => 'open', 'x' => -1]], 'l'],
            [480, [$R, 'crouch', ['shL' => -54, 'elL' => -34, 'shR' => -88, 'elR' => -2, 'h' => 'fist', 'm' => 'open', 'x' => 1]], 'l'],
            [560, [$R, 'crouch', ['shL' => -58, 'elL' => -30, 'shR' => -92, 'elR' => -2, 'h' => 'fist', 'm' => 'open', 'x' => -1]], 'l'],
            [640, [$R, 'crouch', ['shL' => -54, 'elL' => -34, 'shR' => -88, 'elR' => -2, 'h' => 'fist', 'm' => 'open', 'x' => 1]], 'l'],
            [720, [$R, 'crouch', ['shL' => -58, 'elL' => -30, 'shR' => -92, 'elR' => -2, 'h' => 'fist', 'm' => 'open', 'x' => -1]], 'l'],
            [800, [$R, 'crouch', ['shL' => -54, 'elL' => -34, 'shR' => -88, 'elR' => -2, 'h' => 'fist', 'm' => 'open', 'x' => 1]], 'l'],
            [880, [$R, 'crouch', ['shL' => -58, 'elL' => -30, 'shR' => -92, 'elR' => -2, 'h' => 'fist', 'm' => 'open', 'x' => -1]], 'l'],
            [960, [$R, 'crouch', ['shL' => -54, 'elL' => -34, 'shR' => -88, 'elR' => -2, 'h' => 'fist', 'm' => 'open', 'x' => 1]], 'l'],
            [1040, [$R, 'crouch', ['shL' => -58, 'elL' => -30, 'shR' => -92, 'elR' => -2, 'h' => 'fist', 'm' => 'open']], 'l'],
            [1600, [$R, 'crouch', ['shL' => -58, 'elL' => -30, 'shR' => -92, 'elR' => -2, 'h' => 'fist', 'm' => 'smile']]], [2000, $R]]],
        'archer' => ['hint' => 3, 'keys' => [
            [0, $R], [350, [$R, 'wide', ['shL' => 88, 'elL' => 0, 'p' => ['bow']]], 'o'],
            [800, [$R, 'wide', ['shL' => 90, 'elL' => 0, 'shR' => 78, 'elR' => -150, 'hR' => 'fist', 'waist' => -4, 'p' => ['bow'], 'm' => 'flat']]],
            [1600, [$R, 'wide', ['shL' => 90, 'elL' => 0, 'shR' => 84, 'elR' => -160, 'hR' => 'fist', 'waist' => -5, 'p' => ['bow'], 'm' => 'flat']]],
            [1700, [$R, 'wide', ['shL' => 90, 'elL' => 0, 'shR' => -60, 'elR' => 0, 'hR' => 'open', 'waist' => 2, 'p' => ['bow'], 'm' => 'open']], 'o'],
            [2200, [$R, 'wide', ['shL' => 90, 'elL' => 0, 'shR' => -60, 'elR' => 0, 'p' => ['bow'], 'm' => 'open']]], [2600, $R]]],
        'why' => ['hint' => 2, 'keys' => [
            [0, $R], [400, [$R, 'straight', 'armsDown', ['m' => 'flat']], 'o'],
            [700, [$R, 'straight', 'armsDown', ['m' => 'flat', 'p' => ['bubble'], 'y' => -1]]],
            [2400, [$R, 'straight', 'armsDown', ['m' => 'flat', 'p' => ['bubble'], 'y' => -1]]], [2800, $R]]],
        'milla' => ['hint' => 2, 'keys' => [
            [0, $R], [300, [$R, ['shR' => 20, 'elR' => -100, 'wrR' => -10, 'shL' => 130, 'elL' => 20, 'p' => ['flag'], 'm' => 'open']], 'o'],
            [650, [$R, ['shR' => 20, 'elR' => -100, 'wrR' => -10, 'shL' => 150, 'elL' => 40, 'waist' => -10, 'x' => -4, 'hipL' => 20, 'knL' => -40, 'anL' => 20, 'p' => ['flag'], 'm' => 'open']]],
            [1000, [$R, ['shR' => 20, 'elR' => -100, 'wrR' => -10, 'shL' => 120, 'elL' => 10, 'waist' => 10, 'x' => 4, 'hipR' => -20, 'knR' => 40, 'anR' => -20, 'p' => ['flag'], 'm' => 'open']]],
            [1350, [$R, ['shR' => 20, 'elR' => -100, 'wrR' => -10, 'shL' => 150, 'elL' => 40, 'waist' => -10, 'x' => -4, 'hipL' => 20, 'knL' => -40, 'anL' => 20, 'p' => ['flag'], 'm' => 'open']]],
            [1700, [$R, ['shR' => 20, 'elR' => -100, 'wrR' => -10, 'shL' => 120, 'elL' => 10, 'waist' => 10, 'x' => 4, 'hipR' => -20, 'knR' => 40, 'anR' => -20, 'p' => ['flag'], 'm' => 'open']]],
            [2100, [$R, ['p' => ['flag']]]], [2600, $R]]],
        'selfie' => ['hint' => 3, 'keys' => [
            [0, $R], [400, [$R, ['shR' => -128, 'elR' => -40, 'wrR' => -30, 'p' => ['phone']]], 'o'],
            [700, [$R, ['shR' => -128, 'elR' => -40, 'wrR' => -30, 'neck' => 10, 'shL' => 20, 'elL' => -60, 'p' => ['phone']]]],
            [1250, [$R, ['shR' => -128, 'elR' => -40, 'wrR' => -30, 'neck' => 10, 'shL' => 20, 'elL' => -60, 'p' => ['phone', 'flash']]], 's'],
            [1450, [$R, ['shR' => -128, 'elR' => -40, 'wrR' => -30, 'neck' => 10, 'shL' => 20, 'elL' => -60, 'p' => ['phone']]], 's'],
            [2100, [$R, ['shR' => -128, 'elR' => -40, 'wrR' => -30, 'neck' => 10, 'shL' => 20, 'elL' => -60, 'p' => ['phone']]]], [2600, $R]]],
        'dive' => ['hint' => 3, 'keys' => [
            [0, $R], [300, [$R, 'crouchDeep', 'armsBack', ['m' => 'open']], 'o'],
            [600, [$R, 'straight', 'armsUp', ['air' => 1, 'y' => -20, 'r' => -40, 'm' => 'open']], 'o'],
            [900, [$R, 'straight', 'armsUp', ['air' => 1, 'y' => 30, 'r' => -86, 'x' => -6, 'm' => 'open']], 'i'],
            [1300, [$R, 'straight', 'armsUp', ['air' => 1, 'y' => 30, 'r' => -86, 'x' => -16, 'm' => 'open', 'hip' => 6]], 'o'],
            [1800, [$R, 'straight', 'armsUp', ['air' => 1, 'y' => 30, 'r' => -86, 'x' => -16, 'm' => 'open']]],
            [2200, [$R, 'crouch', ['m' => 'smile']], 'io'], [2600, $R]]],
        'tardelli' => ['hint' => 2, 'keys' => [
            [0, $R], [260, [$R, 'crouch', 'fistsUp', ['m' => 'open']], 'o'],
            [520, [$R, ['hipL' => 36, 'knL' => -80, 'anL' => 20, 'shL' => 150, 'elL' => 22, 'shR' => -60, 'elR' => 90, 'h' => 'fist', 'neck' => -8, 'm' => 'open']]],
            [780, [$R, ['hipR' => -36, 'knR' => 80, 'anR' => -20, 'shR' => -150, 'elR' => -22, 'shL' => 60, 'elL' => -90, 'h' => 'fist', 'neck' => 8, 'm' => 'open']]],
            [1040, [$R, ['hipL' => 36, 'knL' => -80, 'anL' => 20, 'shL' => 150, 'elL' => 22, 'shR' => -60, 'elR' => 90, 'h' => 'fist', 'neck' => -8, 'm' => 'open']]],
            [1300, [$R, ['hipR' => -36, 'knR' => 80, 'anR' => -20, 'shR' => -150, 'elR' => -22, 'shL' => 60, 'elL' => -90, 'h' => 'fist', 'neck' => 8, 'm' => 'open']]],
            [1600, [$R, 'wide', 'fistsUp', ['m' => 'open', 'neck' => 0]]], [2000, [$R, 'wide', 'fistsUp', ['m' => 'open']]], [2400, $R]]],
        'zen' => ['hint' => 3, 'keys' => [
            [0, $R], [500, [$R, 'crouch', ['sh' => 30, 'el' => -30]], 'io'],
            [1000, [$R, 'sitCross', ['sh' => 26, 'el' => -40, 'wr' => 30, 'm' => 'smile']], 'io'],
            [1600, [$R, 'sitCross', ['sh' => 26, 'el' => -40, 'wr' => 30, 'air' => 1, 'y' => 30, 'm' => 'smile']], 'io'],
            [2200, [$R, 'sitCross', ['sh' => 26, 'el' => -40, 'wr' => 30, 'm' => 'smile']], 'io'], [2800, $R]]],
        'cartwheel' => ['hint' => 2, 'keys' => [
            [0, $R], [250, [$R, 'star', ['m' => 'open']], 'o'],
            [700, [$R, 'star', ['air' => 1, 'r' => 180, 'y' => -52, 'm' => 'open']], 'l'],
            [1150, [$R, 'star', ['air' => 1, 'r' => 360, 'y' => 0, 'm' => 'open']], 'l'],
            [1400, [$R, 'star', ['r' => 360, 'm' => 'open']], 'o'], [1900, [$R, ['r' => 360]]]]],
        'backflip' => ['hint' => 4, 'keys' => [
            [0, $R],
            [320, [$R, 'crouchDeep', 'armsBack', ['m' => 'flat']], 'io'],
            [480, [$R, 'straight', 'armsUp', ['air' => 1, 'y' => -18, 'm' => 'open']], 'o'],
            [640, [$R, 'tuck', ['air' => 1, 'y' => -52, 'r' => -110, 'm' => 'open']], 'l'],
            [820, [$R, 'tuck', ['air' => 1, 'y' => -58, 'r' => -250, 'm' => 'open']], 'l'],
            [980, [$R, 'straight', 'armsOut', ['air' => 1, 'y' => -22, 'r' => -350, 'm' => 'open']], 'l'],
            [1100, [$R, 'crouch', 'armsOut', ['r' => -360, 'm' => 'open']], 'o'],
            [1400, [$R, 'wide', 'armsV', ['r' => -360, 'm' => 'open', 'h' => 'fist']], 'io'],
            [1900, [$R, ['r' => -360]]]]],
        'siu' => ['hint' => 6, 'keys' => [
            [0, $R], [280, [$R, 'crouchDeep', 'armsBack', ['m' => 'flat']], 'io'],
            [480, [$R, 'straight', 'armsUp', ['air' => 1, 'y' => -30, 'sx' => .9, 'm' => 'open']], 'o'],
            [580, [$R, 'straight', ['sh' => 20, 'el' => 0, 'air' => 1, 'y' => -38, 'sx' => .12, 'm' => 'open']], 'l'],
            [680, [$R, 'straight', ['sh' => 30, 'el' => 0, 'air' => 1, 'y' => -36, 'sx' => -.9, 'm' => 'open']], 'l'],
            [780, [$R, 'straight', ['sh' => 38, 'el' => 0, 'air' => 1, 'y' => -26, 'sx' => .12, 'm' => 'open']], 'l'],
            [880, [$R, ['hip' => 22, 'kn' => -44, 'an' => 22, 'sh' => 42, 'el' => 0, 'sx' => 1, 'm' => 'open']], 'i'],
            [1040, [$R, ['hip' => 18, 'kn' => -6, 'an' => -12, 'sh' => 40, 'el' => 0, 'y' => -2, 'm' => 'open', 'p' => ['bubble']]], 'o'],
            [2200, [$R, ['hip' => 18, 'kn' => -6, 'an' => -12, 'sh' => 40, 'el' => 0, 'y' => -2, 'm' => 'open', 'p' => ['bubble']]]],
            [2600, $R]]],
        'hernanes' => ['hint' => 4, 'keys' => [
            [0, $R],
            [320, [$R, 'crouchDeep', 'armsBack', ['m' => 'flat']], 'io'],
            [480, [$R, 'straight', 'armsUp', ['air' => 1, 'y' => -26, 'm' => 'open']], 'o'],
            [640, [$R, 'tuck', ['air' => 1, 'y' => -66, 'r' => -150, 'm' => 'open']], 'l'],
            [820, [$R, 'tuck', ['air' => 1, 'y' => -78, 'r' => -360, 'm' => 'open']], 'l'],
            [1000, [$R, 'tuck', ['air' => 1, 'y' => -66, 'r' => -570, 'm' => 'open']], 'l'],
            [1150, [$R, 'straight', 'armsOut', ['air' => 1, 'y' => -22, 'r' => -700, 'm' => 'open']], 'l'],
            [1270, [$R, 'crouch', 'armsOut', ['r' => -720, 'm' => 'open']], 'o'],
            [1600, [$R, 'wide', 'armsUp', ['r' => -720, 'm' => 'open', 'h' => 'fist']], 'io'],
            [2200, [$R, ['r' => -720]]]]],
    ];
}

/* ---------------------------------------------------------------- calcolo delle pose */

/** Combina pose di base e ritocchi in una posa completa: angoli di tutte le articolazioni, corpo, mani, bocca, accessori. */
function avatar_pose(string|array $spec): array
{
    $lib = avatar_pose_lib();
    $p = ['x' => 0, 'y' => 0, 'r' => 0, 'sx' => 1, 'air' => 0, 'hL' => 'open', 'hR' => 'open', 'm' => 'smile', 'p' => []]
        + array_fill_keys(AV_JOINTS, 0);
    $parts = is_array($spec) && array_is_list($spec) ? $spec : [$spec];
    foreach ($parts as $part) {
        $a = is_string($part) ? ($lib[$part] ?? []) : $part;
        foreach ($a as $k => $v) {
            if (in_array($k, ['sh', 'el', 'wr', 'hip', 'kn', 'an'], true)) {
                $p[$k . 'L'] = $v;
                $p[$k . 'R'] = -$v;
            } elseif ($k === 'h') {
                $p['hL'] = $p['hR'] = $v;
            } elseif ($k !== 'wide') {
                $p[$k] = $v;
            }
        }
    }
    return $p;
}

/** Ruota il punto $pt di $deg gradi attorno a $c. */
function avatar_rot(array $pt, array $c, float $deg): array
{
    $a = deg2rad($deg);
    $dx = $pt[0] - $c[0];
    $dy = $pt[1] - $c[1];
    return [$c[0] + $dx * cos($a) - $dy * sin($a), $c[1] + $dx * sin($a) + $dy * cos($a)];
}

/**
 * Di quanto abbassare il corpo perché il punto più basso delle gambe (tacco, punta o ginocchio) tocchi terra: la stessa formula è in
 * assets/avatar.js. Le gambe destre sono specchiate attorno a x = 60.
 */
function avatar_ground(array $p): float
{
    $low = -INF;
    foreach (['L' => 1, 'R' => -1] as $s => $m) {
        foreach ([[38, 160], [58, 160], [50, 137]] as $i => $pt) {
            if ($i < 2) {
                $pt = avatar_rot($pt, AV_PIVOTS['an' . $s], $p['an' . $s] * $m);
                $pt = avatar_rot($pt, AV_PIVOTS['kn' . $s], $p['kn' . $s] * $m);
            }
            $pt = avatar_rot($pt, AV_PIVOTS['hip' . $s], $p['hip' . $s] * $m);
            $low = max($low, $pt[1]);
        }
    }
    return AV_GROUND - $low;
}

function avatar_joint_transform(string $j, array $p): string
{
    $a = $j === 'waist' || $j === 'neck' ? $p[$j] : ($p[$j] * (str_ends_with($j, 'R') ? -1 : 1));
    return 'rotate(' . round($a, 2) . ' ' . AV_PIVOTS[$j][0] . ' ' . AV_PIVOTS[$j][1] . ')';
}

function avatar_root_transform(array $p): string
{
    $y = $p['y'] + ($p['air'] ? 0 : avatar_ground($p));
    return 'translate(' . round($p['x'], 2) . ' ' . round($y, 2) . ') rotate(' . round($p['r'], 2) . ' ' . AV_ROOT_PIVOT[0] . ' ' . AV_ROOT_PIVOT[1]
        . ') translate(60 0) scale(' . $p['sx'] . ' 1) translate(-60 0)';
}

/** Tutto quello che serve allo script delle animazioni (assets/avatar.js): perni, pose del catalogo ed esultanze già risolte. */
function avatar_rig_json(): string
{
    $anims = [];
    foreach (avatar_celebrations() as $name => $c) {
        $anims[$name] = array_map(fn($k) => [$k[0], avatar_pose($k[1]), $k[2] ?? 'io'], $c['keys']);
    }
    $poses = [];
    foreach (avatar_pose_items() as $name => $spec) {
        $poses[$name] = avatar_pose($spec);
    }
    return json_encode(['pivots' => AV_PIVOTS, 'root' => AV_ROOT_PIVOT, 'ground' => AV_GROUND, 'poses' => $poses, 'anims' => $anims],
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
}
