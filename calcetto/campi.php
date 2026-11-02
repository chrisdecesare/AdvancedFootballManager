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
    if ($do === 'review') {
        $p = pitch_by_id((int) ($_POST['pitch'] ?? 0));
        if (!$p) {
            redirect('campi.php');
        }
        $body = pitch_clean_review((string) ($_POST['body'] ?? ''));
        $err = !$canVote ? 'Per scrivere una recensione serve un account da giocatore.' : pitch_review_error($body);
        if ($err) {
            flash('err', $err);
        } elseif (!rate_hit('pitch_review_' . (int) current_user()['id'], 10, 3600)) {
            flash('err', 'Hai scritto molte recensioni in poco tempo: riprova tra un\'ora.');
        } else {
            pitch_review_save((int) $p['id'], (int) $me, $body);
            flash('ok', 'Recensione di «' . $p['name'] . '» salvata. Se vuoi, scrivila anche su Google.');
        }
        redirect($to($p['city']) . '#campo-' . (int) $p['id']);
    }
    if ($do === 'review_delete') {
        $pid = pitch_review_delete((int) ($_POST['review'] ?? 0), $me ? (int) $me : null, is_admin());
        $p = $pid ? pitch_by_id($pid) : null;
        flash($p ? 'ok' : 'err', $p ? 'Recensione tolta.' : 'Recensione non trovata.');
        redirect($p ? $to($p['city']) . '#campo-' . (int) $p['id'] : 'campi.php');
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

$reviews = pitch_reviews_of(array_column($pitches, 'id'));

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
      <li class="pitch-row" id="campo-<?= (int) $p['id'] ?>">
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
        <?php $rv = $reviews[(int) $p['id']] ?? []; ?>
        <div class="pitch-reviews">
          <?php foreach (array_slice($rv, 0, 3) as $r): ?>
          <blockquote class="review"><p><?= h($r['body']) ?></p>
            <footer class="muted small"><?= h($r['name']) ?> · <?= h(push_when($r['updated_at'])) ?>
              <?php if (($me && (int) $r['player_id'] === (int) $me) || is_admin()): ?>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="review_delete"><input type="hidden" name="review" value="<?= (int) $r['id'] ?>">
                <button class="gcal-undo" data-confirm="Togliere questa recensione?">toglila</button></form>
              <?php endif; ?></footer></blockquote>
          <?php endforeach; ?>
          <?php if (count($rv) > 3): ?>
          <details><summary class="small">Le altre <?= count($rv) - 3 ?> recensioni</summary>
            <?php foreach (array_slice($rv, 3) as $r): ?>
            <blockquote class="review"><p><?= h($r['body']) ?></p><footer class="muted small"><?= h($r['name']) ?> · <?= h(push_when($r['updated_at'])) ?></footer></blockquote>
            <?php endforeach; ?>
          </details>
          <?php endif; ?>
          <?php if ($canVote): $mineRv = null; foreach ($rv as $r) { if ((int) $r['player_id'] === (int) $me) { $mineRv = $r; } } ?>
          <details><summary class="small"><?= $mineRv ? 'Cambia la tua recensione' : 'Scrivi una recensione' ?></summary>
            <form method="post" class="form">
              <?= csrf_field() ?><input type="hidden" name="do" value="review"><input type="hidden" name="pitch" value="<?= (int) $p['id'] ?>">
              <label class="field"><span>La tua recensione (da <?= REVIEW_MIN ?> a <?= REVIEW_MAX ?> caratteri)</span>
                <textarea name="body" required minlength="<?= REVIEW_MIN ?>" maxlength="<?= REVIEW_MAX ?>" rows="3" placeholder="Fondo, spogliatoi, illuminazione, prezzo…"><?= h($mineRv['body'] ?? '') ?></textarea></label>
              <button class="btn btn-ghost btn-sm">Salva la recensione</button>
            </form>
          </details>
          <?php endif; ?>
          <a class="small link" href="<?= h(pitch_google_url($p)) ?>" target="_blank" rel="noopener noreferrer"><i class="ti ti-brand-google"></i> Scrivi la recensione anche su Google</a>
        </div>
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
