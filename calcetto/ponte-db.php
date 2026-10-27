<?php
/*
 * Ponte verso il database per il sito Next.js (calcettomanager.win): così i due siti usano lo stesso database, quello di Altervista.
 * Altervista non lascia uscire connessioni verso TiDB, Vercel o Cloudflare (proxy con una lista di siti ammessi, prova del 27/10/2026),
 * mentre le richieste in entrata funzionano: è Next a chiamare qui.
 *
 * Protocollo: POST con corpo JSON {"ops": [{"sql": "..."}, ...], "atomic": bool}. Le query arrivano già composte da Next (mysql2.format,
 * con l'escape dei valori). Intestazioni: X-Ponte-Ts (secondi Unix) e X-Ponte-Sig = HMAC-SHA256 esadecimale di "<ts>\n<corpo>" con
 * DB_BRIDGE_SECRET. Firma sbagliata o vecchia di più di 60 secondi = 403. Senza segreto (almeno 32 caratteri) la pagina non esiste (404).
 * Risposta: {"ok": true, "results": [{"rows": [...]} | {"affected": n, "insertId": id}]} con interi e decimali già come numeri,
 * oppure {"ok": false, "error": "..."}. Con "atomic" tutte le query stanno in una transazione: o tutte o nessuna.
 *
 * Il segreto lo scrive il deploy (config.bridge.php, dal segreto DB_BRIDGE_SECRET di GitHub) e lo stesso valore va su Vercel.
 * Le tabelle di WordPress (stesso database) non si toccano: le query che le nominano sono rifiutate.
 */
require __DIR__ . '/config.php';
if (is_file(__DIR__ . '/config.bridge.php')) {
    require __DIR__ . '/config.bridge.php';
}
date_default_timezone_set(TIMEZONE);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function ponte_out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$secret = defined('DB_BRIDGE_SECRET') ? (string) DB_BRIDGE_SECRET : '';
if (strlen($secret) < 32) {
    ponte_out(404, ['ok' => false, 'error' => 'non disponibile']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ponte_out(405, ['ok' => false, 'error' => 'solo POST']);
}
$raw = (string) file_get_contents('php://input', false, null, 0, 4 * 1024 * 1024);
$ts = (string) ($_SERVER['HTTP_X_PONTE_TS'] ?? '');
$sig = strtolower((string) ($_SERVER['HTTP_X_PONTE_SIG'] ?? ''));
if (!preg_match('/^\d{9,11}$/', $ts) || abs(time() - (int) $ts) > 60
    || !hash_equals(hash_hmac('sha256', $ts . "\n" . $raw, $secret), $sig)) {
    ponte_out(403, ['ok' => false, 'error' => 'firma non valida']);
}
$req = json_decode($raw, true);
$ops = is_array($req) ? ($req['ops'] ?? null) : null;
if (!is_array($ops) || count($ops) === 0 || count($ops) > 200) {
    ponte_out(400, ['ok' => false, 'error' => 'richiesta non valida']);
}
foreach ($ops as $op) {
    $sql = is_array($op) ? ($op['sql'] ?? null) : null;
    if (!is_string($sql) || $sql === '' || preg_match('/\b(avwp|wp)_[a-z0-9_]+/i', $sql)) {
        ponte_out(400, ['ok' => false, 'error' => 'query non ammessa']);
    }
}

/** Interi e decimali come numeri (come mysql2 con decimalNumbers), date e testo come stringhe. */
function ponte_rows(PDOStatement $st): array
{
    $cast = [];
    for ($i = 0, $n = $st->columnCount(); $i < $n; $i++) {
        $m = $st->getColumnMeta($i) ?: [];
        $t = strtoupper((string) ($m['native_type'] ?? ''));
        $cast[(string) ($m['name'] ?? $i)] = in_array($t, ['TINY', 'SHORT', 'LONG', 'LONGLONG', 'INT24', 'YEAR'], true) ? 'i'
            : (in_array($t, ['NEWDECIMAL', 'DECIMAL', 'DOUBLE', 'FLOAT'], true) ? 'f' : '');
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        foreach ($r as $k => &$v) {
            if ($v !== null && ($cast[$k] ?? '') !== '') {
                $v = $cast[$k] === 'i' ? (int) $v : (float) $v;
            }
        }
    }
    return $rows;
}

// Connessione propria e non db() di lib/db.php: FOUND_ROWS fa contare a un UPDATE le righe trovate e non solo quelle cambiate, come mysql2 in
// Next (il codice di Next decide su quel numero). Stesso fuso orario di lib/db.php.
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . (defined('DB_PORT') ? ';port=' . DB_PORT : '') . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_FOUND_ROWS => true]
    );
    $pdo->exec("SET time_zone = '" . date('P') . "'");
} catch (PDOException $e) {
    ponte_out(503, ['ok' => false, 'error' => 'database non raggiungibile']);
}
$atomic = !empty($req['atomic']);
$results = [];
try {
    if ($atomic) {
        $pdo->beginTransaction();
    }
    foreach ($ops as $op) {
        $st = $pdo->prepare($op['sql']);
        $st->execute();
        $results[] = $st->columnCount() > 0
            ? ['rows' => ponte_rows($st)]
            : ['affected' => $st->rowCount(), 'insertId' => (int) $pdo->lastInsertId()];
    }
    if ($atomic) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($atomic && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ponte_out(500, ['ok' => false, 'error' => $e->getMessage(), 'at' => count($results)]);
}
ponte_out(200, ['ok' => true, 'results' => $results]);
