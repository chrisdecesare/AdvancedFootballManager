<?php
/*
 * Landing per chi non ha fatto l'accesso: la stessa del sito Next.js (next-app/src/app/(public)/benvenuto/page.tsx), così chi arriva su
 * Altervista o su calcettomanager.win vede la stessa presentazione. Ci arriva index.php quando nessuno è entrato.
 * Prezzi, pagina per i centri sportivi e iscrizione alle novità stanno sul sito Next (WIN_SITE): qui solo i link.
 */
require __DIR__ . '/lib/bootstrap.php';
if (current_user()) {
    redirect('index.php');
}

const WIN_SITE = 'https://calcettomanager.win';

$problems = [
    ['messages', '«Chi c\'è giovedì?»', 'Quaranta messaggi nel gruppo, tre «forse» e alla fine siete in nove.'],
    ['scale', 'Squadre a caso', 'Si fanno al campo, finisce 12 a 3 e la prossima volta qualcuno non viene.'],
    ['coin-euro', '«Chi deve ancora pagare?»', 'Nessuno se lo ricorda, tranne chi ha anticipato il campo.'],
];
$features = [
    ['hand-click', 'Ci sono / Non ci sono', 'Ognuno risponde con un tocco. Chi non risponde riceve il promemoria, non tu.'],
    ['users-group', 'Squadre equilibrate', 'Le forma l\'app dal livello di ognuno e da chi gioca bene insieme. Partite combattute fino all\'ultimo.'],
    ['star', 'Voti, MVP e classifica', 'Dopo la partita ci si vota a vicenda: il livello nasce dai voti dei compagni, non dall\'autostima.'],
    ['news', 'La Gazzetta', 'Ogni settimana il giornale della lega: infermeria, probabili formazioni, chi è in forma.'],
    ['trophy', 'Fanta, Avatar e KOIN', 'Scommesse con KOIN finti, fantacalcio della lega e avatar da vestire: il gruppo resta vivo tutta la settimana.'],
    ['receipt', 'Quote del campo', 'Si vede chi ha pagato e chi no. Fine dei promemoria imbarazzanti.'],
];
$different = [
    ['gift', 'Gratis per chi gioca. Per sempre.', 'Niente commissione a partita, niente «tassa» introdotta dopo. Chi vuole di più sceglie un piano, gli altri non pagano mai.'],
    ['click', 'Tre tocchi, non tredici', 'Apri, rispondi, chiudi. Si usa dal browser e si installa come app: niente numero di telefono, niente SMS che non arrivano.'],
    ['bell-off', 'Notifiche solo per le tue partite', 'Ti scriviamo quando serve a te: la tua partita, il tuo voto, la tua lega. Niente spam di campi liberi che non ti interessano.'],
    ['mail-off', 'Newsletter facoltativa', 'Per giocare servono nome, username e password: l\'email è facoltativa e le novità le ricevi solo se le chiedi tu.'],
    ['lifebuoy', 'Risponde una persona', 'Hai un problema? Scrivi e ti risponde chi l\'app la fa, non un modulo che finisce nel vuoto.'],
];
$faq = [
    ['È davvero gratis?', 'Sì: presenze, squadre, voti, classifica, Gazzetta, Fanta e Avatar sono gratis, per leghe e giocatori illimitati. Ci sono piani facoltativi per chi vuole extra (Pro, Capitano) e per i centri sportivi.'],
    ['Come fate a guadagnare, allora?', 'Con gli abbonamenti facoltativi e con i centri sportivi, che pagano per avere le loro leghe e i loro tornei sull\'app. Mai con commissioni sulle vostre partite.'],
    ['Devo scaricare un\'app?', 'No. Si apre dal browser del telefono e, se vuoi, la aggiungi alla schermata Home con un tocco: si comporta come un\'app, con le notifiche.'],
    ['I KOIN sono soldi veri?', 'No, sono una valuta finta della lega: non si comprano e non si convertono in euro. Servono solo per lo sfottò.'],
    ['E i miei dati?', 'Ognuno vede solo le sue leghe. Non vendiamo dati e non usiamo pubblicità personalizzata. Puoi cancellare l\'account quando vuoi.'],
];

$ver = fn(string $f) => substr((string) @md5_file(__DIR__ . '/assets/' . $f), 0, 10);
$card = function (array $it, string $extra = ''): void { ?>
    <div class="card pub-card<?= $extra ?>"><i class="ti ti-<?= h($it[0]) ?> pub-ico" aria-hidden="true"></i><h3><?= h($it[1]) ?></h3><p><?= h($it[2]) ?></p></div>
<?php };
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ffd23f">
<title>Calcetto Manager · Il calcetto tra amici, senza il caos della chat</title>
<meta name="description" content="Presenze, squadre equilibrate, voti, MVP e classifica per il tuo calcetto. Gratis per chi gioca, nessuna commissione, niente app da scaricare.">
<link rel="canonical" href="<?= WIN_SITE ?>/">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" type="image/png" href="assets/icons/icon-192.png">
<link rel="apple-touch-icon" href="assets/icons/icon-180.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Caveat:wght@500;700&family=Lilita+One&family=Nunito:wght@500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
<link rel="stylesheet" href="assets/style.css?v=<?= h($ver('style.css')) ?>">
<link rel="stylesheet" href="assets/landing.css?v=<?= h($ver('landing.css')) ?>">
</head>
<body>
<header class="pub-top">
  <div class="pub-top-in">
    <a href="benvenuto.php" class="brand"><span class="brand-ball">⚽</span> Calcetto Manager</a>
    <nav class="pub-nav" aria-label="Sito">
      <a href="<?= WIN_SITE ?>/prezzi">Prezzi</a>
      <a href="<?= WIN_SITE ?>/centri-sportivi">Centri sportivi</a>
      <a href="login.php" class="btn btn-ghost btn-sm">Accedi</a>
      <a href="create_league.php" class="btn btn-primary btn-sm pub-nav-cta">Crea la lega</a>
    </nav>
  </div>
</header>
<main class="pub">
  <section class="pub-hero">
    <div class="pub-hero-txt">
      <span class="pub-kicker">Calcetto tra amici · gratis</span>
      <h1>Il calcetto del giovedì, <span class="pub-hl">senza il caos della chat.</span></h1>
      <p class="pub-lead">Presenze, squadre equilibrate, voti e classifica in un'unica app. Il gruppo WhatsApp torna a servire per gli sfottò.</p>
      <div class="btn-row">
        <a class="btn btn-primary pub-cta" href="create_league.php"><i class="ti ti-ball-football" aria-hidden="true"></i> Crea la tua lega gratis</a>
        <a class="btn btn-ghost" href="login.php">Ho già un account</a>
      </div>
      <p class="muted small">Nessuna carta, nessun numero di telefono. In 2 minuti mandi il link al gruppo.</p>
    </div>
    <div class="pub-hero-card card" aria-hidden="true">
      <div class="pub-mock-head"><i class="ti ti-calendar-event"></i> Giovedì 21:00 · Campo 2</div>
      <div class="pub-mock-count"><b>9</b>/10 confermati</div>
      <div class="pub-mock-btns"><span class="btn btn-yes is-on btn-sm">Ci sono</span><span class="btn btn-no btn-sm">Non ci sono</span></div>
      <div class="pub-mock-teams"><span class="tag" style="background: var(--team-a)">Blu · 74</span><span class="tag" style="background: var(--team-b)">Arancio · 73</span></div>
      <div class="pub-mock-mvp"><i class="ti ti-crown"></i> MVP della settimana: <b>Marco</b> · 8,2</div>
    </div>
  </section>

  <section class="pub-sec">
    <h2 class="center">Ogni settimana la stessa storia</h2>
    <div class="pub-grid3"><?php foreach ($problems as $p) { $card($p, ' pub-problem'); } ?></div>
  </section>

  <section class="pub-sec">
    <h2 class="center">Cosa fa Calcetto Manager</h2>
    <div class="pub-grid3"><?php foreach ($features as $f) { $card($f); } ?></div>
  </section>

  <section class="pub-sec pub-band">
    <h2 class="center">Perché non è l'ennesima app</h2>
    <p class="center muted pub-sub">Abbiamo letto centinaia di recensioni delle altre app di calcetto e prenotazione. Ecco cosa abbiamo fatto diversamente.</p>
    <div class="pub-grid-diff">
      <?php foreach ($different as $d): ?>
        <div class="pub-diff"><i class="ti ti-<?= h($d[0]) ?> pub-ico" aria-hidden="true"></i><div><h3><?= h($d[1]) ?></h3><p><?= h($d[2]) ?></p></div></div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="pub-sec">
    <h2 class="center">Si parte in tre passi</h2>
    <ol class="pub-steps">
      <li><b>Crea la lega</b><span>Il nome della lega e il tuo account. Un minuto.</span></li>
      <li><b>Manda il link nel gruppo</b><span>Chi lo apre si iscrive e lo approvi tu.</span></li>
      <li><b>Giocate</b><span>Presenze, squadre e classifica pensano a sé.</span></li>
    </ol>
    <div class="center"><a class="btn btn-primary pub-cta" href="create_league.php">Crea la tua lega gratis</a></div>
  </section>

  <section class="pub-sec">
    <div class="card pub-centers">
      <div>
        <span class="pub-kicker">Hai un centro sportivo?</span>
        <h2>I gruppi che giocano da te, più uniti e più fedeli.</h2>
        <p>Leghe e tornei con classifiche per i gruppi del tuo centro. Prezzi chiari, 30 giorni di prova, nessuna commissione sulle prenotazioni.</p>
      </div>
      <a class="btn btn-primary" href="<?= WIN_SITE ?>/centri-sportivi">Scopri l'offerta per i centri <i class="ti ti-arrow-right" aria-hidden="true"></i></a>
    </div>
  </section>

  <section class="pub-sec pub-narrow center">
    <h2>Vuoi sapere quando esce qualcosa di nuovo?</h2>
    <p class="muted">Facoltativo: al massimo due email al mese, nient'altro.</p>
    <a class="btn btn-ghost" href="<?= WIN_SITE ?>/#novita"><i class="ti ti-mail" aria-hidden="true"></i> Iscriviti alle novità</a>
  </section>

  <section class="pub-sec pub-narrow">
    <h2 class="center">Domande</h2>
    <?php foreach ($faq as $q): ?>
      <details class="card pub-faq"><summary><?= h($q[0]) ?></summary><p><?= h($q[1]) ?></p></details>
    <?php endforeach; ?>
  </section>
</main>
<footer class="pub-foot">
  <div class="pub-foot-in">
    <div><b>Calcetto Manager</b><p class="muted small">Il calcetto tra amici, organizzato. Gratis per chi gioca.</p></div>
    <nav class="pub-foot-links" aria-label="Informazioni">
      <a href="<?= WIN_SITE ?>/prezzi">Prezzi</a>
      <a href="<?= WIN_SITE ?>/centri-sportivi">Per i centri sportivi</a>
      <a href="<?= WIN_SITE ?>/privacy">Privacy e cookie</a>
      <a href="<?= WIN_SITE ?>/termini">Termini</a>
    </nav>
  </div>
</footer>
</body>
</html>
