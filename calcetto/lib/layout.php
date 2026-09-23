<?php
function layout_start(string $title, string $active = ''): void
{
    $u = current_user();
    $nav = [
        'home' => ['index.php', 'Home', 'home'],
        'matches' => ['matches.php', 'Partite', 'calendar-event'],
        'players' => ['players.php', 'Rosa', 'shirt'],
        'standings' => ['standings.php', 'Classifica', 'trophy'],
        'bets' => ['bets.php', 'Scommesse', 'coin'],
        'shop' => ['shop.php', 'Negozio', 'shopping-bag'],
        'curiosities' => ['curiosities.php', 'Curiosità', 'bulb'],
    ];
    if (is_admin()) {
        $nav['payments'] = ['payments.php', 'Pagamenti', 'cash'];
        $nav['admin'] = ['admin.php', 'Admin', 'settings'];
        $pending = pending_count();
    }
    // pulsante con la campanella: attiva/disattiva le notifiche (o porta alla scheda che le spiega)
    $notifHref = ($u && push_supported()) ? ((my_player_id() ? 'player_edit.php?id=' . my_player_id() : 'profile.php') . '#notifiche') : '';
    // gettoni delle scommesse: sempre visibili accanto al profilo, non solo nella pagina Scommesse
    $myId = my_player_id();
    if ($u && $myId) {
        $dole = wallet_open($myId);
        if ($dole) {
            flash('ok', $dole);
        }
    }
    $coins = $myId ? wallet_balance($myId) : null;
    // versione = impronta del contenuto (non la data): se un upload FTP viene letto a metà, il browser non conserva
    // per 30 giorni un file troncato con lo stesso indirizzo di quello completo
    $ver = fn(string $f) => substr((string) @md5_file(__DIR__ . '/../assets/' . $f), 0, 10);
    $css = 'assets/style.css?v=' . $ver('style.css');
    $js = 'assets/app.js?v=' . $ver('app.js');
    ?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ffd23f">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" type="image/png" href="assets/icons/icon-192.png">
<link rel="apple-touch-icon" href="assets/icons/icon-180.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Calcetto">
<?php if (($pushUid = push_account_id()) && push_supported() && ($pushKey = vapid_public_key()) !== ''): ?>
<meta name="push-key" content="<?= h($pushKey) ?>">
<meta name="push-user" content="<?= (int) $pushUid ?>">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<script src="assets/push.js?v=<?= h($ver('push.js')) ?>" defer></script>
<?php endif; ?>
<title><?= h($title) ?> · <?= h(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<!-- Google Fonts: Lilita One (titoli, stile fumetto) + Nunito (testo) -->
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@500;700;800;900&display=swap" rel="stylesheet">
<!-- Tabler Icons: icone disegnate a tratto (tabler.io/icons) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
<link rel="stylesheet" href="<?= h($css) ?>">
<script src="<?= h($js) ?>" defer></script>
<noscript><style>@media (max-width: 799px) { .nav-toggle { display: none; } .nav { display: flex; position: static; flex-direction: row; grid-column: 1 / -1; overflow-x: auto; box-shadow: none; background: none; padding: 0 0 6px; border: 0; } .nav-extra { display: none !important; } }</style></noscript>
</head>
<body>
<header class="topbar<?= count($nav) > 6 ? ' nav-many' : '' ?>">
  <div class="topbar-inner">
    <?php if ($u || PUBLIC_READ): ?>
    <button type="button" class="nav-toggle" aria-label="Apri il menu" aria-expanded="false" aria-controls="site-nav"><i class="ti ti-menu-2"></i></button>
    <?php endif; ?>
    <a class="brand" href="index.php"><span class="brand-ball"><i class="ti ti-ball-football"></i></span><?= h(APP_NAME) ?></a>
    <?php if ($u || PUBLIC_READ): ?>
    <nav class="nav" id="site-nav">
      <?php foreach ($nav as $key => [$href, $label, $icon]): ?>
        <a href="<?= $href ?>" class="<?= $key === $active ? 'active' : '' ?>" title="<?= h($label) ?>"><i class="ti ti-<?= $icon ?>"></i><span class="nav-label"><?= $label ?></span><?php if ($key === 'admin' && !empty($pending)): ?><span class="nav-badge" title="Iscrizioni da approvare"><?= (int) $pending ?></span><?php endif; ?></a>
      <?php endforeach; ?>
      <?php if ($u): ?>
        <?php /* sui telefoni "?" e uscita non stanno accanto al titolo: si trovano in fondo al menu */ ?>
        <?php if ($notifHref): ?><a href="<?= h($notifHref) ?>" class="nav-extra" data-push-bell><i class="ti ti-bell"></i><span class="nav-label">Notifiche</span></a><?php endif; ?>
        <a href="index.php?tour=1" class="nav-extra"><i class="ti ti-help"></i><span class="nav-label">Rivedi il tutorial</span></a>
        <a href="logout.php" class="nav-extra"><i class="ti ti-logout"></i><span class="nav-label">Esci</span></a>
      <?php endif; ?>
    </nav>
    <?php else: ?><div class="nav"></div><?php endif; ?>
    <div class="userbox">
      <?php if ($u): ?>
        <a href="profile.php" class="me <?= $active === 'profile' ? 'active' : '' ?>">
          <?= avatar(['name' => $u['player_name'] ?: $u['username'], 'photo' => $u['photo']], 'xs') ?>
          <span><?= h($u['player_name'] ?: $u['username']) ?></span>
          <?php if ($u['role'] !== 'player'): ?><span class="tag tag-admin"><?= h(strtolower(role_label($u['role']))) ?></span><?php endif; ?>
        </a>
        <?php if ($coins !== null): ?><a href="shop.php" class="coin-pill" title="I tuoi gettoni: si spendono nel Negozio"><i class="ti ti-coin"></i> <?= $coins ?></a><?php endif; ?>
        <?php if (!empty($pending)): ?><a href="admin.php" class="nav-badge pending-dot" title="Iscrizioni da approvare" aria-label="<?= (int) $pending ?> iscrizioni da approvare"><?= (int) $pending ?></a><?php endif; ?>
        <?php if ($notifHref): ?><a href="<?= h($notifHref) ?>" class="btn btn-ghost btn-sm" data-push-bell title="Notifiche" aria-label="Notifiche"><i class="ti ti-bell"></i></a><?php endif; ?>
        <a href="index.php?tour=1" class="btn btn-ghost btn-sm" title="Rivedi il tutorial" aria-label="Rivedi il tutorial"><i class="ti ti-help"></i></a>
        <a href="logout.php" class="btn btn-ghost btn-sm" title="Esci"><i class="ti ti-logout"></i></a>
      <?php else: ?>
        <a href="login.php" class="btn btn-primary btn-sm">Accedi</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<main class="wrap">
<?php if ($u && is_admin() && (int) meta_get('schema') < SCHEMA_VERSION): ?>
  <div class="flash flash-err"><i class="ti ti-database-exclamation"></i> L'ultimo aggiornamento del database non è andato a buon fine (schema v<?= (int) meta_get('schema') ?>, serve v<?= SCHEMA_VERSION ?>): qualche funzione nuova potrebbe non funzionare.
    <?php if ($schemaErr = meta_get('schema_error')): ?><br><small>Errore: <code><?= h($schemaErr) ?></code></small><?php endif; ?></div>
<?php endif; ?>
<?php if ($u && !is_admin() && !allowed_group_ids()): ?>
  <div class="flash flash-err"><i class="ti ti-users-group"></i> Non fai ancora parte di nessun gruppo: chiedi all'admin di assegnarti al tuo, poi vedrai giocatori e partite.</div>
<?php endif; ?>
<?php foreach (take_flashes() as [$type, $msg]): ?>
  <div class="flash flash-<?= h($type) ?>"><?= h($msg) ?></div>
<?php endforeach;
}

function layout_end(): void
{
    $u = current_user();
    ?>
</main>
<footer class="footer"><?= h(APP_NAME) ?> · <?= date('Y') ?></footer>
<?php if (tour_wanted($u)): ?>
<script type="application/json" id="tour-data"><?= json_encode(
    ['endpoint' => 'tour.php', 'csrf' => csrf_token(), 'steps' => tour_steps($u)],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<?php endif; ?>
</body>
</html>
<?php
}

/** Pulsanti "Ci sono / Non ci sono" del giocatore collegato. */
function availability_buttons(array $match, ?string $myStatus, string $back): string
{
    if (!my_player_id() || $match['status'] !== 'programmata') {
        return '';
    }
    $btn = function (string $status, string $label, string $cls) use ($match, $myStatus, $back) {
        $on = $myStatus === $status;
        return '<form method="post" action="action.php">' . csrf_field() .
            '<input type="hidden" name="do" value="availability">' .
            '<input type="hidden" name="match_id" value="' . (int) $match['id'] . '">' .
            '<input type="hidden" name="status" value="' . ($on ? 'in_attesa' : $status) . '">' .
            '<input type="hidden" name="back" value="' . h($back) . '">' .
            '<button class="btn ' . $cls . ($on ? ' is-on' : '') . '">' . $label . '</button></form>';
    };
    $txt = ['confermato' => 'Hai confermato', 'assente' => 'Hai detto che non ci sei'][$myStatus] ?? 'Non hai ancora risposto';
    return '<div class="avail"><span class="avail-label">' . $txt . '</span><div class="avail-btns">' .
        $btn('confermato', '<i class="ti ti-check"></i> Ci sono', 'btn-yes') . $btn('assente', '<i class="ti ti-x"></i> Non ci sono', 'btn-no') . '</div></div>';
}

/** Riga giocatore compatta per le liste (presenze, squadre). */
function player_line(array $p, string $extra = ''): string
{
    $num = $p['shirt_number'] !== null && $p['shirt_number'] !== '' ? '<span class="num">' . (int) $p['shirt_number'] . '</span>' : '';
    return '<a class="pline" href="player.php?id=' . (int) ($p['player_id'] ?? $p['id']) . '">' .
        avatar($p, 'sm') . '<span class="pline-name">' . h($p['name']) . '</span>' . $num .
        '<span class="pos pos-' . strtolower(position_abbr($p['position'] ?? 'Jolly')) . '" title="Posizione preferita">' .
        position_abbr($p['position'] ?? 'Jolly') . '</span>' .
        (!empty($p['position2']) ? '<span class="pos pos-' . strtolower(position_abbr($p['position2'])) . '" title="Seconda scelta">' .
            position_abbr($p['position2']) . '</span>' : '') . $extra . '</a>';
}

/**
 * Riquadro per attivare le notifiche push su questo dispositivo (lo riempie assets/app.js).
 * $banner = versione compatta per la Home, che sparisce da sola una volta scelto.
 */
function push_card(bool $banner = false, bool $waiting = false): string
{
    if (!push_account_id() || !push_supported()) {
        return '';
    }
    if ($banner) {
        return '<section class="card push-banner" data-push-card data-push-banner hidden>'
            . '<i class="ti ti-bell-ringing push-ic"></i>'
            . '<div class="push-txt"><strong>Vuoi le notifiche?</strong> <span class="muted small">Ti avvisiamo quando c\'è una partita a cui non hai risposto e quando aprono o chiudono le votazioni.</span></div>'
            . '<div class="btn-row"><button type="button" class="btn btn-primary btn-sm" data-push-toggle>Attiva</button>'
            . '<button type="button" class="btn btn-ghost btn-sm" data-push-dismiss>Non ora</button></div></section>';
    }
    // $waiting = appena iscritto, in attesa dell'approvazione: la notifica gli dice quando può entrare
    return '<section class="card push-card" data-push-card id="notifiche">'
        . '<h2><i class="ti ti-bell"></i> Notifiche</h2>'
        . ($waiting
            ? '<p class="muted small">Vuoi sapere subito quando l\'admin approva la tua iscrizione? Attiva le notifiche su questo dispositivo: ti arriverà un avviso appena puoi entrare.</p>'
            : '<p class="muted small">Ti avvisiamo con una notifica sul telefono quando c\'è una partita a cui non hai ancora risposto e quando aprono o chiudono le votazioni. '
              . 'L\'attivazione vale per questo dispositivo: se usi più telefoni o computer, attivala su ognuno.</p>')
        . '<p class="small push-status" data-push-status>Controllo…</p>'
        . '<div class="btn-row"><button type="button" class="btn btn-primary btn-sm" data-push-toggle hidden></button>'
        . '<button type="button" class="btn btn-ghost btn-sm" data-push-test hidden><i class="ti ti-send"></i> Invia una notifica di prova</button></div>'
        . '</section>';
}

/** Riquadro «Sicurezza» (email e password) con il collegamento alla pagina account.php. */
function security_card(): string
{
    $u = current_user();
    if (!$u) {
        return '';
    }
    $state = !empty($u['email']) ? '<i class="ti ti-circle-check acc-ok"></i> Email confermata: <strong>' . h($u['email']) . '</strong>'
        : (!empty($u['pending_email']) ? '<i class="ti ti-hourglass"></i> Email da confermare: <strong>' . h($u['pending_email']) . '</strong>'
        : '<i class="ti ti-alert-triangle"></i> Nessuna email: se dimentichi la password dovrai chiedere all\'admin.');
    return '<section class="card" id="sicurezza"><h2><i class="ti ti-shield-lock"></i> Sicurezza</h2><p class="small">' . $state . '</p>'
        . '<p class="muted small">Email per recuperare la password, cambio password e uscita dagli altri dispositivi.</p>'
        . '<a class="btn btn-ghost btn-sm" href="account.php"><i class="ti ti-settings"></i> Email, password e dispositivi</a></section>';
}
