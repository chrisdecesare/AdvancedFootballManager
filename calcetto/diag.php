<?php
// TEMPORANEO: diagnostica della connessione al database. Stampa solo sì/no e codici d'errore,
// mai password né nomi utente. Da cancellare subito dopo l'uso.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

echo 'PHP ' . PHP_VERSION . ' | pdo_mysql: ' . (extension_loaded('pdo_mysql') ? 'si' : 'no') . "\n";
echo 'auto_prepend_file impostato: ' . (ini_get('auto_prepend_file') ? 'si' : 'no') . "\n";
$names = [];
foreach (array_merge(array_keys($_SERVER), array_keys($_ENV)) as $k) {
    if (preg_match('/DB|MYSQL|WP_/i', (string) $k)) {
        $names[$k] = 1;
    }
}
echo 'variabili d\'ambiente con DB/MYSQL/WP nel nome: ' . ($names ? implode(', ', array_keys($names)) : '(nessuna)') . "\n";

// WordPress in modalita' minima: carica solo il database, senza temi né plugin
define('SHORTINIT', true);
$wl = dirname(__DIR__, 2) . '/wp-load.php';
echo 'wp-load.php presente: ' . (is_file($wl) ? 'si' : 'no') . "\n";
try {
    require_once $wl;
    echo "wp-load.php caricato senza errori\n";
} catch (Throwable $e) {
    echo 'errore caricando WordPress: ' . get_class($e) . "\n";
}
$all = true;
foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $k) {
    $has = defined($k);
    $all = $all && $has;
    echo "$k definita: " . ($has ? 'si' : 'no') . "\n";
}
if (!$all) {
    exit("costanti mancanti: mi fermo\n");
}
echo 'DB_HOST=' . DB_HOST . "\n";

$host = DB_HOST;
$port = '';
if (preg_match('/^([^:\/]+):(\d{1,5})$/', $host, $m)) {
    $host = $m[1];
    $port = ';port=' . $m[2];
}
try {
    $pdo = new PDO("mysql:host=$host$port;dbname=" . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "connessione PDO con le credenziali di WordPress: OK\n";
} catch (Throwable $e) {
    exit('connessione FALLITA (' . get_class($e) . ') codice driver=' . ($e instanceof PDOException ? ($e->errorInfo[1] ?? '?') : '?') . "\n");
}
try {
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    echo "SET time_zone: OK\n";
} catch (Throwable $e) {
    echo 'SET time_zone: FALLITO codice=' . ($e instanceof PDOException ? ($e->errorInfo[1] ?? '?') : '?') . "\n";
}
try {
    echo 'tabelle nel database: ' . $pdo->query('SHOW TABLES')->rowCount() . "\n";
    $pdo->exec('CREATE TABLE IF NOT EXISTS zz_prova_permessi (id INT) ENGINE=InnoDB');
    $pdo->exec('DROP TABLE zz_prova_permessi');
    echo "permesso CREATE/DROP TABLE: OK\n";
} catch (Throwable $e) {
    echo 'permessi tabelle: FALLITO codice=' . ($e instanceof PDOException ? ($e->errorInfo[1] ?? '?') : '?') . "\n";
}
