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
    // chi gestisce le partite della lega può cambiare la disponibilità di chiunque, un giocatore solo la propria
    $pid = $match && can_manage_group((int) $match['group_id']) && !empty($_POST['player_id']) ? (int) $_POST['player_id'] : my_player_id();

    if (!$match || $match['status'] !== 'programmata') {
        flash('err', 'La partita non accetta più conferme.');
    } elseif (!match_access($match)) {
        flash('err', 'Questa partita non è del tuo gruppo.');
    } elseif (!$pid) {
        flash('err', 'Il tuo account non è collegato a un giocatore: chiedi all\'admin.');
    } elseif (!(is_guest() ? $pid === my_player_id() : player_in_group($pid, (int) $match['group_id']))) {   // l'ospite non ha gruppi: basta che la partita sia la sua
        flash('err', 'Il giocatore non fa parte del gruppo di questa partita.');
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
        log_activity('presenza', $status . ' · ' . fmt_date_short($match['match_date']) . ($pid !== my_player_id() ? ' · giocatore #' . $pid : ''), (int) $match['group_id']);
        $msg = ['confermato' => 'Presenza confermata', 'assente' => 'Segnato come assente', 'in_attesa' => 'Risposta annullata'];
        flash('ok', $msg[$status]);
    }
}
redirect($back);
