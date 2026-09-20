<?php
/*
 * Promemoria "non hai ancora risposto" da un pianificatore esterno (facoltativo).
 * Senza cron partono comunque, ma solo quando qualcuno usa il sito: con un cron ogni 10-15 minuti sono puntuali.
 * Indirizzo (il codice lo trovi in Admin → Notifiche):  .../cron.php?key=CODICE
 */
define('NO_SESSION', true);
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');

if (!tables_exist() || !hash_equals(push_cron_key(), (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit("Codice non valido.\n");
}
meta_set('push_last_run', (string) time());   // così non parte anche il controllo "al volo" degli utenti
close_due_votings();
echo 'ok ' . push_run_due() . "\n";
