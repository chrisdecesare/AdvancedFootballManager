<?php
/* «Accetto» sull'avviso dei termini aggiornati (per chi era già iscritto): registra versione e data, poi torna dov'era. Solo POST (CSRF già controllato da bootstrap.php). */
require __DIR__ . '/lib/bootstrap.php';
require_login();
if (is_post() && !is_guest()) {
    record_consent((int) current_user()['id']);
}
redirect(safe_back($_POST['back'] ?? '', 'index.php'));
