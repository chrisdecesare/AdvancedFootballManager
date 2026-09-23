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
 * Indirizzo Google Maps con l'itinerario fino al campo. Senza punto di partenza Maps usa la posizione attuale di chi clicca
 * (sul telefono apre l'app e chiede il permesso di localizzazione).
 */
function maps_url(string $place): string
{
    return 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=' . rawurlencode(trim($place));
}

/** Etichetta del campo con il nome che porta all'itinerario su Google Maps (o il semplice testo se il campo non è stato scelto). */
function place_chip(?string $place): string
{
    $place = trim((string) $place);
    if ($place === '') {
        return '<span><i class="ti ti-map-pin"></i> Campo da definire</span>';
    }
    return '<a class="place-link" href="' . h(maps_url($place)) . '" target="_blank" rel="noopener noreferrer" title="Apri l\'itinerario in Google Maps, partendo da dove ti trovi">'
        . '<i class="ti ti-map-pin"></i> ' . h($place) . ' <i class="ti ti-route place-go"></i></a>';
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
    return q('SELECT p.id, p.name FROM players p LEFT JOIN users u ON u.player_id = p.id WHERE u.id IS NULL AND p.is_guest = 0 ORDER BY p.name')->fetchAll();
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

/**
 * Conto alla rovescia (lo aggiorna assets/app.js) verso un istante. $within = mostralo solo se mancano meno di tanti secondi
 * (0 = sempre); $reload = a zero ricarica la pagina (per vedere le votazioni chiuse). L'ora del server serve a non dipendere
 * da quella, magari sbagliata, del telefono.
 */
function countdown_html(string $dt, string $prefix, string $done, int $within = 0, bool $reload = false, string $icon = 'hourglass', string $cls = ''): string
{
    return '<span class="countdown ' . h($cls) . '" data-countdown="' . strtotime($dt) . '" data-now="' . time() . '" data-prefix="' . h($prefix)
        . '" data-done="' . h($done) . '"' . ($within ? ' data-within="' . $within . '" hidden' : '') . ($reload ? ' data-reload="1"' : '')
        . '><i class="ti ti-' . h($icon) . '"></i> <span data-cd-out></span></span>';
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
 * Salva un'immagine caricata in uploads/players, ritagliata alla proporzione $w:$h e ridimensionata a $w x $h se c'è GD.
 * Di solito il ritaglio l'ha già scelto la persona nel browser: qui si prende il centro (o, con $vAlign = 0, la parte alta,
 * così una foto intera non taglia la testa) solo per i file non ritagliati. Ritorna il percorso relativo o null;
 * in caso di errore imposta $error.
 */
function save_player_image(array $file, int $player_id, ?string &$error, string $prefix = 'p', int $w = 400, int $h = 400, float $vAlign = 0.0, string $what = 'foto'): ?string
{
    $error = null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "Caricamento dell'immagine non riuscito ($what).";
        return null;
    }
    if ($file['size'] > 6 * 1024 * 1024) {
        $error = "L'immagine supera 6 MB ($what).";
        return null;
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]]) || $info[0] < 1 || $info[1] < 1) {
        $error = "Formato non valido ($what): usa JPG, PNG, WEBP o GIF.";
        return null;
    }
    $dir = __DIR__ . '/../uploads/players';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $base = $prefix . $player_id . '_' . bin2hex(random_bytes(4));

    if (function_exists('imagecreatetruecolor')) {
        $loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp', IMAGETYPE_GIF => 'imagecreatefromgif'];
        $load = $loaders[$info[2]];
        $src = function_exists($load) ? @$load($file['tmp_name']) : false;
        if (function_exists($load) && !$src) {
            $error = "Immagine non leggibile ($what): prova con un altro file.";
            return null;
        }
        if ($src) {
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $exif = @exif_read_data($file['tmp_name']);
                $rot = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
                if ($rot) {
                    $src = imagerotate($src, $rot, 0);
                }
            }
            $sw = imagesx($src);
            $sh = imagesy($src);
            $ta = $w / $h;
            if ($sw / $sh > $ta) {                       // troppo larga: taglia ai lati, dal centro
                $cw = (int) round($sh * $ta);
                $ch = $sh;
                $cx = (int) (($sw - $cw) / 2);
                $cy = 0;
            } else {                                     // troppo alta: taglia sopra/sotto
                $cw = $sw;
                $ch = (int) round($sw / $ta);
                $cx = 0;
                $cy = (int) (($sh - $ch) * $vAlign);
            }
            $dst = imagecreatetruecolor($w, $h);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // PNG trasparenti: fondo bianco
            imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $w, $h, $cw, $ch);
            $path = 'uploads/players/' . $base . '.jpg';
            imagejpeg($dst, __DIR__ . '/../' . $path, 85);
            imagedestroy($src);
            imagedestroy($dst);
            return $path;
        }
    }
    $path = 'uploads/players/' . $base . '.' . $types[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/../' . $path)) {
        $error = "Impossibile salvare l'immagine (permessi della cartella uploads?).";
        return null;
    }
    return $path;
}

/** Foto profilo: quadrata 400x400. */
function save_player_photo(array $file, int $player_id, ?string &$error): ?string
{
    return save_player_image($file, $player_id, $error, 'p', 400, 400, 0.0, 'foto');
}

/** Immagine di sfondo del profilo: 1000x400 (proporzione 5:2). */
function save_profile_bg(array $file, int $player_id, ?string &$error): ?string
{
    return save_player_image($file, $player_id, $error, 'b', 1000, 400, 0.5, 'sfondo');
}

/** Colore #rrggbb valido (minuscolo) oppure null. */
function clean_hex_color($c): ?string
{
    $c = strtolower(trim((string) $c));
    return preg_match('/^#[0-9a-f]{6}$/', $c) ? $c : null;
}

/** Stesso colore mescolato col bianco (per le strisce dello sfondo). */
function lighten_hex(string $hex, float $amount = 0.3): string
{
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    $mix = fn($c) => (int) round($c + (255 - $c) * $amount);
    return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
}

/** Attributo style dello sfondo scelto per il profilo e per la sua carta nella Rosa ('' = colori del ruolo). */
function profile_bg_style(array $p, bool $halo = false): string
{
    if (!empty($p['bg_preset'])) {
        return '';   // sfondo speciale del negozio: lo disegna la classe bgp-... (bg_preset_class in lib/shop.php)
    }
    $img = $p['bg_image'] ?? null;
    if ($img && preg_match('#^uploads/players/[A-Za-z0-9_.-]+$#', $img) && is_file(__DIR__ . '/../' . $img)) {
        // $halo: nelle carte della Rosa resta il cerchio chiaro dietro la foto
        return 'background: ' . ($halo ? 'radial-gradient(circle at 50% 38%, rgba(255, 255, 255, .55) 0 58px, transparent 59px), ' : '')
            . "url('" . h($img) . '?v=' . filemtime(__DIR__ . '/../' . $img) . "') center / cover no-repeat, var(--pc);";
    }
    if ($c = clean_hex_color($p['bg_color'] ?? '')) {
        return '--pc: ' . $c . '; --pc2: ' . lighten_hex($c) . ';';
    }
    return '';
}

function delete_photo_file(?string $path): void
{
    if ($path && preg_match('#^uploads/players/[A-Za-z0-9_.-]+$#', $path) && !str_contains($path, '..') && is_file(__DIR__ . '/../' . $path)) {
        @unlink(__DIR__ . '/../' . $path);
    }
}
