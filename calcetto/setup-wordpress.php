<?php
/*
 * Configurazione una tantum su Altervista (o su qualunque WordPress): si apre dal browser
 * DOPO aver fatto il login in WordPress come amministratore. Nessun altro può usarla.
 *
 *  1. "Collega il database": copia le credenziali del database di WordPress in config.local.php
 *     (che resta solo sul server, mai su GitHub) e genera il codice per install.php.
 *  2. "Rimuovi i file caricati per errore": cancella da wp-content i file del gestionale finiti lì
 *     nel primo deploy. Cancella SOLO i file identici byte per byte a quelli caricati (controllo
 *     con SHA-1); se un file è diverso lo lascia e lo segnala.
 *
 * Quando ha finito, questo file si cancella da solo.
 */
$wpLoad = dirname(__DIR__, 2) . '/wp-load.php';
if (!is_file($wpLoad)) {
    http_response_code(404);
    exit('WordPress non trovato: questo script serve solo per i siti dentro WordPress.');
}
require_once $wpLoad;

if (!is_user_logged_in() || !current_user_can('manage_options')) {
    status_header(403);
    nocache_headers();
    exit('Accesso negato. Accedi a WordPress come amministratore (' . esc_html(wp_login_url()) . '), poi ricarica questa pagina.');
}
nocache_headers();
header('X-Robots-Tag: noindex');

const OUR_TABLES = ['players', 'users', 'matches', 'match_players', 'ratings', 'mvp_votes', 'meta', 'login_attempts'];

// file caricati per errore nella radice di wp-content dal primo deploy, con il loro SHA-1
const STRAY_FILES = [
    '.htaccess' => '84ac723667f8b37fcc95051bb5778028f7176cd2',
    'action.php' => '6022589a49570ce6bcc1ab8d71230f83fff025ab',
    'admin.php' => '95272883a775eee6c899eb0656d7639dfb16afc6',
    'assets/app.js' => '177f35fffb36847d2a2c1e9768794e7357123a50',
    'assets/style.css' => 'a4aeb57adf559660f4c53cbdb85304588c573392',
    'config.php' => '07d31d9698afadc493988f95389d6354bf67b294',
    'index.php' => '70807c24cf9a18efd5158db075d8b707fb62e6c4',
    'lib/.htaccess' => '6498d20eb8a0abf4628784b52839c1266348be3a',
    'lib/auth.php' => '0deba59c251b53c42fdbc69412573853dc71982d',
    'lib/balance.php' => '17a9b4ebcaf6361936d5a282face71f68cc21e07',
    'lib/bootstrap.php' => 'a3b693a769caf5c99bd52a124209cae34f779daf',
    'lib/db.php' => 'f8e6dc4dab39cebc6fcdd93cb03a34ae575c132b',
    'lib/formation.php' => '32e1cc2aefd56fac1016567660a56023a63e949f',
    'lib/helpers.php' => '46cbfe2be4f81d7ffa64011c5cac16ab9608aee5',
    'lib/layout.php' => 'c58598187d05bf46ca156b977d92fa860b8a2453',
    'lib/schema.sql' => '3dd1d87cb0d7804fb6afaebc0a49f28731442bf1',
    'lib/stats.php' => 'ba1c84c99276fe486d773db47e9e904eaf970271',
    'login.php' => 'd7a98b4d009949f6dda8f7a1e0a5b204f8c650e3',
    'logout.php' => 'fbb891c32d313e8d2de69d30236a63382bcda57b',
    'match.php' => '0927b49e2a0c6daaa4f40735622a54bfe76afdcf',
    'matches.php' => 'c067b4f7d9535689be0bf32030e02bf8e78b08ac',
    'payments.php' => 'bb69e4113d842add5c649bcb74bfd8ef1ea6d9a4',
    'player.php' => 'b1cc5600979adb492abad2c4047bb993b42f30c5',
    'player_edit.php' => '68cc9a27572ecf2bcf5c836fb52b1cbfc38e1fb8',
    'players.php' => '0403efe0f198352a814f2da282374d7971a3c863',
    'profile.php' => 'a339e5287ccc082b65e2847037730f05a3a587b6',
    'standings.php' => '0aea69bf4b4b1a18c387c3bbab48d32d1b858a72',
    'uploads/.htaccess' => '2673e577418802ec08d4066891bdfdeabcfafb96',
];
const STRAY_DIRS = ['lib', 'assets', 'uploads/players'];   // si tolgono solo se vuote (rmdir)

$root = dirname(__DIR__);                       // wp-content
$cfg = __DIR__ . '/config.local.php';

/** @return array{delete: string[], skip: string[]} */
function scan_stray(string $root): array
{
    $delete = $skip = [];
    foreach (STRAY_FILES as $rel => $sha) {
        $f = $root . '/' . $rel;
        if (!is_file($f)) {
            continue;
        }
        if (sha1_file($f) === $sha) {
            $delete[] = $rel;
        } else {
            $skip[] = $rel;
        }
    }
    return ['delete' => $delete, 'skip' => $skip];
}

/** Testo di config.local.php. I valori passano da var_export, quindi apici e backslash sono sicuri. */
function build_local_config(string $host, ?int $port, string $user, string $pass, string $name, string $key): string
{
    $code = <<<'PHP'
<?php
// Creato da setup-wordpress.php: usa il database di WordPress. Non è su GitHub.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

PHP;
    foreach (['DB_HOST' => $host, 'DB_USER' => $user, 'DB_PASS' => $pass, 'DB_NAME' => $name] as $k => $v) {
        $code .= "define('$k', " . var_export($v, true) . ");\n";
    }
    if ($port !== null) {
        $code .= "define('DB_PORT', $port);\n";
    }
    return $code . "define('INSTALL_KEY', " . var_export($key, true) . ");\n";
}

$msgs = [];
$errors = [];
$key = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'calcetto_setup')) {
        status_header(400);
        exit('Sessione scaduta: torna indietro e ricarica la pagina.');
    }
    $do = $_POST['do'] ?? '';

    if ($do === 'link_db') {
        if (is_file($cfg)) {
            $errors[] = 'Il database è già collegato (config.local.php esiste).';
        } elseif (!defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_HOST')) {
            $errors[] = 'WordPress non espone le credenziali del database.';
        } else {
            global $wpdb;
            $existing = array_map('strtolower', (array) $wpdb->get_col('SHOW TABLES'));
            $clash = array_values(array_intersect(OUR_TABLES, $existing));
            if ($clash) {
                $errors[] = 'Nel database ci sono già tabelle con i nomi del gestionale (' . implode(', ', $clash) . '): non le tocco.';
            } else {
                $host = (string) DB_HOST;
                $port = null;
                if (substr_count($host, ':') === 1) {
                    [$h, $p] = explode(':', $host, 2);
                    if ($p !== '' && ctype_digit($p)) {
                        $host = $h;
                        $port = (int) $p;
                    }
                }
                $key = bin2hex(random_bytes(12));
                $code = build_local_config($host, $port, DB_USER, DB_PASSWORD, DB_NAME, $key);
                if (file_put_contents($cfg, $code, LOCK_EX) === false) {
                    $errors[] = 'Non riesco a scrivere config.local.php (permessi della cartella).';
                    $key = null;
                } else {
                    @chmod($cfg, 0640);
                    $msgs[] = 'Database collegato: config.local.php creato.';
                }
            }
        }
    }

    if ($do === 'cleanup') {
        $scan = scan_stray($root);
        $removed = 0;
        foreach ($scan['delete'] as $rel) {
            if (@unlink($root . '/' . $rel)) {
                $removed++;
            } else {
                $errors[] = "Non riesco a cancellare $rel.";
            }
        }
        if (in_array('index.php', $scan['delete'], true) && !file_exists($root . '/index.php')) {
            file_put_contents($root . '/index.php', "<?php\n// Silence is golden.\n");   // il file originale di WordPress
        }
        if (is_file($root . '/.ftp-deploy-sync-state.json')) {
            @unlink($root . '/.ftp-deploy-sync-state.json');
        }
        foreach (STRAY_DIRS as $d) {
            @rmdir($root . '/' . $d);
        }
        $msgs[] = "Cancellati $removed file dalla radice di wp-content; index.php di WordPress ripristinato.";
    }
}

$scan = scan_stray($root);
$linked = is_file($cfg);
$done = $linked && !$scan['delete'];

?><!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Configurazione del gestionale</title>
<style>body{font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem}
.card{border:1px solid #ccc;border-radius:8px;padding:1rem 1.25rem;margin:1rem 0}
.ok{background:#e8f6e8;border-color:#7c7}.err{background:#fde8e8;border-color:#d77}
code{background:#eee;padding:.1rem .35rem;border-radius:4px}
button{font:inherit;padding:.5rem 1rem;cursor:pointer}</style></head><body>
<h1>Configurazione del gestionale</h1>
<?php foreach ($msgs as $m): ?><div class="card ok"><?= esc_html($m) ?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="card err"><?= esc_html($e) ?></div><?php endforeach; ?>

<?php if ($key): ?>
  <div class="card ok"><strong>Codice di installazione (mostrato una sola volta, copialo):</strong>
    <p><code><?= esc_html($key) ?></code></p>
    <p>Ora apri <a href="install.php">install.php</a> e usalo per creare il primo account admin.</p></div>
<?php endif; ?>

<div class="card">
  <h2>1. Database</h2>
  <?php if ($linked): ?>
    <p>Collegato. Se non hai ancora creato l'admin, apri <a href="install.php">install.php</a>
       (il codice è in <code>config.local.php</code> sul server, oppure cancella quel file e rifai questo passo).</p>
  <?php else: ?>
    <p>Usa lo stesso database di WordPress (le tabelle del gestionale hanno nomi diversi da quelle di WordPress).</p>
    <form method="post"><?php wp_nonce_field('calcetto_setup'); ?><input type="hidden" name="do" value="link_db">
      <button>Collega il database</button></form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>2. File caricati per errore in wp-content</h2>
  <?php if ($scan['delete']): ?>
    <p>Ci sono <?= count($scan['delete']) ?> file del gestionale nella radice di <code>wp-content</code>. Verranno cancellati solo se identici a quelli caricati.</p>
    <form method="post"><?php wp_nonce_field('calcetto_setup'); ?><input type="hidden" name="do" value="cleanup">
      <button>Rimuovi i file</button></form>
  <?php else: ?>
    <p>Nessun file da rimuovere.</p>
  <?php endif; ?>
  <?php if ($scan['skip']): ?>
    <p>Lasciati al loro posto perché diversi da quelli caricati: <code><?= esc_html(implode(', ', $scan['skip'])) ?></code></p>
  <?php endif; ?>
</div>

<?php if ($done && !$key): ?>
  <div class="card ok">Fatto: questo script si cancella da solo.</div>
<?php endif; ?>
</body></html>
<?php
if ($done && !$key) {
    @unlink(__FILE__);
}
