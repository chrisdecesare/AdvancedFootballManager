<?php
require __DIR__ . '/lib/bootstrap.php';
require_view();

$id = int_get('id');
$match = get_match($id);
if (!$match || !match_access($match)) {   // partita inesistente o di un gruppo a cui non appartieni
    http_response_code(404);
    layout_start('Partita non trovata', 'matches');
    echo '<div class="card"><h2>Partita non trovata</h2><a href="matches.php"><i class="ti ti-arrow-left"></i> Partite</a></div>';
    layout_end();
    exit;
}
$me = my_player_id();
$self = 'match.php?id=' . $id;
$gid = (int) $match['group_id'];
$canManage = can_manage_group($gid);   // presenze, squadre, risultato, votazioni: admin/manager della lega (e l'admin del sito)
$canAdmin = can_admin_group($gid);     // pagamenti, ospiti, eliminazione: chi amministra la lega

/* ---------------------------------------------------------------- azioni */
if (is_post()) {
    $do = $_POST['do'] ?? '';
    $pid = (int) ($_POST['player_id'] ?? 0);

    if ($do === 'vote') {
        require_login();
        handle_vote($match, $me);
        log_activity('voto', fmt_date_short($match['match_date']), $gid);
        redirect($self . '#voti');
    }

    // cronaca in diretta: la tengono anche i giocatori in campo, non solo chi gestisce le partite
    if (in_array($do, ['live_goal', 'live_injury', 'live_undo'], true)) {
        require_login();
        $uid = (int) current_user()['id'];
        if ($do === 'live_goal') {
            $err = live_can_edit($match, $me)
                ? live_add_goal($match, $pid, (int) ($_POST['assist_id'] ?? 0) ?: null, !empty($_POST['own']), $uid)
                : 'Gol e autogol si segnano a partita in corso, da chi gioca o da chi gestisce le partite.';
            $ok = !empty($_POST['own']) ? 'Autogol segnato.' : 'Gol segnato!';
        } elseif ($do === 'live_injury') {
            $err = live_can_edit($match, $me, 'infortunio')
                ? live_add_injury($match, $pid, (string) ($_POST['note'] ?? ''), $uid)
                : 'Gli infortuni si segnano dal calcio d\'inizio, da chi gioca o da chi gestisce le partite.';
            $ok = 'Infortunio segnato. Forza e rimettiti presto!';
        } else {
            $err = live_remove_event($match, (int) ($_POST['event_id'] ?? 0), $me);
            $ok = 'Evento tolto.';
        }
        flash($err ? 'err' : 'ok', $err ?: $ok);
        redirect($self . '#diretta');
    }

    require_login();
    if (!$canManage) {
        flash('err', 'Questa partita la gestisce chi amministra la sua lega.');
        redirect($self);
    }
    $actor = (int) current_user()['id'];
    // partita annullata: i dati restano come sono; si può solo riaprirla, eliminarla o segnare i pagamenti
    if ($match['status'] === 'annullata' && !in_array($do, ['reopen', 'delete', 'toggle_paid'], true)) {
        flash('err', 'La partita è annullata: per cambiarla riportala prima a "programmata".');
        redirect($self);
    }
    if ($canAdmin || !in_array($do, ['toggle_paid', 'add_guest', 'remove_guest', 'call_free_agent', 'invite_free_agent', 'delete'], true)) {   // quelle rifiutate sotto non contano
        log_activity('partita', $do . ' · ' . fmt_date_short($match['match_date']), $gid);
    }
    switch ($do) {
        case 'cancel':
            match_cancel($match, is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '', !empty($_POST['no_fee']), $actor);   // lib/stats.php
            flash('ok', 'Partita annullata: non conta per classifiche, statistiche, voti e Fanta, e le scommesse sono state rimborsate. Presenze, squadre e gol restano salvati.');
            break;

        case 'edit_info':
            $dt = DateTime::createFromFormat('Y-m-d H:i', ($_POST['date'] ?? '') . ' ' . ($_POST['time'] ?? ''));
            if ($dt) {
                $newDate = $dt->format('Y-m-d H:i:s');
                // cambia il giorno di una partita in programma: le vecchie risposte non valgono più
                $reset = $match['status'] === 'programmata' && substr($newDate, 0, 10) !== substr($match['match_date'], 0, 10);
                db()->beginTransaction();
                q('UPDATE matches SET match_date = ?, location = ?, team_a_name = ?, team_b_name = ?, fee = ?, notes = ?, keepers = ? WHERE id = ?', [
                    $newDate, trim($_POST['location'] ?? ''),
                    clean_team_name($_POST['team_a'] ?? '', TEAM_A_NAME), clean_team_name($_POST['team_b'] ?? '', TEAM_B_NAME),
                    max(0, (float) str_replace(',', '.', $_POST['fee'] ?? '0')),
                    trim($_POST['notes'] ?? '') ?: null, ($_POST['keepers'] ?? '') === 'fissi' ? 'fissi' : 'volanti', $id]);
                if ($reset) {
                    sync_match_players($id);   // anche chi non aveva ancora una riga deve poter rispondere
                    reset_match_responses($id);
                } elseif ($newDate !== $match['match_date']) {
                    // cambia solo l'ora: gli avvisi delle scommesse (apertura, ultima ora) si rifanno sul nuovo orario
                    q("DELETE FROM push_log WHERE match_id = ? AND kind IN ('betsopen', 'bets1h')", [$id]);
                }
                db()->commit();
                if ($match['status'] === 'programmata') {
                    push_notify_match_changed($id, $match, $reset, $actor);   // avvisa i giocatori, a pagina già inviata
                }
                flash('ok', 'Dati della partita aggiornati.' . ($reset ? ' Il giorno è cambiato: le risposte dei giocatori sono state azzerate e dovranno rispondere di nuovo.' : ''));
            } else {
                flash('err', 'Data o ora non valide.');
            }
            break;

        case 'set_avail':
            $st = $_POST['status'] ?? '';
            if ($st === 'confermato' && !empty(get_player((int) $pid)['injured'])) {
                flash('err', 'Giocatore infortunato: non può confermare finché non è segnato di nuovo disponibile.');
            } elseif (in_array($st, ['confermato', 'in_attesa', 'assente'], true)) {
                $was = (string) q('SELECT availability FROM match_players WHERE match_id = ? AND player_id = ?', [$id, $pid])->fetchColumn();
                q("UPDATE match_players SET availability = ?, team = IF(? = 'confermato', team, NULL)
                   WHERE match_id = ? AND player_id = ?", [$st, $st, $id, $pid]);
                assign_formation($id);
                if ($st === 'assente') {
                    bets_void_for_player($id, $pid);   // le scommesse su di lui/lei saltano (singole cancellate, nelle multiple solo quella selezione)
                }
                push_notify_roster_change($id, $pid, $was, $st, $actor);   // lo sanno gli altri confermati
            }
            break;

        case 'gen_teams':
            $stats = compute_stats([(int) $match['group_id']]);   // rating calcolati sulle partite del suo gruppo
            $pool = [];
            foreach (match_roster($id) as $r) {
                if ($r['availability'] === 'confermato') {
                    $pool[] = ['id' => (int) $r['player_id'], 'prefs' => player_prefs($r),
                        'rating' => $stats[(int) $r['player_id']]['ovr'] ?? 6];
                }
            }
            if (count($pool) < 2) {
                flash('err', 'Servono almeno 2 giocatori confermati.');
                break;
            }
            @set_time_limit(90);   // con 22 giocatori e molte intese il calcolo può richiedere qualche secondo sull'hosting
            $chem = chemistry([(int) $match['group_id']]);   // intesa dalle partite già giocate insieme, nel gruppo
            $res = balance_teams($pool, 6, $match, chemistry_pairs_for($chem, array_column($pool, 'id')));
            db()->beginTransaction();
            q('UPDATE match_players SET team = NULL WHERE match_id = ?', [$id]);
            foreach (['A', 'B'] as $t) {
                foreach ($res[$t] as $p) {
                    q('UPDATE match_players SET team = ? WHERE match_id = ? AND player_id = ?', [$t, $id, $p]);
                }
            }
            db()->commit();
            assign_formation($id);
            $hasChem = abs($res['chemA'] ?? 0) + abs($res['chemB'] ?? 0) > 0;
            $fa = $res['sumA'] + ($res['chemA'] ?? 0);
            $fb = $res['sumB'] + ($res['chemB'] ?? 0);
            flash('ok', sprintf('Squadre generate: forza %s vs %s (differenza %s)%s.',
                fmt_num($fa, 1), fmt_num($fb, 1), fmt_num(abs($fa - $fb), 2),
                $hasChem ? ', compresa l\'intesa ' . fmt_signed($res['chemA'], 2) . ' / ' . fmt_signed($res['chemB'], 2) : ''));
            redirect($self . '#squadre');

        case 'move_team':
            q("UPDATE match_players SET team = IF(team = 'A', 'B', 'A'), availability = 'confermato'
               WHERE match_id = ? AND player_id = ?", [$id, $pid]);
            assign_formation($id);
            redirect($self . '#squadre');

        case 'swap_slots':
            // scambia squadra e posto di due pedine (senza ricalcolare il resto)
            $a = q('SELECT player_id, team, slot FROM match_players WHERE match_id = ? AND player_id = ?', [$id, (int) ($_POST['p1'] ?? 0)])->fetch();
            $b = q('SELECT player_id, team, slot FROM match_players WHERE match_id = ? AND player_id = ?', [$id, (int) ($_POST['p2'] ?? 0)])->fetch();
            if ($a && $b && $a['team'] && $b['team']) {
                q('UPDATE match_players SET team = ?, slot = ? WHERE match_id = ? AND player_id = ?', [$b['team'], $b['slot'], $id, $a['player_id']]);
                q('UPDATE match_players SET team = ?, slot = ? WHERE match_id = ? AND player_id = ?', [$a['team'], $a['slot'], $id, $b['player_id']]);
            }
            redirect($self . '#squadre');

        case 'set_formation':
            $t = ($_POST['team'] ?? '') === 'B' ? 'b' : 'a';
            $f = trim($_POST['formation'] ?? '');
            q("UPDATE matches SET formation_$t = ? WHERE id = ?", [parse_formation($f) ? $f : '', $id]);
            assign_formation($id);
            flash('ok', $f ? "Modulo $f impostato." : 'Modulo automatico.');
            redirect($self . '#squadre');

        case 'reset_formation':
            assign_formation($id);
            flash('ok', 'Posizioni ricalcolate in base alle preferenze.');
            redirect($self . '#squadre');

        case 'add_to_team':
            $t = ($_POST['team'] ?? '') === 'B' ? 'B' : 'A';
            $was = (string) q('SELECT availability FROM match_players WHERE match_id = ? AND player_id = ?', [$id, $pid])->fetchColumn();
            push_notify_roster_change($id, $pid, $was, 'confermato', $actor);
            q("UPDATE match_players SET team = ?, availability = 'confermato' WHERE match_id = ? AND player_id = ?", [$t, $id, $pid]);
            assign_formation($id);
            redirect($self . '#squadre');

        case 'remove_from_team':
            q('UPDATE match_players SET team = NULL, slot = NULL WHERE match_id = ? AND player_id = ?', [$id, $pid]);
            assign_formation($id);
            redirect($self . '#squadre');

        case 'clear_teams':
            q('UPDATE match_players SET team = NULL, slot = NULL WHERE match_id = ?', [$id]);
            flash('ok', 'Squadre azzerate.');
            break;

        case 'save_result':
            $sa = $_POST['score_a'] ?? '';
            $sb = $_POST['score_b'] ?? '';
            $stats = [];
            foreach (['goals', 'assists', 'own_goals'] as $k) {
                $stats[$k] = array_map('intval', (array) ($_POST[$k] ?? []));
            }
            $err = match_apply_result($match, $stats, $sa === '' ? null : max(0, (int) $sa), $sb === '' ? null : max(0, (int) $sb), !empty($_POST['finish']), $actor);
            if ($err) {
                flash('err', $err);
            } elseif (!empty($_POST['finish']) && $match['status'] !== 'giocata') {
                flash('ok', 'Partita conclusa: votazioni aperte per chi ha giocato, fino a ' . push_when(get_match($id)['voting_ends_at']) . '.');
            } else {
                flash('ok', 'Risultato salvato.');
            }
            redirect($self . '#risultato');

        case 'add_link':
            $x = (int) ($_POST['assister_id'] ?? 0);
            $y = (int) ($_POST['scorer_id'] ?? 0);
            $times = max(1, min(20, (int) ($_POST['n'] ?? 1)));
            $tx = q('SELECT team FROM match_players WHERE match_id = ? AND player_id = ? AND team IS NOT NULL', [$id, $x])->fetchColumn();
            $ty = q('SELECT team FROM match_players WHERE match_id = ? AND player_id = ? AND team IS NOT NULL', [$id, $y])->fetchColumn();
            if (!$tx || !$ty) {
                flash('err', 'Scegli due giocatori che hanno giocato la partita.');
            } elseif ($x === $y) {
                flash('err', 'Chi fa assist e chi segna devono essere due giocatori diversi.');
            } elseif ($tx !== $ty) {
                flash('err', 'Devono essere della stessa squadra.');
            } else {
                q('INSERT INTO match_links (match_id, assister_id, scorer_id, n) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE n = VALUES(n)', [$id, $x, $y, $times]);
                flash('ok', 'Assist registrato.');
            }
            redirect($self . '#assist');

        case 'del_link':
            q('DELETE FROM match_links WHERE match_id = ? AND assister_id = ? AND scorer_id = ?',
                [$id, (int) ($_POST['assister_id'] ?? 0), (int) ($_POST['scorer_id'] ?? 0)]);
            redirect($self . '#assist');

        case 'close_voting':
            $late = close_voting_now($id, $actor);   // voti d'ufficio a chi non ha votato + notifica
            if ($late === null) {
                flash('err', 'Le votazioni erano già chiuse.');
                break;
            }
            flash('ok', 'Votazioni chiuse: voti e MVP ora contano nelle statistiche.'
                . ($late ? ' A chi non ha votato (' . implode(', ', $late) . ') è stato dato ' . default_vote_label() . ' d\'ufficio a tutti gli altri.' : ''));
            break;

        case 'nudge':
            // le campanelle: solo a chi non ha ancora risposto sulla presenza, o non ha ancora votato (lib/webpush.php: push_nudge)
            $kind = ($_POST['kind'] ?? '') === 'voti' ? 'voti' : 'presenza';
            [$err, $sent, $unreachable] = push_nudge($id, $kind, $actor);
            $anchor = $kind === 'voti' ? '#voti' : '#presenze';
            if ($err) {
                flash('err', $err);
                redirect($self . $anchor);
            }
            $what = $kind === 'voti' ? 'votato' : 'detto se c\'è';
            flash('ok', ($sent ? 'Promemoria mandato a ' . $sent . ($sent === 1 ? ' giocatore che non ha' : ' giocatori che non hanno') . ' ancora ' . $what . '.'
                    : 'Nessuno di quelli che mancano riceve le notifiche.')
                . ($unreachable ? ' ' . $unreachable . ($unreachable === 1 ? ' non ha le notifiche attive: avvisalo tu.' : ' non hanno le notifiche attive: avvisali tu.') : ''));
            redirect($self . $anchor);

        case 'open_voting':
            q('UPDATE matches SET voting_open = 1, voting_ends_at = ? WHERE id = ?', [default_voting_end(), $id]);
            q('DELETE FROM ratings WHERE match_id = ? AND is_auto = 1', [$id]);   // i voti d'ufficio si rifanno alla prossima chiusura
            bets_unsettle($id, ['mvp']);   // l'MVP torna in gioco: le scommesse si ripagano alla prossima chiusura
            push_notify_voting($id, true, $actor);
            flash('ok', 'Votazioni riaperte.');
            break;

        case 'set_voting_end':
            if ($match['status'] !== 'giocata' || !$match['voting_open']) {
                flash('err', 'Le votazioni non sono aperte.');
                break;
            }
            if (!empty($_POST['clear'])) {
                q('UPDATE matches SET voting_ends_at = NULL WHERE id = ?', [$id]);
                flash('ok', 'Nessuna scadenza: le votazioni si chiudono solo quando le chiudi tu.');
                break;
            }
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', (string) ($_POST['ends'] ?? ''));
            if (!$dt || $dt->getTimestamp() <= time()) {
                flash('err', 'Scegli un orario di fine votazioni che non sia già passato.');
                break;
            }
            q('UPDATE matches SET voting_ends_at = ? WHERE id = ?', [$dt->format('Y-m-d H:i:s'), $id]);
            flash('ok', 'Le votazioni terminano ' . push_when($dt->format('Y-m-d H:i:s')) . '.');
            break;

        case 'reopen':   // da giocata o da annullata
            q("UPDATE matches SET status = 'programmata', voting_open = 0, voting_ends_at = NULL, cancel_reason = NULL, cancelled_at = NULL WHERE id = ?", [$id]);
            bets_unsettle($id);   // le scommesse tornano aperte e si ripagano quando la partita viene richiusa
            match_rewards_sync($id);   // e i premi per gol e assist si tolgono (tornano quando il risultato viene salvato di nuovo)
            fanta_win_credits_sync($id);   // anche i crediti fanta della vittoria
            flash('ok', 'Partita riportata a "programmata".');
            break;

        case 'toggle_paid':
            if (!$canAdmin) {
                flash('err', 'I pagamenti li gestisce solo un admin.');
                break;
            }
            q('UPDATE match_players SET paid = 1 - paid WHERE match_id = ? AND player_id = ?', [$id, $pid]);
            redirect($self . '#pagamenti');

        case 'add_guest':
            if (!$canAdmin) {
                flash('err', 'Gli ospiti li aggiunge solo un admin.');
                break;
            }
            $res = guest_create($match, $_POST);
            if (isset($res['error'])) {
                flash('err', $res['error']);
            } else {
                // la password non si può rivedere: è l'unico momento in cui compare
                flash('ok', 'Ospite aggiunto. Manda questi dati a ' . trim((string) ($_POST['name'] ?? '')) . ' — indirizzo: ' . trusted_base_url() . 'login.php · utente «' . $res['username']
                    . '» · password «' . $res['password'] . '». Vede questa partita e la formazione, l\'accesso vale fino a ' . GUEST_KEEP_DAYS . ' giorni dopo la partita.');
            }
            redirect($self . '#presenze');

        case 'call_free_agent':   // un giocatore libero (ospite che ha salvato l'account) chiamato a questa partita
            if (!$canAdmin) {
                flash('err', 'Gli ospiti li aggiunge solo un admin.');
                break;
            }
            $err = guest_call($match, $pid, $actor);
            flash($err ? 'err' : 'ok', $err ?? 'Giocatore chiamato: gli è arrivata una notifica e ora deve dire se c\'è.');
            redirect($self . '#presenze');

        case 'invite_free_agent':   // ... oppure invitato nella lega
            if (!$canAdmin) {
                flash('err', 'Nella lega invita solo chi la amministra.');
                break;
            }
            $err = league_invite_free_agent($gid, $pid, $actor);
            flash($err ? 'err' : 'ok', $err ?? 'Invito mandato: se accetta entra nella lega.');
            redirect($self . '#presenze');

        case 'guest_votes':   // i voti di un ospite contano per la lega? (dati e ricevuti)
            $ok = !empty($_POST['ok']) ? 1 : 0;
            if (!q('SELECT 1 FROM match_players mp JOIN players p ON p.id = mp.player_id WHERE mp.match_id = ? AND mp.player_id = ? AND p.is_guest = 1', [$id, $pid])->fetch()) {
                flash('err', 'Ospite non trovato in questa partita.');
                break;
            }
            q('UPDATE match_players SET votes_ok = ? WHERE match_id = ? AND player_id = ?', [$ok, $id, $pid]);
            if ($match['status'] === 'giocata' && !$match['voting_open']) {   // l'MVP può cambiare: le scommesse sull'MVP si ripagano
                bets_unsettle($id, ['mvp']);
                bets_settle($id, ['mvp']);
            }
            flash('ok', $ok ? 'Ora i voti dell\'ospite contano per la lega.' : 'I voti dell\'ospite non contano per la lega (lui vede comunque il suo feedback).');
            redirect($self . '#voti');

        case 'remove_guest':
            if (!$canAdmin) {
                flash('err', 'Gli ospiti li toglie solo un admin.');
                break;
            }
            $removed = guest_remove_from_match($pid, $id);
            flash($removed ? 'ok' : 'err', $removed ? 'Ospite tolto dalla partita.' : 'Ospite non trovato.');
            redirect($self . '#presenze');

        case 'delete':
            if (!$canAdmin) {
                flash('err', 'Solo un admin può eliminare una partita.');
                break;
            }
            push_notify_match_cancelled($match, $actor);   // prima di cancellare: dopo non si saprebbe più a chi mandarla
            foreach (match_guests($id) as $g) {
                guest_remove_from_match((int) $g['id'], $id);   // gli ospiti se ne vanno con la partita (chi ha salvato l'account lo tiene)
            }
            q('DELETE FROM wallet_moves WHERE ref IN (?, ?, ?)', ['premio-m' . $id, 'premio-cr-m' . $id, 'consolazione-m' . $id]);   // premi per gol e assist e consolazione se ne vanno con la partita
            q('DELETE FROM matches WHERE id = ?', [$id]);
            fanta_win_credits_sync($id);   // i crediti fanta della vittoria se ne vanno con la partita
            flash('ok', 'Partita eliminata.');
            redirect('matches.php');
    }
    redirect($self);
}

function handle_vote(array $match, ?int $me): void
{
    $id = (int) $match['id'];
    if ($match['status'] !== 'giocata' || !$match['voting_open']) {
        flash('err', 'Le votazioni per questa partita sono chiuse.');
        return;
    }
    $mates = [];
    foreach (match_roster($id) as $r) {
        if ($r['team']) {   // chi vota e chi viene votato: chi ha giocato, ospiti compresi (i loro voti contano se la lega li accetta)
            $mates[(int) $r['player_id']] = true;
        }
    }
    if (!$me || !isset($mates[$me])) {
        flash('err', 'Può votare solo chi ha giocato la partita.');
        return;
    }
    $votes = (array) ($_POST['vote'] ?? []);
    $mvp = (int) ($_POST['mvp'] ?? 0);
    if (!$mvp || $mvp === $me || !isset($mates[$mvp])) {
        flash('err', 'Scegli l\'MVP tra gli altri giocatori della partita.');
        return;
    }
    // miglior difensore e (con i portieri fissi) miglior portiere: obbligatori quando c'è qualcuno da votare oltre a sé
    $awards = [];
    foreach (match_award_options($match, match_roster($id)) as $award => $options) {
        $options = array_diff($options, [$me]);
        if (!$options) {
            continue;
        }
        $pick = (int) ($_POST['award'][$award] ?? 0);
        if (!in_array($pick, $options, true)) {
            flash('err', 'Scegli il ' . lcfirst(MATCH_AWARDS[$award]['name']) . ' tra gli altri giocatori della partita.');
            return;
        }
        $awards[$award] = $pick;
    }
    db()->beginTransaction();
    q('DELETE FROM ratings WHERE match_id = ? AND voter_id = ?', [$id, $me]);
    foreach ($mates as $pid => $_) {
        if ($pid === $me || !isset($votes[$pid])) {
            continue;
        }
        $v = round(max(1, min(10, (float) $votes[$pid])) * 2) / 2; // passi da 0,5
        q('INSERT INTO ratings (match_id, voter_id, rated_id, vote) VALUES (?, ?, ?, ?)', [$id, $me, $pid, $v]);
    }
    q('DELETE FROM mvp_votes WHERE match_id = ? AND voter_id = ?', [$id, $me]);
    q('INSERT INTO mvp_votes (match_id, voter_id, voted_id) VALUES (?, ?, ?)', [$id, $me, $mvp]);
    q('DELETE FROM award_votes WHERE match_id = ? AND voter_id = ?', [$id, $me]);
    foreach ($awards as $award => $pick) {
        q('INSERT INTO award_votes (match_id, voter_id, award, voted_id) VALUES (?, ?, ?, ?)', [$id, $me, $award, $pick]);
    }
    db()->commit();
    flash('ok', 'Voti registrati, grazie! Puoi modificarli finché le votazioni sono aperte.');
    $_SESSION['vote_done'] = 1;   // fa comparire il segno di spunta grande nella pagina successiva
}

/* ---------------------------------------------------------------- dati */
if ($match['status'] === 'programmata') {
    sync_match_players($id);
}
$isGuest = is_guest();
$guestSaved = $isGuest && guest_saved();
$roster = match_roster($id);
$stats = $isGuest ? [] : compute_stats([(int) $match['group_id']]);
$byStatus = ['confermato' => [], 'in_attesa' => [], 'assente' => []];
$teams = ['A' => [], 'B' => []];
$myRow = null;
foreach ($roster as $r) {
    $byStatus[$r['availability']][] = $r;
    if ($r['team']) {
        $teams[$r['team']][] = $r;
    }
    if ((int) $r['player_id'] === $me) {
        $myRow = $r;
    }
}
$hasTeams = $teams['A'] || $teams['B'];
$played = $match['status'] === 'giocata';
$cancelled = $match['status'] === 'annullata';
$canManageMatch = $canManage;   // per i comandi di una partita annullata (riaprirla, eliminarla)
if ($cancelled) {
    $canManage = false;          // annullata: si vede tutto com'era, ma niente si modifica
}
$votingOpen = $played && (int) $match['voting_open'];
$avgs = match_vote_averages()[$id] ?? [];
$mvpCounts = match_mvp_counts()[$id] ?? [];
$mvp = $played ? match_mvp($id) : null;
$iPlayed = $myRow && $myRow['team'];
$showVotes = $played && (!$votingOpen || $canAdmin);

// voti già dati dal giocatore collegato (per precompilare il modulo)
$myVotes = [];
$myMvp = null;
$myAwards = [];
if ($votingOpen && $iPlayed) {
    foreach (q('SELECT rated_id, vote FROM ratings WHERE match_id = ? AND voter_id = ?', [$id, $me])->fetchAll() as $r) {
        $myVotes[(int) $r['rated_id']] = (float) $r['vote'];
    }
    $myMvp = q('SELECT voted_id FROM mvp_votes WHERE match_id = ? AND voter_id = ?', [$id, $me])->fetchColumn() ?: null;
    foreach (q('SELECT award, voted_id FROM award_votes WHERE match_id = ? AND voter_id = ?', [$id, $me])->fetchAll() as $r) {
        $myAwards[$r['award']] = (int) $r['voted_id'];
    }
}
// miglior difensore e miglior portiere (lib/stats.php: MATCH_AWARDS): chi si può votare, voti e vincitori
$awardOptions = $played ? match_award_options($match, $roster) : [];
$awardCounts = $played ? match_award_counts($id) : [];
$awardWinners = $played ? match_award_winners($id) : [];
$awardOf = [];   // id giocatore => premi vinti
foreach ($awardWinners as $award => $pid) {
    $awardOf[$pid][] = $award;
}
$awardTags = fn(int $pid) => implode('', array_map(fn($a) => '<span class="tag tag-award tag-award-' . $a . '"><i class="ti ti-' . MATCH_AWARDS[$a]['icon'] . '"></i> '
    . h(MATCH_AWARDS[$a]['name']) . '</span>', $awardOf[$pid] ?? []));
$voters = $played ? array_map('intval', q('SELECT voter_id FROM mvp_votes WHERE match_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN)) : [];
$participants = array_merge($teams['A'], $teams['B']);
$voteParticipants = $participants;   // anche gli ospiti votano e si votano (per la lega contano se chi la gestisce li accetta)
$guestPlayers = array_values(array_filter($participants, fn($r) => $r['is_guest']));
$chem = !$played && $hasTeams && !$isGuest ? chemistry([(int) $match['group_id']]) : ['pairs' => [], 'players' => []];
$nameOf = short_names($roster);
$links = $hasTeams ? q('SELECT ml.assister_id, ml.scorer_id, ml.n, a.name AS an, s.name AS sn FROM match_links ml
                        JOIN players a ON a.id = ml.assister_id JOIN players s ON s.id = ml.scorer_id
                        WHERE ml.match_id = ? ORDER BY a.name, s.name', [$id])->fetchAll() : [];
// cronaca in diretta (lib/live.php)
$events = $isGuest ? [] : live_events($id);
$injured = [];
foreach ($events as $ev) {
    if ($ev['kind'] === 'infortunio') {
        $injured[(int) $ev['player_id']] = (string) $ev['note'];
    }
}
$liveOn = live_is_on($match);
$canLive = $hasTeams && live_can_edit($match, $me);
$canInjury = $hasTeams && live_can_edit($match, $me, 'infortunio');

/*
 * Campanelle (solo admin e manager della lega): un promemoria soltanto a chi non ha ancora risposto sulla presenza o non ha
 * ancora votato (lib/webpush.php: push_nudge). Accanto ai nomi, un'icona segna chi non riceve le notifiche e va avvisato a voce.
 */
$nudgeReach = [];
$noPush = function (int $pid, string $kind) use ($canManage, $id, &$nudgeReach): string {
    if (!$canManage) {
        return '';
    }
    $nudgeReach[$kind] ??= push_nudge_missing($id, $kind);
    return isset($nudgeReach[$kind][$pid]) && !$nudgeReach[$kind][$pid]
        ? ' <i class="ti ti-bell-off vote-nopush" title="Non riceve le notifiche: avvisalo tu" aria-label="senza notifiche"></i>' : '';
};
$nudgeNames = fn(array $rows, string $kind) => implode(', ', array_map(fn($r) => h($r['name']) . $noPush((int) $r['player_id'], $kind), $rows));
$nudgeBell = function (string $kind, string $label, string $title) use ($canManage, $id): string {
    if (!$canManage) {
        return '';
    }
    $last = push_nudged_at($id, $kind);
    $cooling = $last !== null && $last + PUSH_NUDGE_MINUTES * 60 > time();
    return '<form method="post" class="inline nudge-form">' . csrf_field() . '<input type="hidden" name="do" value="nudge"><input type="hidden" name="kind" value="' . $kind . '">'
        . '<button class="btn btn-ghost btn-sm vote-bell"' . ($cooling ? ' disabled title="Già mandato alle ' . date('H:i', $last) . ': si può rimandare dopo '
            . PUSH_NUDGE_MINUTES . ' minuti"' : ' title="' . h($title) . '"') . '><i class="ti ti-bell-ringing"></i> ' . h($label) . '</button></form>';
};

layout_start('Partita del ' . fmt_date_short($match['match_date']), 'matches');
if (!empty($_SESSION['vote_done'])):
    unset($_SESSION['vote_done']); ?>
<div class="vote-done" data-vote-done role="status" aria-live="polite">
  <div class="vote-done-card">
    <svg class="vote-done-badge" viewBox="0 0 120 120" aria-hidden="true">
      <circle class="vd-shadow" cx="66" cy="66" r="52"/>
      <circle class="vd-disc" cx="60" cy="60" r="52"/>
      <path class="vd-check" d="M34 62 L53 81 L88 40"/>
    </svg>
    <div class="vote-done-title">Voti inviati!</div>
    <div class="vote-done-sub">Puoi cambiarli finché le votazioni sono aperte.</div>
  </div>
</div>
<?php endif; ?>
<?php if (!$isGuest): ?><a class="back" href="matches.php"><i class="ti ti-arrow-left"></i> Partite</a><?php endif; ?>

<section class="card match-head">
  <div class="match-when">
    <span class="eyebrow"><?= $cancelled ? '<span class="tag tag-live"><i class="ti ti-ban"></i> Partita annullata</span>' : ($played ? 'Partita giocata' : ($liveOn ? '<span class="tag tag-live"><i class="ti ti-broadcast"></i> in corso</span>' : '<i class="ti ti-calendar-event"></i> In programma')) ?></span>
    <h1><?= h(ucfirst(fmt_date_long($match['match_date']))) ?></h1>
    <div class="hero-meta">
      <span><i class="ti ti-clock"></i> <?= fmt_time($match['match_date']) ?></span>
      <?= place_chip($match['location']) ?>
      <?= group_tag((int) $match['group_id']) ?>
      <?php if ((float) $match['fee'] > 0): ?><span><?= fmt_money($match['fee']) ?> a testa</span><?php endif; ?>
      <span><i class="ti ti-hand-stop"></i> <?= h(keepers_label(match_keepers($match))) ?></span>
    </div>
    <?php if ($match['notes']): ?><p class="muted"><?= nl2br(h($match['notes'])) ?></p><?php endif; ?>
    <?php if ($cancelled): ?>
      <p class="flash flash-warn"><i class="ti ti-ban"></i> Annullata<?= $match['cancelled_at'] ? ' il ' . fmt_date_short($match['cancelled_at']) : '' ?><?= $match['cancel_reason'] ? ': ' . h($match['cancel_reason']) : '' ?>.
        Non conta per classifiche, statistiche, voti e Fanta, e le scommesse sono state rimborsate; presenze, squadre e gol restano qui com'erano.</p>
    <?php endif; ?>
    <?php if (!$played && !$cancelled && strtotime($match['match_date']) > time() - 3 * 3600): ?>
      <div class="hero-count"><?= countdown_html($match['match_date'], 'Mancano ', 'Si gioca!', 86400, false, 'hourglass-high', 'countdown-big') ?></div>
    <?php endif; ?>
    <?php if (!$played && !$cancelled): ?><div class="match-cal"><?= gcal_button($match) ?></div><?php endif; ?>
  </div>
  <?php if ($played || $match['score_a'] !== null): ?>
    <div class="score">
      <div class="score-team team-a"><?= h(team_name('A', $match)) ?></div>
      <div class="score-num"><?= $match['score_a'] ?? '-' ?> <span>–</span> <?= $match['score_b'] ?? '-' ?></div>
      <div class="score-team team-b"><?= h(team_name('B', $match)) ?></div>
    </div>
  <?php endif; ?>
  <?= availability_buttons($match, $myRow['availability'] ?? null, $self) ?>
</section>

<?php
// modulo dei voti: per i giocatori della lega (sezione Voti) e per l'ospite (la sua vista)
$voteForm = function () use ($voteParticipants, $me, $myVotes, $myMvp, $myAwards, $awardOptions, $match) { ?>
    <form method="post" class="vote-form">
      <?= csrf_field() ?><input type="hidden" name="do" value="vote">
      <p class="muted">Dai un voto da 1 a 10 a ogni compagno e avversario, poi scegli l'MVP<?= !empty($awardOptions['por']) ? ', il miglior difensore e il miglior portiere' : ' e il miglior difensore' ?>. <?= $myMvp ? '<strong>Hai già votato:</strong> puoi modificare.' : '' ?> <span class="small">Se non voti entro la chiusura, a tutti gli altri viene dato <?= default_vote_label() ?> d'ufficio.</span></p>
      <?php foreach ($voteParticipants as $r): $pid = (int) $r['player_id']; if ($pid === $me) continue;
        $val = $myVotes[$pid] ?? 6; ?>
        <div class="vote-row">
          <div class="vote-who"><?= avatar($r, 'sm') ?><span><?= h($r['name']) ?></span><span class="team-dot team-<?= strtolower($r['team']) ?>"></span></div>
          <input type="range" min="1" max="10" step="0.5" name="vote[<?= $pid ?>]" value="<?= h($val) ?>" class="vote-range" aria-label="Voto a <?= h($r['name']) ?>">
          <output class="vote <?= vote_class($val) ?>"><?= fmt_num($val) ?></output>
          <label class="mvp-pick" title="MVP"><input type="radio" name="mvp" value="<?= $pid ?>" <?= (int) $myMvp === $pid ? 'checked' : '' ?> required><span><i class="ti ti-star-filled"></i></span></label>
        </div>
      <?php endforeach; ?>
      <div class="award-picks">
      <?php foreach (MATCH_AWARDS as $award => $aw): $opts = array_values(array_diff($awardOptions[$award] ?? [], [$me])); if (!$opts) continue; ?>
        <label class="field award-pick"><span><i class="ti ti-<?= $aw['icon'] ?>"></i> <?= h($aw['name']) ?></span>
          <select name="award[<?= $award ?>]" required>
            <option value="">Scegli…</option>
            <?php foreach ($voteParticipants as $r): $pid = (int) $r['player_id']; if (!in_array($pid, $opts, true)) continue; ?>
              <option value="<?= $pid ?>" <?= ($myAwards[$award] ?? 0) === $pid ? 'selected' : '' ?>><?= h($r['name']) ?> (<?= h(team_name($r['team'], $match)) ?>)</option>
            <?php endforeach; ?>
          </select></label>
      <?php endforeach; ?>
      </div>
      <button class="btn btn-primary btn-block">Invia voti</button>
    </form>
<?php };
?>
<?php if ($isGuest): // l'ospite vede la sua partita e in che squadra gioca, non la rosa ?>
<section class="card guest-card">
  <h2><i class="ti ti-shirt"></i> La tua squadra</h2>
  <?php if ($myRow && $myRow['team']): $mt = $myRow['team']; ?>
    <p class="guest-team"><span class="team-dot team-<?= strtolower($mt) ?>"></span> Giochi con <strong><?= h(team_name($mt, $match)) ?></strong>
      contro <strong><?= h(team_name($mt === 'A' ? 'B' : 'A', $match)) ?></strong>.</p>
  <?php elseif ($played): ?>
    <p class="muted">Non risulti tra chi ha giocato questa partita.</p>
  <?php elseif (($myRow['availability'] ?? '') === 'assente'): ?>
    <p class="muted">Hai detto che non ci sei. Se cambi idea, tocca «Ci sono» qui sopra.</p>
  <?php else: ?>
    <p class="muted">Le squadre non sono ancora state fatte: quando l'admin le decide, qui vedrai in quale giochi.<?= ($myRow['availability'] ?? '') === 'confermato' ? ' Intanto la tua presenza è confermata.' : ' Ricordati di dire se ci sei.' ?></p>
  <?php endif; ?>
  <p class="small muted"><i class="ti ti-info-circle"></i> Sei un ospite: vedi questa partita e la formazione in campo (non le presenze) e, dopo la partita, voti
    e vieni votato. <?= $guestSaved ? 'Hai salvato il tuo account: non scade.' : 'Il tuo accesso resta attivo fino al ' . h(date('d/m', strtotime($match['match_date']) + GUEST_KEEP_DAYS * 86400)) . ', a meno che non lo salvi.' ?>
    <a href="guest.php">La tua area</a></p>
</section>
<?php if ($played && $myRow && $myRow['team']): ?>
<section class="card" id="voti">
  <h2>Voti <?= $votingOpen ? '<span class="tag tag-live">aperti</span>' : '<span class="tag">chiusi</span>' ?></h2>
  <?php if ($votingOpen): ?>
    <?php if ($match['voting_ends_at']): ?><p class="vote-deadline"><i class="ti ti-alarm"></i> Le votazioni terminano <strong><?= h(push_when($match['voting_ends_at'])) ?></strong></p><?php endif; ?>
    <?php $voteForm(); ?>
    <p class="small muted"><i class="ti ti-info-circle"></i> Il tuo feedback (i voti che ricevi) lo vedi qui alla chiusura delle votazioni. Per la classifica della lega i tuoi voti contano solo se chi la gestisce li accetta.</p>
  <?php else: $fb = guest_feedback((int) $me, $id); $ok = $myRow['votes_ok']; ?>
    <div class="guest-feedback">
      <div><span class="vote <?= vote_class($fb['avg']) ?>"><?= fmt_num($fb['avg']) ?></span></div>
      <p><strong>Il tuo feedback:</strong> media dei voti che hai ricevuto da <?= $fb['votes'] ?> <?= $fb['votes'] === 1 ? 'giocatore' : 'giocatori' ?><?= $fb['mvp'] ? ', e ' . $fb['mvp'] . ($fb['mvp'] === 1 ? ' voto' : ' voti') . ' come MVP' : '' ?>.
        <br><span class="small muted"><?= $ok === null ? 'La lega non ha ancora deciso se far contare i tuoi voti.' : ((int) $ok ? 'La lega ha fatto contare i tuoi voti.' : 'Per la lega i tuoi voti non contano, ma il feedback resta tuo.') ?></span></p>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php if (!$guestSaved): ?>
<section class="card guest-save" id="salva">
  <h2><i class="ti ti-user-check"></i> Vuoi tenere il tuo account?</h2>
  <p>Se lo salvi non scade più. Finché non entri in una lega sei tra i <strong>giocatori liberi</strong>: chi gestisce le altre leghe vede il tuo nome,
    i tuoi ruoli, quante partite hai giocato da ospite e la media dei voti ricevuti, e può chiamarti a una partita o invitarti nella sua lega.</p>
  <p class="small muted">Scegli una password tua: quella di adesso la conosce chi ti ha invitato.</p>
  <form method="post" action="guest.php" class="form form-grid">
    <?= csrf_field() ?><input type="hidden" name="do" value="save">
    <label class="field"><span>Nuova password</span><input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
    <label class="field"><span>Ripeti la password</span><input type="password" name="password2" required minlength="8" maxlength="72" autocomplete="new-password"></label>
    <div class="span-2"><button class="btn btn-primary btn-sm"><i class="ti ti-device-floppy"></i> Salva il mio account</button></div>
  </form>
</section>
<?php endif; ?>
<?php if ($hasTeams): ?>
<section class="card" id="squadre">
  <h2><i class="ti ti-layout-grid"></i> Formazione</h2>
  <div class="squad-pitch"><?= render_pitch($match, $roster, false, false) ?></div>
</section>
<?php endif; ?>
<?php layout_end(); exit; endif; ?>

<?php if ($events || $canLive || $canInjury): ?>
<section class="card" id="diretta">
  <div class="card-head">
    <h2><i class="ti ti-broadcast"></i> <?= $liveOn ? 'Diretta' : 'Cronaca' ?></h2>
    <?php if ($liveOn): ?><a class="btn btn-ghost btn-sm" href="<?= h($self) ?>#diretta"><i class="ti ti-refresh"></i> Aggiorna</a><?php endif; ?>
  </div>
  <?php if (!$events): ?>
    <p class="empty"><?= $liveOn ? 'Ancora nessun evento. Segna qui i gol mentre giocate: il risultato si aggiorna da solo e chi non gioca riceve la notifica.' : 'Nessun evento segnato.' ?></p>
  <?php else: ?>
    <ol class="live-events">
      <?php foreach (array_reverse($events) as $ev):
          $canUndo = live_can_edit($match, $me, $ev['kind']) && ($canManage || (int) $ev['created_by'] === (int) (current_user()['id'] ?? 0))
              && ($ev['kind'] === 'infortunio' || $liveOn); ?>
        <li class="live-ev">
          <span class="live-min"><?= h(live_minute($match, $ev['created_at'])) ?></span>
          <?php if ($ev['kind'] === 'gol'): ?><i class="ti ti-ball-football"></i>
          <?php elseif ($ev['kind'] === 'autogol'): ?><span class="ev ev-og">AG</span>
          <?php else: ?><i class="ti ti-first-aid-kit ev-inj"></i><?php endif; ?>
          <span class="live-who"><span class="team-dot team-<?= strtolower((string) $ev['team']) ?>"></span><strong><?= h($ev['name']) ?></strong>
            <?php if ($ev['kind'] === 'gol' && $ev['assist_name']): ?><span class="muted small">assist <?= h($ev['assist_name']) ?></span><?php endif; ?>
            <?php if ($ev['kind'] === 'autogol'): ?><span class="muted small">autogol</span><?php endif; ?>
            <?php if ($ev['kind'] === 'infortunio'): ?><span class="muted small">infortunato<?= $ev['note'] ? ': ' . h($ev['note']) : '' ?></span><?php endif; ?></span>
          <?php if ($canUndo): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="live_undo"><input type="hidden" name="event_id" value="<?= (int) $ev['id'] ?>">
              <button class="icon-btn" title="Togli (segnato per sbaglio)" data-confirm="Togliere questo evento?<?= $ev['kind'] !== 'infortunio' ? ' Il risultato torna indietro di un gol.' : '' ?>"><i class="ti ti-x"></i></button></form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <?php if ($canLive): ?>
    <form method="post" class="form form-grid live-form" id="live-goal-form">
      <?= csrf_field() ?><input type="hidden" name="do" value="live_goal">
      <label class="field"><span><i class="ti ti-ball-football"></i> Chi ha segnato</span><select name="player_id" required data-assist-from>
        <?php foreach (['A', 'B'] as $t): ?><optgroup label="<?= h(team_name($t, $match)) ?>">
          <?php foreach ($teams[$t] as $r): ?><option value="<?= (int) $r['player_id'] ?>" data-team="<?= $t ?>"><?= h($r['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
      </select></label>
      <label class="field"><span>Assist (facoltativo)</span><select name="assist_id" data-assist-to>
        <option value="">— nessuno —</option>
        <?php foreach ($participants as $r): ?><option value="<?= (int) $r['player_id'] ?>" data-team="<?= h($r['team']) ?>"><?= h($r['name']) ?></option><?php endforeach; ?>
      </select></label>
      <label class="field check span-2"><input type="checkbox" name="own" value="1"> Autogol (il gol va all'altra squadra)</label>
      <div class="span-2"><button class="btn btn-primary"><i class="ti ti-ball-football"></i> Gol!</button></div>
    </form>
  <?php endif; ?>
  <?php if ($canInjury): ?>
    <details class="collapsible">
      <summary><strong><i class="ti ti-first-aid-kit"></i> Segna un infortunio</strong></summary>
      <form method="post" class="form form-grid">
        <?= csrf_field() ?><input type="hidden" name="do" value="live_injury">
        <label class="field"><span>Chi si è fatto male</span><select name="player_id" required>
          <?php foreach ($participants as $r): ?><option value="<?= (int) $r['player_id'] ?>"><?= h($r['name']) ?> (<?= h(team_name($r['team'], $match)) ?>)</option><?php endforeach; ?>
        </select></label>
        <label class="field"><span>Cosa è successo (facoltativo)</span><input name="note" maxlength="120" placeholder="Es. caviglia, stiramento..."></label>
        <div class="span-2"><button class="btn btn-ghost btn-sm"><i class="ti ti-first-aid-kit"></i> Segna infortunio</button></div>
      </form>
    </details>
  <?php endif; ?>
</section>
<?php if ($canLive): ?>
<script>
// l'assist lo fa un compagno di chi segna: la lista mostra solo la sua squadra (e niente assist sugli autogol)
(() => {
  const form = document.getElementById('live-goal-form');
  if (!form) return;
  const from = form.querySelector('[data-assist-from]'), to = form.querySelector('[data-assist-to]'), own = form.querySelector('[name=own]');
  const sync = () => {
    const team = (from.selectedOptions[0] || {}).dataset?.team;
    [...to.options].forEach(o => { if (o.value) o.hidden = own.checked || o.dataset.team !== team || o.value === from.value; });
    if (to.selectedOptions[0]?.hidden) to.value = '';
    to.disabled = own.checked;
  };
  from.addEventListener('change', sync);
  own.addEventListener('change', sync);
  sync();
})();
</script>
<?php endif; ?>
<?php endif; ?>

<?php if (!$played): ?>
<section class="card" id="presenze">
  <div class="card-head">
    <h2>Presenze</h2>
    <?php if ($byStatus['in_attesa'] && strtotime($match['match_date']) > time()): ?>
      <?= $nudgeBell('presenza', 'Ricorda di rispondere', 'Manda una notifica solo a chi non ha ancora detto se c\'è') ?>
    <?php endif; ?>
  </div>
  <div class="avail-cols">
    <?php foreach (['confermato' => '<i class="ti ti-user-check"></i> Confermati', 'in_attesa' => '<i class="ti ti-user-question"></i> Da confermare', 'assente' => '<i class="ti ti-user-x"></i> Assenti'] as $st => $label): ?>
      <div class="avail-col">
        <h3><?= $label ?> <span class="count <?= $st === 'confermato' ? 'count-yes' : ($st === 'assente' ? 'count-no' : '') ?>"><?= count($byStatus[$st]) ?></span></h3>
        <?php foreach ($byStatus[$st] as $r): ?>
          <div class="pline-row">
            <?= player_line($r) ?><?= $st === 'in_attesa' ? $noPush((int) $r['player_id'], 'presenza') : '' ?>
            <?php if ($canManage): ?>
              <form method="post" class="inline">
                <?= csrf_field() ?><input type="hidden" name="do" value="set_avail"><input type="hidden" name="player_id" value="<?= (int) $r['player_id'] ?>">
                <select name="status" class="mini-select" data-autosubmit aria-label="Cambia stato">
                  <option value="confermato" <?= $st === 'confermato' ? 'selected' : '' ?>>Ci sono</option>
                  <option value="in_attesa" <?= $st === 'in_attesa' ? 'selected' : '' ?>>In attesa</option>
                  <option value="assente" <?= $st === 'assente' ? 'selected' : '' ?>>Assente</option>
                </select>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($canAdmin && !$cancelled): $guests = match_guests($id); ?>
  <div class="guest-admin">
    <h3><i class="ti ti-user-plus"></i> Ospiti <span class="count"><?= count($guests) ?></span></h3>
    <?php foreach ($guests as $g): ?>
      <div class="pline-row">
        <span><strong><?= h($g['name']) ?></strong> <span class="muted small">· <?= $g['guest_saved'] ? 'giocatore libero' : 'utente <code>' . h($g['username'] ?? '—') . '</code>' . ($g['guest_email'] ? ' · ' . h($g['guest_email']) : '') ?></span></span>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="remove_guest"><input type="hidden" name="player_id" value="<?= (int) $g['id'] ?>">
          <button class="icon-btn" title="Togli l'ospite" data-confirm="Togliere <?= h($g['name']) ?> dalla partita?<?= $g['guest_saved'] ? ' Il suo account resta.' : ' Se non gioca altre partite, il suo accesso viene cancellato.' ?>"><i class="ti ti-x"></i></button></form>
      </div>
    <?php endforeach; ?>
    <details class="collapsible">
      <summary><strong>Aggiungi un ospite</strong></summary>
      <p class="muted small">Per chi gioca solo questa partita e non fa parte della lega. Riceve un utente e una password: vede la partita, in che squadra gioca e la formazione in campo (non le presenze) e può dire se ci sarà.
        Dopo la partita vota e viene votato, ma per la lega i suoi voti contano solo se li accetti (sezione Voti). Non scommette e nessuno può scommettere su di lui.
        L'accesso sparisce <?= GUEST_KEEP_DAYS ?> giorni dopo la partita, a meno che non decida di salvarlo: allora diventa un giocatore libero che le leghe possono chiamare.
        Se scrivi la sua email e un giorno si iscrive davvero (e la conferma), questa partita gli comparirà tra quelle giocate.</p>
      <form method="post" class="form form-grid">
        <?= csrf_field() ?><input type="hidden" name="do" value="add_guest">
        <label class="field span-2"><span>Nome e cognome</span><input name="name" required maxlength="80" autocomplete="off"></label>
        <label class="field"><span>Posizione preferita</span><select name="position">
          <?php foreach (main_positions() as $o): ?><option <?= $o === 'Centrocampista' ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Seconda posizione</span><select name="position2">
          <option value="">— nessuna —</option>
          <?php foreach (positions() as $o): ?><option><?= h($o) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Piede</span><select name="foot">
          <?php foreach (feet() as $o): ?><option><?= h($o) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Numero di maglia (facoltativo)</span><input type="number" name="shirt_number" min="0" max="99"></label>
        <label class="field span-2"><span>Email (facoltativa)</span><input type="email" name="email" maxlength="190" autocomplete="off" placeholder="nome@esempio.it"></label>
        <label class="field"><span>Username (se vuoto lo scelgo io)</span><input name="username" maxlength="50" autocomplete="off" autocapitalize="none" spellcheck="false"></label>
        <label class="field"><span>Password (se vuota la genero io)</span><input name="password" maxlength="72" autocomplete="off"></label>
        <div class="span-2"><button class="btn btn-primary btn-sm"><i class="ti ti-user-plus"></i> Crea l'ospite</button></div>
      </form>
    </details>
    <?php $free = array_filter(free_agents($gid), fn($p) => !in_array((int) $p['id'], array_map(fn($g) => (int) $g['id'], $guests), true)); ?>
    <?php if ($free): ?>
    <details class="collapsible" id="liberi">
      <summary><strong>Giocatori liberi</strong> <span class="count"><?= count($free) ?></span></summary>
      <p class="muted small">Ospiti di altre partite che hanno tenuto l'account e non sono in nessuna lega. Puoi chiamarli a questa partita (giocano da ospiti) o invitarli nella lega.</p>
      <?php foreach ($free as $fa): $fb = $fa['feedback']; ?>
        <div class="pline-row free-agent">
          <span><?= avatar($fa, 'sm') ?> <strong><?= h($fa['name']) ?></strong>
            <span class="muted small">· <?= h(implode(' / ', array_filter([$fa['position'], $fa['position2']]))) ?> · <?= h($fa['foot']) ?> · <?= $fb['matches'] ?> <?= $fb['matches'] === 1 ? 'partita' : 'partite' ?> da ospite</span>
            <?php if ($fb['avg'] !== null): ?><span class="vote <?= vote_class($fb['avg']) ?>" title="Media dei voti ricevuti (<?= $fb['votes'] ?>)"><?= fmt_num($fb['avg']) ?></span><?php endif; ?></span>
          <span class="btn-row">
            <?php if ($match['status'] === 'programmata'): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="call_free_agent"><input type="hidden" name="player_id" value="<?= (int) $fa['id'] ?>">
              <button class="btn btn-ghost btn-sm"><i class="ti ti-phone-call"></i> Chiama alla partita</button></form><?php endif; ?>
            <?php if ($fa['invited']): ?><span class="tag">invitato in lega</span><?php else: ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="invite_free_agent"><input type="hidden" name="player_id" value="<?= (int) $fa['id'] ?>">
              <button class="btn btn-ghost btn-sm"><i class="ti ti-user-plus"></i> Invita in lega</button></form><?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </details>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card" id="squadre">
  <div class="card-head">
    <h2>Squadre</h2>
    <?php if ($canManage && !$played): ?>
      <div class="btn-row">
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="gen_teams">
          <button class="btn btn-primary btn-sm"><i class="ti ti-scale"></i> <?= $hasTeams ? 'Rigenera' : 'Genera squadre bilanciate' ?></button></form>
        <?php if ($hasTeams): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="clear_teams">
            <button class="btn btn-ghost btn-sm" data-confirm="Azzerare le squadre?">Azzera</button></form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php if (!$hasTeams): ?>
    <p class="empty"><?= $canManage ? 'Quando ci sono abbastanza confermati, premi "Genera squadre bilanciate": l\'algoritmo divide i giocatori in due squadre con forza complessiva simile.' : 'Le squadre non sono ancora state fatte.' ?></p>
  <?php else: ?>
    <div class="squad-grid">
    <div class="squad-pitch">
      <?= render_pitch($match, $roster, $canManage && !$played) ?>
      <?php if ($canManage && !$played): ?>
        <form method="post" class="center"><?= csrf_field() ?><input type="hidden" name="do" value="reset_formation">
          <button class="btn btn-ghost btn-sm"><i class="ti ti-refresh"></i> Ricalcola posizioni dalle preferenze</button></form>
      <?php endif; ?>
    </div>
    <div class="teams">
      <?php foreach (['A', 'B'] as $t): $str = team_strength($roster, $t, $stats); ?>
        <div class="team team-<?= strtolower($t) ?>">
          <div class="team-head">
            <strong><?= h(team_name($t, $match)) ?></strong>
            <?php if ($played): ?><span class="team-score"><?= (int) $match['score_' . strtolower($t)] ?></span><?php endif; ?>
            <span class="team-str" title="Somma dei rating"><i class="ti ti-scale"></i> <?= fmt_num($str['sum'], 1) ?> <small>(media <?= fmt_num($str['avg'], 2) ?>)</small></span>
          </div>
          <?php $roles = team_roles($roster, $t); ?>
          <span class="team-roles" title="Giocatori per ruolo (1ª scelta) e con Jolly come 2ª scelta">
            POR <?= $roles['POR'] ?> · DIF <?= $roles['DIF'] ?> · CEN <?= $roles['CEN'] ?> · ATT <?= $roles['ATT'] ?><?= $roles['JOL'] ? ' · Jolly ' . $roles['JOL'] : '' ?></span>
          <?php $tch = !$played ? team_chemistry($chem, array_column($teams[$t], 'player_id')) : ['pairs' => []]; ?>
          <?php if ($tch['pairs']): ?>
            <span class="team-roles team-chem" title="Intesa: bonus o malus di forza per chi ha già giocato insieme (risultati, assist, gol)">
              <i class="ti ti-heart-handshake"></i> Intesa <strong><?= fmt_signed($tch['sum'], 2) ?></strong>
              <?php foreach (array_slice($tch['pairs'], 0, 2) as $cp): ?>
                · <?= h($nameOf[$cp['a']] ?? '?') ?>+<?= h($nameOf[$cp['b']] ?? '?') ?> <?= fmt_signed($cp['score'], 2) ?>
              <?php endforeach; ?></span>
          <?php endif; ?>
          <?php foreach ($teams[$t] as $r): $pid = (int) $r['player_id'];
            $extra = '';
            if ($r['goals']) $extra .= '<span class="ev"><i class="ti ti-ball-football"></i>' . ($r['goals'] > 1 ? '×' . $r['goals'] : '') . '</span>';
            if ($r['assists']) $extra .= '<span class="ev"><b class="ast">A</b>' . ($r['assists'] > 1 ? '×' . $r['assists'] : '') . '</span>';
            if ($r['own_goals']) $extra .= '<span class="ev ev-og">AG' . ($r['own_goals'] > 1 ? '×' . $r['own_goals'] : '') . '</span>';
            if (isset($injured[$pid])) $extra .= '<span class="ev ev-inj" title="Infortunato' . ($injured[$pid] !== '' ? ': ' . h($injured[$pid]) : '') . '"><i class="ti ti-first-aid-kit"></i></span>';
            if ($showVotes && isset($avgs[$pid])) $extra .= '<span class="vote ' . vote_class($avgs[$pid]['avg']) . '">' . fmt_num($avgs[$pid]['avg']) . '</span>';
            if (!$votingOpen && $mvp === $pid) $extra .= '<span class="tag tag-mvp"><i class="ti ti-star-filled"></i> MVP</span>';
            if (!$votingOpen) $extra .= $awardTags($pid);
            if (!$played && !$r['is_guest']) $extra .= '<span class="ovr" title="Overall">' . overall($stats[$pid]['ovr'] ?? 6) . '</span>';
          ?>
            <div class="pline-row">
              <?= player_line($r, $extra) ?>
              <?php if ($canManage && !$played): ?>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="move_team"><input type="hidden" name="player_id" value="<?= $pid ?>">
                  <button class="icon-btn" title="Sposta nell'altra squadra"><i class="ti ti-arrows-exchange"></i></button></form>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="remove_from_team"><input type="hidden" name="player_id" value="<?= $pid ?>">
                  <button class="icon-btn" title="Togli dalla squadra"><i class="ti ti-x"></i></button></form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
    </div>
    <?php
    $benched = array_filter($byStatus['confermato'], fn($r) => !$r['team']);
    if ($canManage && !$played && $benched): ?>
      <div class="bench">
        <span class="muted small">Confermati senza squadra:</span>
        <?php foreach ($benched as $r): ?>
          <span class="bench-item"><?= h($r['name']) ?>
            <?php foreach (['A', 'B'] as $t): ?>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="add_to_team"><input type="hidden" name="player_id" value="<?= (int) $r['player_id'] ?>"><input type="hidden" name="team" value="<?= $t ?>">
                <button class="icon-btn team-<?= strtolower($t) ?>" title="Metti in <?= h(team_name($t, $match)) ?>">+<?= h(mb_substr(team_name($t, $match), 0, 1)) ?></button></form>
            <?php endforeach; ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php if ($canManage && $hasTeams): ?>
<section class="card" id="risultato">
  <h2>Risultato e marcatori</h2>
  <form method="post" class="form" id="result-form">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_result">
    <div class="result-inputs">
      <label class="team-a"><?= h(team_name('A', $match)) ?> <input type="number" min="0" max="99" name="score_a" id="score_a" value="<?= h($match['score_a']) ?>"></label>
      <span>–</span>
      <label class="team-b"><input type="number" min="0" max="99" name="score_b" id="score_b" value="<?= h($match['score_b']) ?>"> <?= h(team_name('B', $match)) ?></label>
      <button type="button" class="btn btn-ghost btn-sm" id="calc-score" title="Gol della squadra + autogol degli avversari">Calcola dai gol</button>
    </div>
    <div class="table-wrap"><table class="table table-inputs">
      <thead><tr><th>Giocatore</th><th>Squadra</th><th><i class="ti ti-ball-football"></i> Gol</th><th><b class="ast">A</b> Assist</th><th>Autogol</th></tr></thead>
      <tbody>
      <?php foreach ($participants as $r): $pid = (int) $r['player_id']; ?>
        <tr data-team="<?= $r['team'] ?>">
          <td><?= h($r['name']) ?></td>
          <td><span class="team-dot team-<?= strtolower($r['team']) ?>"></span><?= h(team_name($r['team'], $match)) ?></td>
          <td><input type="number" min="0" max="99" name="goals[<?= $pid ?>]" value="<?= (int) $r['goals'] ?>" class="in-goals"></td>
          <td><input type="number" min="0" max="99" name="assists[<?= $pid ?>]" value="<?= (int) $r['assists'] ?>"></td>
          <td><input type="number" min="0" max="99" name="own_goals[<?= $pid ?>]" value="<?= (int) $r['own_goals'] ?>" class="in-og"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="btn-row">
      <button class="btn btn-ghost">Salva</button>
      <?php if (!$played): ?>
        <button class="btn btn-primary" name="finish" value="1" data-confirm="Chiudere la partita e aprire le votazioni?"><i class="ti ti-flag-2"></i> Salva e concludi partita</button>
      <?php endif; ?>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if ($canManage && $hasTeams): ?>
<section class="card" id="assist">
  <h2><i class="ti ti-heart-handshake"></i> Chi ha fatto assist a chi <span class="muted small">(facoltativo)</span></h2>
  <p class="muted small">Serve a misurare l'<strong>intesa</strong>: quando uno serve spesso l'altro (o si servono a vicenda) rendono meglio insieme e le squadre bilanciate ne tengono conto.
    Non deve coincidere con la tabella qui sopra: registra solo le combinazioni che ricordi.</p>
  <?php if ($links): ?>
    <div class="list">
      <?php foreach ($links as $lk): ?>
        <div class="link-row">
          <span><strong><?= h($lk['an']) ?></strong> <i class="ti ti-arrow-right"></i> <strong><?= h($lk['sn']) ?></strong> <span class="muted">· <?= (int) $lk['n'] ?> gol</span></span>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="del_link">
            <input type="hidden" name="assister_id" value="<?= (int) $lk['assister_id'] ?>"><input type="hidden" name="scorer_id" value="<?= (int) $lk['scorer_id'] ?>">
            <button class="icon-btn" title="Togli"><i class="ti ti-x"></i></button></form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <form method="post" class="form form-grid form-grid-4" id="assist-form">
    <?= csrf_field() ?><input type="hidden" name="do" value="add_link">
    <label class="field"><span>Assist di</span><select name="assister_id" data-assist-from>
      <?php foreach ($participants as $r): ?><option value="<?= (int) $r['player_id'] ?>" data-team="<?= h($r['team']) ?>"><?= h($r['name']) ?> (<?= h(team_name($r['team'], $match)) ?>)</option><?php endforeach; ?></select></label>
    <label class="field"><span>per il gol di <span class="muted small">(solo compagni di squadra)</span></span><select name="scorer_id" data-assist-to>
      <?php foreach ($participants as $r): ?><option value="<?= (int) $r['player_id'] ?>" data-team="<?= h($r['team']) ?>"><?= h($r['name']) ?> (<?= h(team_name($r['team'], $match)) ?>)</option><?php endforeach; ?></select></label>
    <label class="field"><span>Quante volte</span><input type="number" name="n" min="1" max="20" value="1"></label>
    <div><button class="btn btn-primary btn-sm">Aggiungi</button></div>
  </form>
</section>
<script>
// l'assist si dà solo a un compagno di squadra: appena si sceglie chi lo fa, "per il gol di" mostra solo la sua squadra
(() => {
  const form = document.getElementById('assist-form');
  if (!form) return;
  const from = form.querySelector('[data-assist-from]'), to = form.querySelector('[data-assist-to]');
  const sync = () => {
    const team = (from.selectedOptions[0] || {}).dataset?.team;
    const prev = to.value;
    let firstOk = null;
    [...to.options].forEach(o => {
      const ok = o.dataset.team === team && o.value !== from.value;
      o.hidden = !ok;
      if (ok && firstOk === null) firstOk = o.value;
    });
    if (to.selectedOptions[0]?.hidden && firstOk !== null) to.value = firstOk;
    else to.value = prev;
  };
  from.addEventListener('change', sync);
  sync();
})();
</script>
<?php endif; ?>

<?php if ($played): ?>
<section class="card" id="voti">
  <div class="card-head">
    <h2>Voti <?= $votingOpen ? '<span class="tag tag-live">aperti</span>' : '<span class="tag">chiusi</span>' ?></h2>
    <span class="muted small">Hanno votato <?= count($voters) ?>/<?= count($voteParticipants) ?></span>
  </div>

  <?php if ($votingOpen && $match['voting_ends_at']): ?>
    <p class="vote-deadline"><i class="ti ti-alarm"></i> Le votazioni terminano <strong><?= h(push_when($match['voting_ends_at'])) ?></strong>
      <?= countdown_html($match['voting_ends_at'], '· mancano ', 'chiusura in corso…', 0, true, 'hourglass', 'countdown-small') ?></p>
  <?php elseif ($votingOpen): ?>
    <p class="vote-deadline muted"><i class="ti ti-alarm"></i> Nessun orario di fine: le votazioni si chiudono quando le chiude chi gestisce la partita.</p>
  <?php endif; ?>

  <?php if ($votingOpen && $iPlayed): ?>
    <?php $voteForm(); ?>
  <?php elseif ($votingOpen): ?>
    <p class="muted">Votano solo i giocatori che hanno partecipato. I risultati si vedono alla chiusura delle votazioni.</p>
  <?php endif; ?>

  <?php $missing = array_filter($voteParticipants, fn($r) => !in_array((int) $r['player_id'], $voters, true)); ?>
  <?php if ($missing && $votingOpen): ?>
  <div class="vote-missing">
    <p class="small muted">Mancano: <?= $nudgeNames($missing, 'voti') ?></p>
    <?= $nudgeBell('voti', 'Ricorda di votare', 'Manda una notifica solo a chi non ha ancora votato') ?>
  </div>
  <?php endif; ?>
  <?php if ($missing && !$votingOpen): ?><p class="small muted"><i class="ti ti-info-circle"></i> Non hanno votato: <?= h(implode(', ', array_column($missing, 'name'))) ?> (a tutti gli altri è stato dato <?= default_vote_label() ?> d'ufficio).</p><?php endif; ?>

  <?php if ($showVotes): ?>
    <?php if ($votingOpen): ?><p class="small muted"><i class="ti ti-eye"></i> Anteprima visibile solo agli admin.</p><?php endif; ?>
    <?php
    $rank = $voteParticipants;
    usort($rank, fn($a, $b) => ($avgs[(int) $b['player_id']]['avg'] ?? 0) <=> ($avgs[(int) $a['player_id']]['avg'] ?? 0));
    ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Giocatore</th><th>Media</th><th>N. voti</th><th><i class="ti ti-star-filled"></i> voti MVP</th>
        <?php foreach (MATCH_AWARDS as $award => $aw): if (empty($awardOptions[$award])) continue; ?><th title="Voti come <?= h(lcfirst($aw['name'])) ?>"><i class="ti ti-<?= $aw['icon'] ?>"></i> <?= $award === 'dif' ? 'difensore' : 'portiere' ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($rank as $r): $pid = (int) $r['player_id']; $a = $avgs[$pid] ?? null; ?>
        <tr class="<?= $mvp === $pid ? 'row-mvp' : '' ?>">
          <td><?php if ($r['is_guest']): ?><span class="tname"><?= avatar($r, 'xs') ?> <?= h($r['name']) ?></span> <span class="tag">ospite</span><?php if (!(int) $r['votes_ok']): ?> <span class="tag tag-muted" title="I voti di questo ospite non contano per la lega">non conta</span><?php endif; ?>
            <?php else: ?><a class="tname" href="player.php?id=<?= $pid ?>"><?= avatar($r, 'xs') ?> <?= h($r['name']) ?></a><?php endif; ?> <?= $mvp === $pid ? '<span class="tag tag-mvp">MVP</span>' : '' ?><?= $awardTags($pid) ?></td>
          <td><span class="vote <?= vote_class($a['avg'] ?? null) ?>"><?= fmt_num($a['avg'] ?? null) ?></span></td>
          <td><?= $a['n'] ?? 0 ?></td>
          <td><?= $mvpCounts[$pid] ?? 0 ?></td>
          <?php foreach (MATCH_AWARDS as $award => $aw): if (empty($awardOptions[$award])) continue; ?>
            <td><?= in_array($pid, $awardOptions[$award], true) ? ($awardCounts[$award][$pid] ?? 0) : '–' ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <?php if ($canManage && $guestPlayers): ?>
  <div class="guest-votes">
    <h3><i class="ti ti-user-question"></i> Voti degli ospiti</h3>
    <p class="small muted">Gli ospiti votano e vengono votati, così ricevono un feedback. Per la lega (medie, MVP, premi, Fanta, scommesse) i loro voti, quelli che danno
      e quelli che ricevono, contano solo se li accetti tu. Finché non decidi, non contano.</p>
    <?php foreach ($guestPlayers as $g): $gid2 = (int) $g['player_id']; $fb = guest_feedback($gid2, $id); $ok = $g['votes_ok']; $raw = q('SELECT AVG(vote) FROM ratings WHERE match_id = ? AND rated_id = ?', [$id, $gid2])->fetchColumn(); ?>
      <div class="pline-row guest-votes-row">
        <span><?= avatar($g, 'xs') ?> <strong><?= h($g['name']) ?></strong>
          <span class="muted small">· <?= in_array($gid2, $voters, true) ? 'ha votato' : 'non ha votato' ?> · media ricevuta <?= $raw !== null && $raw !== false ? fmt_num((float) $raw) : '–' ?></span>
          <?= $ok === null ? '<span class="tag">da decidere</span>' : ((int) $ok ? '<span class="tag tag-ok">contano</span>' : '<span class="tag tag-muted">non contano</span>') ?></span>
        <span class="btn-row">
          <?php if ($ok === null || !(int) $ok): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="guest_votes"><input type="hidden" name="player_id" value="<?= $gid2 ?>"><input type="hidden" name="ok" value="1">
            <button class="btn btn-ghost btn-sm"><i class="ti ti-check"></i> Fai contare</button></form><?php endif; ?>
          <?php if ($ok === null || (int) $ok): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="guest_votes"><input type="hidden" name="player_id" value="<?= $gid2 ?>"><input type="hidden" name="ok" value="0">
            <button class="btn btn-ghost btn-sm"><i class="ti ti-x"></i> Non far contare</button></form><?php endif; ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($votingOpen && $canManage): ?>
    <form method="post" class="deadline-form"><?= csrf_field() ?><input type="hidden" name="do" value="set_voting_end">
      <label class="field"><span>Fine votazioni</span>
        <input type="datetime-local" name="ends" value="<?= $match['voting_ends_at'] ? h(date('Y-m-d\TH:i', strtotime($match['voting_ends_at']))) : '' ?>"></label>
      <button class="btn btn-ghost btn-sm">Salva orario</button>
      <?php if ($match['voting_ends_at']): ?><button class="btn btn-ghost btn-sm" name="clear" value="1">Nessuna scadenza</button><?php endif; ?>
    </form>
  <?php endif; ?>

  <?php if ($canManage): ?>
    <div class="btn-row">
      <form method="post" class="inline"><?= csrf_field() ?>
        <?php if ($votingOpen): ?>
          <input type="hidden" name="do" value="close_voting"><button class="btn btn-primary" data-confirm="Chiudere le votazioni? Voti, MVP, miglior difensore e miglior portiere entreranno nelle statistiche. A chi non ha votato verrà dato <?= default_vote_label() ?> d'ufficio a tutti gli altri."><i class="ti ti-lock"></i> Chiudi votazioni</button>
        <?php else: ?>
          <input type="hidden" name="do" value="open_voting"><button class="btn btn-ghost"><i class="ti ti-lock-open"></i> Riapri votazioni</button>
        <?php endif; ?>
      </form>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($canAdmin && (float) $match['fee'] > 0 && $participants): ?>
<section class="card" id="pagamenti">
  <?php $paidN = count(array_filter($participants, fn($r) => $r['paid'])); ?>
  <div class="card-head"><h2>Pagamenti</h2>
    <span class="muted small">Incassati <?= fmt_money($paidN * $match['fee']) ?> su <?= fmt_money(count($participants) * $match['fee']) ?></span></div>
  <div class="pay-grid">
    <?php foreach ($participants as $r): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle_paid"><input type="hidden" name="player_id" value="<?= (int) $r['player_id'] ?>">
        <button class="pay <?= $r['paid'] ? 'is-paid' : '' ?>"><?= $r['paid'] ? '<i class="ti ti-check"></i>' : '<i class="ti ti-circle"></i>' ?> <?= h($r['name']) ?></button></form>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($canManage): ?>
<details class="card collapsible">
  <summary><strong><i class="ti ti-settings"></i> Gestione partita</strong></summary>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?><input type="hidden" name="do" value="edit_info">
    <label class="field"><span>Data</span><input type="date" name="date" value="<?= date('Y-m-d', strtotime($match['match_date'])) ?>" required></label>
    <label class="field"><span>Ora</span><input type="time" name="time" value="<?= fmt_time($match['match_date']) ?>" required></label>
    <label class="field span-2"><span>Campo (nome e indirizzo: cliccandoci sopra si apre l'itinerario su Google Maps)</span><input name="location" value="<?= h($match['location']) ?>" placeholder="Es. Centro sportivo Rossi, Via Roma 1, Milano"></label>
    <label class="field"><span><span class="team-dot team-a"></span>Nome squadra 1</span><input name="team_a" maxlength="40" value="<?= h(team_name('A', $match)) ?>"></label>
    <label class="field"><span><span class="team-dot team-b"></span>Nome squadra 2</span><input name="team_b" maxlength="40" value="<?= h(team_name('B', $match)) ?>"></label>
    <label class="field"><span>Quota a testa (€)</span><input name="fee" inputmode="decimal" value="<?= h(number_format((float) $match['fee'], 2, ',', '')) ?>"></label>
    <label class="field"><span>Portieri (cambia le quote dei marcatori)</span><select name="keepers">
      <?php foreach (['volanti' => 'Volanti (in porta a turno)', 'fissi' => 'Fissi'] as $kv => $kl): ?><option value="<?= $kv ?>" <?= match_keepers($match) === $kv ? 'selected' : '' ?>><?= $kl ?></option><?php endforeach; ?></select></label>
    <label class="field span-2"><span>Note</span><input name="notes" value="<?= h($match['notes']) ?>"></label>
    <?php if (!$played): ?><p class="muted small span-2">Se cambi il giorno, le risposte «Ci sono / Non ci sono» si azzerano. I giocatori ricevono una notifica per ogni modifica.</p><?php endif; ?>
    <div class="span-2"><button class="btn btn-ghost">Salva modifiche</button></div>
  </form>
  <details class="collapsible" id="annulla">
    <summary><strong><i class="ti ti-ban"></i> Partita annullata</strong></summary>
    <p class="muted small">Per una partita saltata o interrotta (pioggia, campo chiuso, troppi assenti, infortunio...). La partita si chiude e
      <strong>non conta</strong> per classifiche, statistiche, voti, Fanta e premi in KOIN; le scommesse vengono rimborsate. Presenze, squadre,
      gol già segnati e pagamenti <strong>restano salvati</strong>, e si può sempre riportarla a "programmata". Chi era in lista riceve una notifica.</p>
    <form method="post" class="form form-grid">
      <?= csrf_field() ?><input type="hidden" name="do" value="cancel">
      <label class="field span-2"><span>Motivo (facoltativo, lo vedono tutti)</span><input name="reason" maxlength="200" placeholder="Es. campo allagato, interrotta per infortunio"></label>
      <?php if ((float) $match['fee'] > 0): ?>
        <label class="field check span-2"><input type="checkbox" name="no_fee" value="1"><span>Nessuno paga la quota di <?= fmt_money($match['fee']) ?> (si azzera)</span></label>
      <?php endif; ?>
      <div class="span-2"><button class="btn btn-danger" data-confirm="Annullare la partita? Non conterà per classifiche e statistiche e le scommesse verranno rimborsate."><i class="ti ti-ban"></i> Annulla la partita</button></div>
    </form>
  </details>
  <div class="btn-row danger-zone">
    <?php if ($played): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="reopen">
        <button class="btn btn-ghost" data-confirm="Riportare la partita a 'programmata'? Voti e MVP restano salvati ma non contano finché non la richiudi.">↩️ Riporta a programmata</button></form>
    <?php endif; ?>
    <?php if ($canAdmin): ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="delete">
      <button class="btn btn-danger" data-confirm="Eliminare definitivamente la partita con presenze, gol e voti?"><i class="ti ti-trash"></i> Elimina partita</button></form>
    <?php endif; ?>
  </div>
</details>
<?php endif; ?>
<?php if ($cancelled && $canManageMatch): ?>
<section class="card">
  <h2><i class="ti ti-settings"></i> Partita annullata</h2>
  <p class="muted small">Riportandola a "programmata" si può di nuovo modificare: le scommesse tornano aperte e, quando la chiudi, conta come le altre.</p>
  <div class="btn-row danger-zone">
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="reopen">
      <button class="btn btn-ghost" data-confirm="Riportare la partita a 'programmata'? Le scommesse rimborsate tornano aperte.">↩️ Riporta a programmata</button></form>
    <?php if ($canAdmin): ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="delete">
      <button class="btn btn-danger" data-confirm="Eliminare definitivamente la partita con presenze, gol e voti?"><i class="ti ti-trash"></i> Elimina partita</button></form>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
<?php
layout_end();
