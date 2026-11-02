<?php
/*
 * I campi della zona con le stelle da 1 a 5: aggiungi quello dove giochi e votalo (come next-app/src/app/(site)/campi). Le stelle compaiono anche
 * nella scelta del campo quando si crea una partita. La città è quella delle tue leghe (o quella che scrivi).
 */
require __DIR__ . '/lib/bootstrap.php';
require_login();

$me = my_player_id();
$canVote = $me && !is_guest();
$to = fn(string $city) => 'campi.php?citta=' . urlencode(city_slug($city));

if (is_post()) {
    $do = $_POST['do'] ?? '';
    $stars = $_POST['stars'] ?? '';
    if ($do === 'rate') {
        $p = pitch_by_id((int) ($_POST['pitch'] ?? 0));
        if (!$p) {
            redirect('campi.php');
        }
        if (!$canVote) {
            flash('err', 'Per votare serve un account da giocatore.');
        } elseif (!pitch_valid_stars($stars)) {
            flash('err', 'Scegli da 1 a 5 stelle.');
        } else {
            pitch_rate((int) $p['id'], (int) $me, (int) $stars);
            flash('ok', 'Hai dato ' . (int) $stars . ((int) $stars === 1 ? ' stella' : ' stelle') . ' a «' . $p['name'] . '».');
        }
        redirect($to($p['city']));
    }
    if ($do === 'add') {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        $address = mb_substr(trim((string) ($_POST['address'] ?? '')), 0, 190);
        $city = mb_substr(trim((string) ($_POST['city'] ?? '')), 0, 80);
        $back = $city !== '' ? $to($city) : 'campi.php';
        $err = !$canVote ? 'Per aggiungere un campo serve un account da giocatore.'
            : (pitch_input_error($name, $address, $city) ?? (pitch_valid_stars($stars) ? null : 'Dai al campo da 1 a 5 stelle.'));
        if ($err) {
            flash('err', $err);
        } elseif (!rate_hit('pitch_add_' . (int) current_user()['id'], 8, 3600)) {
            flash('err', 'Hai aggiunto molti campi in poco tempo: riprova tra un\'ora.');
        } else {
            [$id, $existed] = pitch_add((int) current_user()['id'], $name, $address, $city);
            pitch_rate($id, (int) $me, (int) $stars);
            flash('ok', $existed ? '«' . $name . '» c\'era già: ho registrato il tuo voto.' : 'Campo «' . $name . '» aggiunto, con il tuo voto.');
        }
        redirect($back);
    }
    redirect('campi.php');
}

$mine = pitch_cities_of_groups(allowed_group_ids());
$asked = trim((string) ($_GET['citta'] ?? ''));
$slug = $asked !== '' ? city_slug($asked) : ($mine[0] ?? '');
$pitches = $slug !== '' ? pitches_in([$slug], $me ? (int) $me : null) : [];
$cityName = $pitches[0]['city'] ?? ($asked !== '' && !preg_match('/^[a-z0-9-]+$/', $asked) ? $asked : '');

layout_start('I campi', 'pitches');
?>
<div class="narrow-lg">
  <div class="page-head"><h1>I campi</h1></div>
  <section class="card">
    <h2><i class="ti ti-map-pin"></i> Dove giochi?</h2>
    <p class="muted small">Cerca i campi di una città, aggiungi quello dove giochi e dagli da 1 a 5 stelle. Le stelle le vedono tutti quando si crea una partita.</p>
    <form method="get" class="form">
      <label class="field"><span>Città</span><input name="citta" value="<?= h($cityName) ?>" placeholder="Es. Cava de' Tirreni" required maxlength="80"></label>
      <button class="btn btn-ghost btn-sm"><i class="ti ti-search"></i> Mostra i campi</button>
    </form>
  </section>

  <?php if ($slug !== ''): ?>
  <section class="card">
    <h2><i class="ti ti-soccer-field"></i> Campi <?= $cityName !== '' ? 'a ' . h($cityName) : 'della zona' ?></h2>
    <?php if (!$pitches): ?><p class="empty">Nessun campo in questa città: aggiungi il primo qui sotto.</p>
    <?php else: ?>
    <ul class="plain-list pitch-list">
      <?php foreach ($pitches as $p): ?>
      <li class="pitch-row">
        <div>
          <strong><?= h($p['name']) ?></strong><?php if ($p['center_id']): ?><span class="tag tag-ok" style="margin-left:6px">Centro</span><?php endif; ?>
          <div class="muted small"><?= h($p['address'] ?: $p['city']) ?></div>
          <?= pitch_stars_html($p['avg'], $p['votes']) ?>
        </div>
        <?php if ($canVote): ?>
        <form method="post" class="pitch-vote">
          <?= csrf_field() ?><input type="hidden" name="do" value="rate"><input type="hidden" name="pitch" value="<?= (int) $p['id'] ?>">
          <select name="stars" aria-label="Le tue stelle per <?= h($p['name']) ?>" required>
            <option value="" disabled<?= $p['mine'] === null ? ' selected' : '' ?>>Le tue stelle</option>
            <?php foreach ([5, 4, 3, 2, 1] as $n): ?><option value="<?= $n ?>"<?= $p['mine'] === $n ? ' selected' : '' ?>><?= pitch_stars_text($n) ?> <?= $n ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-ghost btn-sm"><?= $p['mine'] !== null ? 'Cambia voto' : 'Vota' ?></button>
        </form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2><i class="ti ti-plus"></i> Aggiungi il campo dove giochi</h2>
    <?php if ($canVote): ?>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="do" value="add">
      <div class="form-grid">
        <label class="field"><span>Nome del campo</span><input name="name" required minlength="2" maxlength="120" placeholder="Es. Campo Sportivo Comunale"></label>
        <label class="field"><span>Indirizzo</span><input name="address" maxlength="190" placeholder="Es. Via Roma 1"></label>
        <label class="field"><span>Città</span><input name="city" required maxlength="80" value="<?= h($cityName) ?>" placeholder="Es. Cava de' Tirreni"></label>
        <label class="field"><span>Le tue stelle (da 1 a 5)</span>
          <select name="stars" required>
            <option value="" disabled selected>Scegli</option>
            <?php foreach ([5, 4, 3, 2, 1] as $n): ?><option value="<?= $n ?>"><?= pitch_stars_text($n) ?> <?= $n ?></option><?php endforeach; ?>
          </select></label>
      </div>
      <button class="btn btn-primary">Aggiungi e vota</button>
    </form>
    <?php else: ?><p class="muted">Per aggiungere e votare i campi serve un account da giocatore.</p><?php endif; ?>
  </section>
</div>
<?php
layout_end();
