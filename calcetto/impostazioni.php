<?php
/*
 * Le impostazioni di chi ha un account (come next-app/src/app/(site)/impostazioni): notifiche, email, verifica in due passaggi e dispositivi
 * collegati (per tutti), poi quello che il ruolo permette: il manager vede le sue leghe, l'admin tutta la gestione del sito (admin.php,
 * che ora si chiama «Gestione del sito»).
 */
require __DIR__ . '/lib/bootstrap.php';
require_login();
if (is_guest()) {
    redirect('index.php');
}

$u = current_user();
$uid = (int) $u['id'];
$acc = q('SELECT username, email, pending_email, totp_secret, totp_recovery FROM users WHERE id = ?', [$uid])->fetch();
$devices = (int) q('SELECT COUNT(*) FROM auth_tokens WHERE user_id = ? AND expires_at > ?', [$uid, date('Y-m-d H:i:s')])->fetchColumn();
$known = q('SELECT device, last_seen FROM known_devices WHERE user_id = ? ORDER BY last_seen DESC LIMIT 6', [$uid])->fetchAll();
$recoveryLeft = $acc['totp_secret'] ? count(json_decode($acc['totp_recovery'] ?? '[]', true) ?: []) : 0;
$admin = is_admin();
$owned = my_owned_leagues();
$manager = $u['role'] === 'manager' || in_array('manager', my_league_roles(), true);
$pendingByLeague = [];
foreach ($owned as $gid => $_) {
    $pendingByLeague[$gid] = league_pending_count((int) $gid);
}
$pendingSite = $admin ? max(0, pending_for_me() - array_sum($pendingByLeague)) : 0;
$proposals = $admin ? proposal_unread_count() : 0;

layout_start('Impostazioni', 'settings');
?>
<div class="narrow-lg">
  <div class="page-head"><h1>Impostazioni</h1></div>
  <p class="muted">Account <strong><?= h($acc['username']) ?></strong> · <?= h(strtolower(role_label($u['role']))) ?>
    <?php if (my_player_id()): ?> · <a class="link" href="player_edit.php?id=<?= (int) my_player_id() ?>">Modifica il tuo profilo</a><?php endif; ?></p>

  <?= push_card() ?>

  <section class="card" id="email">
    <h2><i class="ti ti-mail"></i> Email</h2>
    <?php if ($acc['email']): ?><p><i class="ti ti-circle-check acc-ok"></i> <strong><?= h($acc['email']) ?></strong> <span class="tag">collegata e confermata</span></p>
    <?php elseif ($acc['pending_email']): ?><p><i class="ti ti-hourglass"></i> Da confermare: <strong><?= h($acc['pending_email']) ?></strong> (apri il link nell'email, controlla anche lo spam).</p>
    <?php else: ?><p><i class="ti ti-alert-triangle"></i> Nessuna email collegata: se dimentichi la password dovrai chiedere all'admin.</p><?php endif; ?>
    <p class="muted small">Serve per recuperare la password da solo e per avvisarti se qualcuno cambia i dati dell'account. Non la vede nessun altro.</p>
    <a class="btn btn-ghost btn-sm" href="account.php#email"><i class="ti ti-mail"></i> <?= $acc['email'] ? 'Cambia o togli l\'email' : 'Collega la tua email' ?></a>
  </section>

  <section class="card" id="mfa">
    <h2><i class="ti ti-shield-lock"></i> Verifica in due passaggi (MFA)</h2>
    <?php if ($acc['totp_secret']): ?><p><i class="ti ti-circle-check acc-ok"></i> <strong>Attiva.</strong> Codici di recupero rimasti: <strong><?= $recoveryLeft ?></strong> su <?= TOTP_RECOVERY_CODES ?>.</p>
    <?php else: ?><p><i class="ti ti-alert-triangle"></i> Non attiva: per entrare basta la password.<?= ($admin || $manager || $owned) ? ' <strong>Chi gestisce una lega o il sito dovrebbe attivarla.</strong>' : '' ?></p><?php endif; ?>
    <a class="btn btn-ghost btn-sm" href="account.php#due-passaggi"><i class="ti ti-shield-lock"></i> <?= $acc['totp_secret'] ? 'Gestisci la verifica' : 'Attiva la verifica in due passaggi' ?></a>
    <a class="btn btn-ghost btn-sm" href="account.php#password"><i class="ti ti-key"></i> Cambia password</a>
  </section>

  <section class="card" id="dispositivi">
    <h2><i class="ti ti-devices"></i> Dispositivi collegati</h2>
    <p>Questo account è collegato su <strong><?= $devices ?></strong> <?= $devices === 1 ? 'dispositivo' : 'dispositivi' ?> (compreso questo). Su ognuno resti collegato per <?= REMEMBER_DAYS ?> giorni.</p>
    <?php if ($known): ?>
    <ul class="plain-list small"><?php foreach ($known as $d): ?><li><i class="ti ti-device-mobile"></i> <strong><?= h($d['device']) ?></strong> <span class="muted">· ultimo accesso <?= h(fmt_date_short($d['last_seen'])) ?> <?= h(fmt_time($d['last_seen'])) ?></span></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <form method="post" action="account.php" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="logout_all">
      <button class="btn btn-ghost btn-sm" data-confirm="Scollegare tutti gli altri dispositivi?"><i class="ti ti-logout"></i> Esci da tutti gli altri dispositivi</button></form>
  </section>

  <?php if (($manager || $owned) && !$admin): ?>
  <section class="card" id="le-tue-leghe">
    <h2><i class="ti ti-users-group"></i> <?= count($owned) > 1 ? 'Le tue leghe' : 'La tua lega' ?></h2>
    <?php if ($owned): ?>
    <div class="list"><?php foreach ($owned as $gid => $name): ?>
      <a class="card admin-link" href="league.php?id=<?= (int) $gid ?>"><span><i class="ti ti-users-group"></i></span><strong><?= h($name) ?></strong>
        <small><?= !empty($pendingByLeague[$gid]) ? (int) $pendingByLeague[$gid] . ' richieste da guardare' : 'Gestisci: membri, sfide, pagamenti' ?></small></a>
    <?php endforeach; ?></div>
    <?php else: ?><p class="muted small">Sei manager: crei e gestisci le partite della tua lega dalla scheda «Partite». Le iscrizioni, i ruoli e i pagamenti li gestisce chi amministra la lega.</p><?php endif; ?>
    <p><a class="btn btn-ghost btn-sm" href="matches.php"><i class="ti ti-calendar-event"></i> Le partite</a></p>
  </section>
  <?php endif; ?>

  <?php if ($admin): ?>
  <section class="card" id="gestione-sito">
    <h2><i class="ti ti-settings"></i> Gestione del sito</h2>
    <p class="muted small">Quello che puoi fare come admin: iscrizioni, gruppi, account e ruoli, KOIN, notifiche, email e proposte dei giocatori.</p>
    <div class="admin-links">
      <a class="card admin-link" href="admin.php"><span><i class="ti ti-user-plus"></i></span><strong>Iscrizioni e account</strong><small><?= $pendingSite ? $pendingSite . ' da approvare' : 'Approva, ruoli, password' ?></small></a>
      <a class="card admin-link" href="admin.php#gruppi"><span><i class="ti ti-users-group"></i></span><strong>Gruppi</strong><small>Leghe di casa e giocatori</small></a>
      <a class="card admin-link" href="admin.php#proposte"><span><i class="ti ti-bulb"></i></span><strong>Proposte</strong><small><?= $proposals ? $proposals . ' nuove' : 'Dei giocatori' ?></small></a>
      <a class="card admin-link" href="admin.php#campi"><span><i class="ti ti-map-pin"></i></span><strong>Campi</strong><small>Aggiunti dagli utenti</small></a>
      <a class="card admin-link" href="admin.php#notifiche"><span><i class="ti ti-bell"></i></span><strong>Notifiche ed email</strong><small>Stato e coda di invio</small></a>
      <a class="card admin-link" href="admin.php#koin"><span><i class="ti ti-coin"></i></span><strong>Regala KOIN</strong><small>Ai giocatori</small></a>
      <?php if (is_platform_owner()): ?><a class="card admin-link" href="platform.php"><span><i class="ti ti-world"></i></span><strong>Piattaforma</strong><small>Tutte le leghe del sito</small></a><?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
</div>
<?php
layout_end();
