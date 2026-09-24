<?php
/*
 * Link d'invito di una lega: join.php?c=CODICE (lo copia chi gestisce la lega da «La mia lega»).
 * Chi non ha un account si iscrive (register.php?c=...), chi ce l'ha entra subito (lega libera) o manda una richiesta.
 */
require __DIR__ . '/lib/bootstrap.php';

if (!tables_exist()) {
    redirect('install.php');
}
$code = is_string($_GET['c'] ?? $_POST['c'] ?? null) ? (string) ($_GET['c'] ?? $_POST['c']) : '';
$blocked = invite_blocked();   // troppi codici sbagliati da questa connessione: chi li prova a caso non trova più niente
$league = $blocked ? null : league_by_code($code);
if (!$league && !$blocked && $code !== '') {
    invite_bad_attempt($code);
}
$u = current_user();

if ($league && $u && is_post() && ($_POST['do'] ?? '') === 'join') {
    if (is_guest()) {
        flash('err', 'Con un account da ospite non si entra nelle leghe: iscriviti con un account tuo.');
        redirect('join.php?c=' . urlencode($league['invite_code']));
    }
    $gid = (int) $league['id'];
    $res = league_join((int) $u['id'], $league);
    if ($res === 'joined' || $res === 'already') {
        set_group_filter($gid);
        flash('ok', $res === 'joined' ? 'Benvenuto in ' . $league['name'] . '!' : 'Fai già parte di ' . $league['name'] . '.');
        redirect('index.php');
    }
    if ($res === 'limit') {
        flash('err', 'Hai già mandato troppe richieste oggi: riprova domani.');
    } else {
        flash('ok', $res === 'requested'
            ? 'Richiesta inviata: appena un admin di ' . $league['name'] . ' la accetta, la lega ti compare.'
            : 'Avevi già chiesto di entrare: la richiesta è in attesa di un admin della lega.');
    }
    redirect('join.php?c=' . urlencode($league['invite_code']));
}

$member = $league && $u && my_player_id() && player_in_group((int) my_player_id(), (int) $league['id']);
$waiting = $league && $u && q('SELECT 1 FROM group_requests WHERE group_id = ? AND user_id = ?', [$league['id'], $u['id']])->fetch();
$members = $league ? (int) q('SELECT COUNT(*) FROM player_groups WHERE group_id = ?', [$league['id']])->fetchColumn() : 0;
$owner = $league && $league['owner_user_id']
    ? q('SELECT COALESCE(p.name, u.username) FROM users u LEFT JOIN players p ON p.id = u.player_id WHERE u.id = ?', [$league['owner_user_id']])->fetchColumn()
    : null;

layout_start($league ? 'Invito in ' . $league['name'] : 'Invito non valido');
?>
<div class="narrow">
  <div class="card login-card">
    <div class="login-ball"><i class="ti ti-users-group"></i></div>
    <?php if (!$league): ?>
      <h1>Invito non valido</h1>
      <p class="muted"><?= $blocked ? 'Troppi link d\'invito sbagliati da questa connessione: riprova tra un\'ora.' : 'Il link non corrisponde a nessuna lega: forse è stato cambiato. Chiedine uno nuovo a chi gestisce la lega.' ?></p>
      <a class="btn btn-primary btn-block" href="<?= $u ? 'index.php' : 'login.php' ?>"><?= $u ? 'Torna alla home' : 'Vai al login' ?></a>
    <?php else: ?>
      <h1><?= h($league['name']) ?></h1>
      <p class="muted"><?= $owner ? 'Lega organizzata da <strong>' . h((string) $owner) . '</strong> · ' : '' ?><?= $members ?> giocatori</p>
      <?php if ($member): ?>
        <p>Fai già parte di questa lega.</p>
        <a class="btn btn-primary btn-block" href="index.php">Vai alla home</a>
      <?php elseif ($u): ?>
        <?php if ($waiting): ?>
          <p><i class="ti ti-hourglass"></i> Hai già chiesto di entrare: aspetta che un admin della lega accetti.</p>
        <?php else: ?>
          <p><?= $league['join_mode'] === 'libero' ? 'Chi ha questo link entra subito nella lega.' : 'Un admin della lega deve accettare la richiesta.' ?></p>
          <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="join"><input type="hidden" name="c" value="<?= h($league['invite_code']) ?>">
            <button class="btn btn-primary btn-block"><i class="ti ti-login-2"></i> <?= $league['join_mode'] === 'libero' ? 'Entra nella lega' : 'Chiedi di entrare' ?></button></form>
        <?php endif; ?>
      <?php else: ?>
        <p>Sei stato invitato a giocare in questa lega: presenze, squadre bilanciate, voti, classifica e scommesse a gettoni finti.</p>
        <div class="btn-col">
          <a class="btn btn-primary btn-block" href="register.php?c=<?= h(urlencode($league['invite_code'])) ?>"><i class="ti ti-user-plus"></i> Crea un account</a>
          <a class="btn btn-ghost btn-block" href="login.php?next=<?= h(urlencode('join.php?c=' . $league['invite_code'])) ?>"><i class="ti ti-login"></i> Ho già un account</a>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
