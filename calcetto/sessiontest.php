<?php
// TEMPORANEO: riproduce il passaggio del login (nuovo id di sessione + redirect) senza credenziali,
// per capire se la sessione sopravvive al redirect da questo browser. Da cancellare a fine prova.
require __DIR__ . '/lib/bootstrap.php';

$step = $_GET['step'] ?? '1';

if (is_post() && $step === '2') {
    $target = ($_POST['go'] ?? '') === 'dir' ? 'probe/' : 'sessiontest.php?step=3';
    session_regenerate_id(true);
    $_SESSION['probe'] = 'ok';
    authtrace('probe_set_' . (($_POST['go'] ?? '') === 'dir' ? 'dir' : 'file'));
    redirect($target);
}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Prova sessione</title>
<style>body{font:18px/1.5 system-ui,sans-serif;max-width:32rem;margin:1.5rem auto;padding:0 1rem}
button{font:inherit;padding:.8rem 1rem;margin:.4rem 0;width:100%}.ok{color:#080}.ko{color:#b00}</style></head><body>
<h1>Prova sessione</h1>
<?php if ($step === '3'): ?>
  <?php authtrace('probe_read_file'); ?>
  <p class="<?= isset($_SESSION['probe']) ? 'ok' : 'ko' ?>"><strong>
    <?= isset($_SESSION['probe']) ? 'SESSIONE OK (redirect a un file)' : 'SESSIONE PERSA (redirect a un file)' ?></strong></p>
  <p><a href="sessiontest.php">Ripeti la prova</a></p>
<?php else: ?>
  <p>Tocca i due pulsanti, uno alla volta, e leggi il risultato.</p>
  <form method="post" action="sessiontest.php?step=2"><?= csrf_field() ?><input type="hidden" name="go" value="file">
    <button>Prova A: redirect a un file</button></form>
  <form method="post" action="sessiontest.php?step=2"><?= csrf_field() ?><input type="hidden" name="go" value="dir">
    <button>Prova B: redirect a una cartella</button></form>
<?php endif; ?>
</body></html>
