<?php
// TEMPORANEO: diagnostica della connessione al database. Stampa solo sì/no e codici d'errore,
// mai password né nomi utente. Da cancellare subito dopo l'uso.
require __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

foreach ([2, 3] as $up) {
    $f = dirname(__DIR__, $up) . '/wp-config.php';
    echo "wp-config.php a $up livelli sopra: esiste=" . (@file_exists($f) ? 'si' : 'no') . ' leggibile=' . (@is_readable($f) ? 'si' : 'no') . "\n";
}
echo 'open_basedir impostato: ' . (ini_get('open_basedir') ? 'si' : 'no') . "\n";
echo 'credenziali lette da WordPress: ' . (wp_db_credentials() ? 'si' : 'no') . "\n";
echo 'DB_USER e\' ancora il segnaposto: ' . (DB_USER === 'NOMEUTENTE' ? 'si' : 'no') . "\n";
echo 'DB_HOST=' . DB_HOST . ' porta=' . (defined('DB_PORT') ? DB_PORT : '-') . "\n";
echo 'pdo_mysql: ' . (extension_loaded('pdo_mysql') ? 'si' : 'no') . ' | mysqli: ' . (extension_loaded('mysqli') ? 'si' : 'no') . "\n";
echo 'PHP ' . PHP_VERSION . "\n";

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . (defined('DB_PORT') ? ';port=' . DB_PORT : '') . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "connessione: OK\n";
} catch (Throwable $e) {
    echo 'connessione: FALLITA (' . get_class($e) . ') codice driver=' . ($e instanceof PDOException ? ($e->errorInfo[1] ?? '?') : '?') . "\n";
    exit;
}
try {
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    echo "SET time_zone: OK\n";
} catch (Throwable $e) {
    echo 'SET time_zone: FALLITO codice driver=' . ($e instanceof PDOException ? ($e->errorInfo[1] ?? '?') : '?') . "\n";
}
try {
    $n = (int) $pdo->query('SHOW TABLES')->rowCount();
    echo "tabelle nel database: $n\n";
    $pdo->exec('CREATE TABLE IF NOT EXISTS zz_prova_permessi (id INT) ENGINE=InnoDB');
    $pdo->exec('DROP TABLE zz_prova_permessi');
    echo "permesso CREATE/DROP TABLE: OK\n";
} catch (Throwable $e) {
    echo 'permessi tabelle: FALLITO codice driver=' . ($e instanceof PDOException ? ($e->errorInfo[1] ?? '?') : '?') . "\n";
}
