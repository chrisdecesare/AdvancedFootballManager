<?php
/*
 * Fanta: il fantacalcio della lega (regole e calcoli in lib/fanta.php). Schede:
 *  - squadra: la mia rosa (titolari, panchina, capitano), il budget e i punti partita per partita;
 *  - mercato: tutte le figurine della lega con prezzo e rendimento, da comprare e vendere;
 *  - classifica: tutti contro tutti, e la rosa di ogni squadra (?m=id);
 *  - scambi: proposte ricevute e fatte, e una nuova proposta a una squadra (?con=id);
 *  - premi: cosa si vince, regolamento e albo d'oro.
 * Chi amministra la lega apre e chiude le stagioni da qui.
 */
require __DIR__ . '/lib/bootstrap.php';
require_login();
if (is_guest() || !fanta_visible()) {
    redirect('index.php');
}

$gid = fanta_group((int) ($_GET['g'] ?? $_POST['g'] ?? 0));
$tabs = ['squadra' => ['La mia squadra', 'shirt-sport'], 'mercato' => ['Mercato', 'building-store'], 'classifica' => ['Classifica', 'trophy'],
    'scambi' => ['Scambi', 'arrows-exchange'], 'premi' => ['Premi e regole', 'award']];
$tab = isset($tabs[$_GET['t'] ?? '']) ? (string) $_GET['t'] : 'squadra';
$me = my_player_id();

if (!$gid) {
    layout_start('Fanta', 'fanta');
    echo '<div class="page-head"><h1>Fanta</h1></div><p class="empty card">Per giocare al Fanta devi far parte di una lega.</p>';
    layout_end();
    exit;
}

$season = fanta_season($gid);
$canPlay = fanta_can_play($me, $gid);
$isAdm = can_admin_group($gid);
$back = fn(array $set = []) => 'fanta.php?' . http_build_query(array_filter(['t' => $tab, 'g' => $gid] + $set, fn($v) => $v !== '' && $v !== null));

/* ---------------------------------------------------------------- azioni */
if (is_post()) {
    $do = (string) ($_POST['do'] ?? '');
    $pid = (int) ($_POST['pid'] ?? 0);
    $name = fn(int $id) => (string) (get_player($id)['name'] ?? '?');
    $err = null;
    $ok = null;
    if (in_array($do, ['open', 'close'], true)) {
        if (!$isAdm) {
            $err = 'Solo chi amministra la lega apre e chiude le stagioni.';
        } elseif ($do === 'open') {
            $err = fanta_open_season($gid);
            $ok = 'Stagione aperta: le quote di adesso sono le quote base, fate le vostre squadre!';
        } else {
            $err = fanta_close_season($gid);
            $ok = 'Stagione chiusa: i primi ' . FANTA_PRIZE_RANKS . ' hanno ricevuto i premi.';
        }
    } elseif (!$season) {
        $err = 'Non c\'è una stagione aperta.';
    } elseif (!$canPlay) {
        $err = 'Per giocare al Fanta ti serve un giocatore in questa lega.';
    } else {
        switch ($do) {
            case 'buy':
                $err = fanta_buy($season, $me, $pid);
                $ok = $name($pid) . ' è nella tua squadra!';
                break;
            case 'sell':
                $sold = fanta_price_list($gid)[$pid] ?? 1;
                $err = fanta_sell($season, $me, $pid);
                $ok = 'Hai venduto ' . $name($pid) . ' a ' . fanta_cr($sold) . '.';
                break;
            case 'bench':
                $err = fanta_set_bench($season, $me, $pid);
                $ok = $name($pid) . ' va in panchina.';
                break;
            case 'captain':
                $err = fanta_set_captain($season, $me, $pid);
                $ok = $name($pid) . ' è il tuo capitano: bonus e malus doppi.';
                break;
            case 'propose':
                $err = fanta_trade_propose($season, $me, (int) ($_POST['to'] ?? 0), (int) ($_POST['give'] ?? 0), (int) ($_POST['want'] ?? 0));
                $ok = 'Proposta inviata: ora tocca all\'altra squadra.';
                break;
            case 'accetta':
            case 'rifiuta':
            case 'ritira':
                $err = fanta_trade_answer((int) ($_POST['trade'] ?? 0), $me, $do);
                $ok = ['accetta' => 'Scambio fatto!', 'rifiuta' => 'Proposta rifiutata.', 'ritira' => 'Proposta ritirata.'][$do];
                break;
            default:
                $err = 'Operazione non valida.';
        }
        if (!$err && in_array($do, ['buy', 'sell', 'propose', 'accetta'], true)) {
            log_activity('fanta', $do . ($pid ? ' · ' . $name($pid) : ''), $gid);
        }
    }
    flash($err ? 'err' : 'ok', $err ?: (string) $ok);
    redirect($back(['con' => (int) ($_POST['to'] ?? 0) ?: '', 'm' => (int) ($_POST['m'] ?? 0) ?: '']));
}

/* ---------------------------------------------------------------- dati */
$cards = fanta_cards($gid);
$quotes = fanta_price_list($gid);                           // quota attuale (ultima prestazione): a questa si compra e si vende
$prices = $season ? fanta_prices($season) : $quotes;       // quota base (all'apertura della stagione)
$form = fanta_form($gid);
$sid = $season ? (int) $season['id'] : 0;
$playersById = $cards;
$pl = function (int $id) use (&$playersById): array {
    return $playersById[$id] ??= (get_player($id) ?: ['id' => $id, 'name' => '?', 'photo' => null, 'shirt_number' => null, 'position' => '', 'avatar_look' => null, 'hat_key' => null]);
};
$owners = [];
$standings = [];
$seasonMatches = [];
$seasonPts = [];   // punti di ogni figurina nella stagione (come giocatore, senza capitano)
if ($season) {
    foreach (q('SELECT player_id, COUNT(*) n FROM fanta_picks WHERE season_id = ? GROUP BY player_id', [$sid])->fetchAll() as $r) {
        $owners[(int) $r['player_id']] = (int) $r['n'];
    }
    $standings = fanta_standings($sid);
    $seasonMatches = fanta_season_matches($sid);
    foreach ($seasonMatches as $m) {
        foreach (fanta_match_points((int) $m['id']) as $p => $pt) {
            $seasonPts[$p] = ($seasonPts[$p] ?? 0) + $pt['pts'];
        }
    }
}
$myRoster = $season && $canPlay ? fanta_roster($sid, $me) : [];
$myIds = array_map(fn($r) => (int) $r['player_id'], $myRoster);
$left = $season && $canPlay ? fanta_credits_left($sid, $me) : FANTA_BUDGET;
$myRank = null;
foreach ($standings as $r) {
    if ($r['manager_id'] === $me) {
        $myRank = $r;
    }
}
$next = q("SELECT * FROM matches WHERE group_id = ? AND status = 'programmata' AND match_date > ? ORDER BY match_date LIMIT 1", [$gid, date('Y-m-d H:i:s')])->fetch() ?: null;
$trades = $season && $canPlay ? fanta_open_trades($sid, $me) : [];
$incoming = count(array_filter($trades, fn($t) => (int) $t['to_id'] === $me));

/** La figurina: il giocatore in pixel art, quota attuale (e base), nome, numeri; $extra sotto (pulsanti). 'cost' = quanto l'hai pagata. */
$card = function (int $pid, string $extra = '', array $o = []) use ($pl, $prices, $quotes, $form, $seasonPts, $owners, $season): string {
    $p = $pl($pid);
    $f = $form[$pid] ?? ['avg' => 0, 'apps' => 0, 'rate' => 0];
    $price = $quotes[$pid] ?? 1;
    $base = $prices[$pid] ?? $price;
    $pct = (int) round($f['rate'] * 100);
    $trend = $price <=> $base;
    $badge = !empty($o['captain']) ? '<span class="fz-badge fz-cap" title="Capitano: bonus e malus doppi">C</span>'
        : (!empty($o['bench']) ? '<span class="fz-badge fz-bench" title="In panchina: entra se un titolare non gioca">P</span>' : '');
    return '<article class="fz-card fz-p' . (int) $price . (!empty($o['mine']) ? ' is-mine' : '') . (!empty($o['bench']) ? ' is-bench' : '') . '">'
        . '<span class="fz-price" title="Quota attuale: si compra e si vende a questa">' . (int) $price . '</span>' . $badge
        . '<a class="fz-fig" href="player.php?id=' . $pid . '">' . avatar_figure(avatar_look($p), ['number' => $p['shirt_number'], 'label' => $p['name']]) . '</a>'
        . '<div class="fz-name">' . h($p['name']) . '</div>'
        . '<div class="fz-pos">' . h((string) ($p['position'] ?? '')) . '</div>'
        . '<div class="fz-quote">' . ($season ? '<span title="Quota all\'apertura della stagione">base ' . (int) $base . '</span>' : '')
        . ($trend ? ' <i class="ti ti-trending-' . ($trend > 0 ? 'up is-up' : 'down is-down') . '" title="' . ($trend > 0 ? 'In salita' : 'In discesa') . '"></i>' : '')
        . (isset($o['cost']) ? ' <span title="Quanto l\'hai pagata">· pagata ' . (int) $o['cost'] . '</span>' : '') . '</div>'
        . '<dl class="fz-stats">'
        . ($season ? '<div><dt>Stagione</dt><dd>' . fanta_fmt((float) ($seasonPts[$pid] ?? 0)) . '</dd></div>' : '')
        . '<div><dt title="Punti fanta a partita giocata, ultime ' . FANTA_FORM_MATCHES . ' partite">Media</dt><dd>' . ($f['apps'] ? fanta_fmt($f['avg']) : '–') . '</dd></div>'
        . '<div class="fz-att"><span class="fz-pct is-' . ($pct >= FANTA_ATT_GOOD ? 'hi' : ($pct >= FANTA_ATT_OK ? 'mid' : 'lo')) . '" title="Presenze: ha giocato il ' . $pct
        . '% delle ultime ' . FANTA_FORM_MATCHES . ' partite della lega">' . $pct . '%</span></div>'
        . ($season ? '<div><dt title="In quante squadre del Fanta c\'è">Rose</dt><dd>' . ($owners[$pid] ?? 0) . '</dd></div>' : '')
        . '</dl>' . $extra . '</article>';
};
$form_btn = function (string $do, int $pid, string $label, string $cls = 'btn-ghost', string $confirm = '') use ($gid): string {
    return '<form method="post" class="fz-act">' . csrf_field() . '<input type="hidden" name="g" value="' . $gid . '"><input type="hidden" name="do" value="' . $do . '">'
        . '<input type="hidden" name="pid" value="' . $pid . '"><button class="btn btn-sm ' . $cls . '"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . $label . '</button></form>';
};

layout_start('Fanta', 'fanta');
?>
<div class="page-head">
  <h1>Fanta <?php if ($season): ?><span class="muted small">stagione <?= (int) $season['n'] ?></span><?php endif; ?></h1>
  <?php if (!fanta_public()): ?><span class="tag tag-admin"><i class="ti ti-flask"></i> in prova · solo admin fino a <?= h(fmt_date_long(FANTA_LAUNCH_AT)) ?> alle <?= fmt_time(FANTA_LAUNCH_AT) ?></span><?php endif; ?>
  <?php if ($season && $canPlay): ?>
  <div class="fz-head-info">
    <span class="fz-budget" title="Crediti fanta (non sono KOIN): si parte da <?= FANTA_BUDGET ?>, aumentano comprando basso e rivendendo alto, e <?= fanta_cr(FANTA_WIN_CREDITS) ?> in più per ogni partita che vinci"><i class="ti ti-wallet"></i> <strong><?= $left ?></strong> <?= $left === 1 ? 'credito' : 'crediti' ?></span>
    <?php if ($myRank): ?><span class="fz-rank"><i class="ti ti-trophy"></i> <?= $myRank['rank'] ?>° · <?= fanta_fmt($myRank['total']) ?> pt</span><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php
$bar = bar_group_ids();
if (count($bar) > 1): ?>
<nav class="sortbar fz-leagues" aria-label="Lega"><i class="ti ti-users-group"></i>
  <?php foreach ($bar as $g): ?><a href="fanta.php?t=<?= h($tab) ?>&amp;g=<?= $g ?>" class="<?= $g === $gid ? 'active' : '' ?>"><?= h(all_groups()[$g] ?? '?') ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($isAdm): ?>
<section class="card fz-admin">
  <?php if ($season): ?>
    <p><i class="ti ti-settings"></i> Stagione <?= (int) $season['n'] ?> aperta dal <?= fmt_date_short($season['started_at']) ?>: <?= count($seasonMatches) ?> partite giocate o in corso.
      Chiudendola, i primi <?= FANTA_PRIZE_RANKS ?> ricevono i premi e la classifica resta nell'albo d'oro.</p>
    <?php $openVotes = array_filter($seasonMatches, fn($m) => (int) $m['voting_open']); ?>
    <?php if ($openVotes): ?><p class="small av-short"><i class="ti ti-alert-triangle"></i> Ci sono votazioni ancora aperte: se chiudi adesso, di quelle partite contano i voti arrivati finora e l'MVP non conta.</p><?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="g" value="<?= $gid ?>"><input type="hidden" name="do" value="close">
      <button class="btn btn-sm" data-confirm="Chiudere la stagione e dare i premi? Non si torna indietro."><i class="ti ti-flag-checkered"></i> Chiudi la stagione e premia</button></form>
  <?php else: ?>
    <p><i class="ti ti-settings"></i> Nessuna stagione aperta. Aprendola, le quote di adesso (quelle del Mercato) diventano le quote base della stagione e ognuno può fare la sua squadra.
      Contano le partite che iniziano da quel momento.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="g" value="<?= $gid ?>"><input type="hidden" name="do" value="open">
      <button class="btn btn-primary btn-sm"><i class="ti ti-player-play"></i> Apri la stagione <?= (int) (fanta_last_season($gid)['n'] ?? 0) + 1 ?></button></form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!$season): ?>
<p class="card fz-intro"><i class="ti ti-cards"></i> Il Fanta è il fantacalcio della lega: con <?= FANTA_BUDGET ?> crediti ti compri <?= FANTA_ROSTER ?> figurine dei tuoi compagni
  (<?= FANTA_STARTERS ?> titolari e una in panchina), scegli il capitano e ogni partita fai punti con i loro voti, gol e assist. I primi <?= FANTA_PRIZE_RANKS ?> vincono oggetti che non si comprano.
  <strong><?= fanta_last_season($gid) ? 'La prossima stagione non è ancora aperta.' : 'La prima stagione non è ancora aperta.' ?></strong> <?= $isAdm ? '' : 'La apre chi amministra la lega.' ?></p>
<?php elseif (!$canPlay): ?>
<p class="card fz-intro"><i class="ti ti-eye"></i> Stai guardando il Fanta di questa lega: per giocare serve un giocatore della lega collegato al tuo account.</p>
<?php endif; ?>

<?php
// mini tutorial «Come si legge una figurina» (assets/app.js: startTour): da solo la prima volta che si apre il Mercato, poi dal pulsante
$withCards = in_array($tab, ['mercato', 'squadra'], true) || ($tab === 'classifica' && !empty($_GET['m']));
if ($withCards):
    $miniTour = ['storeKey' => 'fanta-tour-figurina', 'auto' => $tab === 'mercato', 'steps' => [
        ['sel' => null, 'icon' => 'cards', 'title' => 'Come si legge una figurina',
            'text' => 'Ogni figurina è un giocatore della tua lega. In mezzo minuto ti spiego cosa vogliono dire i numeri.', 'bullets' => []],
        ['sel' => '.fz-card .fz-price', 'icon' => 'coin', 'title' => 'Quota attuale',
            'text' => 'Quanti crediti costa adesso: si compra e si vende sempre a questa. Cambia dopo ogni partita, quando si chiudono le votazioni.',
            'bullets' => ['1: ha perso senza fare granché.', '2: una partita normale.', '3: una bella partita (un gol e la vittoria, un voto alto).', '4: una prestazione sontuosa.']],
        ['sel' => '.fz-card .fz-quote', 'icon' => 'trending-up', 'title' => 'Base e andamento',
            'text' => 'La base è la quota che aveva all\'inizio della stagione. La freccia verde vuol dire che è salita, quella rossa che è scesa.',
            'bullets' => ['Nella tua squadra vedi anche quanto l\'hai pagata: se la quota è più alta, vendendola guadagni crediti.']],
        ['sel' => '.fz-card .fz-pct', 'icon' => 'calendar-check', 'title' => 'Presenze',
            'text' => 'La percentuale dice quante delle ultime ' . FANTA_FORM_MATCHES . ' partite della lega ha giocato. Chi non gioca fa 0 punti.',
            'bullets' => ['Verde (da ' . FANTA_ATT_GOOD . '%): gioca quasi sempre.', 'Gialla (da ' . FANTA_ATT_OK . '%): ogni tanto salta.',
                'Rossa: gioca poco, rischi che non ti porti punti.']],
        ['sel' => '.fz-card .fz-stats', 'icon' => 'chart-bar', 'title' => 'Gli altri numeri',
            'text' => 'Per capire chi rende di più.',
            'bullets' => ['Stagione: i punti fanta fatti in questa stagione.', 'Media: i punti fanta a partita giocata.', 'Rose: in quante squadre del Fanta c\'è già.']],
        ['sel' => '.fz-card .fz-badge', 'icon' => 'letter-c', 'title' => 'Capitano e panchina',
            'text' => 'La C è il capitano: bonus e malus doppi. La P è la panchina: entra al posto del primo titolare che non gioca.', 'bullets' => []],
        ['sel' => '.fz-card .fz-btns', 'icon' => 'hand-click', 'title' => 'Tocca a te',
            'text' => 'Da qui compri, vendi, scegli il capitano e chi va in panchina. Buon Fanta!', 'bullets' => []],
    ]]; ?>
<script type="application/json" id="mini-tour-data"><?= json_encode($miniTour, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<nav class="shop-tabs fz-tabs">
  <?php foreach ($tabs as $k => [$label, $icon]): ?>
    <a href="<?= h('fanta.php?t=' . $k . '&g=' . $gid) ?>" class="<?= $k === $tab ? 'active' : '' ?>"><i class="ti ti-<?= $icon ?>"></i> <?= $label ?><?php if ($k === 'scambi' && $incoming): ?> <span class="nav-badge"><?= $incoming ?></span><?php endif; ?></a>
  <?php endforeach; ?>
  <?php if ($withCards): ?><button type="button" class="fz-help" data-mini-tour title="Come si legge una figurina"><i class="ti ti-help"></i><span> Come si legge una figurina</span></button><?php endif; ?>
</nav>

<?php if ($tab === 'squadra'): ?>
  <?php if (!$season || !$canPlay): ?>
    <p class="empty card"><?= !$season ? 'Quando si apre la stagione, qui fai la tua squadra.' : 'Non hai una squadra in questa lega.' ?></p>
  <?php else:
      $starters = array_values(array_filter($myRoster, fn($r) => $r['role'] === 'T'));
      $benchRow = array_values(array_filter($myRoster, fn($r) => $r['role'] === 'P'))[0] ?? null; ?>
    <p class="muted small fz-lock"><i class="ti ti-lock-clock"></i>
      <?php if ($next): ?>Per la prossima partita (<?= h(fmt_date_long($next['match_date'])) ?> alle <?= fmt_time($next['match_date']) ?>) conta la squadra che hai al calcio d'inizio: fino ad allora cambi quello che vuoi.
      <?php else: ?>Nessuna partita in programma: per ogni partita conta la squadra che hai al calcio d'inizio.<?php endif; ?></p>
    <h2 class="fz-h2">Titolari <span class="muted small"><?= count($starters) ?>/<?= FANTA_STARTERS ?></span></h2>
    <div class="fz-grid">
      <?php foreach ($starters as $r):
          $pid = (int) $r['player_id'];
          $btns = '<div class="fz-btns">'
              . (!(int) $r['captain'] ? $form_btn('captain', $pid, '<i class="ti ti-letter-c"></i> Capitano') : '')
              . (count($myRoster) >= FANTA_ROSTER ? $form_btn('bench', $pid, '<i class="ti ti-armchair"></i> In panchina') : '')
              . $form_btn('sell', $pid, '<i class="ti ti-coin"></i> Vendi (+' . (int) ($quotes[$pid] ?? 1) . ')', 'btn-ghost', 'Vendere ' . $pl($pid)['name'] . '?') . '</div>';
          echo $card($pid, $btns, ['cost' => (int) $r['cost'], 'captain' => (int) $r['captain'], 'mine' => true]);
      endforeach; ?>
      <?php for ($i = count($starters); $i < FANTA_STARTERS; $i++): ?>
        <a class="fz-card fz-empty" href="<?= h('fanta.php?t=mercato&g=' . $gid) ?>"><i class="ti ti-plus"></i><span>Compra un titolare</span></a>
      <?php endfor; ?>
    </div>
    <h2 class="fz-h2">Panchina</h2>
    <div class="fz-grid">
      <?php if ($benchRow): $pid = (int) $benchRow['player_id'];
          echo $card($pid, '<p class="small muted fz-note">Entra al posto del primo titolare che non gioca. Per farlo titolare, manda in panchina un altro.</p><div class="fz-btns">'
              . $form_btn('sell', $pid, '<i class="ti ti-coin"></i> Vendi (+' . (int) ($quotes[$pid] ?? 1) . ')', 'btn-ghost', 'Vendere ' . $pl($pid)['name'] . '?') . '</div>',
              ['cost' => (int) $benchRow['cost'], 'bench' => true, 'mine' => true]);
      else: ?>
        <a class="fz-card fz-empty" href="<?= h('fanta.php?t=mercato&g=' . $gid) ?>"><i class="ti ti-armchair"></i><span><?= count($starters) < FANTA_STARTERS ? 'Prima completa i titolari' : 'Compra la riserva' ?></span></a>
      <?php endif; ?>
    </div>

    <?php if ($seasonMatches): ?>
    <h2 class="fz-h2">Partita per partita</h2>
    <?php foreach ($seasonMatches as $m):
        $pts = fanta_match_points((int) $m['id']);
        $lineup = fanta_lineups((int) $m['id'])[$me] ?? [];
        $sc = $pts && $lineup ? fanta_lineup_score($lineup, $pts) : null; ?>
      <details class="card fz-match">
        <summary><span><?= h(fmt_date_long($m['match_date'])) ?> · <?= h(team_name('A', $m)) ?> <?= $m['status'] === 'giocata' ? (int) $m['score_a'] . '–' . (int) $m['score_b'] : 'vs' ?> <?= h(team_name('B', $m)) ?></span>
          <strong><?= $sc ? fanta_fmt($sc['total']) . ' pt' : ($m['status'] === 'giocata' ? ($lineup ? '0 pt' : 'squadra vuota') : ($m['status'] === 'annullata' ? 'annullata · non conta' : 'in corso')) ?></strong>
          <?php if ($pts && reset($pts)['provisional']): ?><span class="tag">provvisorio: votazioni aperte</span><?php endif; ?></summary>
        <?php if ($sc): ?>
        <table class="table fz-table"><thead><tr><th>Figurina</th><th>Voto</th><th>Bonus</th><th>Punti</th></tr></thead><tbody>
          <?php foreach ($sc['rows'] as $row): $p = $pts[$row['player_id']] ?? null; ?>
          <tr class="<?= !empty($row['bench']) ? 'is-bench' : '' ?>">
            <td><?= h($pl($row['player_id'])['name']) ?><?= $row['captain'] ? ' <span class="fz-badge fz-cap">C</span>' : '' ?><?= $row['sub'] ? ' <span class="tag">entra dalla panchina</span>' : '' ?><?= !empty($row['bench']) ? ' <span class="tag">panchina</span>' : '' ?></td>
            <?php if ($p && $row['played'] && empty($row['bench'])): ?>
            <td><?= fmt_num($p['vote']) ?></td>
            <td title="<?= h(implode(', ', array_filter(array_map(fn($k, $n) => $n ? $n . ' ' . ['goal' => 'gol', 'assist' => 'assist', 'mvp' => 'MVP', 'win' => 'vittoria', 'own_goal' => 'autogol'][$k] : '', array_keys($p['events']), $p['events'])))) ?>"><?= $p['bonus'] >= 0 ? '+' : '' ?><?= $p['bonus'] ?><?= $row['captain'] && $p['bonus'] ? ' ×2' : '' ?></td>
            <td><strong><?= fanta_fmt($row['pts']) ?></strong></td>
            <?php else: ?><td colspan="3" class="muted"><?= !empty($row['bench']) ? ($row['played'] ? 'non è servita' : 'non ha giocato') : 'non ha giocato' ?></td><?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </details>
    <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>

<?php elseif ($tab === 'mercato'): ?>
  <p class="muted small"><i class="ti ti-scale"></i> La quota (da 1 a 4 crediti) la dà l'ultima partita giocata, appena si chiudono le votazioni: 1 a chi ha perso
    senza fare granché, 2 a una partita normale, 3 a una bella partita, 4 a una prestazione sontuosa. Si compra e si vende alla quota attuale<?= $season ? '; la base è quella di inizio stagione' : '' ?>.
    Più squadre possono avere la stessa figurina.</p>
  <?php
  $order = array_keys($cards);
  usort($order, fn($a, $b) => [($quotes[$b] ?? 1), $form[$b]['avg'] ?? 0] <=> [($quotes[$a] ?? 1), $form[$a]['avg'] ?? 0]); ?>
  <div class="fz-grid fz-market">
    <?php foreach ($order as $pid):
        $extra = '';
        if ($season && $canPlay) {
            $price = $quotes[$pid] ?? 1;
            if (in_array($pid, $myIds, true)) {
                $extra = '<p class="fz-own"><i class="ti ti-check"></i> Nella tua squadra</p>';
            } elseif (count($myRoster) >= FANTA_ROSTER) {
                $extra = '<p class="small muted fz-note">Rosa piena</p>';
            } elseif ($price > $left) {
                $extra = '<p class="small av-short fz-note">Ti ' . ($price - $left === 1 ? 'manca ' : 'mancano ') . fanta_cr($price - $left) . '</p>';
            } else {
                $extra = '<div class="fz-btns">' . $form_btn('buy', $pid, '<i class="ti ti-shopping-cart"></i> Compra', 'btn-primary') . '</div>';
            }
        }
        echo $card($pid, $extra, ['mine' => in_array($pid, $myIds, true)]);
    endforeach; ?>
  </div>

<?php elseif ($tab === 'classifica'): ?>
  <?php $view = (int) ($_GET['m'] ?? 0); ?>
  <?php if (!$season): ?>
    <p class="empty card">La classifica parte con la stagione.</p>
  <?php elseif ($view && ($vr = fanta_roster($sid, $view))): ?>
    <p><a class="link" href="<?= h('fanta.php?t=classifica&g=' . $gid) ?>"><i class="ti ti-arrow-left"></i> Classifica</a></p>
    <h2 class="fz-h2">La squadra di <?= h($pl($view)['name']) ?></h2>
    <?php if ($canPlay && $view !== $me): ?><p><a class="btn btn-sm btn-primary" href="<?= h('fanta.php?t=scambi&g=' . $gid . '&con=' . $view) ?>"><i class="ti ti-arrows-exchange"></i> Proponi uno scambio</a></p><?php endif; ?>
    <div class="fz-grid">
      <?php foreach ($vr as $r) echo $card((int) $r['player_id'], '', ['cost' => (int) $r['cost'], 'captain' => (int) $r['captain'], 'bench' => $r['role'] === 'P']); ?>
    </div>
  <?php elseif (!$standings): ?>
    <p class="empty card">Nessuno ha ancora fatto la squadra: sii il primo!</p>
  <?php else: ?>
  <div class="card table-card"><div class="table-wrap"><table class="table fz-standings">
    <thead><tr><th>#</th><th>Fantallenatore</th><th title="Punti della stagione">Punti</th><th title="Punti nell'ultima partita">Ultima</th><th title="Partite in cui aveva la squadra">PG</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($standings as $r): $p = $pl($r['manager_id']); ?>
      <tr class="<?= $r['manager_id'] === $me ? 'is-me' : '' ?><?= $r['rank'] <= FANTA_PRIZE_RANKS ? ' is-prize' : '' ?>">
        <td class="rank rank-<?= $r['rank'] ?>"><span><?= $r['rank'] ?></span></td>
        <td><a class="tname" href="<?= h('fanta.php?t=classifica&g=' . $gid . '&m=' . $r['manager_id']) ?>"><?= avatar($p, 'xs') ?> <?= h($p['name']) ?></a></td>
        <td><strong><?= fanta_fmt($r['total']) ?></strong></td>
        <td><?= $r['last'] === null ? '–' : fanta_fmt($r['last']) ?></td>
        <td><?= $r['played'] ?></td>
        <td class="nowrap"><?php if ($r['rank'] <= FANTA_PRIZE_RANKS): ?><i class="ti ti-gift fz-gift" title="Oggi sarebbe premiato: <?= h(implode(', ', array_map(fn($x) => $x[2]['name'], fanta_prizes_for($r['rank'])))) ?>"></i><?php endif; ?>
          <?php if ($canPlay && $r['manager_id'] !== $me): ?><a class="btn btn-ghost btn-sm" href="<?= h('fanta.php?t=scambi&g=' . $gid . '&con=' . $r['manager_id']) ?>" title="Proponi uno scambio"><i class="ti ti-arrows-exchange"></i></a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
  <p class="muted small">Tutti contro tutti: vince chi fa più punti nella stagione. A pari punti conta la partita migliore. Tocca un nome per vedere la sua squadra.</p>
  <?php endif; ?>

<?php elseif ($tab === 'scambi'): ?>
  <?php if (!$season || !$canPlay): ?>
    <p class="empty card"><?= !$season ? 'Gli scambi si fanno durante la stagione.' : 'Non hai una squadra in questa lega.' ?></p>
  <?php else:
      $con = (int) ($_GET['con'] ?? 0);
      $managers = array_keys(fanta_managers($gid));
      $tradeLine = function (array $t) use ($pl): string {
          return '<strong>' . h($pl((int) $t['from_id'])['name']) . '</strong> dà <strong>' . h($pl((int) $t['give_id'])['name']) . '</strong> e riceve <strong>'
              . h($pl((int) $t['want_id'])['name']) . '</strong> da <strong>' . h($pl((int) $t['to_id'])['name']) . '</strong>';
      }; ?>
    <?php if ($trades): ?>
    <section class="card">
      <h2 class="fz-h2">Proposte in attesa</h2>
      <?php foreach ($trades as $t): $in = (int) $t['to_id'] === $me; ?>
      <div class="fz-trade">
        <p><i class="ti ti-<?= $in ? 'inbox' : 'send' ?>"></i> <?= $tradeLine($t) ?> <span class="muted small">· <?= fmt_date_short($t['created_at']) ?></span></p>
        <form method="post" class="fz-trade-btns"><?= csrf_field() ?><input type="hidden" name="g" value="<?= $gid ?>"><input type="hidden" name="trade" value="<?= (int) $t['id'] ?>">
          <?php if ($in): ?>
            <button class="btn btn-primary btn-sm" name="do" value="accetta"><i class="ti ti-check"></i> Accetta</button>
            <button class="btn btn-ghost btn-sm" name="do" value="rifiuta"><i class="ti ti-x"></i> Rifiuta</button>
          <?php else: ?>
            <button class="btn btn-ghost btn-sm" name="do" value="ritira"><i class="ti ti-arrow-back-up"></i> Ritira</button>
          <?php endif; ?>
        </form>
      </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section class="card">
      <h2 class="fz-h2">Proponi uno scambio</h2>
      <p class="muted small">Una figurina tua per una sua, diverse tra loro: nessuno dei due può ritrovarsi due volte lo stesso giocatore. I crediti non cambiano; la figurina che arriva prende il posto (e la fascia) di quella che parte.</p>
      <div class="sortbar">Con:
        <?php foreach ($managers as $mid): if ($mid === $me || !fanta_roster($sid, $mid)) continue; ?>
          <a href="<?= h('fanta.php?t=scambi&g=' . $gid . '&con=' . $mid) ?>" class="<?= $con === $mid ? 'active' : '' ?>"><?= h($pl($mid)['name']) ?></a>
        <?php endforeach; ?>
      </div>
      <?php if ($con && $con !== $me && in_array($con, $managers, true)):
          $theirs = fanta_roster($sid, $con);
          $theirIds = array_map(fn($r) => (int) $r['player_id'], $theirs);
          $canGive = array_filter($myRoster, fn($r) => !in_array((int) $r['player_id'], $theirIds, true));
          $canWant = array_filter($theirs, fn($r) => !in_array((int) $r['player_id'], $myIds, true)); ?>
        <?php if (!$canGive || !$canWant): ?>
          <p class="empty">Non c'è niente da scambiare con <?= h($pl($con)['name']) ?>: <?= !$myRoster ? 'la tua squadra è vuota.' : 'avete le stesse figurine.' ?></p>
        <?php else: ?>
        <form method="post" class="fz-propose"><?= csrf_field() ?><input type="hidden" name="g" value="<?= $gid ?>"><input type="hidden" name="do" value="propose"><input type="hidden" name="to" value="<?= $con ?>">
          <fieldset><legend>Dai</legend>
            <?php foreach ($canGive as $i => $r): $p = $pl((int) $r['player_id']); ?>
              <label class="fz-pick"><input type="radio" name="give" value="<?= (int) $r['player_id'] ?>" required<?= $i === array_key_first($canGive) ? ' checked' : '' ?>>
                <?= avatar($p, 'xs') ?> <?= h($p['name']) ?> <span class="fz-mini-price" title="Quota attuale"><?= (int) ($quotes[(int) $r['player_id']] ?? 1) ?></span></label>
            <?php endforeach; ?>
          </fieldset>
          <i class="ti ti-arrows-exchange fz-swap"></i>
          <fieldset><legend>Chiedi a <?= h($pl($con)['name']) ?></legend>
            <?php foreach ($canWant as $i => $r): $p = $pl((int) $r['player_id']); ?>
              <label class="fz-pick"><input type="radio" name="want" value="<?= (int) $r['player_id'] ?>" required<?= $i === array_key_first($canWant) ? ' checked' : '' ?>>
                <?= avatar($p, 'xs') ?> <?= h($p['name']) ?> <span class="fz-mini-price" title="Quota attuale"><?= (int) ($quotes[(int) $r['player_id']] ?? 1) ?></span></label>
            <?php endforeach; ?>
          </fieldset>
          <div><button class="btn btn-primary"><i class="ti ti-send"></i> Proponi</button></div>
        </form>
        <?php endif; ?>
      <?php elseif (!$con): ?>
        <p class="muted small">Scegli la squadra con cui scambiare.</p>
      <?php endif; ?>
    </section>
  <?php endif; ?>

<?php else: /* premi e regole */ ?>
  <section class="card fz-rules">
    <h2 class="fz-h2">Come si gioca</h2>
    <ul>
      <li><strong>Squadra:</strong> <?= FANTA_ROSTER ?> figurine dei giocatori della lega, <?= FANTA_STARTERS ?> titolari e una in panchina, con <?= FANTA_BUDGET ?> crediti (non sono i KOIN del portafoglio). Più squadre possono avere la stessa figurina.</li>
      <li><strong>Quote:</strong> ogni figurina vale da 1 a 4 crediti in base alla sua ultima partita (voto e bonus, appena si chiudono le votazioni): 1 a chi ha perso senza fare granché,
        2 a una partita normale, 3 a una bella partita (per esempio un gol e la vittoria, o un voto da <?= fmt_num(FANTA_QUOTE_VOTE[3]) ?>), 4 a una prestazione sontuosa (una doppietta, l'MVP, un voto da <?= fmt_num(FANTA_QUOTE_VOTE[4]) ?>).
        La quota base è quella che aveva all'apertura della stagione. Chi non ha ancora giocato vale 1.</li>
      <li><strong>Mercato:</strong> compri e vendi quando vuoi, sempre alla quota attuale: se compri a 1 e rivendi a 4 hai 3 crediti in più da spendere. E ogni partita della lega che vinci in campo ti dà <?= fanta_cr(FANTA_WIN_CREDITS) ?> in più. Per ogni partita conta la squadra che hai al calcio d'inizio.</li>
      <li><strong>Punti:</strong> media dei voti ricevuti in partita, più <?= FANTA_BONUS['goal'] ?> a gol, <?= FANTA_BONUS['assist'] ?> ad assist, <?= FANTA_BONUS['mvp'] ?> all'MVP, <?= FANTA_BONUS['win'] ?> se vince, <?= FANTA_BONUS['own_goal'] ?> ad autogol. Chi non gioca fa 0. Finché le votazioni sono aperte i punti sono provvisori (l'MVP conta alla chiusura).</li>
      <li><strong>Capitano:</strong> un titolare con bonus e malus doppi (un gol vale +<?= 2 * FANTA_BONUS['goal'] ?>, un autogol <?= 2 * FANTA_BONUS['own_goal'] ?>).</li>
      <li><strong>Panchina:</strong> entra al posto del primo titolare che non gioca (una sostituzione a partita).</li>
      <li><strong>Scambi:</strong> una figurina tua per una diversa di un'altra squadra, se l'altro accetta.</li>
      <li><strong>Classifica:</strong> tutti contro tutti, somma dei punti di tutte le partite della stagione. La stagione la chiude chi amministra la lega.</li>
    </ul>
  </section>
  <h2 class="fz-h2">Premi di fine stagione</h2>
  <p class="muted small">Non si comprano nel Negozio: si vincono solo qui. Chi arriva più in alto prende anche i premi dei posti sotto. Se un premio ce l'hai già, ricevi <?= FANTA_DUPLICATE_KOIN ?> KOIN al suo posto.</p>
  <?php
  $myLook = $me ? avatar_look(get_player($me)) : avatar_defaults() + ['hat' => null, 'height' => null, 'weight' => null];
  $crop = ['hair' => 'head', 'hat' => 'head'];
  $kindLabel = ['celebration' => 'Esultanza', 'hair' => 'Capelli', 'hat' => 'Copricapo', 'jersey' => 'Maglia', 'nick' => 'Nickname']; ?>
  <div class="fz-tiers">
    <?php for ($rank = 1; $rank <= FANTA_PRIZE_RANKS; $rank++):
        $only = array_filter(fanta_prizes_for($rank), fn($x) => $x[2]['fanta'] === $rank);
        if (!$only) {
            continue;
        }
        $who = $rank === 1 ? 'Solo il 1°' : ($rank === 2 ? 'Il 1° e il 2°' : 'Dal 1° al ' . $rank . '°'); ?>
    <section class="card fz-tier fz-tier-<?= $rank ?>">
      <header class="fz-tier-head">
        <span class="fz-medal"><?= $rank ?>°</span>
        <div><strong><?= $who ?></strong>
          <span class="muted small"><?= $rank === 1 ? 'Oltre a tutti i premi qui sotto.' : ($rank === FANTA_PRIZE_RANKS ? 'Per tutti quelli che chiudono tra i primi ' . $rank . '.' : 'Più i premi dei posti sotto.') ?></span></div>
      </header>
      <div class="fz-tier-items">
        <?php foreach ($only as [$kind, $key, $item]):
            $l = $myLook;
            if ($kind !== 'nick') {
                $l[$kind] = $key;
            }
            $opts = ['number' => $me ? get_player($me)['shirt_number'] : null, 'label' => $item['name']]
                + (isset($crop[$kind]) ? ['crop' => $crop[$kind]] : [])
                + ($kind === 'celebration' ? ['hint' => $item['anim'], 'viewBox' => AVATAR_VIEWBOX] : []); ?>
        <figure class="fz-prize">
          <div class="fz-prize-fig<?= $kind === 'nick' ? ' is-nick' : '' ?>">
            <?php if ($kind === 'nick'): ?><span class="nick nick-big">«<?= h($item['name']) ?>»</span>
            <?php else: ?><?= avatar_figure($l, $opts) ?><?php endif; ?>
          </div>
          <figcaption><strong><?= h($item['name']) ?></strong><span><?= h($kindLabel[$kind] ?? $kind) ?></span></figcaption>
        </figure>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endfor; ?>
  </div>
  <?php $hof = q('SELECT a.*, s.n FROM fanta_awards a JOIN fanta_seasons s ON s.id = a.season_id WHERE s.group_id = ? ORDER BY s.n DESC, a.rank_pos', [$gid])->fetchAll(); ?>
  <?php if ($hof): ?>
  <h2 class="fz-h2">Albo d'oro</h2>
  <div class="card table-card"><div class="table-wrap"><table class="table">
    <thead><tr><th>Stagione</th><th>#</th><th>Fantallenatore</th><th>Punti</th><th>Premi</th></tr></thead><tbody>
    <?php foreach ($hof as $a): ?>
      <tr><td><?= (int) $a['n'] ?></td><td class="rank rank-<?= (int) $a['rank_pos'] ?>"><span><?= (int) $a['rank_pos'] ?></span></td>
        <td><?= h($pl((int) $a['manager_id'])['name']) ?></td><td><?= fanta_fmt((float) $a['points']) ?></td><td class="small"><?= h($a['prizes']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  <?php endif; ?>
<?php endif; ?>
<?php
layout_end();
