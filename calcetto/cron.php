<?php
/*
 * Promemoria "non hai ancora risposto" da un pianificatore esterno (facoltativo).
 * Senza cron partono comunque, ma solo quando qualcuno usa il sito: con un cron ogni 10-15 minuti sono puntuali.
 * Indirizzo .../cron.php con l'intestazione «X-Cron-Key: CODICE» (il codice lo trovi in Admin → Notifiche);
 * per i pianificatori che non sanno mandare intestazioni va anche .../cron.php?key=CODICE.
 */
define('NO_SESSION', true);
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');

// il codice si manda meglio nell'intestazione X-Cron-Key: nell'indirizzo finisce nei log del server e del servizio di cron.
// L'indirizzo con ?key= resta valido per i pianificatori già impostati.
$key = $_SERVER['HTTP_X_CRON_KEY'] ?? $_GET['key'] ?? '';
if (!tables_exist() || !is_string($key) || !hash_equals(push_cron_key(), $key)) {
    http_response_code(403);
    exit("Codice non valido.\n");
}
meta_set('push_last_run', (string) time());   // così non parte anche il controllo "al volo" degli utenti
close_due_votings();
fanta_snapshot_due();         // Fanta: formazioni al calcio d'inizio (lib/fanta.php)
shop_news_notify_due();       // oggetti appena usciti nel negozio: notifica push (lib/shop.php)
$due = push_run_due();          // promemoria "non hai ancora risposto", scommesse aperte e ultima ora: finiscono in coda come le altre
$sent = push_queue_run(200);    // spedisce la coda: notifiche nuove e riprove di quelle non riuscite
push_queue_cleanup();           // il registro tiene un mese
guests_cleanup();               // toglie gli account degli ospiti la cui partita e' vecchia di una settimana
echo 'ok ' . $due . ' in coda, ' . $sent . " consegnate\n";
