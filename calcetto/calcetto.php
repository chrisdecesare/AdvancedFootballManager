<?php
/* Indice delle città con leghe e campi (come /calcetto del sito Next): da qui si va alla pagina di una città con la mappa. */
require __DIR__ . '/lib/bootstrap.php';
if (!tables_exist()) {
    redirect('install.php');
}
$pub = pub_start('Calcetto nella tua città', 'Trova una lega di calcetto e un campo vicino a te: mappa, giocatori, partite al mese. Chiedi di entrare con un tocco.');
$cities = cities_with_leagues();
?>
<div class="pub-sec">
  <span class="pub-kicker">Trova una lega</span>
  <h1>Il calcetto nella tua città</h1>
  <p class="pub-lead">Leghe di amici che cercano giocatori e campi dove prenotare. Scegli la città, guarda la mappa e chiedi di entrare.</p>
  <?php if (!$cities): ?>
    <p class="muted">Ancora nessuna città: <a class="link" href="create_league.php">crea la prima lega</a> e rendila visibile dalla sua pagina.</p>
  <?php else: ?>
    <div class="city-list">
      <?php foreach ($cities as $c): ?>
        <a href="calcetto-<?= h($c['slug']) ?>" class="card pub-card"><h3><i class="ti ti-map-pin"></i> <?= h($c['city']) ?></h3>
          <p class="muted small"><?= (int) $c['leagues'] ?> <?= (int) $c['leagues'] === 1 ? 'lega' : 'leghe' ?> · <?= (int) $c['centers'] ?> <?= (int) $c['centers'] === 1 ? 'campo' : 'campi' ?></p></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <p class="muted small" style="margin-top:24px">Hai una lega? Rendila visibile dalla sua pagina («Trovami sulla mappa»).</p>
</div>
<?php pub_end($pub); ?>
