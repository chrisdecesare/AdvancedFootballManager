<?php
// Genera gli avatar in pixel art (quelli veri dell'app) per la locandina: php docs/ufficio/poster/avatars.php > avatars.json
// Serve il database locale (lo include l'app). Uso: dalla radice del repository.
chdir(__DIR__ . '/../../../calcetto');
require 'lib/bootstrap.php';
$base = avatar_defaults();
$looks = [
    ['look' => ['hair' => 'ha_ricci', 'hair_color' => 'hc_nero', 'skin' => 'sk_7', 'shorts' => 'p_neri', 'shoes' => 's_nere'], 'jersey' => ['a' => '#ffd23f', 'b' => '#1f1a2e', 'pattern' => 'stripes_v'], 'hint' => 'siu', 'n' => 10],
    ['look' => ['hair' => 'ha_ciuffo', 'hair_color' => 'hc_biondo', 'skin' => 'sk_2', 'shorts' => 'p_bianchi'], 'jersey' => ['a' => '#53c8f5', 'b' => '#ffffff', 'pattern' => 'halves'], 'hint' => 'plane', 'n' => 7],
    ['look' => ['hair' => 'ha_pelato', 'hair_color' => 'hc_nero', 'beard' => 'be_piena', 'skin' => 'sk_4', 'shorts' => 'p_neri'], 'jersey' => ['a' => '#e63946', 'b' => '#1f1a2e', 'pattern' => 'stripes_h'], 'hint' => 'dance', 'n' => 9],
    ['look' => ['hair' => 'ha_lunghi', 'hair_color' => 'hc_rosso', 'skin' => 'sk_3', 'shorts' => 'p_bianchi'], 'jersey' => ['a' => '#38d178', 'b' => '#ffffff', 'pattern' => 'sash'], 'hint' => 'heart', 'n' => 11],
    ['look' => ['hair' => 'ha_codino', 'hair_color' => 'hc_castano', 'beard' => 'be_baffi', 'skin' => 'sk_5', 'shorts' => 'p_blu'], 'jersey' => ['a' => '#ff8c42', 'b' => '#1f1a2e', 'pattern' => 'sleeves'], 'hint' => 'kneel', 'n' => 4],
];
$out = [];
foreach ($looks as $l) {
    $svg = avatar_figure($l['look'] + $base, ['hint' => $l['hint'], 'number' => $l['n'], 'jersey' => $l['jersey']]);
    $out[] = $svg;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
