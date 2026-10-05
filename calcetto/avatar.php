<?php
/*
 * Personaggio: il giocatore a figura intera (disegno in lib/avatar.php) e il suo negozio, a KOIN come il Negozio del profilo
 * (lib/shop.php). Capelli, barba, occhiali, maglie, pantaloncini, scarpette, pet, pose ed esultanze; carnagione e colori naturali dei
 * capelli sono gratis per tutti. I copricapi sono quelli del Negozio: comprati o indossati qui o là, si vedono in tutti e due i posti.
 *
 * Funziona anche senza JavaScript: «Prova» è un link che mostra l'oggetto addosso al personaggio (?try=chiave); lo script in fondo
 * fa lo stesso senza ricaricare e anima pose ed esultanze. Prima del lancio la vede solo l'admin; si apre a tutti alla scadenza del
 * countdown in Home (lib/guess.php: avatar_public), con solo una parte degli oggetti: gli altri sono «in arrivo», senza nome.
 */
require __DIR__ . '/lib/bootstrap.php';
require_login();
if (!avatar_visible() || is_guest()) {
    redirect('index.php');
}

$me = my_player_id();
if (!$me) {
    flash('err', 'Il tuo account non è collegato a un giocatore: chiedi all\'admin.');
    redirect('profile.php');
}

$kinds = avatar_kinds();
$cat = isset($kinds[$_GET['c'] ?? '']) ? (string) $_GET['c'] : 'hair';
$view = in_array($_GET['v'] ?? '', ['mine', 'wish'], true) ? (string) $_GET['v'] : 'shop';
$page = max(1, (int) ($_GET['p'] ?? 1));
const AV_PER_PAGE = 48;
$rarities = ['base' => 'Base', 'comune' => 'Comune', 'raro' => 'Raro', 'epico' => 'Epico', 'leggendario' => 'Leggendario'];
$rar = isset($rarities[$_GET['r'] ?? '']) ? (string) $_GET['r'] : '';
$sort = ($_GET['o'] ?? '') === 'desc' ? 'desc' : 'asc';
$try = (string) ($_GET['try'] ?? '');

$url = function (array $set = []) use ($cat, $view, $rar, $sort, $page): string {
    $q = array_filter(array_merge(['c' => $cat, 'v' => $view !== 'shop' ? $view : '', 'r' => $rar, 'o' => $sort === 'desc' ? 'desc' : '',
        'p' => $page > 1 ? $page : ''], $set),
        fn($v) => $v !== '' && $v !== null);
    return 'avatar.php' . ($q ? '?' . http_build_query($q) : '');
};

/* ---------------------------------------------------------------- azioni */
if (is_post() && ($_POST['do'] ?? '') === 'figure') {
    // figura del personaggio: è il genere del profilo (lo stesso di Modifica profilo)
    $g = (string) ($_POST['gender'] ?? '');
    if (isset(genders()[$g])) {
        q('UPDATE players SET gender = ? WHERE id = ?', [$g, $me]);
        flash('ok', 'Figura aggiornata: ' . mb_strtolower(genders()[$g]) . '.');
    }
    redirect($url() . '#personaggio');
}
if (is_post() && ($_POST['do'] ?? '') === 'body') {
    // corporatura: altezza e peso, gratis; «Non dirlo» torna alle misure del disegno di base
    $clear = isset($_POST['clear']);
    $num = fn(string $k) => !$clear && is_numeric($_POST[$k] ?? null) ? (int) round((float) $_POST[$k]) : null;
    $err = avatar_set_body($me, $num('height'), $num('weight'));
    flash($err ? 'err' : 'ok', $err ?: ($clear ? 'Il personaggio è tornato alle misure di base.' : 'Corporatura salvata: il personaggio ha le tue proporzioni.'));
    redirect($url() . '#personaggio');
}
if (is_post()) {
    $kind = (string) ($_POST['kind'] ?? '');
    $key = (string) ($_POST['key'] ?? '');
    $do = $_POST['do'] ?? '';
    if (isset($kinds[$kind])) {
        $item = $key !== '' ? shop_item($kind, $key) : null;
        if ($do === 'buy' && $item) {
            wallet_open($me);
            $err = shop_buy($me, $kind, $key, null, true) ?? shop_equip($me, $kind, $key);
            if (!$err) {
                log_activity('negozio', $kind . ' · ' . $item['name']);
            }
            flash($err ? 'err' : 'ok', $err ?: 'Comprato e indossato: «' . $item['name'] . '»!');
        } elseif ($do === 'wish' && $item) {
            $on = shop_wish_toggle($me, $key);
            if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {   // il cuoricino premuto senza ricaricare la pagina
                header('Content-Type: application/json');
                echo json_encode(['on' => $on, 'n' => shop_market(true)['wish'][$key] ?? 0]);
                exit;
            }
            flash('ok', $on ? '«' . $item['name'] . '» è tra i tuoi obiettivi.' : 'Tolto dagli obiettivi.');
        } elseif ($do === 'wear') {
            $err = shop_equip($me, $kind, $key !== '' ? $key : null);
            flash($err ? 'err' : 'ok', $err ?: ($item ? '«' . $item['name'] . '» ' . ($kind === 'celebration' ? 'è la tua esultanza.' : 'indossato.') : 'Tolto.'));
        }
    }
    $r = (string) ($_POST['r'] ?? '');
    redirect($url(['c' => isset($kinds[$kind]) ? $kind : $cat, 'try' => $key, 'v' => in_array($_POST['v'] ?? '', ['mine', 'wish'], true) ? $_POST['v'] : '',
        'p' => max(1, (int) ($_POST['p'] ?? 1)) > 1 ? (int) $_POST['p'] : '',
        'r' => isset($rarities[$r]) ? $r : '', 'o' => ($_POST['o'] ?? '') === 'desc' ? 'desc' : '']) . '#personaggio');
}

/* ---------------------------------------------------------------- dati */
$dole = wallet_open($me);
if ($dole) {
    flash('ok', $dole);
}
$mp = get_player($me);
$look = avatar_look($mp);
$owned = shop_owned($me) + ['' => true];
shop_credits_grant($me);
$balance = shop_spendable($me);   // KOIN + crediti del Personaggio
$avCredits = shop_credit_balance($me);
$catalog = shop_catalog();
$number = $mp['shirt_number'];
$wish = shop_wishlist($me);
$wishN = shop_market()['wish'];
$admin = is_admin();

/**
 * Oggetti di un tipo che questo giocatore può vedere (le maglie create dagli altri no; tra i copricapi anche «nessuno»).
 * Quelli non ancora usciti (drops.php) li vede solo l'admin, in anteprima ("out"), e chi li ha già.
 */
$itemsOf = function (string $kind) use ($catalog, $me, $owned, $admin): array {
    static $cache = [];
    if (isset($cache[$kind])) {
        return $cache[$kind];
    }
    $items = $kind === 'hat' ? ['' => ['name' => 'Nessun copricapo', 'price' => 0, 'out' => false]] : [];
    foreach ($catalog[$kind] as $k => $item) {
        $out = !shop_released((string) $k, $item);
        if ((!isset($item['owner_player_id']) || $item['owner_player_id'] === $me) && (!$out || $admin || isset($owned[$k]))
            && (!isset($item['fanta']) || isset($owned[$k]))) {   // i premi del Fanta si vedono solo da chi li ha vinti
            $items[$k] = $item + ['out' => $out];
        }
    }
    return $cache[$kind] = $items;
};
$priceOf = fn(string $key, array $item) => shop_price($key, $item, $me);   // il prezzo di adesso (lib/shop.php: shop_price)
$wornKey = fn(string $kind) => $kind === 'hat' ? (string) $look['hat'] : $look[$kind];

// schede Negozio / Guardaroba e categorie: quanti oggetti ci sono e quanti ne ho
$count = [];
$totAll = $totMine = 0;
foreach ($kinds as $k => $_) {
    $all = $itemsOf($k);
    $count[$k] = [count(array_filter($all, fn($i) => !$i['out'])), count(array_intersect_key($all, $owned))];
    $totAll += $count[$k][0];
    $totMine += $count[$k][1];
}

$items = [];
foreach ($itemsOf($cat) as $k => $item) {
    [$rk] = avatar_rarity($item['price']);
    if (($view === 'mine' && !isset($owned[$k])) || ($view === 'wish' && !isset($wish[$k])) || ($rar !== '' && $rk !== $rar)) {
        continue;
    }
    $items[(string) $k] = $item + ['now' => $priceOf((string) $k, $item)[0]];
}
uasort($items, fn($a, $b) => [$a['out'], $sort === 'desc' ? -(int) $a['now'] : (int) $a['now']] <=> [$b['out'], $sort === 'desc' ? -(int) $b['now'] : (int) $b['now']]);
$pages = max(1, (int) ceil(count($items) / AV_PER_PAGE));
$page = min($page, $pages);
$items = array_slice($items, ($page - 1) * AV_PER_PAGE, AV_PER_PAGE, true);
// «in arrivo»: quanti oggetti di questa categoria non sono ancora usciti. Chi non è admin vede solo il numero, mai nomi o figure
$soon = 0;
if (!$admin && $view === 'shop') {
    foreach ($catalog[$cat] as $k => $item) {
        if (!isset($owned[$k]) && !isset($item['owner_player_id']) && !shop_released((string) $k, $item)) {
            $soon++;
        }
    }
}
$presentRar = [];
foreach ($itemsOf($cat) as $item) {
    $presentRar[avatar_rarity($item['price'])[0]] = true;
}

// oggetto mostrato nel pannello: quello che si sta provando, sennò quello indossato
$selected = array_key_exists($try, $itemsOf($cat)) ? $try : $wornKey($cat);
$lookWith = function (string $key) use ($look, $cat): array {
    $l = $look;
    $l[$cat] = $cat === 'hat' ? ($key !== '' ? $key : null) : $key;
    return $l;
};

$kindDesc = [
    'hair_color' => 'Tinta per capelli, sopracciglia e barba.',
    'skin' => 'La carnagione del tuo personaggio: sono tutte gratis.',
    'beard' => 'Barba e baffi prendono il colore dei capelli.',
    'glasses' => 'Per vederci meglio, o solo per stile.',
    'hat' => 'È lo stesso copricapo del Negozio: si vede anche sul tuo profilo e nella Rosa.',
    'shorts' => 'Pantaloncini da gara.',
    'shoes' => 'Scarpette da calcetto, tacchetti corti.',
    'pet' => 'Ti aspetta a bordo campo, sempre.',
    'pose' => 'Come sta in posa il tuo personaggio.',
    'celebration' => 'Premi «Esulta!» per vederla.',
];
$jerseyKind = ['club' => 'Maglia da club', 'national' => 'Maglia della nazionale', 'custom' => 'Creata da te'];
$patterns = avatar_patterns();

$stateOf = function (string $key, array $item) use ($cat, $owned, $wornKey): string {
    return $wornKey($cat) === $key ? 'worn' : (isset($owned[$key]) ? ($item['price'] ? 'owned' : 'free') : 'buy');
};

/** Il pannello sotto il personaggio: nome, rarità, descrizione e il pulsante giusto (compra, indossa, già tuo). */
$infoHtml = function (string $key, array $item) use ($cat, $kinds, $kindDesc, $jerseyKind, $patterns, $balance, $avCredits, $stateOf, $view, $rar, $sort, $page, $priceOf, $wish, $wishN): string {
    [$rk, $rl] = avatar_rarity($item['price']);
    $desc = $item['desc'] ?? ($cat === 'jersey'
        ? $jerseyKind[$item['kind']] . (($item['pattern'] ?? 'solid') !== 'solid' ? ' · ' . mb_strtolower($patterns[$item['pattern']]) : '') . '.'
        : ($kindDesc[$cat] ?? ''));
    $state = $stateOf($key, $item);
    $form = function (string $do, string $label, string $cls, bool $disabled = false) use ($cat, $key, $view, $rar, $sort, $page): string {
        return '<form method="post" class="av-act">' . csrf_field()
            . '<input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="kind" value="' . h($cat) . '"><input type="hidden" name="key" value="' . h($key) . '">'
            . '<input type="hidden" name="v" value="' . h($view) . '"><input type="hidden" name="r" value="' . h($rar) . '"><input type="hidden" name="o" value="' . h($sort) . '">'
            . '<input type="hidden" name="p" value="' . $page . '">'
            . '<button class="btn ' . $cls . ' btn-block"' . ($disabled ? ' disabled' : '') . '>' . $label . '</button></form>';
    };
    [$price, $why] = $key !== '' ? $priceOf($key, $item) : [0, null];
    $wishBtn = $key !== '' && ($item['price'] ?? 0) > 0 && !isset($item['fanta'])
        ? '<form method="post" class="av-wish">' . csrf_field() . '<input type="hidden" name="do" value="wish"><input type="hidden" name="kind" value="' . h($cat) . '">'
            . '<input type="hidden" name="key" value="' . h($key) . '"><input type="hidden" name="v" value="' . h($view) . '"><input type="hidden" name="p" value="' . $page . '">'
            . '<button class="wish' . (isset($wish[$key]) ? ' is-on' : '') . '" data-wish aria-pressed="' . (isset($wish[$key]) ? 'true' : 'false') . '" title="'
            . (isset($wish[$key]) ? 'Togli dagli obiettivi' : 'Aggiungi agli obiettivi') . '"><i class="ti ti-heart' . (isset($wish[$key]) ? '-filled' : '') . '"></i> <span data-wish-n>'
            . ($wishN[$key] ?? 0) . '</span></button></form>'
        : '';
    $word = $cat === 'celebration' ? ['Scegli questa esultanza', 'È la tua esultanza'] : ($cat === 'pose' ? ['Usa questa posa', 'È la tua posa'] : ['Indossa', 'Lo indossi']);
    if ($state === 'worn') {
        $act = '<p class="av-state is-worn"><i class="ti ti-circle-check"></i> ' . $word[1] . '</p>';
    } elseif ($state === 'buy') {
        $short = $balance < (int) $price;
        $out = !empty($item['out']);
        $act = '<p class="av-price"><i class="ti ti-coin"></i> ' . (int) $price . ' <span>KOIN</span>'
            . ($price !== $item['price'] ? ' <span class="price-move ' . ($price > $item['price'] ? 'is-up' : 'is-down') . '"><i class="ti ti-trending-'
                . ($price > $item['price'] ? 'up' : 'down') . '"></i> base ' . (int) $item['price'] . '</span>' : '') . '</p>'
            . ($why ? '<p class="small av-why">' . h(shop_price_note($why)) . '</p>' : '')
            . ($out ? '<p class="av-state"><i class="ti ti-eye-off"></i> Non ancora uscito: lo fai uscire da <a class="link" href="drops.php">Uscite</a>.</p>'
                : $form('buy', '<i class="ti ti-shopping-bag"></i> Compra e ' . ($cat === 'celebration' || $cat === 'pose' ? 'usa' : 'indossa'), 'btn-primary', $short)
                . ($short ? '<p class="small av-short">Ti mancano ' . ((int) $price - $balance) . ' KOIN (hai ' . ($balance - $avCredits) . ' KOIN + ' . $avCredits . ' crediti Personaggio): <a class="link" href="bets.php">vai a scommettere</a>.</p>' : ''));
    } else {
        $act = '<p class="av-state"><i class="ti ti-' . ($state === 'free' ? 'gift' : 'hanger') . '"></i> ' . ($state === 'free' ? 'Incluso per tutti' : 'Nel tuo guardaroba') . '</p>'
            . $form('wear', $word[0], 'btn-primary');
    }
    return '<div class="av-info-top"><span class="av-info-kind">' . h($kinds[$cat]) . '</span>' . $wishBtn . '<span class="rar rar-' . $rk . '">' . $rl . '</span></div>'
        . '<h2 class="av-info-name">' . h($item['name']) . '</h2>'
        . ($desc !== '' ? '<p class="av-info-desc">' . h($desc) . '</p>' : '') . $act;
};

$catIcons = ['hair' => 'scissors', 'hair_color' => 'palette', 'skin' => 'hand-stop', 'beard' => 'mood-smile-beam', 'glasses' => 'eyeglass',
    'hat' => 'hat', 'jersey' => 'shirt-sport', 'shorts' => 'hanger', 'shoes' => 'shoe', 'pet' => 'paw', 'pose' => 'accessible',
    'celebration' => 'confetti'];
$crop = ['hair' => 'head', 'hair_color' => 'head', 'beard' => 'head', 'glasses' => 'head', 'hat' => 'head', 'jersey' => 'torso',
    'shorts' => 'legs', 'shoes' => 'feet'][$cat] ?? '';
$stageLook = $lookWith($selected);

$unseen = shop_news_unseen();   // tipi con oggetti nuovi non ancora guardati (pallino rosso, per chi non ha le notifiche)
if (!isset($_GET['fig'])) {
    shop_news_seen($cat);
}

// «Prova» dal catalogo: la pagina chiede solo il personaggio del palco (avatar_px.js lo mette al posto di quello di prima);
// con bh/bw (cursori della corporatura) lo si vede con quell'altezza e quel peso prima di salvarli
if (isset($_GET['fig'])) {
    foreach (['bh' => 'height', 'bw' => 'weight'] as $q => $k) {
        if (is_numeric($_GET[$q] ?? null)) {
            $stageLook[$k] = max(AVATAR_BODY[$k][0], min(AVATAR_BODY[$k][1], (int) $_GET[$q]));
        }
    }
    echo avatar_figure($stageLook, ['number' => $number, 'stage' => true, 'label' => 'Il personaggio di ' . $mp['name']]);
    exit;
}

layout_start('Personaggio', 'avatar');
?>
<div class="page-head"><h1>Personaggio</h1><?php if (!avatar_public()): ?><span class="tag tag-admin"><i class="ti ti-flask"></i> in prova · solo admin</span><?php endif; ?></div>
<?= $me ? eco_switch($me, 'avatar.php') : '' ?>

<div class="av-layout">
  <aside class="av-side" id="personaggio">
    <section class="card av-panel">
      <div class="av-panel-head">
        <span class="av-panel-title"><i class="ti ti-user-star"></i> Il tuo personaggio</span>
        <div class="av-panel-btns">
          <button type="button" class="btn btn-sm btn-ghost" data-av-play hidden><i class="ti ti-confetti"></i> Esulta!</button>
          <button type="button" class="btn btn-sm btn-ghost" data-av-pause hidden aria-pressed="false"><i class="ti ti-player-pause"></i> Pausa</button>
        </div>
      </div>
      <div class="av-stage" data-av-stage<?= $cat === 'celebration' && $try !== '' ? ' data-autoplay="1"' : '' ?>>
        <?= avatar_figure($stageLook, ['number' => $number, 'stage' => true, 'label' => 'Il personaggio di ' . $mp['name']]) ?>
      </div>
      <div class="av-who"><strong><?= h($mp['name']) ?></strong><?= nick_html($mp) ?></div>
      <form method="post" class="av-figure" aria-label="Figura">
        <?= csrf_field() ?><input type="hidden" name="do" value="figure">
        <span class="av-figure-label"><i class="ti ti-user"></i> Figura</span>
        <?php foreach (genders() as $g => $gl): ?>
        <button class="av-figure-opt<?= $look['figure'] === $g ? ' is-on' : '' ?>" name="gender" value="<?= h($g) ?>" aria-pressed="<?= $look['figure'] === $g ? 'true' : 'false' ?>">
          <i class="ti ti-<?= h(gender_icon($g)) ?>"></i> <?= h($gl) ?></button>
        <?php endforeach; ?>
      </form>
      <details class="av-body">
        <summary><i class="ti ti-ruler-measure"></i> Corporatura
          <span class="av-body-now"><?= $look['height'] || $look['weight']
              ? h(trim(($look['height'] ? number_format($look['height'] / 100, 2, ',', '') . ' m' : '') . ($look['height'] && $look['weight'] ? ' · ' : '') . ($look['weight'] ? $look['weight'] . ' kg' : '')))
              : 'misure di base' ?></span></summary>
        <form method="post" class="av-body-form" data-av-body>
          <?= csrf_field() ?><input type="hidden" name="do" value="body">
          <p class="small">Altezza e peso danno le proporzioni al tuo personaggio: più alto o più basso, più robusto o più snello. Sono gratis e le puoi cambiare quando vuoi.</p>
          <?php foreach (['height' => ['Altezza', 'cm'], 'weight' => ['Peso', 'kg']] as $k => [$lbl, $unit]): [$min, $max, $def] = AVATAR_BODY[$k]; ?>
          <label class="av-body-row">
            <span><?= $lbl ?></span>
            <input type="range" name="<?= $k ?>" min="<?= $min ?>" max="<?= $max ?>" step="1" value="<?= (int) ($look[$k] ?? $def) ?>" data-unit="<?= $unit ?>">
            <output><?= (int) ($look[$k] ?? $def) ?> <?= $unit ?></output>
          </label>
          <?php endforeach; ?>
          <div class="av-body-btns">
            <button class="btn btn-primary btn-sm"><i class="ti ti-check"></i> Salva</button>
            <?php if ($look['height'] || $look['weight']): ?><button class="btn btn-ghost btn-sm" name="clear" value="1"><i class="ti ti-arrow-back-up"></i> Non dirlo</button><?php endif; ?>
          </div>
        </form>
      </details>
      <div class="av-info" data-av-info aria-live="polite"><?= $infoHtml($selected, $itemsOf($cat)[$selected]) ?></div>
    </section>
  </aside>

  <div class="av-main">
    <nav class="shop-tabs av-tabs">
      <a href="<?= h($url(['v' => '', 'p' => ''])) ?>" class="<?= $view === 'shop' ? 'active' : '' ?>"><i class="ti ti-building-store"></i> Negozio <span class="count"><?= $totAll ?></span></a>
      <a href="<?= h($url(['v' => 'mine', 'p' => ''])) ?>" class="<?= $view === 'mine' ? 'active' : '' ?>"><i class="ti ti-hanger"></i> Guardaroba <span class="count"><?= $totMine ?></span></a>
      <a href="<?= h($url(['v' => 'wish', 'p' => ''])) ?>" class="<?= $view === 'wish' ? 'active' : '' ?>"><i class="ti ti-heart"></i> Obiettivi <span class="count"><?= count($wish) ?></span></a>
      <?php if ($admin): ?><a href="drops.php" class="av-drops"><i class="ti ti-rocket"></i> Uscite</a><?php endif; ?>
      <span class="av-coins"><i class="ti ti-coin"></i> <strong><?= $balance ?></strong> KOIN<?php if ($avCredits): ?> <small>(di cui <?= $avCredits ?> solo per il Personaggio)</small><?php endif; ?></span>
    </nav>

    <nav class="av-cats" aria-label="Categorie">
      <?php $i = 0; foreach ($kinds as $k => $label): $i++; ?>
      <a href="<?= h($url(['c' => $k, 'r' => '', 'p' => ''])) ?>" class="av-cat<?= $k === $cat ? ' active' : '' ?>"<?= $k === $cat ? ' aria-current="page"' : '' ?>>
        <span class="av-cat-n"><?= sprintf('%02d', $i) ?> <i class="ti ti-<?= $catIcons[$k] ?>"></i><?php if (isset($unseen[$k]) && $k !== $cat): ?><span class="news-dot" title="Oggetti nuovi"></span><?php endif; ?></span>
        <span class="av-cat-l"><?= h($label) ?></span>
        <span class="av-cat-c"><?= $count[$k][1] ?>/<?= $count[$k][0] ?></span>
      </a>
      <?php endforeach; ?>
    </nav>

    <div class="av-head">
      <h2><?= h($kinds[$cat]) ?></h2>
      <div class="sortbar av-filters">
        <a href="<?= h($url(['r' => '', 'p' => ''])) ?>" class="<?= $rar === '' ? 'active' : '' ?>">Tutte</a>
        <?php foreach ($rarities as $rk => $rl): if (!isset($presentRar[$rk])) continue; ?>
          <a href="<?= h($url(['r' => $rk, 'p' => ''])) ?>" class="<?= $rar === $rk ? 'active' : '' ?>"><?= $rl ?></a>
        <?php endforeach; ?>
        <a href="<?= h($url(['o' => $sort === 'desc' ? '' : 'desc', 'p' => ''])) ?>" class="av-sort" title="Cambia ordine"><i class="ti ti-arrows-sort"></i> Prezzo <?= $sort === 'desc' ? 'decrescente' : 'crescente' ?></a>
      </div>
    </div>

    <div class="av-grid">
      <?php if ($cat === 'jersey' && $view === 'shop' && $admin): // Crea la tua maglia: ancora in prova, solo admin ?>
      <a class="av-item av-item-new" href="jersey_creator.php">
        <span class="av-item-plus"><i class="ti ti-brush"></i></span>
        <span class="av-item-name">Crea la tua maglia</span>
        <span class="av-item-state">Colori e motivo li scegli tu: gratis</span>
        <span class="av-item-try">Crea <i class="ti ti-arrow-right"></i></span>
      </a>
      <?php endif; ?>
      <?php foreach ($items as $key => $item):
          $key = (string) $key;
          [$rk, $rl] = avatar_rarity($item['price']);
          $state = $stateOf($key, $item);
          $pl = $lookWith($key); ?>
      <article class="av-item rar-<?= $rk ?><?= $state === 'worn' ? ' is-worn' : '' ?><?= $key === $selected ? ' is-trying' : '' ?><?= $item['out'] ? ' is-out' : '' ?>" data-av-item data-kind="<?= h($cat) ?>">
        <a class="av-item-link" href="<?= h($url(['try' => $key])) ?>#personaggio" data-av-try>
          <span class="av-item-top"><span class="rar rar-<?= $rk ?>"><?= $item['out'] ? 'Non uscito' : $rl ?></span><?php if (!$item['out'] && shop_is_new($key)): ?><span class="tag-new">Nuovo</span><?php endif; ?><?php if (isset($wish[$key])): ?><i class="ti ti-heart-filled av-item-wish" title="Tra i tuoi obiettivi"></i><?php endif; ?>
            <span class="av-item-mark" title="<?= ['worn' => 'Indossato', 'owned' => 'Nel guardaroba', 'free' => 'Incluso', 'buy' => 'Da comprare'][$state] ?>"><i class="ti ti-<?= ['worn' => 'check', 'owned' => 'hanger', 'free' => 'gift', 'buy' => 'plus'][$state] ?>"></i></span></span>
          <span class="av-item-fig"><?= avatar_figure($pl, ['number' => $number, 'crop' => $crop] + ($cat === 'celebration' ? ['hint' => $item['anim']] : [])) ?></span>
          <span class="av-item-name"><?= h($item['name']) ?></span>
          <span class="av-item-state"><?php if ($state === 'worn'): ?><?= $cat === 'celebration' || $cat === 'pose' ? 'In uso' : 'Indossato' ?>
            <?php elseif ($state === 'free'): ?>Incluso
            <?php elseif ($state === 'owned'): ?>Nel guardaroba
            <?php else: ?><span class="av-cost<?= $balance < (int) $item['now'] ? ' is-short' : '' ?>"><i class="ti ti-coin"></i> <?= (int) $item['now'] ?><?php if ($item['now'] !== $item['price']): ?> <i class="ti ti-trending-<?= $item['now'] > $item['price'] ? 'up' : 'down' ?> price-move <?= $item['now'] > $item['price'] ? 'is-up' : 'is-down' ?>"></i><?php endif; ?></span><?php endif; ?></span>
          <span class="av-item-try"><?= $cat === 'celebration' ? 'Guarda' : 'Prova' ?> <i class="ti ti-arrow-right"></i></span>
        </a>
        <template><?= $infoHtml($key, $item) ?></template>
      </article>
      <?php endforeach; ?>
      <?php if ($soon && $page === $pages): ?>
      <div class="av-item av-item-new av-item-soon" aria-label="Altri oggetti in arrivo">
        <span class="av-item-plus"><i class="ti ti-lock"></i></span>
        <span class="av-item-name">Coming soon</span>
        <span class="av-item-state"><?= $soon === 1 ? 'Un altro oggetto è in arrivo' : 'Altri ' . $soon . ' oggetti in arrivo' ?>: usciranno un po' alla volta</span>
        <span class="av-item-try"><i class="ti ti-hourglass"></i> In arrivo</span>
      </div>
      <?php endif; ?>
    </div>
    <?php if (!$items && !$soon): ?><p class="empty card"><?= $view === 'mine' ? 'Nel guardaroba non hai niente di questo tipo con questo filtro.' : ($view === 'wish' ? 'Nessun obiettivo di questo tipo: premi il cuoricino su un oggetto per aggiungerlo.' : 'Niente da mostrare con questo filtro.') ?></p><?php endif; ?>
    <?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Pagine">
      <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= h($url(['p' => $page - 1 > 1 ? $page - 1 : ''])) ?>"><i class="ti ti-chevron-left"></i> Indietro</a><?php endif; ?>
      <span class="pager-n">Pagina <?= $page ?> di <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= h($url(['p' => $page + 1])) ?>">Avanti <i class="ti ti-chevron-right"></i></a><?php endif; ?>
    </nav>
    <?php endif; ?>
  </div>
</div>

<script src="assets/wish.js?v=<?= h(substr((string) @md5_file(__DIR__ . '/assets/wish.js'), 0, 10)) ?>"></script>
<script src="assets/avatar_px.js?v=<?= h(substr((string) @md5_file(__DIR__ . '/assets/avatar_px.js'), 0, 10)) ?>"></script>
<script>
(() => {
  const stage = document.querySelector('[data-av-stage]');
  const info = document.querySelector('[data-av-info]');
  const playBtn = document.querySelector('[data-av-play]');
  const pauseBtn = document.querySelector('[data-av-pause]');
  const P = window.PixelAvatar;
  if (!stage || !info || !P) return;
  playBtn.hidden = pauseBtn.hidden = false;
  const colors = ['#ffd23f', '#ff6b9a', '#53c8f5', '#38d178', '#ff8c42', '#a67cf2', '#ffffff'];
  const confetti = () => {
    stage.querySelectorAll('.av-confetti').forEach(c => c.remove());
    for (let i = 0; i < 34; i++) {
      const c = document.createElement('span');
      c.className = 'av-confetti';
      c.style.cssText = `--x:${Math.random() * 100}%;--c:${colors[i % colors.length]};--dx:${(Math.random() - .5) * 90}px;--r:${(Math.random() - .5) * 900}deg;`
        + `--t:${1.6 + Math.random() * 1.2}s;--d:${Math.random() * .5}s`;
      stage.append(c);
    }
    setTimeout(() => stage.querySelectorAll('.av-confetti').forEach(c => c.remove()), 3400);
  };
  const svg = () => stage.querySelector('.avf');
  const play = () => { if (P.play(svg())) confetti(); };
  const setPause = (v) => {
    stage.classList.toggle('is-paused', v);
    P.setPaused(v);
    pauseBtn.setAttribute('aria-pressed', v ? 'true' : 'false');
    pauseBtn.innerHTML = v ? '<i class="ti ti-player-play"></i> Riprendi' : '<i class="ti ti-player-pause"></i> Pausa';
  };
  playBtn.addEventListener('click', () => { setPause(false); play(); });
  pauseBtn.addEventListener('click', () => setPause(!stage.classList.contains('is-paused')));
  let req = 0;
  document.querySelectorAll('[data-av-try]').forEach(link => link.addEventListener('click', async e => {
    if (e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    const card = link.closest('[data-av-item]');
    const href = link.getAttribute('href').split('#')[0];
    info.replaceChildren(card.querySelector('template').content.cloneNode(true));
    document.querySelectorAll('[data-av-item].is-trying').forEach(c => c.classList.remove('is-trying'));
    card.classList.add('is-trying');
    history.replaceState(null, '', href);
    if (window.matchMedia('(max-width: 899px)').matches) stage.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const mine = ++req;
    try {
      const res = await fetch(href + (href.includes('?') ? '&' : '?') + 'fig=1', { credentials: 'same-origin' });
      if (!res.ok || mine !== req) { if (!res.ok) location.href = href; return; }
      const html = await res.text();
      if (mine !== req) return;
      const old = svg();
      if (old) P.stop(old);
      setPause(false);
      stage.querySelectorAll('.avf').forEach(n => n.remove());
      stage.insertAdjacentHTML('afterbegin', html);
      const fig = svg();
      fig.classList.add('is-new');
      P.idle(fig);
      if (card.dataset.kind === 'celebration') play();
    } catch (err) { location.href = href; }
  }));
  if (stage.dataset.autoplay) play();

  // corporatura: il personaggio sul palco cambia mentre si muovono i cursori (si salva solo con «Salva»)
  const body = document.querySelector('[data-av-body]');
  let bodyReq = 0, bodyT = 0;
  if (body) body.addEventListener('input', e => {
    if (e.target.type !== 'range') return;
    e.target.nextElementSibling.textContent = e.target.value + ' ' + e.target.dataset.unit;
    clearTimeout(bodyT);
    bodyT = setTimeout(async () => {
      const mine = ++bodyReq;
      const href = location.pathname + location.search;
      try {
        const res = await fetch(href + (href.includes('?') ? '&' : '?') + 'fig=1&bh=' + body.elements.height.value + '&bw=' + body.elements.weight.value, { credentials: 'same-origin' });
        if (!res.ok || mine !== bodyReq) return;
        const html = await res.text();
        if (mine !== bodyReq) return;
        const old = svg();
        if (old) P.stop(old);
        stage.querySelectorAll('.avf').forEach(n => n.remove());
        stage.insertAdjacentHTML('afterbegin', html);
        P.idle(svg());
      } catch (err) { /* resta il personaggio di prima */ }
    }, 120);
  });
})();
</script>
<?php
layout_end();
