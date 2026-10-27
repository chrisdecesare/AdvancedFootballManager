<?php
/* La pagina di una città (come /calcetto/<città> del sito Next): mappa con le leghe visibili e i campi, poi le schede. Chi è dentro chiede di entrare con un tocco. */
require __DIR__ . '/lib/bootstrap.php';
require_discover();
if (!tables_exist()) {
    redirect('install.php');
}
$slug = city_slug((string) ($_GET['c'] ?? ''));
$leagues = league_cards($slug);
$centers = centers_in_city($slug);
$name = $leagues[0]['city'] ?? ($centers[0]['city'] ?? '');
if ($name === '') {
    http_response_code(404);
}
$u = current_user();

if (is_post() && ($_POST['do'] ?? '') === 'request') {
    if (!$u || is_guest()) {
        redirect('login.php?next=' . rawurlencode('calcetto-' . $slug));
    }
    $r = league_request((int) $u['id'], (int) ($_POST['group'] ?? 0));
    flash($r === 'inviata' ? 'ok' : 'err', [
        'inviata' => 'Richiesta inviata: te lo diciamo appena chi gestisce la lega risponde.',
        'già' => 'Sei già in questa lega o hai già chiesto di entrare.',
        'limite' => 'Hai mandato troppe richieste oggi: riprova domani.',
        'nonvisibile' => 'Questa lega non è più visibile.',
    ][$r]);
    redirect('calcetto-' . $slug);
}

$pub = pub_start($name !== '' ? "Calcetto a $name: leghe e campi" : 'Calcetto nella tua città', $name !== '' ? count($leagues) . " leghe di calcetto e " . count($centers) . " campi a $name. Guarda la mappa e chiedi di entrare." : '');
if ($name === '') {
    echo '<div class="pub-sec"><h1>Città non trovata</h1><p class="muted">Nessuna lega o campo qui, per ora. <a class="link" href="calcetto.php">Vedi tutte le città</a>.</p></div>';
    pub_end($pub);
    exit;
}
$mine = $u && my_player_id() ? array_flip(player_group_ids((int) my_player_id())) : [];
$pending = $u ? array_flip(array_map('intval', q('SELECT group_id FROM group_requests WHERE user_id = ?', [$u['id']])->fetchAll(PDO::FETCH_COLUMN))) : [];
$owned = $u && !is_guest() ? my_owned_leagues() : [];
$points = [];
foreach ($leagues as $l) {
    if ($l['lat'] !== null && $l['lng'] !== null) {
        $points[] = ['lat' => $l['lat'], 'lng' => $l['lng'], 'label' => $l['name'], 'kind' => $l['home'] ? 'campo' : ($l['isNew'] ? 'nuova' : 'lega'), 'href' => '#lega-' . $l['id'], 'sub' => $l['players'] . ' giocatori'];
    }
}
foreach ($centers as $c) {
    if ($c['lat'] !== null && $c['lng'] !== null) {
        $points[] = ['lat' => (float) $c['lat'], 'lng' => (float) $c['lng'], 'label' => $c['name'], 'kind' => 'centro', 'href' => '#campi', 'sub' => 'centro sportivo'];
    }
}
?>
<section class="pub-sec">
  <span class="pub-kicker">Calcetto a <?= h($name) ?></span>
  <h1>Leghe e campi a <?= h($name) ?></h1>
  <p class="pub-lead"><?= count($leagues) ?> <?= count($leagues) === 1 ? 'lega che cerca' : 'leghe che cercano' ?> giocatori e <?= count($centers) ?> <?= count($centers) === 1 ? 'campo' : 'campi' ?> dove giocare. Scegli una lega e chiedi di entrare: chi la gestisce ti risponde.</p>
  <?php city_map($points); ?>
</section>
<?php if ($centers): ?>
<section class="pub-sec" id="campi">
  <h2>I campi</h2>
  <div class="pub-grid3">
    <?php foreach ($centers as $c): ?>
      <div class="card pub-card"><h3><?= h($c['name']) ?></h3>
        <p class="muted small"><?= h(implode(', ', array_filter([$c['address'], $c['city']]))) ?> · <?= (int) $c['fields_count'] ?> <?= (int) $c['fields_count'] === 1 ? 'campo' : 'campi' ?></p>
        <?php if ($c['phone']): ?><p class="small"><i class="ti ti-phone"></i> <?= h($c['phone']) ?></p><?php endif; ?></div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<section class="pub-sec">
  <h2>Le leghe</h2>
  <?php if (!$leagues): ?>
    <p class="muted">Nessuna lega visibile a <?= h($name) ?>: la tua può essere la prima. <a class="link" href="create_league.php">Crea una lega</a>.</p>
  <?php else: ?>
    <div class="lg-grid">
      <?php foreach ($leagues as $l):
        if (isset($mine[$l['id']])) {
            $act = '<span class="tag tag-ok">Ci giochi già</span>';
        } elseif (isset($pending[$l['id']])) {
            $act = '<span class="tag">Richiesta inviata</span>';
        } else {
            $act = '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="request"><input type="hidden" name="group" value="' . (int) $l['id'] . '">'
                . '<button class="btn btn-primary btn-sm">' . ($u ? 'Chiedi di entrare' : 'Accedi per chiedere di entrare') . '</button></form>';
            if ($owned && !isset($owned[$l['id']])) {
                $act .= '<a class="btn btn-ghost btn-sm" href="nuova-sfida?a=' . (int) $l['id'] . '"><i class="ti ti-swords"></i> Sfida</a>';
            }
        }
        echo league_card_html($l, $act);
      endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php pub_end($pub); ?>
