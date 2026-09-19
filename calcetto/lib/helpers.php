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
