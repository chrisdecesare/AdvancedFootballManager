<?php
/* La cartolina dell'account appena creato (lib/invites.php): resta 30 minuti, solo a chi l'ha creato. */
require __DIR__ . '/lib/bootstrap.php';
require_login();
$back = safe_back($_GET['back'] ?? null, 'index.php');
$inv = invite_get();
layout_start('Invito');
?>
<a class="back" href="<?= h($back) ?>"><i class="ti ti-arrow-left"></i> Torna indietro</a>
<div class="page-head"><h1>Invito</h1></div>
<?php if (!$inv): ?>
  <p class="empty">Nessun invito da mostrare: la cartolina resta disponibile 30 minuti dopo aver creato l'account.</p>
<?php else: ?>
  <p class="muted">Account creato. Manda la cartolina a <b><?= h($inv['name']) ?></b>: con <b>Condividi</b> va dritta su WhatsApp, oppure scaricala o stampala.
    La password la vedi solo qui e solo per 30 minuti.</p>
  <div class="invite">
    <div class="invite-card" id="invite-card" data-invite="<?= h(json_encode($inv, JSON_UNESCAPED_UNICODE)) ?>"><p class="muted">Preparo la cartolina…</p></div>
    <div class="btn-row">
      <button type="button" class="btn btn-primary" id="invite-share">Condividi</button>
      <button type="button" class="btn btn-ghost" id="invite-download">Scarica l'immagine</button>
      <button type="button" class="btn btn-ghost" id="invite-copy">Copia l'invito</button>
      <button type="button" class="btn btn-ghost" onclick="window.print()">Stampa</button>
    </div>
  </div>
  <script src="assets/vendor/qrcode.js?v=1.4.4"></script>
  <script src="assets/invite.js?v=<?= h(substr((string) @md5_file(__DIR__ . '/assets/invite.js'), 0, 10)) ?>"></script>
<?php endif; ?>
<?php layout_end(); ?>
