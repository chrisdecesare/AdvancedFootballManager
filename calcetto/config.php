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
 * finisce su GitHub. Ci sono due modi per darli al sito, in quest'ordine:
 *   1. config.local.php (escluso da Git). Si crea da solo con setup-wordpress.php (su Altervista,
 *      usando il database di WordPress) oppure a mano copiando config.local.php.example;
 *   2. variabili d'ambiente DB_HOST, DB_USER, DB_PASS, DB_NAME (test in locale con docker).
 */
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') !== false ? getenv('DB_HOST') : 'localhost');
defined('DB_USER') || define('DB_USER', getenv('DB_USER') ?: 'NOMEUTENTE');
defined('DB_PASS') || define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
defined('DB_NAME') || define('DB_NAME', getenv('DB_NAME') ?: 'my_NOMEUTENTE');

// Codice da digitare in install.php per creare il primo admin (vuoto = installazione impossibile).
defined('INSTALL_KEY') || define('INSTALL_KEY', '');

define('APP_NAME', 'Calcetto Manager');
define('TIMEZONE', 'Europe/Rome');

// true = chiunque vede home, partite, rosa e classifica senza login
define('PUBLIC_READ', false);

// true = chi ha il link può iscriversi da solo dalla pagina di login. L'account resta "in attesa"
// (non vede nulla) finché un admin non lo approva. false = gli account li crea solo l'admin.
define('REGISTRATION', true);

// true = chiunque può creare la propria lega (create_league.php) e gestirla da solo, invitando i suoi giocatori con un link.
// Le leghe create così le vede solo chi ne fa parte; l'admin del sito le vede tutte da «Piattaforma» (platform.php).
defined('LEAGUE_CREATION') || define('LEAGUE_CREATION', true);

define('TEAM_A_NAME', 'Blu');
define('TEAM_B_NAME', 'Arancio');

// voto dato d'ufficio, alla chiusura delle votazioni, a chi non ha votato (a tutti gli altri giocatori della partita)
define('DEFAULT_VOTE', 6.0);
// ore a disposizione per votare, dalla fine della partita: poi le votazioni si chiudono da sole (l'orario si cambia dalla partita)
define('VOTING_HOURS', 24);

define('POINTS_WIN', 3);
define('POINTS_DRAW', 1);
define('DEFAULT_FEE', 5.00);          // quota a partita (€)
define('DEFAULT_LOCATION', '');       // campo proposto per le nuove partite
define('MATCH_DURATION_MIN', 60);     // durata (minuti) dell'evento creato con "Aggiungi a Google Calendar"
