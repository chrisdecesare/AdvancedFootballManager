<?php
/* Crea la tua maglia: colore primario, colore secondario e un pattern. Resta tua (lib/shop.php: shop_owned() la sblocca in automatico
 * per chi l'ha creata), non si vende ad altri. Il disegno vero e proprio (SVG piatto e texture 3D) si ricostruisce sempre dai tre dati
 * salvati qui: non serve caricare nessuna immagine. */
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

const JERSEY_PATTERNS = [
    'solid' => 'Tinta unita',
    'stripes_v' => 'Strisce verticali',
    'stripes_h' => 'Strisce orizzontali',
    'halves' => 'A metà',
    'sleeves' => 'Maniche a contrasto',
];

$MAX_CUSTOM = 8;
$mine = q('SELECT id, name, primary_color, secondary_color, pattern_key FROM custom_jerseys WHERE player_id = ? ORDER BY created_at DESC', [$me])->fetchAll();

if (is_post()) {
    $name = trim((string) ($_POST['name'] ?? ''));
    $a = (string) ($_POST['primary_color'] ?? '#2a3f9b');
    $b = (string) ($_POST['secondary_color'] ?? '#ffffff');
    $pattern = (string) ($_POST['pattern_key'] ?? 'solid');
    if ($name === '' || mb_strlen($name) > 40) {
        flash('err', 'Dai un nome alla maglia (fino a 40 caratteri).');
    } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $a) || !preg_match('/^#[0-9a-fA-F]{6}$/', $b)) {
        flash('err', 'Colori non validi.');
    } elseif (!isset(JERSEY_PATTERNS[$pattern])) {
        flash('err', 'Pattern non valido.');
    } elseif (count($mine) >= $MAX_CUSTOM) {
        flash('err', 'Hai già ' . $MAX_CUSTOM . ' maglie create: cancellane una per farne un\'altra.');
    } else {
        q('INSERT INTO custom_jerseys (player_id, name, primary_color, secondary_color, pattern_key) VALUES (?, ?, ?, ?, ?)',
            [$me, $name, $a, $b, $pattern]);
        flash('ok', 'Maglia creata: la trovi nel Negozio e sull\'avatar, pronta da indossare.');
        redirect('jersey_creator.php');
    }
}

if (isset($_POST['delete_id'])) {
    q('DELETE FROM custom_jerseys WHERE id = ? AND player_id = ?', [(int) $_POST['delete_id'], $me]);
    flash('ok', 'Maglia cancellata.');
    redirect('jersey_creator.php');
}

layout_start('Crea la tua maglia', 'avatar');
?>
<div class="page-head"><h1>Crea la tua maglia <span class="muted small">resta solo tua</span></h1></div>

<section class="card">
  <div class="jersey-creator">
    <div class="jersey-preview">
      <svg viewBox="0 0 100 100" class="jersey-preview-svg" data-jersey-preview>
        <path data-jp-base d="M20 12 L38 4 Q50 14 62 4 L80 12 L92 30 L78 38 L78 96 H22 L22 38 L8 30 Z" fill="#2a3f9b"/>
        <path data-jp-second d="" fill="#ffffff" opacity="0"/>
      </svg>
    </div>
    <form method="post" class="jersey-form">
      <?= csrf_field() ?>
      <label>Nome<br><input type="text" name="name" maxlength="40" required placeholder="Es. Maglia della fortuna"></label>
      <label>Colore primario<br><input type="color" name="primary_color" value="#2a3f9b" data-jc-a></label>
      <label>Colore secondario<br><input type="color" name="secondary_color" value="#ffffff" data-jc-b></label>
      <label>Pattern<br>
        <select name="pattern_key" data-jc-pattern>
          <?php foreach (JERSEY_PATTERNS as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <button class="btn btn-primary"><i class="ti ti-check"></i> Crea la maglia</button>
    </form>
  </div>
</section>

<?php if ($mine): ?>
<section class="card">
  <h2><i class="ti ti-shirt-sport"></i> Le tue maglie create (<?= count($mine) ?>/<?= $MAX_CUSTOM ?>)</h2>
  <div class="shop-grid">
    <?php foreach ($mine as $cj): ?>
    <article class="card shop-item">
      <div class="shop-thumb"><span class="jersey-thumb" style="--jc-a:<?= h($cj['primary_color']) ?>;--jc-b:<?= h($cj['secondary_color']) ?>"><i class="ti ti-shirt-sport"></i></span></div>
      <div class="shop-name"><?= h($cj['name']) ?></div>
      <div class="shop-act">
        <a class="btn btn-ghost btn-sm" href="avatar.php"><i class="ti ti-3d-cube-sphere"></i> Indossa</a>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int) $cj['id'] ?>">
          <button class="btn btn-ghost btn-sm" data-confirm="Cancellare questa maglia?"><i class="ti ti-trash"></i></button></form>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<script>
(() => {
  const svg = document.querySelector('[data-jersey-preview]');
  if (!svg) return;
  const base = svg.querySelector('[data-jp-base]'), second = svg.querySelector('[data-jp-second]');
  const a = document.querySelector('[data-jc-a]'), b = document.querySelector('[data-jc-b]'), pat = document.querySelector('[data-jc-pattern]');
  const shapes = {
    solid: '',
    stripes_v: 'M32 4 L26 96 H36 L42 4 Z M58 4 L64 96 H74 L68 4 Z',
    stripes_h: 'M10 40 L90 40 L90 55 L10 55 Z',
    halves: 'M50 4 V96 H78 V38 L92 30 L80 12 Z',
    sleeves: 'M20 12 L38 4 Q50 14 62 4 L80 12 L92 30 L78 38 L70 22 L62 4 L38 4 L30 22 L8 30 Z',
  };
  const draw = () => {
    base.setAttribute('fill', a.value);
    const shape = shapes[pat.value] || '';
    second.setAttribute('d', shape);
    second.setAttribute('fill', b.value);
    second.setAttribute('opacity', shape ? '1' : '0');
  };
  [a, b, pat].forEach(el => el.addEventListener('input', draw));
  draw();
})();
</script>
<?php
layout_end();
