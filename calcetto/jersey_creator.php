<?php
/*
 * Crea la tua maglia per l'Avatar: due colori e un motivo (lib/avatar.php: avatar_patterns). Resta tua, gratis
 * (lib/shop.php: shop_owned() la dà a chi l'ha creata) e non si vende agli altri. Ancora in prova: la vede solo l'admin.
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

const MAX_CUSTOM_JERSEYS = 8;
$patterns = avatar_patterns();
$mine = q('SELECT id, name, primary_color, secondary_color, pattern_key FROM custom_jerseys WHERE player_id = ? ORDER BY created_at DESC', [$me])->fetchAll();

if (is_post()) {
    if (isset($_POST['delete_id'])) {
        q('DELETE FROM custom_jerseys WHERE id = ? AND player_id = ?', [(int) $_POST['delete_id'], $me]);
        flash('ok', 'Maglia cancellata.');
        redirect('jersey_creator.php');
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    $a = (string) ($_POST['primary_color'] ?? '');
    $b = (string) ($_POST['secondary_color'] ?? '');
    $pattern = (string) ($_POST['pattern_key'] ?? '');
    if ($name === '' || mb_strlen($name) > 40) {
        flash('err', 'Dai un nome alla maglia (fino a 40 caratteri).');
    } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $a) || !preg_match('/^#[0-9a-fA-F]{6}$/', $b)) {
        flash('err', 'Colori non validi.');
    } elseif (!isset($patterns[$pattern])) {
        flash('err', 'Motivo non valido.');
    } elseif (count($mine) >= MAX_CUSTOM_JERSEYS) {
        flash('err', 'Hai già ' . MAX_CUSTOM_JERSEYS . ' maglie create: cancellane una per farne un\'altra.');
    } else {
        q('INSERT INTO custom_jerseys (player_id, name, primary_color, secondary_color, pattern_key) VALUES (?, ?, ?, ?, ?)',
            [$me, $name, strtolower($a), strtolower($b), $pattern]);
        flash('ok', 'Maglia creata: premi «Indossa» per metterla al tuo avatar.');
        redirect('avatar.php?c=jersey&try=cj' . (int) db()->lastInsertId() . '#avatar');
    }
    redirect('jersey_creator.php');
}

$mp = get_player($me);
$look = avatar_look($mp);

layout_start('Crea la tua maglia', 'avatar');
?>
<a class="back" href="avatar.php?c=jersey"><i class="ti ti-arrow-left"></i> Avatar</a>
<div class="page-head"><h1>Crea la tua maglia</h1><span class="tag tag-admin"><i class="ti ti-flask"></i> in prova · solo admin</span></div>

<section class="card jc">
  <div class="jc-stage av-stage" data-jc-preview>
    <?= avatar_figure($look, ['number' => $mp['shirt_number'], 'all_patterns' => true, 'jersey' => ['a' => '#2a3f9b', 'b' => '#ffffff', 'pattern' => 'solid'],
        'label' => 'Anteprima della maglia']) ?>
  </div>
  <form method="post" class="jc-form">
    <?= csrf_field() ?>
    <label>Nome della maglia<input type="text" name="name" maxlength="40" required placeholder="Es. Maglia della fortuna"></label>
    <div class="jc-colors">
      <label>Colore principale<input type="color" name="primary_color" value="#2a3f9b" data-jc-a></label>
      <label>Secondo colore<input type="color" name="secondary_color" value="#ffffff" data-jc-b></label>
    </div>
    <fieldset class="jc-patterns">
      <legend>Motivo</legend>
      <?php foreach ($patterns as $k => $label): ?>
        <label class="jc-pat"><input type="radio" name="pattern_key" value="<?= h($k) ?>"<?= $k === 'solid' ? ' checked' : '' ?> data-jc-pattern> <span><?= h($label) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <p class="muted small">Il numero è quello del tuo profilo. La maglia resta solo tua ed è gratis<?= $mine ? ' (ne hai ' . count($mine) . ' su ' . MAX_CUSTOM_JERSEYS . ')' : '' ?>.</p>
    <button class="btn btn-primary"<?= count($mine) >= MAX_CUSTOM_JERSEYS ? ' disabled title="Hai già ' . MAX_CUSTOM_JERSEYS . ' maglie: cancellane una"' : '' ?>><i class="ti ti-check"></i> Crea la maglia</button>
  </form>
</section>

<?php if ($mine): ?>
<h2 class="section-title">Le tue maglie</h2>
<div class="av-grid">
  <?php foreach ($mine as $cj): $l = $look; $l['jersey'] = 'cj' . $cj['id']; ?>
  <article class="av-item">
    <div class="av-item-link">
      <span class="av-item-top"><span class="rar rar-base">Creata da te</span></span>
      <span class="av-item-fig"><?= avatar_figure($l, ['number' => $mp['shirt_number']]) ?></span>
      <span class="av-item-name"><?= h($cj['name']) ?></span>
      <span class="av-item-state"><?= h($patterns[$cj['pattern_key']] ?? '') ?></span>
    </div>
    <div class="av-item-foot">
      <a class="btn btn-primary btn-sm" href="avatar.php?c=jersey&amp;try=cj<?= (int) $cj['id'] ?>#avatar">Indossa</a>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int) $cj['id'] ?>">
        <button class="btn btn-ghost btn-sm" data-confirm="Cancellare «<?= h($cj['name']) ?>»?" title="Cancella" aria-label="Cancella"><i class="ti ti-trash"></i></button></form>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
(() => {
  // gli stessi tre toni (luce, base, ombra) di px_tones() in lib/avatar_pixel.php
  const svg = document.querySelector('[data-jc-preview] .avf');
  const a = document.querySelector('[data-jc-a]'), b = document.querySelector('[data-jc-b]');
  if (!svg || !a || !b) return;
  const rgb = h => [1, 3, 5].map(i => parseInt(h.substr(i, 2), 16));
  const hex = c => '#' + c.map(v => Math.round(v).toString(16).padStart(2, '0')).join('');
  const mix = (x, y, t) => { const p = rgb(x), q = rgb(y); return hex(p.map((v, i) => v + (q[i] - v) * t)); };
  const luma = h => { const [r, g, bb] = rgb(h); return (0.299 * r + 0.587 * g + 0.114 * bb) / 255; };
  const tones = c => { const l = luma(c); return [mix(c, '#fff8e6', l > .85 ? 0 : (l < .2 ? .28 : .22)), c, mix(c, '#2a1f4a', l > .85 ? .22 : .32)]; };
  const draw = () => {
    const pat = (document.querySelector('[data-jc-pattern]:checked') || {}).value || 'solid';
    tones(a.value).forEach((v, i) => svg.style.setProperty('--pj' + i, v));
    tones(b.value).forEach((v, i) => svg.style.setProperty('--pk' + i, v));
    const plain = pat === 'solid' && Math.abs(luma(a.value) - luma(b.value)) > .25;
    svg.style.setProperty('--pn', plain ? b.value : (luma(a.value) > .6 ? '#1f1a2e' : '#ffffff'));
    svg.querySelectorAll('[data-pat]').forEach(g => g.setAttribute('display', g.dataset.pat === pat ? 'inline' : 'none'));
  };
  document.querySelectorAll('[data-jc-a], [data-jc-b], [data-jc-pattern]').forEach(el => el.addEventListener('input', draw));
  draw();
})();
</script>
<?php
layout_end();
