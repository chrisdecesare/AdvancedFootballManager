<?php
/* Segnala e blocca (richiesti da Google Play per i contenuti degli utenti e dal DSA, art. 16). Solo POST; il codice CSRF lo controlla bootstrap.php. Come /segnala di Next. */
require __DIR__ . '/lib/bootstrap.php';
require_login();
$back = safe_back($_POST['back'] ?? '', 'index.php');
if (!is_post() || !feature_on('report_block')) {
    redirect($back);
}
$uid = (int) current_user()['id'];
$do = (string) ($_POST['do'] ?? '');
if ($do === 'report') {
    $err = report_content($uid, ['type' => $_POST['type'] ?? '', 'id' => $_POST['id'] ?? 0, 'reason' => $_POST['reason'] ?? '', 'details' => $_POST['details'] ?? '']);
    flash($err ? 'err' : 'ok', $err ?? 'Grazie, abbiamo ricevuto la segnalazione: la guardiamo il prima possibile e ti scriviamo l\'esito nella pagina «Privacy e dati».');
} elseif ($do === 'block') {
    $err = block_player($uid, (int) ($_POST['player_id'] ?? 0));
    flash($err ? 'err' : 'ok', $err ?? 'Giocatore bloccato: non vedrai più i suoi pensieri. Lo sblocchi da «Privacy e dati».');
} elseif ($do === 'unblock') {
    unblock_player($uid, (int) ($_POST['player_id'] ?? 0));
    flash('ok', 'Giocatore sbloccato.');
}
redirect($back);
