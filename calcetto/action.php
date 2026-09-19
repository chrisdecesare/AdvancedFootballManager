<?php
/* Azioni rapide dei giocatori (conferma / assenza) usate da home e pagina partita. */
require __DIR__ . '/lib/bootstrap.php';
require_login();

$back = safe_back($_POST['back'] ?? null);
if (!is_post()) {
    redirect($back);
}

if (($_POST['do'] ?? '') === 'availability') {
    $match = get_match((int) ($_POST['match_id'] ?? 0));
    $status = $_POST['status'] ?? '';
    // un admin può cambiare la disponibilità di chiunque, un giocatore solo la propria
    $pid = is_admin() && !empty($_POST['player_id']) ? (int) $_POST['player_id'] : my_player_id();

    if (!$match || $match['status'] !== 'programmata') {
        flash('err', 'La partita non accetta più conferme.');
    } elseif (!$pid) {
        flash('err', 'Il tuo account non è collegato a un giocatore: chiedi all\'admin.');
    } elseif (!in_array($status, ['confermato', 'assente', 'in_attesa'], true)) {
        flash('err', 'Stato non valido.');
    } else {
        sync_match_players((int) $match['id']);
        // chi si ritira esce anche dalla squadra già formata
        q('INSERT INTO match_players (match_id, player_id, availability) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE availability = VALUES(availability),
             team = IF(VALUES(availability) = \'confermato\', team, NULL)',
            [$match['id'], $pid, $status]);
        assign_formation((int) $match['id']); // chi si ritira esce anche dal campo
        $msg = ['confermato' => 'Presenza confermata', 'assente' => 'Segnato come assente', 'in_attesa' => 'Risposta annullata'];
        flash('ok', $msg[$status]);
    }
}
redirect($back);
