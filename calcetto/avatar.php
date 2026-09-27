<?php
/*
 * Personaggio: il giocatore a figura intera (disegno in lib/avatar.php) e il suo negozio, a gettoni come il Negozio del profilo
 * (lib/shop.php). Capelli, barba, occhiali, maglie, pantaloncini, scarpette, pet, pose ed esultanze; carnagione e colori naturali dei
 * capelli sono gratis per tutti. I copricapi sono quelli del Negozio: comprati o indossati qui o là, si vedono in tutti e due i posti.
 *
 * Funziona anche senza JavaScript: «Prova» è un link che mostra l'oggetto addosso al personaggio (?try=chiave); lo script in fondo
 * fa lo stesso senza ricaricare e anima pose ed esultanze. Ancora in prova: la vede solo l'admin (lib/layout.php).
 */
require __DIR__ . '/lib/bootstrap.php';
require_login();
if (!is_admin()) {
    redirect('index.php');
}

$me = my_player_id();
if (!$me) {
    flash('err', 'Il tuo account non è collegato a un giocatore: chiedi all\'admin.');
    redirect('profile.php');
}

$kinds = avatar_kinds();
$cat = isset($kinds[$_GET['c'] ?? '']) ? (string) $_GET['c'] : 'hair';
$view = ($_GET['v'] ?? '') === 'mine' ? 'mine' : 'shop';
$rarities = ['base' => 'Base', 'comune' => 'Comune', 'raro' => 'Raro', 'epico' => 'Epico', 'leggendario' => 'Leggendario'];
$rar = isset($rarities[$_GET['r'] ?? '']) ? (string) $_GET['r'] : '';
$sort = ($_GET['o'] ?? '') === 'desc' ? 'desc' : 'asc';
$try = (string) ($_GET['try'] ?? '');

$url = function (array $set = []) use ($cat, $view, $rar, $sort): string {
    $q = array_filter(array_merge(['c' => $cat, 'v' => $view === 'mine' ? 'mine' : '', 'r' => $rar, 'o' => $sort === 'desc' ? 'desc' : ''], $set),
        fn($v) => $v !== '' && $v !== null);
    return 'avatar.php' . ($q ? '?' . http_build_query($q) : '');
};

/* ---------------------------------------------------------------- azioni */
if (is_post()) {
    $kind = (string) ($_POST['kind'] ?? '');
    $key = (string) ($_POST['key'] ?? '');
    $do = $_POST['do'] ?? '';
    if (isset($kinds[$kind])) {
        $item = $key !== '' ? shop_item($kind, $key) : null;
        if ($do === 'buy' && $item) {
            wallet_open($me);
            $err = shop_buy($me, $kind, $key) ?? shop_equip($me, $kind, $key);
            if (!$err) {
                log_activity('negozio', $kind . ' · ' . $item['name']);
            }
            flash($err ? 'err' : 'ok', $err ?: 'Comprato e indossato: «' . $item['name'] . '»!');
        } elseif ($do === 'wear') {
            $err = shop_equip($me, $kind, $key !== '' ? $key : null);
            flash($err ? 'err' : 'ok', $err ?: ($item ? '«' . $item['name'] . '» ' . ($kind === 'celebration' ? 'è la tua esultanza.' : 'indossato.') : 'Tolto.'));
        }
    }
    $r = (string) ($_POST['r'] ?? '');
    redirect($url(['c' => isset($kinds[$kind]) ? $kind : $cat, 'try' => $key, 'v' => ($_POST['v'] ?? '') === 'mine' ? 'mine' : '',
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
$balance = wallet_balance($me);
$catalog = shop_catalog();
$number = $mp['shirt_number'];

/** Oggetti di un tipo che questo giocatore può vedere (le maglie create dagli altri no; tra i copricapi anche «nessuno»). */
$itemsOf = function (string $kind) use ($catalog, $me): array {
    $items = $kind === 'hat' ? ['' => ['name' => 'Nessun copricapo', 'price' => 0]] : [];
    foreach ($catalog[$kind] as $k => $item) {
        if (!isset($item['owner_player_id']) || $item['owner_player_id'] === $me) {
            $items[$k] = $item;
        }
    }
    return $items;
};
$wornKey = fn(string $kind) => $kind === 'hat' ? (string) $look['hat'] : $look[$kind];

// schede Negozio / Guardaroba e categorie: quanti oggetti ci sono e quanti ne ho
$count = [];
$totAll = $totMine = 0;
foreach ($kinds as $k => $_) {
    $all = $itemsOf($k);
    $count[$k] = [count($all), count(array_intersect_key($all, $owned))];
    $totAll += $count[$k][0];
    $totMine += $count[$k][1];
}

$items = [];
foreach ($itemsOf($cat) as $k => $item) {
    [$rk] = avatar_rarity($item['price']);
    if (($view === 'mine' && !isset($owned[$k])) || ($rar !== '' && $rk !== $rar)) {
        continue;
    }
    $items[(string) $k] = $item;
}
uasort($items, fn($a, $b) => $sort === 'desc' ? (int) $b['price'] <=> (int) $a['price'] : (int) $a['price'] <=> (int) $b['price']);
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
$infoHtml = function (string $key, array $item) use ($cat, $kinds, $kindDesc, $jerseyKind, $patterns, $balance, $stateOf, $view, $rar, $sort): string {
    [$rk, $rl] = avatar_rarity($item['price']);
    $desc = $item['desc'] ?? ($cat === 'jersey'
        ? $jerseyKind[$item['kind']] . (($item['pattern'] ?? 'solid') !== 'solid' ? ' · ' . mb_strtolower($patterns[$item['pattern']]) : '') . '.'
        : ($kindDesc[$cat] ?? ''));
    $state = $stateOf($key, $item);
    $form = function (string $do, string $label, string $cls, bool $disabled = false) use ($cat, $key, $view, $rar, $sort): string {
        return '<form method="post" class="av-act">' . csrf_field()
            . '<input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="kind" value="' . h($cat) . '"><input type="hidden" name="key" value="' . h($key) . '">'
            . '<input type="hidden" name="v" value="' . h($view) . '"><input type="hidden" name="r" value="' . h($rar) . '"><input type="hidden" name="o" value="' . h($sort) . '">'
            . '<button class="btn ' . $cls . ' btn-block"' . ($disabled ? ' disabled' : '') . '>' . $label . '</button></form>';
    };
    $word = $cat === 'celebration' ? ['Scegli questa esultanza', 'È la tua esultanza'] : ($cat === 'pose' ? ['Usa questa posa', 'È la tua posa'] : ['Indossa', 'Lo indossi']);
    if ($state === 'worn') {
        $act = '<p class="av-state is-worn"><i class="ti ti-circle-check"></i> ' . $word[1] . '</p>';
    } elseif ($state === 'buy') {
        $short = $balance < (int) $item['price'];
        $act = '<p class="av-price"><i class="ti ti-coin"></i> ' . (int) $item['price'] . ' <span>gettoni</span></p>'
            . $form('buy', '<i class="ti ti-shopping-bag"></i> Compra e ' . ($cat === 'celebration' || $cat === 'pose' ? 'usa' : 'indossa'), 'btn-primary', $short)
            . ($short ? '<p class="small av-short">Ti mancano ' . ((int) $item['price'] - $balance) . ' gettoni: <a class="link" href="bets.php">vai a scommettere</a>.</p>' : '');
    } else {
        $act = '<p class="av-state"><i class="ti ti-' . ($state === 'free' ? 'gift' : 'hanger') . '"></i> ' . ($state === 'free' ? 'Incluso per tutti' : 'Nel tuo guardaroba') . '</p>'
            . $form('wear', $word[0], 'btn-primary');
    }
    return '<div class="av-info-top"><span class="av-info-kind">' . h($kinds[$cat]) . '</span><span class="rar rar-' . $rk . '">' . $rl . '</span></div>'
        . '<h2 class="av-info-name">' . h($item['name']) . '</h2>'
        . ($desc !== '' ? '<p class="av-info-desc">' . h($desc) . '</p>' : '') . $act;
};

$catIcons = ['hair' => 'scissors', 'hair_color' => 'palette', 'skin' => 'hand-stop', 'beard' => 'mood-smile-beam', 'glasses' => 'eyeglass',
    'hat' => 'hat', 'jersey' => 'shirt-sport', 'shorts' => 'hanger', 'shoes' => 'shoe', 'pet' => 'paw', 'pose' => 'accessible',
    'celebration' => 'confetti'];
$crop = ['hair' => 'head', 'hair_color' => 'head', 'beard' => 'head', 'glasses' => 'head', 'hat' => 'head', 'jersey' => 'torso',
    'shorts' => 'legs', 'shoes' => 'feet'][$cat] ?? '';
$stageLook = $lookWith($selected);

layout_start('Personaggio', 'avatar');
?>
<div class="page-head"><h1>Personaggio</h1><span class="tag tag-admin"><i class="ti ti-flask"></i> in prova · solo admin</span></div>

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
        <?= avatar_figure($stageLook, ['number' => $number, 'label' => 'Il personaggio di ' . $mp['name']]) ?>
      </div>
      <div class="av-who"><strong><?= h($mp['name']) ?></strong><?= nick_html($mp) ?></div>
      <div class="av-info" data-av-info aria-live="polite"><?= $infoHtml($selected, $itemsOf($cat)[$selected]) ?></div>
    </section>
  </aside>

  <div class="av-main">
    <nav class="shop-tabs av-tabs">
      <a href="<?= h($url(['v' => ''])) ?>" class="<?= $view === 'shop' ? 'active' : '' ?>"><i class="ti ti-building-store"></i> Negozio <span class="count"><?= $totAll ?></span></a>
      <a href="<?= h($url(['v' => 'mine'])) ?>" class="<?= $view === 'mine' ? 'active' : '' ?>"><i class="ti ti-hanger"></i> Guardaroba <span class="count"><?= $totMine ?></span></a>
      <span class="av-coins"><i class="ti ti-coin"></i> <strong><?= $balance ?></strong> gettoni</span>
    </nav>

    <nav class="av-cats" aria-label="Categorie">
      <?php $i = 0; foreach ($kinds as $k => $label): $i++; ?>
      <a href="<?= h($url(['c' => $k, 'r' => ''])) ?>" class="av-cat<?= $k === $cat ? ' active' : '' ?>"<?= $k === $cat ? ' aria-current="page"' : '' ?>>
        <span class="av-cat-n"><?= sprintf('%02d', $i) ?> <i class="ti ti-<?= $catIcons[$k] ?>"></i></span>
        <span class="av-cat-l"><?= h($label) ?></span>
        <span class="av-cat-c"><?= $count[$k][1] ?>/<?= $count[$k][0] ?></span>
      </a>
      <?php endforeach; ?>
    </nav>

    <div class="av-head">
      <h2><?= h($kinds[$cat]) ?></h2>
      <div class="sortbar av-filters">
        <a href="<?= h($url(['r' => ''])) ?>" class="<?= $rar === '' ? 'active' : '' ?>">Tutte</a>
        <?php foreach ($rarities as $rk => $rl): if (!isset($presentRar[$rk])) continue; ?>
          <a href="<?= h($url(['r' => $rk])) ?>" class="<?= $rar === $rk ? 'active' : '' ?>"><?= $rl ?></a>
        <?php endforeach; ?>
        <a href="<?= h($url(['o' => $sort === 'desc' ? '' : 'desc'])) ?>" class="av-sort" title="Cambia ordine"><i class="ti ti-arrows-sort"></i> Prezzo <?= $sort === 'desc' ? 'decrescente' : 'crescente' ?></a>
      </div>
    </div>

    <div class="av-grid">
      <?php if ($cat === 'jersey' && $view === 'shop'): ?>
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
      <article class="av-item rar-<?= $rk ?><?= $state === 'worn' ? ' is-worn' : '' ?><?= $key === $selected ? ' is-trying' : '' ?>" data-av-item data-kind="<?= h($cat) ?>">
        <a class="av-item-link" href="<?= h($url(['try' => $key])) ?>#personaggio" data-av-try>
          <span class="av-item-top"><span class="rar rar-<?= $rk ?>"><?= $rl ?></span>
            <span class="av-item-mark" title="<?= ['worn' => 'Indossato', 'owned' => 'Nel guardaroba', 'free' => 'Incluso', 'buy' => 'Da comprare'][$state] ?>"><i class="ti ti-<?= ['worn' => 'check', 'owned' => 'hanger', 'free' => 'gift', 'buy' => 'plus'][$state] ?>"></i></span></span>
          <span class="av-item-fig"><?= avatar_figure($pl, ['number' => $number, 'crop' => $crop] + ($cat === 'celebration' ? ['hint' => $item['anim']] : [])) ?></span>
          <span class="av-item-name"><?= h($item['name']) ?></span>
          <span class="av-item-state"><?php if ($state === 'worn'): ?><?= $cat === 'celebration' || $cat === 'pose' ? 'In uso' : 'Indossato' ?>
            <?php elseif ($state === 'free'): ?>Incluso
            <?php elseif ($state === 'owned'): ?>Nel guardaroba
            <?php else: ?><span class="av-cost<?= $balance < (int) $item['price'] ? ' is-short' : '' ?>"><i class="ti ti-coin"></i> <?= (int) $item['price'] ?></span><?php endif; ?></span>
          <span class="av-item-try"><?= $cat === 'celebration' ? 'Guarda' : 'Prova' ?> <i class="ti ti-arrow-right"></i></span>
        </a>
        <template><?= $infoHtml($key, $item) ?></template>
      </article>
      <?php endforeach; ?>
    </div>
    <?php if (!$items): ?><p class="empty card"><?= $view === 'mine' ? 'Nel guardaroba non hai niente di questo tipo con questo filtro.' : 'Niente da mostrare con questo filtro.' ?></p><?php endif; ?>
  </div>
</div>

<script>
(() => {
  const stage = document.querySelector('[data-av-stage]');
  const info = document.querySelector('[data-av-info]');
  const playBtn = document.querySelector('[data-av-play]');
  const pauseBtn = document.querySelector('[data-av-pause]');
  if (!stage || !info) return;
  const FULL = '<?= AVATAR_VIEWBOX ?>';
  playBtn.hidden = pauseBtn.hidden = false;
  let timer = 0;
  const play = () => {
    const svg = stage.querySelector('.avf');
    const anim = svg && svg.dataset.anim;
    if (!anim) return;
    [...svg.classList].filter(c => c.startsWith('is-anim-')).forEach(c => svg.classList.remove(c));
    void svg.getBoundingClientRect();   // riparte da capo anche se era a metà
    svg.classList.add('is-anim-' + anim);
    clearTimeout(timer);
    timer = setTimeout(() => svg.classList.remove('is-anim-' + anim), 2600);
  };
  playBtn.addEventListener('click', play);
  pauseBtn.addEventListener('click', () => {
    const paused = stage.classList.toggle('is-paused');
    pauseBtn.setAttribute('aria-pressed', paused ? 'true' : 'false');
    pauseBtn.innerHTML = paused ? '<i class="ti ti-player-play"></i> Riprendi' : '<i class="ti ti-player-pause"></i> Pausa';
  });
  document.querySelectorAll('[data-av-try]').forEach(link => link.addEventListener('click', e => {
    if (e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    const card = link.closest('[data-av-item]');
    const svg = card.querySelector('.avf').cloneNode(true);
    svg.setAttribute('viewBox', FULL);
    stage.replaceChildren(svg);
    info.replaceChildren(card.querySelector('template').content.cloneNode(true));
    document.querySelectorAll('[data-av-item].is-trying').forEach(c => c.classList.remove('is-trying'));
    card.classList.add('is-trying');
    history.replaceState(null, '', link.getAttribute('href').split('#')[0]);
    if (card.dataset.kind === 'celebration') play();
    if (window.matchMedia('(max-width: 899px)').matches) stage.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }));
  if (stage.dataset.autoplay) play();
})();
</script>
<?php
layout_end();
