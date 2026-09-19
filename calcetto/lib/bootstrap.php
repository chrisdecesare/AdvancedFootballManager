<?php
require __DIR__ . '/../config.php';

// In produzione gli errori PHP non vanno mostrati agli utenti (rivelano percorsi e query).
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set(TIMEZONE);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

header_remove('X-Powered-By');

// Header di sicurezza. La CSP non limita gli script/immagini di proposito: Altervista
// inietta da sé il proprio banner pubblicitario e una CSP più stretta lo romperebbe.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header('Cache-Control: private, no-store');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=15552000');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_name('calcetto_sess');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/formation.php';
require __DIR__ . '/balance.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/authtrace.php';   // TEMPORANEO

authtrace('req');
verify_csrf();
if (tables_exist()) {
    ensure_schema();
}
