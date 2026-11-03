<?php
/* Raccoglitore delle violazioni della CSP in sola segnalazione (lib/bootstrap.php). Il browser fa un POST JSON senza codice CSRF e senza che l'utente lo veda.
   Ogni violazione uguale si registra una volta al giorno, e al massimo 10 per connessione all'ora: non si può riempire il registro. */
define('NO_CSRF', true);
require __DIR__ . '/lib/bootstrap.php';
http_response_code(204);
if (!is_post() || !tables_exist()) {
    exit;
}
$d = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
$r = is_array($d) ? ($d['csp-report'] ?? null) : null;
if (!is_array($r)) {
    exit;
}
$one = fn ($k) => mb_substr(preg_replace('/[^\x20-\x7e]/', '?', (string) ($r[$k] ?? '?')), 0, 90);
$detail = mb_substr($one('violated-directive') . ' ← ' . $one('blocked-uri') . ' @ ' . (string) parse_url((string) ($r['document-uri'] ?? ''), PHP_URL_PATH), 0, 250);
$seen = q("SELECT 1 FROM activity_log WHERE action = 'sicurezza_csp' AND detail = ? AND created_at > ?", [$detail, date('Y-m-d H:i:s', time() - 86400)])->fetch();
if (!$seen && rate_hit('__csp_' . substr(md5(client_ip()), 0, 10), 10, 3600)) {
    security_log('csp', $detail);
}
