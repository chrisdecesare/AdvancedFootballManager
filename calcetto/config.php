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
 * finisce su GitHub. Vanno in config.local.php, che è escluso da Git e resta solo sul server:
 * copia config.local.php.example in config.local.php e compilalo.
 *
 * Su Altervista (pannello → Database → attiva MySQL):
 *   DB_HOST = 'localhost'
 *   DB_USER = il tuo nome utente Altervista
 *   DB_PASS = ''              (vuota, se il pannello non ne indica una)
 *   DB_NAME = 'my_' . nome utente   (es. 'my_ingcalcetto')
 */
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// Valori predefiniti (le variabili d'ambiente servono solo per i test in locale con docker)
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
