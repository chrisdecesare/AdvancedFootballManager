<?php
/* Nuova sfida a una lega visibile (come /sfide/nuova del sito Next): si sceglie con quale delle proprie leghe, l'avversaria e un orario libero di un campo o un giorno a scelta. */
require __DIR__ . '/lib/bootstrap.php';
require_discover();
require_login();
$mine = my_owned_leagues();
if (!$mine || is_guest()) {
    redirect('calcetto');
}
$from = (int) ($_REQUEST['da'] ?? array_key_first($mine));
$to = (int) ($_REQUEST['a'] ?? 0);
if (is_post()) {
    verify_csrf();
    $err = challenge_create((int) ($_POST['da'] ?? 0), (int) ($_POST['a'] ?? 0), (int) ($_POST['slot'] ?? 0) ?: null, (string) ($_POST['when'] ?? ''), (string) ($_POST['location'] ?? ''), (string) ($_POST['message'] ?? ''));
    if ($err) {
        flash('err', $err);
        redirect('nuova-sfida?da=' . (int) $_POST['da'] . '&a=' . (int) $_POST['a']);
    }
    flash('ok', 'Sfida inviata: chi gestisce l\'altra lega ha ricevuto un\'email.');
    redirect('sfide');
}
$opp = array_values(array_filter(league_cards(), fn ($l) => !isset($mine[$l['id']])));
$target = null;
foreach ($opp as $l) {
    if ($l['id'] === $to) {
        $target = $l;
    }
}
$slots = $target ? free_slots_in_city(city_slug($target['city'])) : [];
layout_start('Nuova sfida', 'challenges');
?>
<a class="back" href="sfide"><i class="ti ti-arrow-left"></i> Sfide</a>
<div class="page-head"><h1>Nuova sfida</h1></div>
<?php if (!$opp): ?><p class="muted">Nessuna lega visibile da sfidare, per ora.</p><?php else: ?>
<form method="get" class="card inline-form"><label>Sfidi
  <select name="a" onchange="this.form.submit()"><option value="">Scegli la lega…</option>
  <?php foreach ($opp as $l): ?><option value="<?= (int) $l['id'] ?>"<?= $l['id'] === $to ? ' selected' : '' ?>><?= h($l['name']) ?> · <?= h($l['city']) ?></option><?php endforeach; ?></select></label>
  <input type="hidden" name="da" value="<?= $from ?>"><noscript><button class="btn btn-ghost btn-sm">Scegli</button></noscript></form>
<?php if ($target): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="<?= $to ?>">
  <h2>Contro «<?= h($target['name']) ?>»</h2>
  <label>Con la tua lega <select name="da"><?php foreach ($mine as $id => $n): ?><option value="<?= (int) $id ?>"<?= (int) $id === $from ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></label>
  <?php if ($slots): ?><label>Orario libero nei campi di <?= h($target['city']) ?>
    <select name="slot"><option value="">Nessuno: scelgo io giorno e ora</option>
    <?php foreach ($slots as $s): ?><option value="<?= (int) $s['id'] ?>"><?= h(substr($s['starts_at'], 8, 2) . '/' . substr($s['starts_at'], 5, 2) . ' ' . substr($s['starts_at'], 11, 5) . ' · ' . $s['center_name'] . ' campo ' . $s['field_no']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
  <label>Giorno e ora <input type="datetime-local" name="when"></label>
  <label>Dove (se non scegli un campo) <input name="location" maxlength="120"></label>
  <label>Messaggio <textarea name="message" maxlength="300" rows="2"></textarea></label>
  <button class="btn btn-primary">Invia la sfida</button>
</form>
<?php endif; endif; layout_end();
