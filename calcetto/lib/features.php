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
];

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
