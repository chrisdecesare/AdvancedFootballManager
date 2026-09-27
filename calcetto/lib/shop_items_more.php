<?php
/*
 * Catalogo esteso del negozio (lib/shop.php: shop_catalog): più di mille oggetti generati da forme × tavolozze × varianti.
 * Restituisce gli oggetti già nel formato di shop_catalog(), per tipo, ognuno con il suo "drop": il pacchetto con cui esce.
 * Un oggetto con un drop è in vendita solo quando l'admin lo fa uscire (drops.php, tabella shop_releases).
 *
 * Le chiavi sono stabili (finiscono nel database): si possono aggiungere oggetti, ma non cambiare le chiavi di quelli che ci sono.
 * Lunghe al massimo 16 caratteri.
 */

/** I pacchetti: codice => [nome, tipo]. */
function shop_more_drops(): array
{
    $d = [];
    foreach (shop_more_palettes() as $code => [$name]) {
        $d['h_' . $code] = ['Copricapi ' . $name, 'hat'];
    }
    $d['c_new'] = ['Esultanze nuove', 'celebration'];
    foreach (px_celebration_mods() as $code => [$name]) {
        $d['c_' . $code] = ['Esultanze ' . $name, 'celebration'];
    }
    $d['j_naz'] = ['Nazionali dal mondo', 'jersey'];
    foreach (shop_more_jersey_patterns() as $code => [$pattern, $name]) {
        $d['j_' . $code] = ['Maglie ' . $name, 'jersey'];
    }
    $d['hc'] = ['Colori dei capelli', 'hair_color'];
    $d['sk'] = ['Carnagioni', 'skin'];
    $d['sh'] = ['Pantaloncini colorati', 'shorts'];
    $d['so'] = ['Scarpette colorate', 'shoes'];
    $d['pe'] = ['Pet colorati', 'pet'];
    $d['n1'] = ['Nickname: soprannomi', 'nick'];
    $d['n2'] = ['Nickname: da spogliatoio', 'nick'];
    $d['n3'] = ['Nickname: leggende', 'nick'];
    return $d;
}

/** Tavolozze dei copricapi e dei pet: codice => [nome, a, b, c, ritocco del prezzo]. */
function shop_more_palettes(): array
{
    return [
        'sm' => ['smeraldo', '#1f8a4c', '#0f5c32', '#ffd23f', 1.0],
        'co' => ['cobalto', '#2a4fd6', '#1b2f8a', '#ffffff', 1.0],
        'ra' => ['corallo', '#ff7f6b', '#d9533f', '#fff3d6', 1.05],
        'la' => ['lavanda', '#b69cf2', '#7a5fd0', '#fff3d6', 1.1],
        'no' => ['notte', '#2b2540', '#161226', '#53c8f5', 1.15],
        'li' => ['lime', '#b4e33d', '#7fae1c', '#1f1a2e', 1.05],
        'ru' => ['rubino', '#c2185b', '#880e4f', '#ffd23f', 1.2],
        'gh' => ['ghiaccio', '#bfeaff', '#7fc8e8', '#ffffff', 1.25],
    ];
}

/** Colori con nome: codice (3 lettere) => [nome, colore]. Per capelli, pantaloncini, scarpette e maglie. */
function shop_more_colors(): array
{
    return [
        'ros' => ['rosso', '#d62828'], 'rub' => ['rubino', '#9b1d3a'], 'bor' => ['bordeaux', '#6d1a2c'], 'gra' => ['granata', '#8a1c1c'],
        'cor' => ['corallo', '#ff7f6b'], 'sal' => ['salmone', '#fa8072'], 'pes' => ['pesca', '#ffb38a'], 'ara' => ['arancio', '#ff8c42'],
        'mad' => ['mandarino', '#f77f00'], 'ter' => ['terracotta', '#c05a3a'], 'ram' => ['rame', '#b5652b'], 'bro' => ['bronzo', '#a8702a'],
        'cam' => ['cammello', '#c19a6b'], 'sab' => ['sabbia', '#e2c290'], 'cre' => ['crema', '#fff1c7'], 'avo' => ['avorio', '#fffff0'],
        'gia' => ['giallo', '#ffd23f'], 'lim' => ['limone', '#fff44f'], 'ocr' => ['ocra', '#cc9a1f'], 'ora' => ['oro antico', '#c9a227'],
        'lme' => ['lime', '#a4d93b'], 'pis' => ['pistacchio', '#93c572'], 'sal2' => ['salvia', '#9caf88'], 'oli' => ['oliva', '#6b7a2a'],
        'ver' => ['verde', '#1f8a4c'], 'sme' => ['smeraldo', '#10a060'], 'bos' => ['bosco', '#1e4d2b'], 'mil' => ['militare', '#4b5320'],
        'men' => ['menta', '#98ffcc'], 'acq' => ['acquamarina', '#5fd3c4'], 'tur' => ['turchese', '#1fb5a8'], 'pet' => ['petrolio', '#0f5e6b'],
        'cel' => ['celeste', '#a6e6ff'], 'azz' => ['azzurro', '#53c8f5'], 'cie' => ['cielo', '#87ceeb'], 'blu' => ['blu', '#2a3f9b'],
        'cob' => ['cobalto', '#2a4fd6'], 'rea' => ['blu reale', '#3a63ff'], 'nav' => ['blu notte', '#1f2a5c'], 'jea' => ['jeans', '#4a6fa5'],
        'lav' => ['lavanda', '#b69cf2'], 'lil' => ['lilla', '#c8a2c8'], 'vio' => ['viola', '#7a4fd6'], 'mel' => ['melanzana', '#4b2248'],
        'fuc' => ['fucsia', '#ff2e93'], 'ros2' => ['rosa', '#ff8fbf'], 'cip' => ['cipria', '#f4c2c2'], 'mag' => ['magenta', '#c2185b'],
        'bia' => ['bianco', '#ffffff'], 'ghi' => ['ghiaccio', '#dff4ff'], 'arg' => ['argento', '#c0c6d2'], 'gri' => ['grigio', '#8a91a0'],
        'fum' => ['fumo', '#5b5f6b'], 'ant' => ['antracite', '#383e48'], 'ner' => ['nero', '#1f1a2e'], 'car' => ['carbone', '#2b2540'],
        'cio' => ['cioccolato', '#5c3a21'], 'cas' => ['castagna', '#7a4a2a'], 'noc' => ['nocciola', '#a0703f'], 'caf' => ['caffè', '#4b3621'],
    ];
}

/** Motivi delle maglie da club: codice => [motivo, nome del pacchetto]. */
function shop_more_jersey_patterns(): array
{
    return ['s' => ['solid', 'a tinta unita'], 'v' => ['stripes_v', 'a strisce'], 'h' => ['stripes_h', 'a cerchi'], 'm' => ['halves', 'a metà'],
        'f' => ['sash', 'con la fascia']];
}

/**
 * Gli oggetti del catalogo esteso, per tipo: chiave => oggetto (stessi campi di shop_catalog) + drop.
 * $base: il catalogo di lib/shop_items.php già normalizzato (serve per i prezzi e i nomi delle esultanze).
 */
function shop_more_items(array $base): array
{
    $out = [];
    $round = fn(float $p) => max(5, (int) (round($p / 5) * 5));

    // copricapi: ogni modello × 8 tavolozze
    $nouns = ['cap' => 'Cappellino', 'visor' => 'Visiera', 'beanie' => 'Berretto di lana', 'bandana' => 'Bandana', 'beret' => 'Basco',
        'bucket' => 'Cappello da pescatore', 'pilot' => 'Casco da aviatore', 'headphones' => 'Cuffie', 'tophat' => 'Cilindro',
        'bowler' => 'Bombetta', 'fedora' => 'Fedora', 'cowboy' => 'Cappello da cowboy', 'sombrero' => 'Sombrero', 'straw' => 'Cappello di paglia',
        'party' => 'Cono da festa', 'wizard' => 'Cappello da mago', 'santa' => 'Cappello natalizio', 'jester' => 'Cappello da giullare',
        'pumpkin' => 'Zucca', 'flowers' => 'Corona di fiori', 'laurel' => 'Corona d\'alloro', 'crown' => 'Corona', 'halo' => 'Aureola',
        'trophy' => 'Coppa', 'flame' => 'Testa in fiamme', 'grad' => 'Tocco di laurea', 'viking' => 'Elmo vichingo', 'pirate' => 'Tricorno',
        'chef' => 'Cappello da cuoco', 'propeller' => 'Berretto con elica', 'military' => 'Elmetto', 'hardhat' => 'Casco da cantiere',
        'fireman' => 'Casco da pompiere', 'police' => 'Berretto con visiera', 'sailor' => 'Cappello da marinaio', 'captain' => 'Berretto da capitano',
        'astronaut' => 'Casco spaziale', 'knight' => 'Elmo da cavaliere', 'turban' => 'Turbante', 'fez' => 'Fez', 'cone' => 'Birillo',
        'catears' => 'Orecchie da gatto', 'rabbit' => 'Orecchie da coniglio', 'bear' => 'Orecchie d\'orso', 'antlers' => 'Corna di renna',
        'devil' => 'Corna', 'unicorn' => 'Corno di unicorno', 'alien' => 'Antenne', 'mushroom' => 'Cappello di fungo', 'icecream' => 'Gelato'];
    $tplPrice = [];
    foreach ($base['hat'] as $h) {
        $tplPrice[$h['tpl']] = min($tplPrice[$h['tpl']] ?? PHP_INT_MAX, (int) $h['price']);
    }
    foreach (shop_more_palettes() as $pc => [$pname, $a, $b, $c, $mul]) {
        foreach ($nouns as $tpl => $noun) {
            $out['hat']['x' . $tpl . '_' . $pc] = ['name' => $noun . ' ' . $pname, 'price' => $round(($tplPrice[$tpl] ?? 100) * $mul),
                'tpl' => $tpl, 'colors' => ['a' => $a, 'b' => $b, 'c' => $c], 'drop' => 'h_' . $pc];
        }
    }

    // esultanze: le nuove basi, poi ogni base × ogni variante
    $cels = [];
    foreach ($base['celebration'] as $k => $c) {
        $cels[$c['anim']] = [$c['name'], max(40, (int) $c['price'])];
    }
    $newBases = ['double-backflip' => ['Doppio backflip', 500], 'moonwalk' => ['Il moonwalk', 220], 'spin' => ['La piroetta', 150],
        'jump-pump' => ['Salto col pugno', 120], 'star-jump' => ['Salto a stella', 140], 'crowd' => ['Saluto alla curva', 90],
        'sky-kneel' => ['In ginocchio verso il cielo', 180], 'robot-dance' => ['Robot scatenato', 200]];
    foreach ($newBases as $anim => [$name, $price]) {
        $out['celebration']['cx_' . substr(md5($anim), 0, 5)] = ['name' => $name, 'price' => $price, 'anim' => $anim, 'drop' => 'c_new'];
        $cels[$anim] = [$name, $price];
    }
    $modMul = ['x2' => 1.4, 'm' => 1.1, 'f' => 1.2, 'fw' => 1.6, 'st' => 1.3, 'bo' => 1.4, 'co' => 1.8, 'he' => 1.3];
    foreach (px_celebration_mods() as $mc => [$mname]) {
        foreach ($cels as $anim => [$name, $price]) {
            $out['celebration']['cx_' . substr(md5($anim), 0, 5) . '_' . $mc] = ['name' => $name . ' ' . $mname,
                'price' => $round($price * $modMul[$mc]), 'anim' => $anim . '+' . $mc, 'drop' => 'c_' . $mc];
        }
    }

    // maglie: nazionali coi colori delle divise più note (niente stemmi) e combinazioni da club
    $nations = [
        ['arg2', 'Argentina (trasferta)', '#1f2a5c', '#a6e6ff', 'solid'], ['aus', 'Australia', '#ffd23f', '#1f8a4c', 'solid'],
        ['aut', 'Austria', '#d62828', '#ffffff', 'solid'], ['bel', 'Belgio', '#c0392b', '#1f1a2e', 'solid'], ['bol', 'Bolivia', '#1f8a4c', '#ffffff', 'solid'],
        ['bih', 'Bosnia', '#2a3f9b', '#ffd23f', 'solid'], ['bgr', 'Bulgaria', '#ffffff', '#1f8a4c', 'solid'], ['cmr', 'Camerun', '#1f8a4c', '#d62828', 'solid'],
        ['can', 'Canada', '#d62828', '#ffffff', 'solid'], ['chl', 'Cile', '#d62828', '#2a3f9b', 'solid'], ['chn', 'Cina', '#d62828', '#ffd23f', 'solid'],
        ['col', 'Colombia', '#ffd23f', '#2a3f9b', 'solid'], ['kor', 'Corea del Sud', '#d62828', '#1f1a2e', 'solid'], ['civ', 'Costa d\'Avorio', '#ff8c42', '#1f8a4c', 'solid'],
        ['cri', 'Costa Rica', '#d62828', '#2a3f9b', 'solid'], ['dnk', 'Danimarca', '#d62828', '#ffffff', 'solid'], ['ecu', 'Ecuador', '#ffd23f', '#2a3f9b', 'solid'],
        ['egy', 'Egitto', '#d62828', '#ffffff', 'solid'], ['sco', 'Scozia', '#1f2a5c', '#ffffff', 'solid'], ['gal', 'Galles', '#d62828', '#1f8a4c', 'solid'],
        ['fin', 'Finlandia', '#ffffff', '#2a3f9b', 'solid'], ['gha', 'Ghana', '#ffffff', '#1f1a2e', 'solid'], ['grc', 'Grecia', '#2a4fd6', '#ffffff', 'solid'],
        ['irl', 'Irlanda', '#1f8a4c', '#ffffff', 'solid'], ['nir', 'Irlanda del Nord', '#1f8a4c', '#ffffff', 'solid'], ['isl', 'Islanda', '#2a4fd6', '#d62828', 'solid'],
        ['irn', 'Iran', '#ffffff', '#d62828', 'solid'], ['isr', 'Israele', '#2a4fd6', '#ffffff', 'solid'], ['jam', 'Giamaica', '#ffd23f', '#1f8a4c', 'solid'],
        ['mar', 'Marocco', '#d62828', '#1f8a4c', 'solid'], ['mex2', 'Messico (trasferta)', '#ffffff', '#1f8a4c', 'solid'], ['nga', 'Nigeria', '#1f8a4c', '#ffffff', 'solid'],
        ['nor', 'Norvegia', '#d62828', '#1f2a5c', 'solid'], ['nzl', 'Nuova Zelanda', '#ffffff', '#1f1a2e', 'solid'], ['pan', 'Panama', '#d62828', '#2a3f9b', 'solid'],
        ['par', 'Paraguay', '#d62828', '#ffffff', 'stripes_v'], ['per', 'Perù', '#ffffff', '#d62828', 'sash'], ['pol', 'Polonia', '#ffffff', '#d62828', 'solid'],
        ['cze', 'Repubblica Ceca', '#d62828', '#2a3f9b', 'solid'], ['rou', 'Romania', '#ffd23f', '#2a3f9b', 'solid'], ['sen', 'Senegal', '#ffffff', '#1f8a4c', 'solid'],
        ['srb', 'Serbia', '#d62828', '#ffffff', 'solid'], ['svk', 'Slovacchia', '#ffffff', '#2a3f9b', 'solid'], ['svn', 'Slovenia', '#ffffff', '#1f8a4c', 'solid'],
        ['usa', 'Stati Uniti', '#ffffff', '#1f2a5c', 'solid'], ['rsa', 'Sudafrica', '#ffd23f', '#1f8a4c', 'solid'], ['swe', 'Svezia', '#ffd23f', '#2a4fd6', 'solid'],
        ['sui', 'Svizzera', '#d62828', '#ffffff', 'solid'], ['tun', 'Tunisia', '#ffffff', '#d62828', 'solid'], ['tur', 'Turchia', '#d62828', '#ffffff', 'solid'],
        ['ukr', 'Ucraina', '#ffd23f', '#2a4fd6', 'solid'], ['hun', 'Ungheria', '#d62828', '#ffffff', 'solid'], ['uru', 'Uruguay', '#a6e6ff', '#1f1a2e', 'solid'],
        ['ven', 'Venezuela', '#8a1c1c', '#ffd23f', 'solid'], ['alg', 'Algeria', '#ffffff', '#1f8a4c', 'solid'], ['ksa', 'Arabia Saudita', '#ffffff', '#1f8a4c', 'solid'],
        ['qat', 'Qatar', '#6d1a2c', '#ffffff', 'solid'], ['jpn2', 'Giappone (trasferta)', '#ffffff', '#1f2a5c', 'solid'], ['ita2', 'Italia (trasferta)', '#ffffff', '#2a3f9b', 'solid'],
        ['bra2', 'Brasile (trasferta)', '#2a4fd6', '#ffffff', 'solid'],
    ];
    foreach ($nations as [$code, $name, $a, $b, $pat]) {
        $out['jersey']['n2_' . $code] = ['name' => 'Nazionale ' . $name, 'price' => 110, 'kind' => 'national', 'colors' => ['a' => $a, 'b' => $b],
            'pattern' => $pat, 'drop' => 'j_naz'];
    }
    $col = shop_more_colors();
    $pairs = [['ros', 'bia'], ['ros', 'ner'], ['blu', 'bia'], ['nav', 'ros'], ['azz', 'bia'], ['ver', 'bia'], ['ver', 'ner'], ['gia', 'ner'],
        ['gia', 'blu'], ['ara', 'ner'], ['vio', 'bia'], ['gra', 'cel'], ['bor', 'ora'], ['ner', 'ora'], ['bia', 'ner'], ['cel', 'nav'],
        ['fuc', 'ner'], ['tur', 'bia'], ['gri', 'ros'], ['ros2', 'ner']];
    foreach (shop_more_jersey_patterns() as $pc => [$pat, $pname]) {
        foreach ($pairs as [$a, $b]) {
            $out['jersey']['jx_' . $a . $b . '_' . $pc] = ['name' => 'Maglia ' . $col[$a][0] . ' e ' . $col[$b][0] . ' ' . $pname,
                'price' => $pat === 'solid' ? 55 : 80, 'kind' => 'club', 'colors' => ['a' => $col[$a][1], 'b' => $col[$b][1]], 'pattern' => $pat,
                'drop' => 'j_' . $pc];
        }
    }

    // colori: capelli, pantaloncini, scarpette (lo stesso elenco, in parte)
    $i = 0;
    foreach ($col as $code => [$name, $hex]) {
        $i++;
        if ($i <= 50) {
            $out['hair_color']['hcx_' . $code] = ['name' => ucfirst($name), 'price' => 90 + ($i % 5) * 15, 'color' => $hex, 'drop' => 'hc'];
        }
        if ($i % 3 !== 0) {
            $out['shoes']['sx_' . $code] = ['name' => 'Scarpette ' . $name, 'price' => 45 + ($i % 4) * 10, 'color' => $hex, 'drop' => 'so'];
        }
        if ($i % 3 !== 1) {
            $out['shorts']['px_' . $code] = ['name' => 'Pantaloncini ' . $name, 'price' => 30 + ($i % 3) * 10, 'color' => $hex, 'drop' => 'sh'];
        }
    }
    foreach ([['sk_8', 'Porcellana', '#fff0e6'], ['sk_9', 'Rosata', '#f2b8a0'], ['sk_10', 'Dorata', '#dca66f'], ['sk_11', 'Caramello', '#9c6a3f'],
        ['sk_12', 'Ebano', '#4a2d18']] as [$k, $name, $hex]) {
        $out['skin'][$k] = ['name' => $name, 'price' => 0, 'color' => $hex, 'drop' => 'sk'];
    }

    // pet: gli stessi animali con colori nuovi
    foreach (['chick' => 'Pulcino', 'cat' => 'Gatto', 'dog' => 'Cane', 'penguin' => 'Pinguino'] as $animal => $name) {
        foreach (array_slice(shop_more_palettes(), 0, 8) as $pc => [$pname, $a, $b, $c, $mul]) {
            $out['pet']['pex_' . substr($animal, 0, 3) . $pc] = ['name' => $name . ' ' . $pname, 'price' => $round(220 * $mul), 'style' => $animal,
                'colors' => ['a' => $a, 'b' => $animal === 'penguin' ? '#ffffff' : $c], 'drop' => 'pe'];
        }
    }

    // nickname
    $nicks = [
        'n1' => ['Il Fulmine', 'La Saetta', 'Il Cecchino', 'La Roccia', 'Il Bomber', 'La Pantera', 'Il Toro', 'Il Condor', 'La Volpe', 'Il Lupo',
            'Il Ghepardo', 'L\'Aquila', 'Lo Scorpione', 'Il Rinoceronte', 'La Gazzella', 'Il Barracuda', 'Il Tornado', 'L\'Uragano', 'Il Vulcano',
            'La Valanga', 'Il Missile', 'Il Razzo', 'La Freccia', 'Il Cannone', 'Il Martello', 'La Diga', 'Il Castello', 'La Torre', 'Il Faro',
            'La Bussola', 'Il Metronomo', 'Il Direttore', 'L\'Architetto', 'Il Professore', 'Il Chirurgo', 'Il Pittore', 'Il Poeta', 'Il Pianista',
            'Il Violinista', 'Il Ballerino'],
        'n2' => ['Il Panchinaro d\'Oro', 'Mister Assist', 'Re del Dribbling', 'Il Tunnelista', 'L\'Uomo in Più', 'Il Dodicesimo', 'Il Capitano',
            'Il Veterano', 'Il Rookie', 'Il Talento', 'La Promessa', 'Il Mediano', 'Il Libero', 'Il Regista', 'Il Trequartista', 'Il Falso Nove',
            'Il Terzino Volante', 'La Mezzala', 'Il Portierone', 'Il Para-rigori', 'Mani di Colla', 'Piede Fatato', 'Sinistro Magico',
            'Destro Fulminante', 'Colpo di Tacco', 'Re del Pallonetto', 'Il Rovesciatore', 'Il Colpitore di Testa', 'Il Pressatore',
            'L\'Instancabile', 'Il Maratoneta', 'Il Velocista', 'Il Contropiedista', 'Il Tattico', 'Lo Stratega', 'Il Motivatore',
            'L\'Allenatore in Campo', 'Il Leader', 'Il Silenzioso', 'Il Chiacchierone'],
        'n3' => ['Il Divino', 'Il Fenomeno Vero', 'Il Pibe', 'Il Kaiser', 'Il Pallone d\'Oro', 'La Leggenda', 'Il Mito', 'Il Numero Uno',
            'L\'Imperatore', 'Il Re del Calcetto', 'Il Principe', 'Il Duca', 'Il Conte', 'Il Barone', 'Il Marchese', 'Sua Maestà',
            'Il Campione del Mondo', 'Il Recordman', 'L\'Invincibile', 'L\'Immortale', 'Il Predestinato', 'L\'Eletto', 'Il Fuoriclasse',
            'Il Genio', 'L\'Artista', 'Il Mago del Pallone', 'Lo Stregone', 'L\'Alchimista', 'Il Pifferaio', 'Il Signore degli Assist',
            'Il Padrone del Campo', 'Il Dominatore', 'Il Conquistatore', 'Il Titano', 'Il Colosso', 'Il Gigante Buono', 'L\'Highlander',
            'Il Guerriero', 'Il Samurai', 'Il Ninja'],
    ];
    $prices = ['n1' => 60, 'n2' => 90, 'n3' => 160];
    foreach ($nicks as $drop => $names) {
        foreach ($names as $j => $name) {
            $slug = substr(preg_replace('/[^a-z]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name))), 0, 11);
            $out['nick']['nk_' . $slug] = ['name' => $name, 'price' => $prices[$drop] + ($j % 4) * 15, 'drop' => $drop];
        }
    }
    return $out;
}
