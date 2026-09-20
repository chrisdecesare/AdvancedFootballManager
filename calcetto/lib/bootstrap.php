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
// Su Altervista (WordPress dietro la cache Varnish) le richieste GET arrivano a PHP SENZA cookie, tranne
// quelli con nomi da "utente WordPress loggato". Senza questo nome il login riesce ma la pagina dopo il
// redirect non vede la sessione. Il codice è nostro, diverso da quello di WordPress: per WordPress è un
// cookie qualunque, per la cache un utente loggato (quindi non cacheabile). Altrove il nome è innocuo.
session_name('wordpress_logged_in_' . md5('calcetto-manager-session'));
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (defined('NO_SESSION')) {
    $_SESSION = [];     // richieste automatiche (cron.php): niente cookie e niente file di sessione
} else {
    session_start();
}

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/groups.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/formation.php';
require __DIR__ . '/chemistry.php';
require __DIR__ . '/balance.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/tour.php';
require __DIR__ . '/webpush.php';
require __DIR__ . '/curiosities.php';

verify_csrf();
if (tables_exist()) {
    ensure_schema();
    remember_check();   // sessione scaduta ma dispositivo "collegato": rientra da solo
    // mentre qualcuno usa il sito, a risposta già inviata, parte l'eventuale promemoria "non hai ancora risposto"
    if (!empty($_SESSION['uid']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        push_defer('push_maybe_run');
    }
}
