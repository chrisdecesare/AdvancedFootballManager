<?php
/* Pagina web per eliminare l'account (richiesta da Google Play): il percorso in app, cosa si cancella e cosa resta, e un modulo per chi non riesce ad accedere. Come /elimina-account di Next. */
require __DIR__ . '/lib/bootstrap.php';
if (!tables_exist()) {
    redirect('install.php');
}
require_feature('account_delete');
$done = isset($_GET['fatto']);
$sent = false;
$error = null;
if (is_post()) {
    $username = mb_substr(trim((string) ($_POST['username'] ?? '')), 0, 60);
    $contact = mb_substr(strtolower(trim((string) ($_POST['contact'] ?? ''))), 0, 190);
    $message = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['message'] ?? ''))), 0, 500);
    if (($_POST['website'] ?? '') !== '') {
        $sent = true;   // campo trappola per i bot: risposta uguale, niente di salvato
    } elseif (!filter_var($contact, FILTER_VALIDATE_EMAIL)) {
        $error = 'Scrivi un indirizzo email a cui possiamo risponderti.';
    } elseif (!rate_hit('__elimina_ip__', 5, 3600)) {
        $error = 'Troppe richieste da questa connessione: riprova tra un\'ora.';
    } else {
        deletion_request_create($username, $contact, $message);
        $owner = reports_email();
        if ($owner !== '') {
            send_mail($owner, 'Richiesta di eliminazione dell\'account', "Username: " . ($username ?: '(non indicato)') . "\nEmail: $contact\nNote: " . ($message ?: '-') . "\n\nVa gestita entro 30 giorni (Pannello → Segnalazioni).");
        }
        $sent = true;
    }
}
$pub = pub_start('Elimina il tuo account', 'Come eliminare l\'account di Calcetto Manager e i tuoi dati personali, dall\'app o dal sito, e cosa succede ai tuoi dati.');
?>
<article class="pub-sec pub-narrow pub-doc">
  <h1>Elimina il tuo account Calcetto Manager</h1>
  <?php if ($done): ?><p class="pub-done">Il tuo account è stato eliminato. Grazie per aver giocato con noi.</p><?php endif; ?>
  <h2>Da dentro l'app o dal sito</h2>
  <ol>
    <li>Accedi a Calcetto Manager.</li>
    <li>Apri <b>Impostazioni → Sicurezza → Privacy e dati</b> (oppure <a class="link" href="privacy_dati.php">questa pagina</a>).</li>
    <li>Alla sezione <b>Elimina il mio account</b> inserisci la password e scrivi <b>ELIMINA</b>.</li>
  </ol>
  <p>Se possiedi una lega, un centro sportivo o un abbonamento attivo, prima va ceduto o disdetto: la pagina ti dice cosa manca.</p>
  <h2>Cosa viene eliminato</h2>
  <ul>
    <li>Account, username, password, email e verifica in due passaggi.</li>
    <li>Sessioni, dispositivi collegati e notifiche push.</li>
    <li>Nome, foto e sfondo del tuo profilo, i pensieri e le proposte che hai scritto, i tuoi voti ai campetti, i tuoi blocchi.</li>
    <li>I dati di accesso e le operazioni registrate a tuo nome.</li>
  </ul>
  <h2>Cosa resta</h2>
  <ul>
    <li>Il tuo giocatore resta nelle partite e nelle classifiche già giocate come <b>«Ex giocatore»</b>, senza nome né foto: altrimenti cambierebbero le statistiche degli altri.</li>
    <li>I documenti dei pagamenti e delle fatture, per il tempo che la legge richiede, senza più collegamento al tuo account.</li>
    <li>Le segnalazioni che hai fatto, senza il tuo nome, per tenere traccia delle decisioni.</li>
  </ul>
  <h2>Non riesci ad accedere?</h2>
  <p>Compila il modulo: ti scriviamo per verificare che l'account sia tuo, poi lo eliminiamo entro 30 giorni.</p>
  <?php if ($sent): ?>
    <p class="pub-done"><i class="ti ti-circle-check"></i> <b>Richiesta ricevuta.</b> Ti scriviamo all'email indicata per verificare che l'account sia tuo, poi lo eliminiamo (entro 30 giorni).</p>
  <?php else: ?>
    <?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="form"><?= csrf_field() ?>
      <label class="field"><span>Il tuo username (se lo ricordi)</span><input name="username" maxlength="60" autocomplete="username" autocapitalize="none"></label>
      <label class="field"><span>Un'email a cui rispondere</span><input type="email" name="contact" required maxlength="190" autocomplete="email"></label>
      <label class="field"><span>Note (facoltative)</span><textarea name="message" maxlength="500" rows="3"></textarea></label>
      <label class="hp" aria-hidden="true">Sito web <input name="website" tabindex="-1" autocomplete="off"></label>
      <button class="btn btn-primary">Chiedi l'eliminazione</button>
    </form>
  <?php endif; ?>
  <p class="muted small">Altri diritti sui tuoi dati (correggere, scaricare, opporti): vedi l'<a class="link" href="<?= h(NEXT_SITE) ?>/privacy">informativa privacy</a>.</p>
</article>
<?php pub_end($pub);
