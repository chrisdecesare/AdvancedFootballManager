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

/**
 * Giocatori in rosa che non hanno ancora un account collegato. Con $groupIds solo quelli di quelle leghe (più, se $withoutGroup,
 * quelli che non sono in nessuna): chi si iscrive a una lega non deve finire abbinato a un omonimo di un'altra.
 */
function free_roster_players(?array $groupIds = null, bool $withoutGroup = false): array
{
    $where = '';
    if ($groupIds !== null) {
        $in = $groupIds ? implode(',', array_map('intval', $groupIds)) : '0';
        $where = ' AND (EXISTS (SELECT 1 FROM player_groups pg WHERE pg.player_id = p.id AND pg.group_id IN (' . $in . '))'
            . ($withoutGroup ? ' OR NOT EXISTS (SELECT 1 FROM player_groups pg2 WHERE pg2.player_id = p.id)' : '') . ')';
    }
    return q('SELECT p.id, p.name FROM players p LEFT JOIN users u ON u.player_id = p.id WHERE u.id IS NULL AND p.is_guest = 0'
        . $where . ' ORDER BY p.name')->fetchAll();
}

/** Leghe storiche (create dall'admin del sito, senza proprietario). */
function legacy_group_ids(): array
{
    try {
        return array_map('intval', q('SELECT id FROM squad_groups WHERE owner_user_id IS NULL')->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return array_keys(all_groups());
    }
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

/*
 * Sfondo del profilo: da UNA foto si ricavano due ritagli scelti dalla persona nell'editor (assets/app.js, openBgEditor):
 *  - orizzontale 4:1 (BG_H_*): l'intestazione del profilo sui computer;
 *  - verticale 3:4 (BG_V_*): la card nella Rosa e il profilo sui telefoni, dove l'intestazione va a capo.
 * L'originale resta sul server (ridotto a BG_SRC_MAX), così i riquadri si possono cambiare in seguito senza ricaricarla.
 * Un riquadro è "x,y,w": angolo in alto a sinistra e larghezza, in frazioni della foto (0-1); l'altezza viene dalla proporzione.
 */
// dimensioni massime dei ritagli, pensate per gli schermi ad alta densità (telefoni, Mac retina): l'intestazione del profilo
// è larga fino a ~1150 px sullo schermo, cioè ~2300 pixel veri; la card della Rosa ~220 px (fino a ~660 pixel veri).
// Se il riquadro scelto ha meno pixel veri di così, il ritaglio resta alla sua misura: ingrandirlo non aggiungerebbe dettaglio.
const BG_H_W = 2400, BG_H_H = 600;   // 4:1
const BG_V_W = 900, BG_V_H = 1200;   // 3:4
const BG_SRC_MAX = 3200;             // l'originale che si conserva (lato lungo): abbastanza per zoomare senza sgranare
const BG_JPEG_Q = 90;

/** Byte massimi che il server accetta in un caricamento (il più stretto tra upload_max_filesize e post_max_size, con margine). */
function upload_max_bytes(): int
{
    $toBytes = function ($v): int {
        $v = trim((string) $v);
        $n = (float) $v;
        return (int) match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    };
    $limits = array_filter([$toBytes(ini_get('upload_max_filesize')), $toBytes(ini_get('post_max_size'))]);
    return (int) (($limits ? min($limits) : 2097152) * 0.9);   // margine per gli altri campi del modulo
}

/** Riquadro "x,y,w" ripulito e tenuto dentro la foto: [x, y, w, h] in frazioni. $ar = larghezza/altezza del ritaglio. */
function bg_rect(?string $raw, int $iw, int $ih, float $ar): array
{
    $imgAr = $iw / $ih;
    $maxW = min(1.0, $ar / $imgAr);                 // il riquadro più grande che sta nella foto con quella proporzione
    $parts = array_map('floatval', explode(',', (string) $raw));
    if (count($parts) !== 3 || $parts[2] <= 0) {
        $w = $maxW;                                  // nessuna scelta: il riquadro più grande possibile, al centro
        $h = $w * $imgAr / $ar;
        return [(1 - $w) / 2, (1 - $h) / 2, $w, $h];
    }
    $w = max(0.03, min($maxW, $parts[2]));
    $h = $w * $imgAr / $ar;
    $x = max(0.0, min(1 - $w, $parts[0]));
    $y = max(0.0, min(1 - $h, $parts[1]));
    return [$x, $y, $w, $h];
}

/** Carica un'immagine (caricata ora o già salvata) come risorsa GD, raddrizzata secondo l'EXIF. */
function bg_load(string $path, ?string &$error): ?GdImage
{
    $info = @getimagesize($path);
    $loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_WEBP => 'imagecreatefromwebp', IMAGETYPE_GIF => 'imagecreatefromgif'];
    if (!$info || !isset($loaders[$info[2]]) || !function_exists($loaders[$info[2]])) {
        $error = 'Formato non valido (sfondo): usa JPG, PNG, WEBP o GIF.';
        return null;
    }
    $img = @$loaders[$info[2]]($path);
    if (!$img) {
        $error = 'Immagine non leggibile (sfondo): prova con un altro file.';
        return null;
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $rot = [3 => 180, 6 => -90, 8 => 90][(@exif_read_data($path) ?: [])['Orientation'] ?? 1] ?? 0;
        if ($rot) {
            $img = imagerotate($img, $rot, 0);
        }
    }
    return $img;
}

/**
 * Ritaglia $rect ([x, y, w, h] in frazioni) da $src e lo salva come JPEG: al massimo $w x $h, ma mai più grande dei pixel veri
 * del riquadro (niente ingrandimenti, che sgranano soltanto). Ritorna il percorso.
 */
function bg_cut(GdImage $src, array $rect, int $w, int $h, string $name): string
{
    [$x, $y, $rw, $rh] = $rect;
    $sw = imagesx($src);
    $sh = imagesy($src);
    $cw = max(1, (int) round($rw * $sw));
    $ch = max(1, (int) round($rh * $sh));
    $k = min(1.0, $cw / $w);
    $ow = max(1, (int) round($w * $k));
    $oh = max(1, (int) round($h * $k));
    $dst = imagecreatetruecolor($ow, $oh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, (int) round($x * $sw), (int) round($y * $sh), $ow, $oh, $cw, $ch);
    if (function_exists('imageinterlace')) {
        imageinterlace($dst, true);   // JPEG progressivo: si vede subito, poi si definisce
    }
    $path = 'uploads/players/' . $name . '.jpg';
    imagejpeg($dst, __DIR__ . '/../' . $path, BG_JPEG_Q);
    imagedestroy($dst);
    return $path;
}

/**
 * Salva lo sfondo: dalla foto caricata ora ($file) oppure da quella già sul server ($srcPath, per cambiare solo i riquadri),
 * con i riquadri scelti. Ritorna ['src', 'h', 'v', 'crop'] (percorsi e riquadri da salvare) oppure null ($error se c'è un problema).
 */
function save_profile_bg_set(array $file, ?string $srcPath, ?string $rawH, ?string $rawV, int $player_id, ?string &$error): ?array
{
    $error = null;
    if (!function_exists('imagecreatetruecolor')) {
        $error = 'Il server non può elaborare immagini (manca l\'estensione GD di PHP).';
        return null;
    }
    $dir = __DIR__ . '/../uploads/players';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $tag = $player_id . '_' . bin2hex(random_bytes(4));
    $uploaded = ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($uploaded) {
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $error = 'L\'immagine è più grande di quanto accetta il server (' . round(upload_max_bytes() / 1048576, 1) . ' MB): riprova, il browser la ridurrà.';
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = "Caricamento dell'immagine non riuscito (sfondo).";
            return null;
        }
        if ($file['size'] > 15 * 1024 * 1024) {
            $error = "L'immagine supera 15 MB (sfondo).";
            return null;
        }
        if (!($src = bg_load($file['tmp_name'], $error))) {
            return null;
        }
        $srcPath = 'uploads/players/s' . $tag . '.jpg';
        $sw = imagesx($src);
        $sh = imagesy($src);
        $info = @getimagesize($file['tmp_name']);
        $rot = $info && $info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data') ? ((@exif_read_data($file['tmp_name']) ?: [])['Orientation'] ?? 1) : 1;
        if ($info && $info[2] === IMAGETYPE_JPEG && max($sw, $sh) <= BG_SRC_MAX && in_array((int) $rot, [0, 1], true)) {
            // JPEG già della misura giusta e dritto: si tiene il file così com'è, senza ricomprimerlo (ogni passaggio perde qualità)
            if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/../' . $srcPath) && !copy($file['tmp_name'], __DIR__ . '/../' . $srcPath)) {
                $error = "Impossibile salvare l'immagine (permessi della cartella uploads?).";
                return null;
            }
        } else {
            // troppo grande (o da raddrizzare): si riduce una volta sola, con qualità alta
            $k = min(1.0, BG_SRC_MAX / max($sw, $sh));
            if ($k < 1) {
                $small = imagecreatetruecolor((int) round($sw * $k), (int) round($sh * $k));
                imagecopyresampled($small, $src, 0, 0, 0, 0, imagesx($small), imagesy($small), $sw, $sh);
                imagedestroy($src);
                $src = $small;
            }
            imagejpeg($src, __DIR__ . '/../' . $srcPath, 93);
        }
    } else {
        if (!$srcPath || !preg_match('#^uploads/players/[A-Za-z0-9_.-]+$#', $srcPath) || !is_file(__DIR__ . '/../' . $srcPath)) {
            return null;   // niente foto nuova e niente originale: non c'è niente da ritagliare
        }
        if (!($src = bg_load(__DIR__ . '/../' . $srcPath, $error))) {
            return null;
        }
    }
    $iw = imagesx($src);
    $ih = imagesy($src);
    $rh = bg_rect($rawH, $iw, $ih, BG_H_W / BG_H_H);
    $rv = bg_rect($rawV, $iw, $ih, BG_V_W / BG_V_H);
    $out = [
        'src' => $srcPath,
        'h' => bg_cut($src, $rh, BG_H_W, BG_H_H, 'b' . $tag),
        'v' => bg_cut($src, $rv, BG_V_W, BG_V_H, 'v' . $tag),
        'crop' => json_encode(['h' => array_map(fn($n) => round($n, 4), array_slice($rh, 0, 3)), 'v' => array_map(fn($n) => round($n, 4), array_slice($rv, 0, 3))]),
    ];
    imagedestroy($src);
    return $out;
}

/** File dello sfondo di un giocatore (per cancellare quelli che non servono più). */
function bg_files(array $p): array
{
    return array_values(array_filter([$p['bg_image'] ?? null, $p['bg_image_v'] ?? null, $p['bg_src'] ?? null]));
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
function profile_bg_style(array $p): string
{
    if (!empty($p['bg_preset'])) {
        return '';   // sfondo speciale del negozio: lo disegna la classe bgp-... (bg_preset_class in lib/shop.php)
    }
    // percorso assoluto dal dominio (es. /wp-content/calcetto/uploads/...): un url() relativo dentro una variabile CSS
    // verrebbe risolto rispetto a assets/style.css, dove la variabile si usa, e non rispetto alla pagina
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    $url = function (?string $f) use ($base): ?string {
        return $f && preg_match('#^uploads/players/[A-Za-z0-9_.-]+$#', $f) && is_file(__DIR__ . '/../' . $f)
            ? "url('" . h($base . $f) . '?v=' . filemtime(__DIR__ . '/../' . $f) . "')" : null;
    };
    $hImg = $url($p['bg_image'] ?? null);
    if ($hImg) {
        // orizzontale per l'intestazione del profilo, verticale per la card della Rosa e per il profilo sui telefoni
        // (i vecchi sfondi hanno solo quella orizzontale: si usa per entrambe, come prima). Le regole sono in style.css.
        $vImg = $url($p['bg_image_v'] ?? null) ?? $hImg;
        return '--bg-h: ' . $hImg . '; --bg-v: ' . $vImg . ';';
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
