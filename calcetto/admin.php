<?php
require __DIR__ . '/lib/bootstrap.php';
require_admin();

$meUid = (int) current_user()['id'];

if (is_post()) {
    $do = $_POST['do'] ?? '';
    $uid = (int) ($_POST['user_id'] ?? 0);
    switch ($do) {
        case 'create':
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'player';
            $pid = (int) ($_POST['player_id'] ?? 0) ?: null;
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
                flash('err', 'Username: 3-50 caratteri tra lettere, numeri, punto, trattino e underscore.');
            } elseif ($err = password_error($password)) {
                flash('err', $err);
            } elseif (q('SELECT 1 FROM users WHERE username = ?', [$username])->fetch()) {
                flash('err', 'Username già usato.');
            } elseif ($pid && q('SELECT 1 FROM users WHERE player_id = ?', [$pid])->fetch()) {
                flash('err', 'Quel giocatore ha già un account.');
            } else {
                q('INSERT INTO users (username, password_hash, role, player_id) VALUES (?, ?, ?, ?)',
                    [$username, password_hash($password, PASSWORD_DEFAULT), $role, $pid]);
                flash('ok', "Account \"$username\" creato.");
            }
            break;
        case 'reset':
            $password = $_POST['password'] ?? '';
            if ($err = password_error($password)) {
                flash('err', $err);
            } else {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $uid]);
                flash('ok', 'Password aggiornata.');
            }
            break;
        case 'role':
            if ($uid === $meUid) {
                flash('err', 'Non puoi cambiare il tuo ruolo.');
            } else {
                q('UPDATE users SET role = ? WHERE id = ?', [($_POST['role'] ?? '') === 'admin' ? 'admin' : 'player', $uid]);
                flash('ok', 'Ruolo aggiornato.');
            }
            break;
        case 'link':
            $pid = (int) ($_POST['player_id'] ?? 0) ?: null;
            if ($pid && q('SELECT 1 FROM users WHERE player_id = ? AND id <> ?', [$pid, $uid])->fetch()) {
                flash('err', 'Quel giocatore ha già un account.');
            } else {
                q('UPDATE users SET player_id = ? WHERE id = ?', [$pid, $uid]);
                flash('ok', 'Collegamento aggiornato.');
            }
            break;
        case 'delete':
            if ($uid === $meUid) {
                flash('err', 'Non puoi eliminare il tuo account.');
            } else {
                q('DELETE FROM users WHERE id = ?', [$uid]);
                flash('ok', 'Account eliminato (il giocatore e le sue statistiche restano).');
            }
            break;
    }
    redirect('admin.php');
}

$users = q("SELECT u.*, p.name AS player_name FROM users u LEFT JOIN players p ON p.id = u.player_id
            WHERE u.status = 'attivo' ORDER BY u.role, u.username")->fetchAll();
$players = all_players();
$free = q('SELECT p.id, p.name FROM players p LEFT JOIN users u ON u.player_id = p.id WHERE u.id IS NULL ORDER BY p.name')->fetchAll();

layout_start('Admin', 'admin');
?>
<div class="page-head"><h1>Admin</h1></div>

<?php if (is_file(__DIR__ . '/install.php')): ?>
  <div class="flash flash-err"><i class="ti ti-alert-triangle"></i> <strong>install.php</strong> è ancora sul server: cancellalo.</div>
<?php endif; ?>

<p class="muted small"><i class="ti ti-lock"></i> Non c'è iscrizione libera: gli account li crei solo tu, qui sotto o da <em>Rosa → Nuovo giocatore</em>.</p>

<div class="admin-links">
  <a class="card admin-link" href="matches.php#nuova"><span><i class="ti ti-calendar-event"></i></span><strong>Crea partita</strong><small>Data, campo, quota</small></a>
  <a class="card admin-link" href="player_edit.php"><span><i class="ti ti-user-plus"></i></span><strong>Aggiungi giocatore</strong><small>Con o senza account</small></a>
  <a class="card admin-link" href="payments.php"><span><i class="ti ti-currency-euro"></i></span><strong>Pagamenti</strong><small>Quote e saldi</small></a>
  <a class="card admin-link" href="players.php?tutti=1"><span><i class="ti ti-chart-bar"></i></span><strong>Statistiche</strong><small>Apri un giocatore → Modifica</small></a>
</div>

<section class="card">
  <h2>Account</h2>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Username</th><th>Giocatore collegato</th><th>Ruolo</th><th>Password</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $uid = (int) $u['id']; ?>
      <tr>
        <td><strong><?= h($u['username']) ?></strong><?= $uid === $meUid ? ' <span class="muted small">(tu)</span>' : '' ?></td>
        <td>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="link"><input type="hidden" name="user_id" value="<?= $uid ?>">
            <select name="player_id" class="mini-select" data-autosubmit>
              <option value="">— nessuno —</option>
              <?php if ($u['player_id']): ?><option value="<?= (int) $u['player_id'] ?>" selected><?= h($u['player_name']) ?></option><?php endif; ?>
              <?php foreach ($free as $f): ?><option value="<?= (int) $f['id'] ?>"><?= h($f['name']) ?></option><?php endforeach; ?>
            </select></form>
        </td>
        <td>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="role"><input type="hidden" name="user_id" value="<?= $uid ?>">
            <select name="role" class="mini-select" data-autosubmit <?= $uid === $meUid ? 'disabled' : '' ?>>
              <option value="player" <?= $u['role'] === 'player' ? 'selected' : '' ?>>Giocatore</option>
              <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select></form>
        </td>
        <td>
          <form method="post" class="inline pw-form"><?= csrf_field() ?><input type="hidden" name="do" value="reset"><input type="hidden" name="user_id" value="<?= $uid ?>">
            <input type="text" name="password" placeholder="nuova password" minlength="8" maxlength="72" required class="mini-input" autocomplete="off">
            <button class="btn btn-ghost btn-sm">Imposta</button></form>
        </td>
        <td>
          <?php if ($uid !== $meUid): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="user_id" value="<?= $uid ?>">
              <button class="icon-btn" title="Elimina account" data-confirm="Eliminare l'account <?= h($u['username']) ?>?"><i class="ti ti-trash"></i></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <h3>Nuovo account</h3>
  <form method="post" class="form form-grid form-grid-4">
    <?= csrf_field() ?><input type="hidden" name="do" value="create">
    <label class="field"><span>Username</span><input name="username" required autocomplete="off"></label>
    <label class="field"><span>Password</span><input type="text" name="password" required minlength="8" maxlength="72" autocomplete="off"></label>
    <label class="field"><span>Giocatore</span><select name="player_id">
      <option value="">— nessuno —</option>
      <?php foreach ($free as $f): ?><option value="<?= (int) $f['id'] ?>"><?= h($f['name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Ruolo</span><select name="role"><option value="player">Giocatore</option><option value="admin">Admin</option></select></label>
    <div><button class="btn btn-primary">Crea account</button></div>
  </form>
</section>

<section class="card">
  <h2>Come funzionano i calcoli</h2>
  <ul class="howto">
    <li><strong>Voto partita</strong>: a fine partita ogni giocatore vota tutti gli altri (1-10, passi da 0,5). Il voto di un giocatore è la media dei voti ricevuti. Conta nelle statistiche quando chiudi le votazioni.</li>
    <li><strong>MVP</strong>: chi riceve più voti MVP; a parità vince la media voto più alta, poi chi ha segnato di più.</li>
    <li><strong>Rating (OVR)</strong>: rating base dell'admin; con almeno 3 partite votate diventa 50% rating base + 50% media voto (25% con 1-2 partite votate). Aggiunge fino a ±0,5 in base alla % di vittorie.</li>
    <li><strong>Squadre bilanciate</strong>: fino a 22 confermati prova tutte le divisioni possibili e sceglie quelle con somma dei rating più vicina, dividendo anche portieri, difensori e attaccanti. "Rigenera" propone un'altra divisione quasi equivalente.</li>
    <li><strong>Classifica</strong>: <?= POINTS_WIN ?> punti a vittoria, <?= POINTS_DRAW ?> a pareggio; a parità contano % vittorie e gol.</li>
    <li><strong>Forma</strong>: punti medi nelle ultime 5 partite + andamento del voto rispetto alla media.</li>
    <li><strong>Partecipazione %</strong>: partite giocate / partite totali registrate sul sito.</li>
  </ul>
</section>
<?php
layout_end();
