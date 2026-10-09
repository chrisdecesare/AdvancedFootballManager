<?php
/* Il pensiero sulla partita (lib/pensieri.php): lo manda il messaggio che compare a chi ha giocato l'ultima partita. */
require __DIR__ . '/lib/bootstrap.php';
require_login();

$back = safe_back($_POST['back'] ?? null);
$me = my_player_id();
$matchId = (int) ($_POST['match_id'] ?? 0);
if (!is_post() || !$me || is_guest() || !$matchId) {
    redirect($back);
}
$do = $_POST['do'] ?? '';
if ($do === 'later') {   // «Più tardi»: per questa sessione non lo chiede più
    $_SESSION['thought_later'][$matchId] = time();
    redirect($back);
}
$err = thought_save($matchId, $me, $do === 'skip' ? null : (string) ($_POST['body'] ?? ''));
if ($err) {
    flash('err', $err);
    $_SESSION['thought_draft'] = mb_substr((string) ($_POST['body'] ?? ''), 0, THOUGHT_MAX + 50);   // il testo non si perde
} elseif ($do !== 'skip') {
    flash('ok', 'Pensiero salvato: lo leggeranno tutti nella Gazzetta del mercoledì.');
}
redirect($back);
