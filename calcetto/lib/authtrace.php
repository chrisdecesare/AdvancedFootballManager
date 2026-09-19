<?php
// TEMPORANEO: traccia il flusso di accesso per capire perché dal telefono si resta sul login.
// Registra solo: ora, IP, evento, pagina, se è arrivato il cookie di sessione (con un'impronta
// abbreviata), se la sessione risulta autenticata e il tipo di browser. Mai password né username.
function authtrace(string $event): void
{
    try {
        $f = __DIR__ . '/authtrace.log';
        if (is_file($f) && filesize($f) > 300000) {
            return;
        }
        $cookie = isset($_COOKIE[session_name()]) ? substr(sha1((string) $_COOKIE[session_name()]), 0, 6) : 'NO';
        $ua = preg_replace('/[^\x20-\x7e]/', '?', $_SERVER['HTTP_USER_AGENT'] ?? '-');
        $line = implode(' | ', [
            date('H:i:s'),
            substr($_SERVER['REMOTE_ADDR'] ?? '-', 0, 20),
            $event,
            ($_SERVER['REQUEST_METHOD'] ?? '-') . ' ' . basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)),
            'cookie=' . $cookie,
            'sid=' . substr(sha1(session_id()), 0, 6),
            'uid=' . (isset($_SESSION['uid']) ? 'si' : 'no'),
            'ua=' . substr($ua, 0, 110),
        ]);
        @file_put_contents($f, $line . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // il tracciamento non deve mai rompere il sito
    }
}
