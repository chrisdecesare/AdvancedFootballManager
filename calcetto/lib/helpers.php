<?php
function h($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Accetta solo percorsi relativi al sito (niente redirect verso altri domini). */
function safe_back($url, string $fallback = 'index.php'): string
{
    if (!is_string($url) || $url === '' || strlen($url) > 500
        || preg_match('/[\x00-\x1f\x7f\\\\]/', $url)           // a capo, caratteri di controllo, backslash
        || preg_match('#^\s*/{2}#', $url)                      // //altro-dominio.it
        || preg_match('#^\s*[a-z][a-z0-9+.-]*:#i', $url)) {   // http:..., javascript:...
        return $fallback;
    }
    return $url;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function int_get(string $k): int
{
    return isset($_GET[$k]) ? (int) $_GET[$k] : 0;
}

const GIORNI = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];
const MESI = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio',
    'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

function fmt_date_long(string $dt): string
{
    $t = strtotime($dt);
    return GIORNI[(int) date('w', $t)] . ' ' . date('j', $t) . ' ' . MESI[(int) date('n', $t)];
}

function fmt_date_short(string $dt): string
{
    return date('d/m/Y', strtotime($dt));
}

function fmt_time(string $dt): string
{
    return date('H:i', strtotime($dt));
}

function fmt_num($n, int $dec = 1): string
{
    if ($n === null) {
        return '—';
    }
    return number_format((float) $n, $dec, ',', '.');
}

/** Numero con il segno (+0,4 / 0). */
function fmt_signed($n, int $dec = 1): string
{
    $v = round((float) $n, $dec);
    return ($v > 0 ? '+' : '') . number_format($v, $dec, ',', '.');
}

function fmt_money($n): string
{
    return '€ ' . number_format((float) $n, 2, ',', '.');
}

/** Fasce colore dei voti, come su NPMK: basso / medio / buono / top. */
function vote_class($v): string
{
    if ($v === null) {
        return 'v-none';
    }
    $v = (float) $v;
    if ($v < 5.5) {
        return 'v-low';
    }
    if ($v < 7) {
        return 'v-mid';
    }
    if ($v < 8.5) {
        return 'v-good';
    }
    return 'v-top';
}

function positions(): array
{
    return ['Portiere', 'Difensore', 'Centrocampista', 'Attaccante', 'Jolly'];
}

/** Indirizzo assoluto della cartella del sito, per i link che finiscono fuori dal sito (es. nel calendario). */
function site_base_url(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^[A-Za-z0-9.-]+(:[0-9]{1,5})?$/', $host)) {
        return '';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/';
}

/**
 * Link "Aggiungi a Google Calendar" per una partita: apre Google Calendar con l'evento già compilato
 * (la partita non ha una durata salvata: si usa MATCH_DURATION_MIN). Le date vanno in ora locale + fuso.
 */
function gcal_url(array $match): string
{
    $start = new DateTime($match['match_date']);
    $end = (clone $start)->modify('+' . (int) MATCH_DURATION_MIN . ' minutes');
    $fmt = 'Ymd\THis';
    $details = [];
    if (trim((string) ($match['notes'] ?? '')) !== '') {
        $details[] = trim($match['notes']);
    }
    if ((float) ($match['fee'] ?? 0) > 0) {
        $details[] = 'Quota: ' . fmt_money($match['fee']) . ' a testa';
    }
    if ($base = site_base_url()) {
        $details[] = 'Presenze e squadre: ' . $base . 'match.php?id=' . (int) $match['id'];
    }
    $url = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
        . '&text=' . rawurlencode('Calcetto')
        . '&dates=' . $start->format($fmt) . '/' . $end->format($fmt)
        . '&ctz=' . rawurlencode(TIMEZONE);
    if ($details) {
        $url .= '&details=' . rawurlencode(implode("\n", $details));
    }
    if (trim((string) ($match['location'] ?? '')) !== '') {
        $url .= '&location=' . rawurlencode(trim($match['location']));
    }
    return $url;
}

/** Pulsante "Aggiungi a Google Calendar" (solo per le partite in programma). */
function gcal_button(array $match, string $cls = 'btn-ghost btn-sm'): string
{
    if (($match['status'] ?? '') !== 'programmata') {
        return '';
    }
    return '<a class="btn ' . $cls . '" href="' . h(gcal_url($match)) . '" target="_blank" rel="noopener noreferrer">'
        . '<i class="ti ti-calendar-plus"></i> Aggiungi a Google Calendar</a>';
}

/** Come gcal_button() ma solo con l'icona del calendario (per le righe dell'elenco partite). */
function gcal_icon_button(array $match): string
{
    if (($match['status'] ?? '') !== 'programmata') {
        return '';
    }
    return '<a class="icon-btn gcal-btn" href="' . h(gcal_url($match)) . '" target="_blank" rel="noopener noreferrer"'
        . ' title="Aggiungi a Google Calendar" aria-label="Aggiungi a Google Calendar"><i class="ti ti-calendar-plus"></i></a>';
}

/** Giocatori in rosa che non hanno ancora un account collegato. */
function free_roster_players(): array
{
    return q('SELECT p.id, p.name FROM players p LEFT JOIN users u ON u.player_id = p.id WHERE u.id IS NULL ORDER BY p.name')->fetchAll();
}

/** Nome "confrontabile": senza maiuscole, accenti e punteggiatura, con le parole in ordine alfabetico. */
function name_key(string $name): string
{
    $s = mb_strtolower(trim($name));
    $s = strtr($s, ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
    $words = array_values(array_filter(explode(' ', preg_replace('/[^a-z0-9]+/', ' ', $s)), 'strlen'));
    sort($words);
    return implode(' ', $words);
}

/**
 * Giocatore in rosa (senza account) che ha lo stesso nome di chi si iscrive: "Mario Rossi",
 * "mario rossi", "Rossi Mario" e "Màrio Rossi" coincidono. Se i candidati sono zero o più di uno
 * ritorna null (in quel caso sceglie l'admin).
 */
function find_roster_match(string $name, array $free): ?int
{
    $key = name_key($name);
    if ($key === '') {
        return null;
    }
    $hits = [];
    foreach ($free as $f) {
        if (name_key($f['name']) === $key) {
            $hits[] = (int) $f['id'];
        }
    }
    return count($hits) === 1 ? $hits[0] : null;
}

/** Ruoli selezionabili come posizione preferita: il Jolly può essere solo la seconda preferenza. */
function main_positions(): array
{
    return ['Portiere', 'Difensore', 'Centrocampista', 'Attaccante'];
}

/**
 * Applica la regola sulle preferenze: la prima è uno dei quattro ruoli, la seconda (facoltativa) è
 * un altro ruolo oppure Jolly. Un Jolly messo come prima scelta (dati vecchi) diventa la seconda:
 * la prima diventa la vecchia seconda, oppure Centrocampista se non c'era.
 * @return array{0: string, 1: ?string}
 */
function normalize_positions(?string $p1, ?string $p2): array
{
    if (!in_array($p1, main_positions(), true)) {
        $jolly = $p1 === 'Jolly' || $p2 === 'Jolly';
        $p1 = in_array($p2, main_positions(), true) ? $p2 : 'Centrocampista';
        $p2 = $jolly ? 'Jolly' : null;
    }
    if (!in_array($p2, positions(), true) || $p2 === $p1) {
        $p2 = null;
    }
    return [$p1, $p2];
}

function position_abbr(string $p): string
{
    return ['Portiere' => 'POR', 'Difensore' => 'DIF', 'Centrocampista' => 'CEN',
        'Attaccante' => 'ATT', 'Jolly' => 'JOL'][$p] ?? 'JOL';
}

function feet(): array
{
    return ['Destro', 'Sinistro', 'Ambidestro'];
}

/** Nome della squadra: quello scelto per la partita, altrimenti quello di config.php. */
function team_name(?string $t, ?array $match = null): string
{
    if ($t === 'A') {
        return ($match['team_a_name'] ?? '') !== '' ? $match['team_a_name'] : TEAM_A_NAME;
    }
    if ($t === 'B') {
        return ($match['team_b_name'] ?? '') !== '' ? $match['team_b_name'] : TEAM_B_NAME;
    }
    return '—';
}

function clean_team_name(string $name, string $default): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name));
    return $name === '' ? $default : mb_substr($name, 0, 40);
}

/** Posizioni preferite come codici: [1ª scelta, 2ª scelta o null]. */
function player_prefs(array $p): array
{
    return [position_abbr($p['position'] ?? 'Jolly'), !empty($p['position2']) ? position_abbr($p['position2']) : null];
}

/** "Attaccante / Centrocampista" */
function positions_label(array $p): string
{
    return $p['position'] . (!empty($p['position2']) ? ' / ' . $p['position2'] : '');
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $s = mb_substr($parts[0] ?? '', 0, 1);
    if (count($parts) > 1) {
        $s .= mb_substr(end($parts), 0, 1);
    }
    return mb_strtoupper($s);
}

/** Foto del giocatore o, se manca, un cerchio con le iniziali. */
function avatar(array $p, string $size = 'md'): string
{
    if (!empty($p['photo']) && is_file(__DIR__ . '/../' . $p['photo'])) {
        return '<img class="avatar avatar-' . $size . '" src="' . h($p['photo']) . '?v=' .
            filemtime(__DIR__ . '/../' . $p['photo']) . '" alt="' . h($p['name']) . '">';
    }
    $hue = crc32($p['name'] ?? '') % 360;
    return '<span class="avatar avatar-' . $size . ' avatar-initials" style="--hue:' . $hue . '">' .
        h(initials($p['name'] ?? '?')) . '</span>';
}

function result_chip(string $r): string
{
    $label = ['V' => 'V', 'N' => 'N', 'S' => 'S'][$r] ?? '?';
    $title = ['V' => 'Vittoria', 'N' => 'Pareggio', 'S' => 'Sconfitta'][$r] ?? '';
    return '<span class="chip chip-' . strtolower($r) . '" title="' . $title . '">' . $label . '</span>';
}

function form_badge(string $form): string
{
    return [
        'hot' => '<span class="fbadge fbadge-hot"><i class="ti ti-flame"></i> In forma</span>',
        'ok' => '<span class="fbadge"><i class="ti ti-minus"></i> Stabile</span>',
        'cold' => '<span class="fbadge fbadge-cold"><i class="ti ti-snowflake"></i> In calo</span>',
    ][$form] ?? '<span class="fbadge">—</span>';
}

/**
 * Salva la foto caricata in uploads/players, ritagliata quadrata 400x400 se c'è GD.
 * Ritorna il percorso relativo o null; in caso di errore imposta $error.
 */
function save_player_photo(array $file, int $player_id, ?string &$error): ?string
{
    $error = null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Caricamento della foto non riuscito.';
        return null;
    }
    if ($file['size'] > 6 * 1024 * 1024) {
        $error = 'La foto supera 6 MB.';
        return null;
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) {
        $error = 'Formato non valido: usa JPG, PNG, WEBP o GIF.';
        return null;
    }
    $dir = __DIR__ . '/../uploads/players';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $base = 'p' . $player_id . '_' . bin2hex(random_bytes(4));

    if (function_exists('imagecreatetruecolor')) {
        $loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp', IMAGETYPE_GIF => 'imagecreatefromgif'];
        $load = $loaders[$info[2]];
        $src = function_exists($load) ? @$load($file['tmp_name']) : false;
        if ($src) {
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $exif = @exif_read_data($file['tmp_name']);
                $rot = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
                if ($rot) {
                    $src = imagerotate($src, $rot, 0);
                }
            }
            $w = imagesx($src);
            $hgt = imagesy($src);
            $side = min($w, $hgt);
            $dst = imagecreatetruecolor(400, 400);
            // ritaglio centrato in orizzontale e dall'alto in verticale, così non taglia la testa
            imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), 0, 400, 400, $side, $side);
            $path = 'uploads/players/' . $base . '.jpg';
            imagejpeg($dst, __DIR__ . '/../' . $path, 85);
            imagedestroy($src);
            imagedestroy($dst);
            return $path;
        }
    }
    $path = 'uploads/players/' . $base . '.' . $types[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/../' . $path)) {
        $error = 'Impossibile salvare la foto (permessi della cartella uploads?).';
        return null;
    }
    return $path;
}

function delete_photo_file(?string $path): void
{
    if ($path && preg_match('#^uploads/players/[A-Za-z0-9_.-]+$#', $path) && !str_contains($path, '..') && is_file(__DIR__ . '/../' . $path)) {
        @unlink(__DIR__ . '/../' . $path);
    }
}
