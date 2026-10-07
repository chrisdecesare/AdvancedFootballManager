<?php
/*
 * Area dell'Ospite (lib/guests.php): salvare l'account (così non scade e diventa un giocatore libero), gli inviti nelle leghe,
 * le partite giocate da ospite con il feedback ricevuto.
 */
require __DIR__ . '/lib/bootstrap.php';
require_login();
if (!is_guest()) {
    redirect('index.php');
}
$uid = (int) current_user()['id'];
$me = (int) my_player_id();

if (is_post()) {
    $do = $_POST['do'] ?? '';
    switch ($do) {
        case 'save':
            $err = guest_save_account($uid, is_string($_POST['password'] ?? null) ? $_POST['password'] : '', is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '');
            flash($err ? 'err' : 'ok', $err ?? 'Account salvato con la tua nuova password: non scade più e le leghe ti possono chiamare.');
            break;
        case 'unsave':
            guest_unsave_account($me);
            flash('ok', 'Non sei più tra i giocatori liberi: l\'account scade ' . GUEST_KEEP_DAYS . ' giorni dopo la tua ultima partita.');
            break;
        case 'accept':
            $gid = (int) ($_POST['group_id'] ?? 0);
            $err = guest_join_league($uid, $gid);
            if (!$err) {
                set_group_filter($gid);
                flash('ok', 'Benvenuto in ' . group_name($gid) . '! Ora sei un giocatore della lega.');
                redirect('index.php');
            }
            flash('err', $err);
            break;
        case 'decline':
            q('DELETE FROM league_invites WHERE group_id = ? AND player_id = ?', [(int) ($_POST['group_id'] ?? 0), $me]);
            flash('ok', 'Invito rifiutato.');
            break;
    }
    redirect('guest.php');
}

$saved = guest_saved();
$invites = guest_league_invites($me);
$fb = guest_feedback($me);
$matches = q('SELECT m.*, mp.team, mp.availability, mp.votes_ok, EXISTS (SELECT 1 FROM mvp_votes v WHERE v.match_id = m.id AND v.voter_id = mp.player_id) AS voted
              FROM match_players mp JOIN matches m ON m.id = mp.match_id
              WHERE mp.player_id = ? ORDER BY m.match_date DESC', [$me])->fetchAll();

layout_start('La tua area', 'guest');
?>
<div class="page-head"><h1>La tua area</h1></div>

<?php if ($invites): ?>
<section class="card" id="inviti">
  <h2><i class="ti ti-mail-heart"></i> Inviti nelle leghe</h2>
  <p class="muted small">Se accetti diventi un giocatore della lega, con un account normale: le partite giocate da ospite restano tue.</p>
  <?php foreach ($invites as $inv): ?>
    <div class="pline-row">
      <span><i class="ti ti-users-group"></i> <strong><?= h($inv['name']) ?></strong> <span class="muted small">· <?= h(fmt_date_short($inv['created_at'])) ?></span></span>
      <span class="btn-row">
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="accept"><input type="hidden" name="group_id" value="<?= (int) $inv['group_id'] ?>">
          <button class="btn btn-primary btn-sm" data-confirm="Entrare in <?= h($inv['name']) ?>? Non sarai più un giocatore libero, e uscirai dalle partite in programma di altre leghe a cui eri stato chiamato."><i class="ti ti-check"></i> Accetta</button></form>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="decline"><input type="hidden" name="group_id" value="<?= (int) $inv['group_id'] ?>">
          <button class="btn btn-ghost btn-sm"><i class="ti ti-x"></i> No, grazie</button></form>
      </span>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card guest-save" id="salva">
  <?php if ($saved): ?>
    <h2><i class="ti ti-user-check"></i> Sei un giocatore libero</h2>
    <p>Il tuo account non scade. Chi gestisce le leghe vede il tuo nome, i tuoi ruoli, quante partite hai giocato da ospite e la media dei voti ricevuti,
      e può chiamarti a una partita o invitarti nella sua lega: ti arriva una notifica (se le hai attivate) e lo trovi qui.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="unsave">
      <button class="btn btn-ghost btn-sm" data-confirm="Uscire dai giocatori liberi? Le leghe non ti vedranno più e l'account scadrà <?= GUEST_KEEP_DAYS ?> giorni dopo la tua ultima partita."><i class="ti ti-user-off"></i> Non voglio più essere chiamato</button></form>
  <?php else: ?>
    <h2><i class="ti ti-user-check"></i> Vuoi tenere il tuo account?</h2>
    <p>Se lo salvi non scade più. Finché non entri in una lega sei tra i <strong>giocatori liberi</strong>: chi gestisce le altre leghe vede il tuo nome,
      i tuoi ruoli, quante partite hai giocato da ospite e la media dei voti ricevuti, e può chiamarti a una partita o invitarti nella sua lega.</p>
    <p class="small muted">Scegli una password tua: quella di adesso la conosce chi ti ha invitato.</p>
    <form method="post" class="form form-grid">
      <?= csrf_field() ?><input type="hidden" name="do" value="save">
      <label class="field"><span>Nuova password</span><input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
      <label class="field"><span>Ripeti la password</span><input type="password" name="password2" required minlength="8" maxlength="72" autocomplete="new-password"></label>
      <div class="span-2"><button class="btn btn-primary btn-sm"><i class="ti ti-device-floppy"></i> Salva il mio account</button></div>
    </form>
  <?php endif; ?>
  <p class="small muted"><a href="account.php"><i class="ti ti-shield-lock"></i> Email e password</a></p>
</section>

<section class="card" id="partite">
  <div class="card-head"><h2><i class="ti ti-ball-football"></i> Le tue partite da ospite</h2>
    <?php if ($fb['avg'] !== null): ?><span class="muted small">Media dei voti ricevuti <span class="vote <?= vote_class($fb['avg']) ?>"><?= fmt_num($fb['avg']) ?></span> (<?= $fb['votes'] ?> voti)</span><?php endif; ?></div>
  <?php if (!$matches): ?>
    <p class="muted">Nessuna partita, per ora.</p>
  <?php endif; ?>
  <?php foreach ($matches as $m): $mf = $m['status'] === 'giocata' && !(int) $m['voting_open'] ? guest_feedback($me, (int) $m['id']) : null; ?>
    <a class="pline-row guest-match" href="match.php?id=<?= (int) $m['id'] ?>">
      <span><strong><?= h(fmt_date_long($m['match_date'])) ?></strong> <span class="muted small">· <?= h(group_name((int) $m['group_id'])) ?>
        <?php if ($m['status'] === 'giocata'): ?> · <?= h(team_name('A', $m)) ?> <?= (int) $m['score_a'] ?>–<?= (int) $m['score_b'] ?> <?= h(team_name('B', $m)) ?><?php elseif ($m['status'] === 'annullata'): ?> · annullata<?php else: ?> · in programma<?= $m['availability'] === 'in_attesa' ? ': dici se ci sei' : '' ?><?php endif; ?></span></span>
      <?php if ($mf && $mf['avg'] !== null): ?><span class="vote <?= vote_class($mf['avg']) ?>" title="Media dei voti ricevuti"><?= fmt_num($mf['avg']) ?></span>
      <?php elseif ($m['status'] === 'giocata' && (int) $m['voting_open'] && $m['team']): ?><span class="tag<?= $m['voted'] ? '' : ' tag-live' ?>"><?= $m['voted'] ? 'hai votato' : 'vota' ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</section>
<?php layout_end();
