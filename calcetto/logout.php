<?php
define('NO_REMEMBER', true);   // in uscita non si crea un nuovo "resta collegato"
require __DIR__ . '/lib/bootstrap.php';

if (tables_exist()) {
    remember_forget();
}
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
