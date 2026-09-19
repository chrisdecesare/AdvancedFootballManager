<?php
function layout_start(string $title, string $active = ''): void
{
    $u = current_user();
    $nav = [
        'home' => ['index.php', 'Home', 'home'],
        'matches' => ['matches.php', 'Partite', 'calendar-event'],
        'players' => ['players.php', 'Rosa', 'shirt'],
        'standings' => ['standings.php', 'Classifica', 'trophy'],
    ];
    if (is_admin()) {
        $nav['payments'] = ['payments.php', 'Pagamenti', 'cash'];
        $nav['admin'] = ['admin.php', 'Admin', 'settings'];
        $pending = pending_count();
    }
    $css = 'assets/style.css?v=' . @filemtime(__DIR__ . '/../assets/style.css');
    $js = 'assets/app.js?v=' . @filemtime(__DIR__ . '/../assets/app.js');
    ?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ffd23f">
<title><?= h($title) ?> · <?= h(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<!-- Google Fonts: Lilita One (titoli, stile fumetto) + Nunito (testo) -->
<link href="https://fonts.googleapis.com/css2?family=Lilita+One&family=Nunito:wght@500;700;800;900&display=swap" rel="stylesheet">
<!-- Tabler Icons: icone disegnate a tratto (tabler.io/icons) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
<link rel="stylesheet" href="<?= h($css) ?>">
<script src="<?= h($js) ?>" defer></script>
</head>
<body>
<header class="topbar<?= is_admin() ? ' topbar-admin' : '' ?>">
  <div class="topbar-inner">
    <a class="brand" href="index.php"><span class="brand-ball"><i class="ti ti-ball-football"></i></span><?= h(APP_NAME) ?></a>
    <?php if ($u || PUBLIC_READ): ?>
    <nav class="nav">
      <?php foreach ($nav as $key => [$href, $label, $icon]): ?>
        <a href="<?= $href ?>" class="<?= $key === $active ? 'active' : '' ?>"><i class="ti ti-<?= $icon ?>"></i><?= $label ?><?php if ($key === 'admin' && !empty($pending)): ?><span class="nav-badge" title="Iscrizioni da approvare"><?= (int) $pending ?></span><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <?php else: ?><div class="nav"></div><?php endif; ?>
    <div class="userbox">
      <?php if ($u): ?>
        <a href="profile.php" class="me <?= $active === 'profile' ? 'active' : '' ?>">
          <?= avatar(['name' => $u['player_name'] ?: $u['username'], 'photo' => $u['photo']], 'xs') ?>
          <span><?= h($u['player_name'] ?: $u['username']) ?></span>
          <?php if ($u['role'] === 'admin'): ?><span class="tag tag-admin">admin</span><?php endif; ?>
        </a>
        <?php if (!empty($pending)): ?><a href="admin.php" class="nav-badge pending-dot" title="Iscrizioni da approvare" aria-label="<?= (int) $pending ?> iscrizioni da approvare"><?= (int) $pending ?></a><?php endif; ?>
        <a href="index.php?tour=1" class="btn btn-ghost btn-sm" title="Rivedi il tutorial" aria-label="Rivedi il tutorial"><i class="ti ti-help"></i></a>
        <a href="logout.php" class="btn btn-ghost btn-sm" title="Esci"><i class="ti ti-logout"></i></a>
      <?php else: ?>
        <a href="login.php" class="btn btn-primary btn-sm">Accedi</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<main class="wrap">
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
