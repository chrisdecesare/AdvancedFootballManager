<?php
// Prova temporanea: Altervista riesce a raggiungere TiDB Cloud (porta 4000, TLS)? Non usa credenziali vere: un «Access denied»
// vuol dire che rete e TLS funzionano. Da cancellare dopo la prova.
header('Content-Type: text/plain; charset=utf-8');
$host = 'gateway01.eu-central-1.prod.aws.tidbcloud.com';
$t = microtime(true);
$s = @fsockopen($host, 4000, $errno, $errstr, 8);
echo 'porta 4000: ' . ($s ? 'aperta' : "chiusa ($errno $errstr)") . ' in ' . round((microtime(true) - $t) * 1000) . " ms\n";
if ($s) fclose($s);
echo 'pdo_mysql: ' . (extension_loaded('pdo_mysql') ? 'sì' : 'no') . "\n";
echo 'PHP ' . PHP_VERSION . "\n";
try {
    $t = microtime(true);
    new PDO("mysql:host=$host;port=4000;dbname=test", 'prova.inesistente', 'x', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_SSL_CA => __DIR__ . '/lib/certs/isrgrootx1.pem',
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        PDO::ATTR_TIMEOUT => 8,
    ]);
    echo "accesso: riuscito (inatteso)\n";
} catch (Throwable $e) {
    echo 'accesso TLS: ' . $e->getMessage() . ' in ' . round((microtime(true) - $t) * 1000) . " ms\n";
}
