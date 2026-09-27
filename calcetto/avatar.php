<?php
/*
 * Personaggio: l'avatar 3D del giocatore (corpo Ready Player Me + maglia/pantaloncini/scarpe/copricapo/esultanza del negozio,
 * lib/shop.php). Il corpo, i capelli, gli occhiali e la carnagione si scelgono nell'editor di Ready Player Me (l'iframe qui sotto);
 * il resto sono gli oggetti comprati o creati nel Negozio, disegnati sull'avatar da assets/avatar3d.js con Three.js.
 */
require __DIR__ . '/lib/bootstrap.php';
require_login();
if (!is_admin()) {   // ancora in prova: finché non si apre a tutti, solo l'admin la vede (lib/layout.php)
    redirect('index.php');
}

$me = my_player_id();
if (!$me) {
    flash('err', 'Il tuo account non è collegato a un giocatore: chiedi all\'admin.');
    redirect('profile.php');
}
$mp = get_player($me);

/* ---------------------------------------------------------------- azioni */
if (is_post()) {
    $do = $_POST['do'] ?? '';
    if ($do === 'save_avatar') {
        $url = trim((string) ($_POST['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https://[a-z0-9.-]*readyplayer\.me/.*\.glb(\?.*)?$#i', $url)) {
            flash('err', 'Link non valido: deve arrivare da Ready Player Me.');
        } else {
            q('UPDATE players SET avatar_rpm_url = ? WHERE id = ?', [$url ?: null, $me]);
            flash('ok', $url ? 'Avatar salvato.' : 'Avatar rimosso: torni al corpo di base.');
        }
    } elseif (($do === 'wear' || $do === 'take_off') && in_array($_POST['kind'] ?? '', ['jersey', 'shorts', 'shoes', 'hat', 'celebration'], true)) {
        $kind = (string) $_POST['kind'];
        $key = $do === 'wear' ? (string) ($_POST['key'] ?? '') : null;
        $err = shop_equip($me, $kind, $key);
        flash($err ? 'err' : 'ok', $err ?: 'Fatto.');
    }
    redirect('avatar.php#personalizza');
}

/* ---------------------------------------------------------------- dati */
$owned = shop_owned($me);
$catalog = shop_catalog();
$sections = ['jersey' => 'Maglie', 'shorts' => 'Pantaloncini', 'shoes' => 'Scarpe', 'hat' => 'Copricapi', 'celebration' => 'Esultanze'];

$jerseyItem = $mp['equipped_jersey_key'] ? shop_item('jersey', $mp['equipped_jersey_key']) : shop_item('jersey', 'j_casa');
$shortsItem = $mp['equipped_shorts_key'] ? shop_item('shorts', $mp['equipped_shorts_key']) : shop_item('shorts', 'p_bianchi');
$shoesItem = $mp['equipped_shoes_key'] ? shop_item('shoes', $mp['equipped_shoes_key']) : shop_item('shoes', 's_nere');
$celebItem = $mp['equipped_celebration_key'] ? shop_item('celebration', $mp['equipped_celebration_key']) : shop_item('celebration', 'c_pugno');
$hatKey = shop_worn($mp, 'hat');

$sceneData = [
    'rpmUrl' => $mp['avatar_rpm_url'] ?: null,
    'jersey' => $jerseyItem['colors'] ?? ['a' => '#2a3f9b', 'b' => '#ffffff'],
    'shorts' => $shortsItem['color'] ?? '#ffffff',
    'shoes' => $shoesItem['color'] ?? '#1f1a2e',
    'hat' => $hatKey ? (shop_catalog()['hat'][$hatKey] ?? null) : null,
    'celebrationAnim' => $celebItem['anim'] ?? 'fist-pump',
];

layout_start('Personaggio', 'avatar');
?>
<div class="page-head"><h1>Personaggio <span class="muted small">il tuo avatar 3D</span></h1></div>

<section class="card avatar3d-wrap" id="personalizza">
  <div class="avatar3d-stage">
    <div id="avatar3d-canvas" class="avatar3d-canvas" data-avatar3d="<?= h(json_encode($sceneData, JSON_UNESCAPED_SLASHES)) ?>"></div>
    <div class="avatar3d-controls">
      <button type="button" class="btn btn-ghost btn-sm" data-avatar3d-play title="Prova l'esultanza"><i class="ti ti-confetti"></i> Esultanza</button>
    </div>
  </div>

  <div class="avatar3d-side">
    <h3><i class="ti ti-scan"></i> Corpo, capelli, occhiali</h3>
    <p class="muted small">Si scelgono nell'editor: quando finisci premi «Usa questo avatar», il link si salva da solo.</p>
    <button type="button" class="btn btn-primary btn-sm" data-rpm-open><i class="ti ti-user-edit"></i> <?= $mp['avatar_rpm_url'] ? 'Modifica il corpo' : 'Crea il corpo' ?></button>
    <?php if ($mp['avatar_rpm_url']): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="save_avatar"><input type="hidden" name="url" value="">
        <button class="btn btn-ghost btn-sm">Torna al corpo di base</button></form>
    <?php endif; ?>

    <dialog id="rpm-dialog" class="rpm-dialog">
      <iframe id="rpm-frame" class="rpm-frame" allow="camera *; microphone *" title="Editor avatar Ready Player Me"></iframe>
      <button type="button" class="btn btn-ghost btn-sm rpm-close" data-rpm-close><i class="ti ti-x"></i> Chiudi</button>
    </dialog>
    <form method="post" id="rpm-save-form" hidden><?= csrf_field() ?><input type="hidden" name="do" value="save_avatar"><input type="hidden" name="url" id="rpm-url"></form>
  </div>
</section>

<?php foreach ($sections as $kind => $title):
    $column = shop_column($kind);
    $worn = $mp[$column] ?? null;
    $items = array_filter($catalog[$kind], fn($k) => isset($owned[$k]), ARRAY_FILTER_USE_KEY);
?>
<section class="card">
  <div class="card-head">
    <h2><i class="ti ti-shopping-bag"></i> <?= h($title) ?></h2>
    <a class="link" href="shop.php?s=<?= $kind ?>">Negozio <i class="ti ti-arrow-right"></i></a>
  </div>
  <?php if (!$items): ?>
    <p class="empty small">Non hai ancora niente qui: <a class="link" href="shop.php?s=<?= $kind ?>">vai al negozio</a>.</p>
  <?php else: ?>
  <div class="shop-grid">
    <?php foreach ($items as $key => $item): $isWorn = $worn === $key; ?>
    <article class="card shop-item<?= $isWorn ? ' is-worn' : '' ?>">
      <div class="shop-thumb">
        <?php if ($kind === 'hat'): ?><span class="hat-thumb"><?= hat_svg($key) ?></span>
        <?php elseif ($kind === 'jersey'): ?><span class="jersey-thumb" style="--jc-a:<?= h($item['colors']['a']) ?>;--jc-b:<?= h($item['colors']['b']) ?>"><i class="ti ti-shirt-sport"></i></span>
        <?php elseif ($kind === 'celebration'): ?><span class="jersey-thumb celeb-thumb"><i class="ti ti-confetti"></i></span>
        <?php else: ?><span class="jersey-thumb" style="--jc-a:<?= h($item['color']) ?>;--jc-b:<?= h($item['color']) ?>"><i class="ti ti-shirt"></i></span><?php endif; ?>
      </div>
      <div class="shop-name"><?= h($item['name']) ?><?= $isWorn ? ' <span class="tag tag-ok"><i class="ti ti-check"></i> indossato</span>' : '' ?></div>
      <form method="post" class="shop-act"><?= csrf_field() ?><input type="hidden" name="kind" value="<?= $kind ?>"><input type="hidden" name="key" value="<?= h($key) ?>">
        <?php if ($isWorn): ?>
          <input type="hidden" name="do" value="take_off"><button class="btn btn-ghost btn-sm">Togli</button>
        <?php else: ?>
          <input type="hidden" name="do" value="wear"><button class="btn btn-primary btn-sm"><?= $kind === 'celebration' ? 'Scegli' : 'Indossa' ?></button>
        <?php endif; ?>
      </form>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<script type="importmap">
{ "imports": { "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
  "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/" } }
</script>
<script type="module" src="assets/avatar3d.js?v=<?= h(substr((string) @md5_file(__DIR__ . '/assets/avatar3d.js'), 0, 10)) ?>"></script>
<?php
layout_end();
