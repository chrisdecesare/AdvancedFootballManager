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

// Struttura di wp-config.php con TUTTI i valori oscurati (restano solo i nomi delle costanti note)
echo "\n--- righe rilevanti di wp-config.php (valori oscurati) ---\n";
$f = dirname(__DIR__, 2) . '/wp-config.php';
$known = ['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET', 'DB_COLLATE', 'WP_HOME', 'WP_SITEURL'];
foreach (@file($f, FILE_IGNORE_NEW_LINES) ?: [] as $n => $line) {
    if (!preg_match('/DB_|table_prefix|include|require|getenv|\$_SERVER|\$_ENV|\$_/i', $line)) {
        continue;
    }
    $masked = preg_replace_callback('/([\'"])((?:\\.|(?!\1).)*)\1/', function ($m) use ($known) {
        return in_array($m[2], $known, true) ? $m[0] : "'<" . strlen($m[2]) . " car.>'";
    }, $line);
    echo ($n + 1) . ': ' . trim($masked) . "\n";
}
echo 'dimensione file: ' . (@filesize($f) ?: '?') . " byte\n";
