<?php
/* Conferma dell'indirizzo email dal link ricevuto (con un pulsante: i programmi che aprono i link da soli non lo consumano). */
require __DIR__ . '/lib/bootstrap.php';

if (!tables_exist()) {
    redirect('install.php');
}
$t = $_GET['t'] ?? $_POST['t'] ?? '';
$tok = mail_token_load($t, 'verify');
$row = null;
$done = false;
$error = null;
if ($tok) {
    $row = q('SELECT id, email, pending_email FROM users WHERE id = ?', [$tok['user_id']])->fetch();
    if (!$row || $row['pending_email'] !== $tok['email']) {
        $tok = null;     // nel frattempo l'indirizzo è stato cambiato: questo link non vale più
    }
}
if ($tok && is_post()) {
    if (q('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$tok['email'], $tok['user_id']])->fetch()) {
        $error = 'Questo indirizzo è già collegato a un altro account.';
    } else {
        $old = $row['email'];
        q('UPDATE users SET email = ?, pending_email = NULL WHERE id = ?', [$tok['email'], $tok['user_id']]);
        q("DELETE FROM mail_tokens WHERE user_id = ? AND purpose = 'verify'", [$tok['user_id']]);
        if ($old && $old !== $tok['email']) {
            notify_email_changed($old, (int) $tok['user_id'], $tok['email']);
        }
        guests_merge_for_user((int) $tok['user_id']);   // se aveva giocato da ospite con questa email, la partita gli compare tra quelle giocate
        $done = true;
    }
}

$back = current_user() ? ['account.php', 'Torna al tuo account'] : ['login.php', 'Vai al login'];
layout_start('Conferma email');
?>
<div class="narrow">
  <div class="card login-card">
    <div class="login-ball"><i class="ti ti-mail-check"></i></div>
    <?php if ($done): ?>
      <h1>Email confermata!</h1>
      <p>Da adesso, se dimentichi la password, puoi recuperarla da solo dalla pagina di accesso.</p>
      <a class="btn btn-primary btn-block" href="<?= $back[0] ?>"><?= $back[1] ?></a>
    <?php elseif (!$tok): ?>
      <h1>Link non valido</h1>
      <p>Questo link non funziona più: è scaduto, è già stato usato oppure hai chiesto di confermare un altro indirizzo.</p>
      <a class="btn btn-primary btn-block" href="<?= $back[0] ?>"><?= $back[1] ?></a>
    <?php else: ?>
      <h1>Conferma l'indirizzo</h1>
      <p>Vuoi collegare <strong><?= h($tok['email']) ?></strong> al tuo account?</p>
      <?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>
      <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="t" value="<?= h($t) ?>">
        <button class="btn btn-primary btn-block"><i class="ti ti-check"></i> Sì, conferma</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
