<?php
/*
 * Funzioni accese, spente o «solo admin», una per canale: «web» (browser) e «app» (l'app Android di Google Play, che apre il sito con ?app=android).
 * Stesse chiavi e stessi valori di next-app/src/lib/features-format.ts, nella tabella `meta` che i due siti condividono (feature.<chiave>.<canale>:
 * «1» acceso, «admin» solo il proprietario del progetto, altro spento). Nell'app abbonamenti, Sostienici e pubblicità restano spenti comunque:
 * Google Play vieta di vendere funzioni digitali fuori da Play Billing.
 */
const FEATURES = [
    'payments'       => ['label' => 'Abbonamenti e piani a pagamento', 'off' => 'sparisce tutto ciò che parla di prezzi e abbonamenti.', 'appLocked' => true, 'def' => 'on'],
    'donations'      => ['label' => 'Sostienici (Ko-fi)', 'off' => 'sparisce il pulsante Sostienici e la sua pagina.', 'appLocked' => true, 'def' => 'on'],
    'ads'            => ['label' => 'Pubblicità', 'off' => 'nessun annuncio.', 'appLocked' => true, 'def' => 'on'],
    'bets'           => ['label' => 'Scommesse con i KOIN', 'off' => 'la sezione scommesse non si apre.', 'appLocked' => false, 'def' => 'on'],
    'signup'         => ['label' => 'Nuove iscrizioni', 'off' => 'non si possono creare account.', 'appLocked' => false, 'def' => 'on'],
    'account_delete' => ['label' => "Eliminazione dell'account (richiesta da Google Play)", 'off' => 'non si può eliminare il proprio account.', 'appLocked' => false, 'def' => 'admin'],
    'report_block'   => ['label' => 'Segnala e blocca (richiesto da Google Play)', 'off' => 'spariscono i pulsanti Segnala e Blocca.', 'appLocked' => false, 'def' => 'admin'],
    // Schermate intere che si mettono in «lavori in corso» (pagina vera solo al proprietario con «solo admin», cartello per tutti con «spento»).
    // Tenere uguale a PAGES in next-app/src/lib/features-format.ts. Le schermate sicure (Home, Rosa, Classifica, Fanta, Avatar, Trova lega, La mia lega, Impostazioni, Campi) non sono qui.
    'page_partite'    => ['label' => 'Pagina: Partite', 'off' => 'al suo posto compare la schermata «Lavori in corso».', 'appLocked' => false, 'def' => 'admin'],
    'page_scommesse'  => ['label' => 'Pagina: Scommesse', 'off' => 'al suo posto compare la schermata «Lavori in corso».', 'appLocked' => false, 'def' => 'admin'],
    'page_daily'      => ['label' => 'Pagina: Daily access e Ruota', 'off' => 'al suo posto compare la schermata «Lavori in corso».', 'appLocked' => false, 'def' => 'admin'],
    'page_indovina'   => ['label' => 'Pagina: Indovina la funzionalità', 'off' => 'al suo posto compare la schermata «Lavori in corso».', 'appLocked' => false, 'def' => 'admin'],
    'page_curiosita'  => ['label' => 'Pagina: Curiosità', 'off' => 'al suo posto compare la schermata «Lavori in corso».', 'appLocked' => false, 'def' => 'admin'],
    'page_gazzetta'   => ['label' => 'Pagina: Gazzetta', 'off' => 'al suo posto compare la schermata «Lavori in corso».', 'appLocked' => false, 'def' => 'admin'],
    'page_uscite'     => ['label' => 'Pagina: Uscite del negozio', 'off' => 'al suo posto compare la schermata «Lavori in corso».', 'appLocked' => false, 'def' => 'admin'],
    'page_statistiche' => ['label' => 'Pagina: Statistiche avanzate', 'off' => 'al suo posto compare la schermata «Lavori in corso».', 'appLocked' => false, 'def' => 'admin'],
    'page_abbonamento' => ['label' => 'Pagina: Il tuo abbonamento', 'off' => 'al suo posto compare la schermata «Lavori in corso».', 'appLocked' => false, 'def' => 'admin'],
    'page_centro'     => ['label' => 'Pagina: Il tuo centro', 'off' => 'al suo posto compare la schermata «Lavori in corso».', 'appLocked' => false, 'def' => 'admin'],
];

/** Quale schermata copre il file PHP in esecuzione (le pagine non elencate restano sempre aperte). */
const PAGE_FILES = [
    'matches.php' => 'page_partite', 'match.php' => 'page_partite',
    'bets.php' => 'page_scommesse',
    'daily.php' => 'page_daily', 'wheel.php' => 'page_daily',
    'guess.php' => 'page_indovina',
    'curiosities.php' => 'page_curiosita',
    'gazzetta.php' => 'page_gazzetta',
    'drops.php' => 'page_uscite',
];

/** La pagina in esecuzione è in «lavori in corso» per chi guarda? Acceso: no. Solo admin: sì, tranne il proprietario. Spento: sì per tutti. */
function page_under_work(): bool
{
    $key = PAGE_FILES[basename($_SERVER['SCRIPT_NAME'] ?? '')] ?? null;
    if ($key === null || ($key === 'page_partite' && is_guest())) {   // un ospite vede solo le sue partite: per lui restano aperte
        return false;
    }
    $s = feature_state($key);
    return $s === 'on' ? false : ($s === 'admin' ? !is_platform_owner() : true);
}

/** Il cartello da cantiere: due pali con due assi incrociate (stesso disegno di components/WorkInProgress.tsx in Next). */
function wip_screen(): void
{
    ?>
<section class="card wip" role="status">
  <svg class="wip-art" viewBox="0 0 260 210" aria-hidden="true">
    <g stroke-linecap="butt">
      <line x1="46" y1="38" x2="214" y2="150" stroke="var(--ink)" stroke-width="38"/>
      <line x1="46" y1="38" x2="214" y2="150" stroke="var(--yellow)" stroke-width="30"/>
      <line x1="46" y1="172" x2="214" y2="60" stroke="var(--ink)" stroke-width="38"/>
      <line x1="46" y1="172" x2="214" y2="60" stroke="var(--pink)" stroke-width="30"/>
    </g>
    <rect x="30" y="8" width="26" height="194" rx="4" fill="var(--white)" stroke="var(--ink)" stroke-width="4"/>
    <rect x="204" y="8" width="26" height="194" rx="4" fill="var(--white)" stroke="var(--ink)" stroke-width="4"/>
    <text class="wip-text" x="130" y="88" text-anchor="middle">LAVORI</text>
    <text class="wip-text" x="130" y="114" text-anchor="middle">IN</text>
    <text class="wip-text" x="130" y="140" text-anchor="middle">CORSO</text>
  </svg>
  <h1 class="wip-title">Lavori in corso</h1>
  <p class="muted">Stiamo sistemando questa pagina. Torna a trovarci tra poco.</p>
</section>
<?php
}

/** «app» se la richiesta arriva dall'app Android (cookie cm_app messo da bootstrap.php quando si apre /?app=android), altrimenti «web». */
function feature_channel(): string
{
    return ($_COOKIE['cm_app'] ?? '') === 'android' ? 'app' : 'web';
}

/** Lo stato salvato: 'on', 'admin' o 'off'. Se manca il valore vale il predefinito; un valore strano vale spento. */
function feature_state(string $key, ?string $channel = null): string
{
    $f = FEATURES[$key] ?? null;
    if ($f === null) {
        return 'off';
    }
    $channel = $channel ?? feature_channel();
    if ($channel === 'app' && $f['appLocked']) {
        return 'off';
    }
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (q("SELECT k, v FROM meta WHERE k LIKE 'feature.%'")->fetchAll() as $r) {
                $cache[$r['k']] = (string) $r['v'];
            }
        } catch (Throwable $e) {
            $cache = [];   // senza database valgono i predefiniti
        }
    }
    $v = $cache["feature.$key.$channel"] ?? null;
    if ($v === null) {
        return $f['def'];
    }
    return $v === '1' ? 'on' : ($v === 'admin' ? 'admin' : 'off');
}

/** La funzione è visibile a chi guarda? «Solo admin»: solo al proprietario del progetto. */
function feature_on(string $key): bool
{
    $s = feature_state($key);
    return $s === 'admin' ? is_platform_owner() : $s === 'on';
}

/** Per le pagine che non devono esistere quando la funzione è spenta. */
function require_feature(string $key): void
{
    if (!feature_on($key)) {
        http_response_code(404);
        exit('Pagina non trovata.');
    }
}

function feature_save(string $key, string $channel, string $state): void
{
    if (!isset(FEATURES[$key]) || !in_array($channel, ['web', 'app'], true)) {
        return;
    }
    meta_set("feature.$key.$channel", $state === 'on' ? '1' : ($state === 'admin' ? 'admin' : '0'));
}
