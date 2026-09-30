<?php
define('NO_REMEMBER', true);   // in uscita non si crea un nuovo "resta collegato"
require __DIR__ . '/lib/bootstrap.php';

// Si esce solo con un POST (il codice CSRF lo controlla bootstrap.php): un link o un'immagine su un altro sito non deve poter
// far uscire nessuno. I link «Esci» del sito spediscono il modulo da soli (assets/app.js); aperto a mano, qui si chiede conferma.
if (!is_post()) {
    if (empty($_SESSION['uid'])) {
        redirect('login.php');
    }
    layout_start('Esci');
    ?>
<div class="narrow">
  <div class="card login-card">
    <div class="login-ball"><i class="ti ti-logout"></i></div>
    <h1>Vuoi uscire?</h1>
    <form method="post" class="form"><?= csrf_field() ?>
      <button class="btn btn-primary btn-block"><i class="ti ti-logout"></i> Esci dall'account</button>
    </form>
    <p class="login-alt"><a class="link" href="index.php">Resta collegato</a></p>
  </div>
</div>
<?php
    layout_end();
    exit;
}

if (tables_exist()) {
    if (!empty($_SESSION['uid'])) {
        log_activity('uscita', '', null, (int) $_SESSION['uid']);
    }
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
