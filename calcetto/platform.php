<?php
/*
 * «Piattaforma»: la pagina dell'admin del sito per tenere d'occhio tutto quello che succede, anche nelle leghe create da altri.
 *  - Panoramica: numeri principali, operazioni più frequenti, leghe più attive;
 *  - Leghe: tutte, con proprietario, membri, partite e ultima attività (e il dettaglio di ognuna);
 *  - Account: tutti, con le loro leghe e l'ultimo accesso (e il dettaglio di ognuno);
 *  - Registro: tutte le operazioni, filtrabili per lega, account e tipo.
 * Diversa da admin.php, che resta la gestione quotidiana delle leghe storiche.
 */
require __DIR__ . '/lib/bootstrap.php';
require_admin();

// azioni sugli account delle leghe degli altri (quelli delle leghe storiche si gestiscono anche da admin.php)
if (is_post()) {
    $do = $_POST['do'] ?? '';
    $uid = (int) ($_POST['user_id'] ?? 0);
    $target = q('SELECT id, username, role FROM users WHERE id = ?', [$uid])->fetch();
    if ($target && $do === 'reset') {
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if ($err = password_error($password, $target['username'])) {
            flash('err', $err);
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $uid]);
            security_reset_sessions($uid);   // i dispositivi già collegati devono rifare l'accesso
            notify_password_changed($uid);
            log_activity('admin', 'password reimpostata · @' . $target['username']);
            flash('ok', 'Password di @' . $target['username'] . ' aggiornata.');
        }
    } elseif ($target && $do === 'delete_account') {
        if ($uid === (int) current_user()['id']) {
            flash('err', 'Non puoi eliminare il tuo account.');
        } elseif (q('SELECT 1 FROM squad_groups WHERE owner_user_id = ?', [$uid])->fetch()) {
            flash('err', 'L\'account possiede una lega: prima cedila a qualcun altro (o eliminala) da «Gestisci».');
        } else {
            q('DELETE FROM users WHERE id = ?', [$uid]);
            log_activity('admin', 'account eliminato · @' . $target['username']);
            flash('ok', 'Account @' . $target['username'] . ' eliminato (il giocatore e le sue statistiche restano).');
            redirect('platform.php?t=account');
        }
    }
    redirect('platform.php?t=account&account=' . $uid);
}

$tab = in_array($_GET['t'] ?? '', ['leghe', 'account', 'registro'], true) ? $_GET['t'] : 'panoramica';
$leagueId = int_get('lega');
$accountId = int_get('account');
$labels = activity_labels();
$since30 = date('Y-m-d H:i:s', time() - 30 * 86400);
$since7 = date('Y-m-d H:i:s', time() - 7 * 86400);
$since1 = date('Y-m-d H:i:s', time() - 86400);
$self = fn(array $p = []) => 'platform.php' . ($p ? '?' . http_build_query($p) : '');

/** Chi ha fatto l'operazione, leggibile. */
function who(array $a): string
{
    return $a['player_name'] ?: ($a['username'] ?: ($a['user_id'] ? 'account #' . (int) $a['user_id'] : '—'));
}

/** Tabella delle operazioni del registro. */
function activity_table(array $rows, array $labels, bool $showLeague = true): string
{
    if (!$rows) {
        return '<p class="empty">Nessuna operazione.</p>';
    }
    $groups = all_groups();
    $out = '<div class="table-wrap"><table class="table"><thead><tr><th>Quando</th><th>Chi</th>' . ($showLeague ? '<th>Lega</th>' : '')
        . '<th>Cosa</th><th>Dettaglio</th><th>IP</th></tr></thead><tbody>';
    foreach ($rows as $a) {
        $gid = (int) $a['group_id'];
        $out .= '<tr><td class="small nowrap">' . fmt_date_short($a['created_at']) . ' ' . fmt_time($a['created_at']) . '</td>'
            . '<td class="small">' . ($a['user_id'] ? '<a class="link" href="platform.php?t=account&amp;account=' . (int) $a['user_id'] . '">' . h(who($a)) . '</a>' : h(who($a))) . '</td>'
            . ($showLeague ? '<td class="small">' . ($gid ? '<a class="link" href="platform.php?t=leghe&amp;lega=' . $gid . '">' . h($groups[$gid] ?? '#' . $gid) . '</a>' : '<span class="muted">—</span>') . '</td>' : '')
            . '<td class="small"><span class="tag tag-act act-' . h(explode('_', $a['action'])[0]) . '">' . h($labels[$a['action']] ?? $a['action']) . '</span></td>'
            . '<td class="small muted">' . h($a['detail']) . '</td><td class="small muted">' . h($a['ip']) . '</td></tr>';
    }
    return $out . '</tbody></table></div>';
}

$logSelect = 'SELECT a.*, u.username, p.name AS player_name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN players p ON p.id = u.player_id';

layout_start('Piattaforma', 'platform');
?>
<div class="page-head"><h1>Piattaforma <span class="muted small">tutto il sito, tutte le leghe</span></h1></div>

<nav class="shop-tabs">
  <a href="platform.php" class="<?= $tab === 'panoramica' ? 'active' : '' ?>"><i class="ti ti-chart-dots"></i> Panoramica</a>
  <a href="platform.php?t=leghe" class="<?= $tab === 'leghe' ? 'active' : '' ?>"><i class="ti ti-users-group"></i> Leghe</a>
  <a href="platform.php?t=account" class="<?= $tab === 'account' ? 'active' : '' ?>"><i class="ti ti-user-circle"></i> Account</a>
  <a href="platform.php?t=registro" class="<?= $tab === 'registro' ? 'active' : '' ?>"><i class="ti ti-list-details"></i> Registro</a>
</nav>

<?php if ($tab === 'panoramica'):
    $k = q("SELECT
              (SELECT COUNT(*) FROM squad_groups) AS leagues,
              (SELECT COUNT(*) FROM squad_groups WHERE owner_user_id IS NOT NULL) AS user_leagues,
              (SELECT COUNT(*) FROM squad_groups WHERE owner_user_id IS NOT NULL AND created_at > ?) AS new_leagues,
              (SELECT COUNT(*) FROM users WHERE status = 'attivo' AND role <> 'ospite') AS accounts,
              (SELECT COUNT(*) FROM users WHERE status = 'attivo' AND role <> 'ospite' AND created_at > ?) AS new_accounts,
              (SELECT COUNT(*) FROM users WHERE status = 'in_attesa') AS pending,
              (SELECT COUNT(*) FROM users WHERE last_seen_at > ?) AS active7,
              (SELECT COUNT(*) FROM users WHERE last_seen_at > ?) AS active1,
              (SELECT COUNT(*) FROM matches WHERE status = 'giocata') AS played,
              (SELECT COUNT(*) FROM matches WHERE status = 'programmata' AND match_date > NOW()) AS upcoming,
              (SELECT COUNT(*) FROM bets WHERE created_at > ?) + (SELECT COUNT(*) FROM combo_bets WHERE created_at > ?) AS bets30,
              (SELECT COUNT(*) FROM activity_log WHERE created_at > ?) AS ops1",
        [$since30, $since30, $since7, $since1, $since30, $since30, $since1])->fetch();
    $byAction = q('SELECT action, COUNT(*) AS n FROM activity_log WHERE created_at > ? GROUP BY action ORDER BY n DESC', [$since30])->fetchAll();
    $maxAct = $byAction ? max(array_column($byAction, 'n')) : 1;
    $topLeagues = q('SELECT a.group_id, COUNT(*) AS n, COUNT(DISTINCT a.user_id) AS users FROM activity_log a
                     WHERE a.created_at > ? AND a.group_id IS NOT NULL GROUP BY a.group_id ORDER BY n DESC LIMIT 8', [$since30])->fetchAll();
    $newest = q('SELECT g.id, g.name, g.created_at, u.username FROM squad_groups g LEFT JOIN users u ON u.id = g.owner_user_id
                 WHERE g.owner_user_id IS NOT NULL ORDER BY g.id DESC LIMIT 5')->fetchAll();
    $recent = q($logSelect . ' ORDER BY a.id DESC LIMIT 15')->fetchAll();
    $groups = all_groups(); ?>
<div class="kpi-grid">
  <a class="card kpi" href="platform.php?t=leghe"><small>Leghe</small><strong><?= (int) $k['leagues'] ?></strong><span class="muted small"><?= (int) $k['user_leagues'] ?> create da utenti · +<?= (int) $k['new_leagues'] ?> in 30 giorni</span></a>
  <a class="card kpi" href="platform.php?t=account"><small>Account</small><strong><?= (int) $k['accounts'] ?></strong><span class="muted small">+<?= (int) $k['new_accounts'] ?> in 30 giorni<?= (int) $k['pending'] ? ' · ' . (int) $k['pending'] . ' in attesa' : '' ?></span></a>
  <div class="card kpi"><small>Attivi</small><strong><?= (int) $k['active7'] ?></strong><span class="muted small">negli ultimi 7 giorni · <?= (int) $k['active1'] ?> oggi</span></div>
  <div class="card kpi"><small>Partite</small><strong><?= (int) $k['played'] ?></strong><span class="muted small">giocate · <?= (int) $k['upcoming'] ?> in programma</span></div>
  <div class="card kpi"><small>Scommesse</small><strong><?= (int) $k['bets30'] ?></strong><span class="muted small">negli ultimi 30 giorni</span></div>
  <a class="card kpi" href="platform.php?t=registro"><small>Operazioni</small><strong><?= (int) $k['ops1'] ?></strong><span class="muted small">nelle ultime 24 ore</span></a>
</div>

<div class="platform-cols">
  <section class="card">
    <h2><i class="ti ti-activity"></i> Cosa fanno <span class="muted small">ultimi 30 giorni</span></h2>
    <?php if (!$byAction): ?><p class="empty">Ancora nessuna operazione registrata.</p><?php endif; ?>
    <div class="bars">
      <?php foreach ($byAction as $r): ?>
        <a class="bar-row" href="platform.php?t=registro&amp;azione=<?= h(urlencode($r['action'])) ?>">
          <span class="bar-lbl small"><?= h($labels[$r['action']] ?? $r['action']) ?></span>
          <span class="bar-track"><span class="bar-fill" style="width:<?= max(2, round($r['n'] / $maxAct * 100)) ?>%"></span></span>
          <span class="bar-n small"><?= (int) $r['n'] ?></span></a>
      <?php endforeach; ?>
    </div>
  </section>
  <section class="card">
    <h2><i class="ti ti-flame"></i> Leghe più attive <span class="muted small">ultimi 30 giorni</span></h2>
    <?php if (!$topLeagues): ?><p class="empty">Nessuna attività nelle leghe.</p><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Lega</th><th>Operazioni</th><th>Persone</th></tr></thead><tbody>
      <?php foreach ($topLeagues as $r): ?><tr><td><a class="link" href="platform.php?t=leghe&amp;lega=<?= (int) $r['group_id'] ?>"><?= h($groups[(int) $r['group_id']] ?? '#' . (int) $r['group_id']) ?></a></td><td><?= (int) $r['n'] ?></td><td><?= (int) $r['users'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <h3>Ultime leghe create</h3>
    <?php if (!$newest): ?><p class="muted small">Nessuna lega creata da utenti, per ora.</p><?php endif; ?>
    <ul class="plain-list">
      <?php foreach ($newest as $r): ?><li><a class="link" href="platform.php?t=leghe&amp;lega=<?= (int) $r['id'] ?>"><?= h($r['name']) ?></a> <span class="muted small">di @<?= h((string) $r['username']) ?> · <?= fmt_date_short($r['created_at']) ?></span></li><?php endforeach; ?>
    </ul>
  </section>
</div>

<section class="card">
  <h2><i class="ti ti-list-details"></i> Ultime operazioni <a class="btn btn-ghost btn-sm" href="platform.php?t=registro">Tutte</a></h2>
  <?= activity_table($recent, $labels) ?>
</section>

<?php elseif ($tab === 'leghe' && $leagueId):
    $l = league_get($leagueId);
    if (!$l): ?><p class="empty card">Lega non trovata.</p><?php else:
    $owner = $l['owner_user_id'] ? q('SELECT u.id, u.username, u.email, u.pending_email, p.name FROM users u LEFT JOIN players p ON p.id = u.player_id WHERE u.id = ?', [$l['owner_user_id']])->fetch() : null;
    $members = q("SELECT p.id, p.name, p.photo, p.active, u.id AS uid, u.username, u.email, u.created_at AS acc_created, u.last_seen_at, gr.role,
                    (SELECT COUNT(*) FROM activity_log a WHERE a.user_id = u.id AND a.group_id = pg.group_id AND a.created_at > ?) AS ops30
                  FROM player_groups pg JOIN players p ON p.id = pg.player_id
                  LEFT JOIN users u ON u.player_id = p.id
                  LEFT JOIN group_roles gr ON gr.group_id = pg.group_id AND gr.user_id = u.id
                  WHERE pg.group_id = ? AND p.is_guest = 0
                  ORDER BY FIELD(COALESCE(gr.role, 'z'), 'owner', 'admin', 'manager', 'z'), p.name", [$since30, $leagueId])->fetchAll();
    $matches = q('SELECT id, match_date, status, score_a, score_b, team_a_name, team_b_name FROM matches WHERE group_id = ? ORDER BY match_date DESC LIMIT 10', [$leagueId])->fetchAll();
    $pend = league_pending($leagueId);
    $log = q($logSelect . ' WHERE a.group_id = ? ORDER BY a.id DESC LIMIT 100', [$leagueId])->fetchAll(); ?>
<p><a class="link" href="platform.php?t=leghe"><i class="ti ti-arrow-left"></i> Tutte le leghe</a></p>
<section class="card">
  <div class="card-head"><h2><?= h($l['name']) ?> <?= $l['owner_user_id'] ? '' : '<span class="tag">storica</span>' ?></h2>
    <div class="btn-row">
      <a class="btn btn-primary btn-sm" href="league.php?id=<?= $leagueId ?>"><i class="ti ti-settings"></i> Gestisci</a>
      <form method="post" action="group.php" class="inline"><?= csrf_field() ?><input type="hidden" name="g" value="<?= $leagueId ?>"><input type="hidden" name="back" value="index.php">
        <button class="btn btn-ghost btn-sm"><i class="ti ti-eye"></i> Guardala come membro</button></form>
    </div></div>
  <p class="small">
    <?php if ($owner): ?>Proprietario: <a class="link" href="platform.php?t=account&amp;account=<?= (int) $owner['id'] ?>"><strong><?= h($owner['name'] ?: $owner['username']) ?></strong> @<?= h($owner['username']) ?></a>
      <?= $owner['email'] ? ' · <i class="ti ti-mail-check"></i> ' . h($owner['email']) : ($owner['pending_email'] ? ' · <i class="ti ti-mail-question"></i> ' . h($owner['pending_email']) . ' (da confermare)' : ' · <span class="muted">nessuna email</span>') ?><br><?php endif; ?>
    Creata il <?= fmt_date_short($l['created_at']) ?> · ingresso: <?= $l['join_mode'] === 'libero' ? 'libero con il link' : 'con approvazione' ?>
    · richieste in attesa: <?= count($pend['registrations']) + count($pend['requests']) ?>
  </p>
</section>

<section class="card">
  <h2><i class="ti ti-users"></i> Persone collegate <span class="muted small"><?= count($members) ?></span></h2>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Giocatore</th><th>Account</th><th>Email</th><th>Ruolo</th><th>Iscritto</th><th>Ultimo accesso</th><th>Operazioni (30 gg)</th></tr></thead><tbody>
    <?php foreach ($members as $m): ?>
      <tr<?= $m['active'] ? '' : ' class="is-off"' ?>><td><?= h($m['name']) ?></td>
        <td class="small"><?= $m['uid'] ? '<a class="link" href="platform.php?t=account&amp;account=' . (int) $m['uid'] . '">@' . h($m['username']) . '</a>' : '<span class="muted">senza account</span>' ?></td>
        <td class="small"><?= h((string) $m['email']) ?></td>
        <td class="small"><?= $m['role'] ? h(LEAGUE_ROLES[$m['role']]) : ($m['uid'] ? 'Giocatore' : '—') ?></td>
        <td class="small muted"><?= $m['acc_created'] ? fmt_date_short($m['acc_created']) : '—' ?></td>
        <td class="small muted"><?= $m['last_seen_at'] ? fmt_date_short($m['last_seen_at']) . ' ' . fmt_time($m['last_seen_at']) : '—' ?></td>
        <td class="small"><?= $m['uid'] ? (int) $m['ops30'] : '' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
</section>

<section class="card">
  <h2><i class="ti ti-calendar-event"></i> Ultime partite</h2>
  <?php if (!$matches): ?><p class="empty">Nessuna partita.</p><?php else: ?>
  <ul class="plain-list">
    <?php foreach ($matches as $m): ?><li><a class="link" href="match.php?id=<?= (int) $m['id'] ?>"><?= fmt_date_short($m['match_date']) ?></a>
      <span class="small"><?= $m['status'] === 'giocata' ? h(team_name('A', $m)) . ' ' . (int) $m['score_a'] . '–' . (int) $m['score_b'] . ' ' . h(team_name('B', $m)) : '<span class="muted">in programma</span>' ?></span></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<section class="card">
  <h2><i class="ti ti-list-details"></i> Operazioni nella lega <a class="btn btn-ghost btn-sm" href="platform.php?t=registro&amp;lega=<?= $leagueId ?>">Tutte</a></h2>
  <?= activity_table($log, $labels, false) ?>
</section>
<?php endif; ?>

<?php elseif ($tab === 'leghe'):
    $qs = trim((string) ($_GET['q'] ?? ''));
    $kind = in_array($_GET['tipo'] ?? '', ['utenti', 'storiche'], true) ? $_GET['tipo'] : '';
    $where = ['1=1'];
    $params = [$since30];
    if ($qs !== '') {
        $where[] = 'g.name LIKE ?';
        $params[] = '%' . addcslashes($qs, '%_\\') . '%';
    }
    if ($kind === 'utenti') {
        $where[] = 'g.owner_user_id IS NOT NULL';
    } elseif ($kind === 'storiche') {
        $where[] = 'g.owner_user_id IS NULL';
    }
    $rows = q("SELECT g.*, u.username AS owner_username, op.name AS owner_name,
                 (SELECT COUNT(*) FROM player_groups pg WHERE pg.group_id = g.id) AS members,
                 (SELECT COUNT(*) FROM player_groups pg JOIN users uu ON uu.player_id = pg.player_id WHERE pg.group_id = g.id) AS accounts,
                 (SELECT COUNT(*) FROM matches m WHERE m.group_id = g.id AND m.status = 'giocata') AS played,
                 (SELECT COUNT(*) FROM matches m WHERE m.group_id = g.id AND m.status = 'programmata') AS scheduled,
                 (SELECT COUNT(*) FROM activity_log a WHERE a.group_id = g.id AND a.created_at > ?) AS ops30,
                 (SELECT MAX(a.created_at) FROM activity_log a WHERE a.group_id = g.id) AS last_act,
                 (SELECT COUNT(*) FROM group_requests r WHERE r.group_id = g.id) + (SELECT COUNT(*) FROM users w WHERE w.status = 'in_attesa' AND w.reg_group_id = g.id) AS pending
               FROM squad_groups g LEFT JOIN users u ON u.id = g.owner_user_id LEFT JOIN players op ON op.id = u.player_id
               WHERE " . implode(' AND ', $where) . ' ORDER BY g.id DESC LIMIT 500', $params)->fetchAll(); ?>
<form method="get" class="filter-bar"><input type="hidden" name="t" value="leghe">
  <input type="search" name="q" value="<?= h($qs) ?>" placeholder="Cerca per nome" class="mini-input" aria-label="Cerca lega">
  <select name="tipo" class="mini-select" aria-label="Tipo di lega"><option value="">Tutte</option><option value="utenti" <?= $kind === 'utenti' ? 'selected' : '' ?>>Create da utenti</option><option value="storiche" <?= $kind === 'storiche' ? 'selected' : '' ?>>Storiche</option></select>
  <button class="btn btn-ghost btn-sm"><i class="ti ti-search"></i> Filtra</button>
</form>
<section class="card table-card">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Lega</th><th>Proprietario</th><th>Creata</th><th>Giocatori</th><th>Account</th><th>Partite</th><th>Operazioni (30 gg)</th><th>Ultima attività</th><th>In attesa</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a class="link" href="platform.php?t=leghe&amp;lega=<?= (int) $r['id'] ?>"><strong><?= h($r['name']) ?></strong></a><?= $r['owner_user_id'] ? '' : ' <span class="tag">storica</span>' ?></td>
        <td class="small"><?= $r['owner_user_id'] ? '<a class="link" href="platform.php?t=account&amp;account=' . (int) $r['owner_user_id'] . '">' . h($r['owner_name'] ?: $r['owner_username']) . '</a>' : '<span class="muted">admin del sito</span>' ?></td>
        <td class="small muted"><?= fmt_date_short($r['created_at']) ?></td>
        <td><?= (int) $r['members'] ?></td><td><?= (int) $r['accounts'] ?></td>
        <td class="small"><?= (int) $r['played'] ?> giocate<?= (int) $r['scheduled'] ? ' · ' . (int) $r['scheduled'] . ' in programma' : '' ?></td>
        <td><?= (int) $r['ops30'] ?></td>
        <td class="small muted"><?= $r['last_act'] ? fmt_date_short($r['last_act']) . ' ' . fmt_time($r['last_act']) : '—' ?></td>
        <td><?= (int) $r['pending'] ? '<span class="count count-no">' . (int) $r['pending'] . '</span>' : '' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">Nessuna lega.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<?php elseif ($tab === 'account' && $accountId):
    $a = q('SELECT u.*, p.name AS player_name FROM users u LEFT JOIN players p ON p.id = u.player_id WHERE u.id = ?', [$accountId])->fetch();
    if (!$a): ?><p class="empty card">Account non trovato.</p><?php else:
    $leagues = $a['player_id'] ? q('SELECT g.id, g.name, g.owner_user_id, gr.role FROM player_groups pg JOIN squad_groups g ON g.id = pg.group_id
                                    LEFT JOIN group_roles gr ON gr.group_id = g.id AND gr.user_id = ? WHERE pg.player_id = ? ORDER BY g.name', [$accountId, $a['player_id']])->fetchAll() : [];
    $requests = q('SELECT g.id, g.name, r.created_at FROM group_requests r JOIN squad_groups g ON g.id = r.group_id WHERE r.user_id = ?', [$accountId])->fetchAll();
    $stats = q("SELECT (SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND created_at > ?) AS ops30,
                       (SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND action = 'accesso') AS logins,
                       (SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?) AS devices", [$accountId, $since30, $accountId, $accountId])->fetch();
    $log = q($logSelect . ' WHERE a.user_id = ? ORDER BY a.id DESC LIMIT 100', [$accountId])->fetchAll(); ?>
<p><a class="link" href="platform.php?t=account"><i class="ti ti-arrow-left"></i> Tutti gli account</a></p>
<section class="card">
  <h2><?= h($a['player_name'] ?: $a['username']) ?> <span class="muted small">@<?= h($a['username']) ?></span> <?= $a['role'] !== 'player' ? '<span class="tag tag-admin">' . h(role_label($a['role'])) . '</span>' : '' ?> <?= $a['status'] !== 'attivo' ? '<span class="tag">in attesa</span>' : '' ?></h2>
  <p class="small">
    <?= $a['email'] ? '<i class="ti ti-mail-check"></i> ' . h($a['email']) : ($a['pending_email'] ? '<i class="ti ti-mail-question"></i> ' . h($a['pending_email']) . ' (da confermare)' : '<span class="muted"><i class="ti ti-mail-off"></i> nessuna email</span>') ?><br>
    Creato il <?= fmt_date_short($a['created_at']) ?> · ultimo accesso: <?= $a['last_seen_at'] ? fmt_date_short($a['last_seen_at']) . ' ' . fmt_time($a['last_seen_at']) : '—' ?>
    · accessi registrati: <?= (int) $stats['logins'] ?> · operazioni negli ultimi 30 giorni: <?= (int) $stats['ops30'] ?> · dispositivi con notifiche: <?= (int) $stats['devices'] ?>
  </p>
  <h3>Leghe</h3>
  <?php if (!$leagues): ?><p class="muted small">Non fa parte di nessuna lega.</p><?php endif; ?>
  <ul class="plain-list">
    <?php foreach ($leagues as $g): ?><li><a class="link" href="platform.php?t=leghe&amp;lega=<?= (int) $g['id'] ?>"><?= h($g['name']) ?></a> <span class="small muted"><?= $g['role'] ? h(LEAGUE_ROLES[$g['role']]) : 'Giocatore' ?><?= $g['owner_user_id'] ? '' : ' · storica' ?></span></li><?php endforeach; ?>
    <?php foreach ($requests as $g): ?><li><?= h($g['name']) ?> <span class="small muted">richiesta in attesa dal <?= fmt_date_short($g['created_at']) ?></span></li><?php endforeach; ?>
  </ul>
  <h3>Account</h3>
  <div class="btn-row">
    <form method="post" class="inline pw-form"><?= csrf_field() ?><input type="hidden" name="do" value="reset"><input type="hidden" name="user_id" value="<?= $accountId ?>">
      <input type="text" name="password" placeholder="nuova password" minlength="8" maxlength="72" required class="mini-input" autocomplete="off" aria-label="Nuova password">
      <button class="btn btn-ghost btn-sm">Reimposta password</button></form>
    <?php if ($accountId !== (int) current_user()['id']): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="delete_account"><input type="hidden" name="user_id" value="<?= $accountId ?>">
        <button class="btn btn-danger btn-sm" data-confirm="Eliminare l'account @<?= h($a['username']) ?>? Il giocatore e le sue statistiche restano."><i class="ti ti-trash"></i> Elimina account</button></form>
    <?php endif; ?>
  </div>
  <p class="small muted">Il ruolo nel sito (admin, manager) si cambia da <a class="link" href="admin.php">Admin</a>; i ruoli dentro una lega da «Gestisci» della lega.</p>
</section>
<section class="card">
  <h2><i class="ti ti-list-details"></i> Operazioni dell'account</h2>
  <?= activity_table($log, $labels) ?>
</section>
<?php endif; ?>

<?php elseif ($tab === 'account'):
    $qs = trim((string) ($_GET['q'] ?? ''));
    $page = max(1, int_get('p'));
    $per = 100;
    $where = "u.role <> 'ospite'";
    $params = [];
    if ($qs !== '') {
        $where .= ' AND (u.username LIKE ? OR p.name LIKE ? OR u.email LIKE ?)';
        $like = '%' . addcslashes($qs, '%_\\') . '%';
        $params = [$like, $like, $like];
    }
    $total = (int) q("SELECT COUNT(*) FROM users u LEFT JOIN players p ON p.id = u.player_id WHERE $where", $params)->fetchColumn();
    $rows = q("SELECT u.id, u.username, u.email, u.pending_email, u.role, u.status, u.created_at, u.last_seen_at, p.name AS player_name,
                 (SELECT GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR ', ') FROM player_groups pg JOIN squad_groups g ON g.id = pg.group_id WHERE pg.player_id = u.player_id) AS leagues,
                 (SELECT GROUP_CONCAT(g.name SEPARATOR ', ') FROM squad_groups g WHERE g.owner_user_id = u.id) AS owns
               FROM users u LEFT JOIN players p ON p.id = u.player_id WHERE $where
               ORDER BY COALESCE(u.last_seen_at, u.created_at) DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params)->fetchAll(); ?>
<form method="get" class="filter-bar"><input type="hidden" name="t" value="account">
  <input type="search" name="q" value="<?= h($qs) ?>" placeholder="Username, nome o email" class="mini-input" aria-label="Cerca account">
  <button class="btn btn-ghost btn-sm"><i class="ti ti-search"></i> Cerca</button>
  <span class="muted small"><?= $total ?> account</span>
</form>
<section class="card table-card">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Account</th><th>Email</th><th>Leghe</th><th>Creato</th><th>Ultimo accesso</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a class="link" href="platform.php?t=account&amp;account=<?= (int) $r['id'] ?>"><strong><?= h($r['player_name'] ?: $r['username']) ?></strong></a> <span class="muted small">@<?= h($r['username']) ?></span>
          <?= $r['role'] !== 'player' ? '<span class="tag tag-admin">' . h(role_label($r['role'])) . '</span>' : '' ?><?= $r['status'] !== 'attivo' ? ' <span class="tag">in attesa</span>' : '' ?></td>
        <td class="small"><?= $r['email'] ? h($r['email']) : ($r['pending_email'] ? '<span class="muted">' . h($r['pending_email']) . ' (da confermare)</span>' : '<span class="muted">—</span>') ?></td>
        <td class="small"><?= h((string) $r['leagues']) ?><?= $r['owns'] ? '<br><span class="muted"><i class="ti ti-crown"></i> ' . h($r['owns']) . '</span>' : '' ?></td>
        <td class="small muted"><?= fmt_date_short($r['created_at']) ?></td>
        <td class="small muted"><?= $r['last_seen_at'] ? fmt_date_short($r['last_seen_at']) . ' ' . fmt_time($r['last_seen_at']) : '—' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">Nessun account.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php if ($total > $per): ?><div class="btn-row">
  <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= h($self(['t' => 'account', 'q' => $qs, 'p' => $page - 1])) ?>">← Precedenti</a><?php endif; ?>
  <?php if ($page * $per < $total): ?><a class="btn btn-ghost btn-sm" href="<?= h($self(['t' => 'account', 'q' => $qs, 'p' => $page + 1])) ?>">Successivi →</a><?php endif; ?>
</div><?php endif; ?>

<?php else:   // registro
    $fLeague = int_get('lega');
    $fAction = isset($labels[$_GET['azione'] ?? '']) ? $_GET['azione'] : '';
    $fUser = trim((string) ($_GET['chi'] ?? ''));
    $page = max(1, int_get('p'));
    $per = 100;
    $where = ['1=1'];
    $params = [];
    if ($fLeague) {
        $where[] = 'a.group_id = ?';
        $params[] = $fLeague;
    }
    if ($fAction !== '') {
        $where[] = 'a.action = ?';
        $params[] = $fAction;
    }
    if ($fUser !== '') {
        $where[] = '(u.username LIKE ? OR p.name LIKE ?)';
        $like = '%' . addcslashes($fUser, '%_\\') . '%';
        array_push($params, $like, $like);
    }
    $rows = q($logSelect . ' WHERE ' . implode(' AND ', $where) . " ORDER BY a.id DESC LIMIT " . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), $params)->fetchAll();
    $more = count($rows) > $per;
    $rows = array_slice($rows, 0, $per);
    $filters = ['t' => 'registro', 'lega' => $fLeague ?: null, 'azione' => $fAction ?: null, 'chi' => $fUser ?: null]; ?>
<form method="get" class="filter-bar"><input type="hidden" name="t" value="registro">
  <select name="lega" class="mini-select" aria-label="Lega"><option value="">Tutte le leghe</option>
    <?php foreach (all_groups() as $gid => $gname): ?><option value="<?= $gid ?>" <?= $fLeague === $gid ? 'selected' : '' ?>><?= h($gname) ?></option><?php endforeach; ?></select>
  <select name="azione" class="mini-select" aria-label="Operazione"><option value="">Tutte le operazioni</option>
    <?php foreach ($labels as $k => $l): ?><option value="<?= h($k) ?>" <?= $fAction === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
  <input type="search" name="chi" value="<?= h($fUser) ?>" placeholder="Chi (username o nome)" class="mini-input" aria-label="Chi">
  <button class="btn btn-ghost btn-sm"><i class="ti ti-filter"></i> Filtra</button>
</form>
<section class="card"><?= activity_table($rows, $labels) ?></section>
<div class="btn-row">
  <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= h($self(array_filter($filters + ['p' => $page - 1]))) ?>">← Più recenti</a><?php endif; ?>
  <?php if ($more): ?><a class="btn btn-ghost btn-sm" href="<?= h($self(array_filter($filters + ['p' => $page + 1]))) ?>">Più vecchie →</a><?php endif; ?>
</div>
<p class="muted small">Il registro tiene le operazioni dell'ultimo anno. Gli accessi, le iscrizioni, la gestione delle leghe e delle partite, voti, presenze, scommesse, negozio e modifiche agli account.</p>
<?php endif; ?>
<?php
layout_end();
