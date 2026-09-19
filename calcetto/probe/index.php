<?php
// TEMPORANEO: seconda metà della prova di sessione (indirizzo di tipo "cartella"). Da cancellare.
require __DIR__ . '/../lib/bootstrap.php';
authtrace('probe_read_dir');
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Prova sessione</title>
<style>body{font:18px/1.5 system-ui,sans-serif;max-width:32rem;margin:1.5rem auto;padding:0 1rem}.ok{color:#080}.ko{color:#b00}</style></head><body>
<h1>Prova sessione</h1>
<p class="<?= isset($_SESSION['probe']) ? 'ok' : 'ko' ?>"><strong>
  <?= isset($_SESSION['probe']) ? 'SESSIONE OK (redirect a una cartella)' : 'SESSIONE PERSA (redirect a una cartella)' ?></strong></p>
<p><a href="../sessiontest.php">Ripeti la prova</a></p>
</body></html>
