<?php
/* Sfide tra leghe (come /sfide del sito Next): quelle ricevute da accettare o rifiutare e quelle inviate. */
require __DIR__ . '/lib/bootstrap.php';
require_discover();
require_login();
$managed = array_keys(my_owned_leagues());
if (!$managed || is_guest()) {
    redirect('calcetto');
}
if (is_post()) {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $do = $_POST['do'] ?? '';
    $err = $do === 'cancel' ? challenge_cancel($id) : challenge_answer($id, $do === 'accept');
    flash($err ? 'err' : 'ok', $err ?: ($do === 'accept' ? 'Sfida accettata: le due partite sono in calendario.' : ($do === 'cancel' ? 'Sfida annullata.' : 'Sfida rifiutata.')));
    redirect('sfide');
}
$ch = challenges_of($managed);
$when = fn ($c) => $c['proposed_at'] ? substr($c['proposed_at'], 8, 2) . '/' . substr($c['proposed_at'], 5, 2) . ' alle ' . substr($c['proposed_at'], 11, 5) : 'da definire';
$status = ['inviata' => 'in attesa', 'accettata' => 'accettata', 'rifiutata' => 'rifiutata', 'annullata' => 'annullata'];
layout_start('Sfide', 'challenges');
?>
<div class="page-head"><h1><i class="ti ti-swords"></i> Sfide tra leghe</h1>
  <a class="btn btn-primary btn-sm" href="nuova-sfida"><i class="ti ti-plus"></i> Nuova sfida</a></div>
<section class="card"><h2>Ricevute</h2>
  <?php if (!$ch['received']): ?><p class="muted">Nessuna sfida ricevuta. Rendi visibile la tua lega (pagina della lega → «Trovami sulla mappa») per farti sfidare.</p><?php endif; ?>
  <ul class="plain-list">
  <?php foreach ($ch['received'] as $c): ?>
    <li><b><?= h($c['from_name']) ?></b> sfida <b><?= h($c['to_name']) ?></b> · <?= h($when($c)) ?><?= $c['location'] ? ' · ' . h($c['location']) : '' ?>
      <?= $c['message'] ? '<br><span class="muted small">«' . h($c['message']) . '»</span>' : '' ?>
      <?php if ($c['status'] === 'inviata'): ?>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <button name="do" value="accept" class="btn btn-primary btn-sm">Accetta</button> <button name="do" value="reject" class="btn btn-ghost btn-sm">Rifiuta</button></form>
      <?php else: ?> <span class="tag"><?= h($status[$c['status']] ?? $c['status']) ?></span><?php endif; ?></li>
  <?php endforeach; ?></ul></section>
<section class="card"><h2>Inviate</h2>
  <?php if (!$ch['sent']): ?><p class="muted">Non hai sfidato nessuno. <a class="link" href="calcetto">Trova una lega</a>.</p><?php endif; ?>
  <ul class="plain-list">
  <?php foreach ($ch['sent'] as $c): ?>
    <li><b><?= h($c['from_name']) ?></b> → <b><?= h($c['to_name']) ?></b> · <?= h($when($c)) ?> <span class="tag"><?= h($status[$c['status']] ?? $c['status']) ?></span>
      <?php if ($c['status'] === 'inviata'): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button name="do" value="cancel" class="btn btn-ghost btn-sm">Annulla</button></form><?php endif; ?></li>
  <?php endforeach; ?></ul></section>
<?php layout_end();
