<?php
/* Segna come visto il tutorial di benvenuto (lo chiama assets/app.js quando si finisce o si salta). */
require __DIR__ . '/lib/bootstrap.php';   // per i POST verifica anche il token CSRF
require_login();

if (!is_post()) {
    redirect('index.php');
}
if (($_POST['do'] ?? '') === 'done') {
    q('UPDATE users SET tour_done = 1 WHERE id = ?', [(int) current_user()['id']]);
}
http_response_code(204);
