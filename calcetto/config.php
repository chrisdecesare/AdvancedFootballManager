<?php
// Questo file si può solo includere: se qualcuno lo apre dal browser non stampa nulla.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
/*
 * Configurazione.
 *
 * I dati riservati (database, codice di installazione) NON stanno qui, perché questo file
 * finisce su GitHub. Ci sono tre modi per dare al sito il database, in quest'ordine:
 *   1. config.local.php (escluso da Git): copia config.local.php.example e compilalo;
 *   2. variabili d'ambiente DB_HOST, DB_USER, DB_PASS, DB_NAME (test in locale con docker);
 *   3. se il sito sta dentro un WordPress (es. Altervista, in wp-content/), usa lo stesso
 *      database di WordPress leggendo il suo wp-config.php. Le tabelle del gestionale hanno
 *      nomi diversi da quelle di WordPress, che iniziano con il suo prefisso (wp_...).
 */
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

/** Credenziali del database dal wp-config.php di WordPress (solo lettura del testo, senza eseguirlo). */
function wp_db_credentials(): ?array
{
    foreach ([2, 3] as $up) {
        $file = dirname(__DIR__, $up) . '/wp-config.php';
        $src = @is_readable($file) ? @file_get_contents($file) : false;
        if ($src === false) {
            continue;
        }
        $v = [];
        foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $k) {
            if (!preg_match('/define\s*\(\s*["\']' . $k . '["\']\s*,\s*(["\'])((?:\\\\.|(?!\1).)*)\1\s*\)/', $src, $m)) {
                continue 2;
            }
            $v[$k] = stripslashes($m[2]);
        }
        // senza prefisso le tabelle di WordPress potrebbero avere i nomi delle nostre: meglio non usarlo
        if (!preg_match('/\$table_prefix\s*=\s*["\'][A-Za-z0-9_]+["\']/', $src)) {
            continue;
        }
        return $v;
    }
    return null;
}

if (!defined('DB_USER') && getenv('DB_USER') === false && ($wp = wp_db_credentials())) {
    $host = $wp['DB_HOST'];
    if (preg_match('/^([^:\/]+):(\d{1,5})$/', $host, $m)) {   // "host:porta"
        $host = $m[1];
        define('DB_PORT', (int) $m[2]);
    }
    define('DB_HOST', $host);
    define('DB_USER', $wp['DB_USER']);
    define('DB_PASS', $wp['DB_PASSWORD']);
    define('DB_NAME', $wp['DB_NAME']);
}

// Valori predefiniti
defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
defined('DB_USER') || define('DB_USER', getenv('DB_USER') ?: 'NOMEUTENTE');
defined('DB_PASS') || define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
defined('DB_NAME') || define('DB_NAME', getenv('DB_NAME') ?: 'my_NOMEUTENTE');

// Codice da digitare in install.php per creare il primo admin (vuoto = installazione impossibile).
defined('INSTALL_KEY') || define('INSTALL_KEY', '');

define('APP_NAME', 'Calcetto Manager');
define('TIMEZONE', 'Europe/Rome');

// true = chiunque vede home, partite, rosa e classifica senza login
define('PUBLIC_READ', false);

// Non esiste iscrizione libera: gli account li crea solo l'admin (pagina Admin).

define('TEAM_A_NAME', 'Blu');
define('TEAM_B_NAME', 'Arancio');

define('POINTS_WIN', 3);
define('POINTS_DRAW', 1);
define('DEFAULT_FEE', 5.00);          // quota a partita (€)
define('DEFAULT_LOCATION', '');       // campo proposto per le nuove partite
