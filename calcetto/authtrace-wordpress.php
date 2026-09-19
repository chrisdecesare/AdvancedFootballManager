<?php
// TEMPORANEO: mostra il tracciamento degli accessi. Solo per l'amministratore di WordPress.
$wpLoad = dirname(__DIR__, 2) . '/wp-load.php';
if (!is_file($wpLoad)) {
    http_response_code(404);
    exit;
}
require_once $wpLoad;
if (!is_user_logged_in() || !current_user_can('manage_options')) {
    status_header(403);
    exit('Accesso negato.');
}
nocache_headers();
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
$f = __DIR__ . '/lib/authtrace.log';
if (!is_file($f)) {
    exit("(nessuna riga registrata)\n");
}
$lines = file($f, FILE_IGNORE_NEW_LINES) ?: [];
echo 'righe: ' . count($lines) . "\n\n" . implode("\n", array_slice($lines, -120)) . "\n";
