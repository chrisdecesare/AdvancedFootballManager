<?php
require __DIR__ . '/lib/bootstrap.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $c = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $c['path'],
        'domain' => $c['domain'],
        'secure' => $c['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
session_destroy();
redirect('login.php');
