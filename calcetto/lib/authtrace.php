<?php
// TEMPORANEO: traccia il flusso di accesso per capire perché dal telefono si resta sul login.
// Registra solo: ora, IP, evento, pagina, impronta abbreviata del cookie di sessione (grezzo, come
// lo manda il browser, e come lo vede PHP), se la sessione risulta autenticata e il browser.
// Mai password né username.
function authtrace(string $event): void
{
    try {
        $f = __DIR__ . '/authtrace.log';
        if (is_file($f) && filesize($f) > 300000) {
            return;
        }
        $name = session_name();
        $raw = 'NO';                                   // cookie come arriva nell'intestazione HTTP
        foreach (explode(';', $_SERVER['HTTP_COOKIE'] ?? '') as $part) {
            $part = trim($part);
            if (strncmp($part, $name . '=', strlen($name) + 1) === 0) {
                $raw = substr(sha1(substr($part, strlen($name) + 1)), 0, 6);
            }
        }
        $php = isset($_COOKIE[$name]) ? substr(sha1((string) $_COOKIE[$name]), 0, 6) : 'NO';   // come lo vede PHP
        $ua = preg_replace('/[^\x20-\x7e]/', '?', $_SERVER['HTTP_USER_AGENT'] ?? '-');
        $line = implode(' | ', [
            date('H:i:s'),
            substr($_SERVER['REMOTE_ADDR'] ?? '-', 0, 20),
            $event,
            ($_SERVER['REQUEST_METHOD'] ?? '-') . ' ' . basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)),
            'browser_manda=' . $raw,
            'php_vede=' . $php,
            'sid=' . substr(sha1(session_id()), 0, 6),
            'uid=' . (isset($_SESSION['uid']) ? 'si' : 'no'),
            'ua=' . substr($ua, 0, 90),
        ]);
        @file_put_contents($f, $line . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // il tracciamento non deve mai rompere il sito
    }
}
