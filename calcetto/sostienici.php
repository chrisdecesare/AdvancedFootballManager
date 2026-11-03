<?php
/* «Sostienici»: il pulsante Ko-fi e i nomi di chi ci sostiene (come /sostienici del sito Next). Pubblica: la vede anche chi non ha fatto l'accesso. */
require __DIR__ . '/lib/bootstrap.php';
if (!tables_exist()) {
    redirect('install.php');
}
require_feature('donations');
$name = kofi_username();
$who = $name ? kofi_supporters() : [];
$pub = pub_start('Sostienici', 'Calcetto Manager è gratis per chi gioca. Se ti piace, puoi offrirci un caffè su Ko-fi: ci aiuta a tenere il sito senza commissioni.');
?>
<article class="pub-sec pub-narrow pub-doc">
  <h1>Sostienici</h1>
  <p>Calcetto Manager è gratis per chi gioca e non prende commissioni sulle partite né sulle quote. Il sito costa tempo, server e un po' di pazienza:
    se ti piace e vuoi darci una mano, puoi offrirci un caffè. Non serve un account e <b>non cambia niente</b> per te: nessuna funzione si sblocca, nessun obbligo.</p>
  <?php if ($name): ?>
  <p class="kofi-box">
    <a class="kofi-btn" href="<?= h(kofi_url($name)) ?>" target="_blank" rel="noopener noreferrer"><i class="ti ti-cup" aria-hidden="true"></i> Offrici un caffè su Ko-fi</a>
    <span class="muted small">Si apre Ko-fi in un'altra scheda: il pagamento lo gestisce Ko-fi, noi non vediamo i dati della tua carta.</span>
  </p>
  <?php else: ?>
  <p class="empty card">Il sostegno con Ko-fi non è ancora attivo: torna a trovarci presto.</p>
  <?php endif; ?>
  <?php if ($who): ?>
  <h2>Grazie a chi ci sostiene</h2>
  <p class="kofi-who"><?= h(implode(' · ', $who)) ?></p>
  <p class="muted small">Qui compaiono solo i nomi di chi ha scelto di essere ricordato.</p>
  <?php endif; ?>
  <h2>Altri modi per aiutarci</h2>
  <ul>
    <li>Racconta Calcetto Manager alla tua squadra e agli altri campi della tua città.</li>
    <li>Scrivi una recensione dei campi dove giochi, in <a class="link" href="campi.php">I campi</a>.</li>
  </ul>
</article>
<?php pub_end($pub);
