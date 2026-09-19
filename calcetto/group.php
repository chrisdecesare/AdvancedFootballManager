<?php
/* Sceglie quale gruppo guardare (Tutti / uno dei propri). Lo chiamano i pulsanti in cima alle pagine. */
require __DIR__ . '/lib/bootstrap.php';
require_login();

if (is_post()) {   // per i POST verifica anche il token CSRF (bootstrap)
    set_group_filter((int) ($_POST['g'] ?? 0));
    redirect(safe_back($_POST['back'] ?? null));
}
redirect('index.php');
