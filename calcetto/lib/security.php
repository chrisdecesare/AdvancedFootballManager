<?php
/*
 * Livelli di sicurezza in più, oltre a quelli di base (CSRF, query preparate, password cifrate, blocco dei tentativi per IP,
 * cookie Secure/HttpOnly/SameSite, HTTPS, .htaccess):
 *  - verifica in due passaggi (TOTP, le app tipo Google Authenticator) con codici di recupero usa e getta;
 *  - "conferma la password": le aree admin e le azioni distruttive la richiedono se l'ultima conferma è vecchia;
 *  - avviso via email per un accesso da un dispositivo mai visto;
 *  - blocco di uno username attaccato da tante connessioni diverse (non solo per IP), con avviso al proprietario;
 *  - moduli pubblici con trappola a tempo contro i bot (oltre al campo nascosto);
 *  - limite ai codici d'invito tentati a caso;
 *  - sessione legata al browser che l'ha aperta;
 *  - eventi di sicurezza nel registro delle operazioni (platform.php → Sicurezza).
 */

const LOGIN_MAX_PER_NAME = 15;       // tentativi falliti su uno username da qualunque connessione, nella finestra di login_blocked()
const RECENT_AUTH_ADMIN = 1800;      // aree admin: password riconfermata negli ultimi 30 minuti (il tempo si allunga finché le usi)
const RECENT_AUTH_DANGER = 600;      // azioni distruttive: negli ultimi 10 minuti
const TOTP_RECOVERY_CODES = 8;
const INVITE_MAX_BAD_PER_HOUR = 20;  // codici d'invito sbagliati per connessione in un'ora

/** Evento di sicurezza nel registro (azioni "sicurezza_..."). */
function security_log(string $what, string $detail = '', ?int $userId = null): void
{
    log_activity('sicurezza_' . $what, $detail, null, $userId);
}

/* ---------------------------------------------------------------- TOTP (verifica in due passaggi) */

function base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

/** Codice a 6 cifre per un intervallo di 30 secondi (RFC 6238, HMAC-SHA1: quello delle app Authenticator). */
function totp_code(string $secret, int $step): string
{
    $hash = hash_hmac('sha1', pack('J', $step), base32_decode($secret), true);
    $o = ord($hash[19]) & 0x0f;
    $n = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Controlla un codice (accetta anche l'intervallo prima e dopo, per gli orologi un po' sfasati). Ritorna l'intervallo usato
 * oppure null. Un codice già usato non vale una seconda volta ($lastStep).
 */
function totp_match(string $secret, string $code, int $lastStep = 0): ?int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) {
        return null;
    }
    $now = intdiv(time(), 30);
    foreach ([0, -1, 1] as $d) {
        $step = $now + $d;
        if ($step > $lastStep && hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function totp_new_secret(): string
{
    return base32_encode(random_bytes(20));
}

/** Indirizzo da mettere nel QR code (lo leggono le app Authenticator). */
function totp_uri(string $secret, string $username): string
{
    $issuer = APP_NAME;
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $username) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&period=30&digits=6';
}

/** Codici di recupero: [in chiaro da mostrare una volta, impronte da salvare]. */
function totp_new_recovery(): array
{
    $plain = [];
    $hashes = [];
    for ($i = 0; $i < TOTP_RECOVERY_CODES; $i++) {
        $c = strtolower(substr(base32_encode(random_bytes(8)), 0, 10));
        $plain[] = substr($c, 0, 5) . '-' . substr($c, 5);
        $hashes[] = password_hash($c, PASSWORD_DEFAULT);
    }
    return [$plain, $hashes];
}

function totp_enabled_for(int $uid): bool
{
    try {
        return (bool) q('SELECT totp_secret IS NOT NULL FROM users WHERE id = ?', [$uid])->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Verifica il secondo passaggio di un account: codice dell'app oppure un codice di recupero (che poi non vale più).
 * Ritorna 'ok', 'recovery' (usato un codice di recupero) o null.
 */
function totp_verify_user(int $uid, string $input): ?string
{
    $r = q('SELECT totp_secret, totp_recovery, totp_last_step FROM users WHERE id = ?', [$uid])->fetch();
    if (!$r || !$r['totp_secret']) {
        return null;
    }
    if (($step = totp_match($r['totp_secret'], $input, (int) $r['totp_last_step'])) !== null) {
        q('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $uid]);   // lo stesso codice non si riusa
        return 'ok';
    }
    $plain = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $input));
    if (strlen($plain) === 10) {
        $codes = json_decode($r['totp_recovery'] ?? '[]', true) ?: [];
        foreach ($codes as $i => $hash) {
            if (password_verify($plain, $hash)) {
                unset($codes[$i]);
                q('UPDATE users SET totp_recovery = ? WHERE id = ?', [json_encode(array_values($codes)), $uid]);
                return 'recovery';
            }
        }
    }
    return null;
}

/* ---------------------------------------------------------------- accesso completo e dispositivi */

/** Chiude il login (password ed eventuale secondo passaggio già verificati). */
function complete_login(int $uid, string $username = ''): void
{
    $sv = (int) q('SELECT session_version FROM users WHERE id = ?', [$uid])->fetchColumn();
    q('DELETE FROM login_attempts WHERE ip = ? AND username = ?', [client_ip(), mb_strtolower($username)]);
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa']);
    $_SESSION['uid'] = $uid;
    $_SESSION['sv'] = $sv;
    mark_authenticated();
    session_bind_browser();
    remember_issue($uid);
    log_activity('accesso', '', null, $uid);
    device_check($uid);
}

/** Password (ed eventuale codice) appena verificata: vale come "conferma recente" per le aree admin. */
function mark_authenticated(): void
{
    $_SESSION['auth_at'] = time();
}

/** Famiglia del dispositivo (sistema + browser), abbastanza stabile da non cambiare a ogni aggiornamento del browser. */
function device_family(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $os = preg_match('/iPhone/i', $ua) ? 'iPhone' : (preg_match('/iPad/i', $ua) ? 'iPad' : (preg_match('/Android/i', $ua) ? 'Android'
        : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS X|Macintosh/i', $ua) ? 'Mac' : (preg_match('/Linux|X11/i', $ua) ? 'Linux' : 'Altro')))));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung Internet'
        : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Chrome\/|CriOS/', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'browser')))));
    return $br . ' su ' . $os;
}

/** Dopo un accesso: se il dispositivo è nuovo per l'account (e non è il primo), lo registra e avvisa via email. */
function device_check(int $uid): void
{
    try {
        $dev = device_family();
        $known = (int) q('SELECT COUNT(*) FROM known_devices WHERE user_id = ?', [$uid])->fetchColumn();
        $seen = q('SELECT 1 FROM known_devices WHERE user_id = ? AND device = ?', [$uid, $dev])->fetch();
        q('INSERT INTO known_devices (user_id, device, last_ip) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_seen = NOW(), last_ip = VALUES(last_ip)',
            [$uid, $dev, client_ip()]);
        if (!$seen && $known > 0) {
            security_log('nuovo_dispositivo', $dev, $uid);
            if ($email = verified_email($uid)) {
                push_defer(fn() => send_mail($email, 'Nuovo accesso al tuo account · ' . APP_NAME, mail_text(mail_name($uid), [
                    'Qualcuno è entrato nel tuo account da un dispositivo che non avevi mai usato: ' . $dev . ', il ' . date('d/m/Y') . ' alle ' . date('H:i') . '.',
                    'Se sei stato tu, puoi ignorare questo messaggio.',
                    'Se non sei stato tu, cambia subito la password e scollega gli altri dispositivi da qui: ' . trusted_base_url() . 'account.php',
                ])));
            }
        }
    } catch (Throwable $e) {
        error_log('device_check: ' . $e->getMessage());
    }
}

/* ---------------------------------------------------------------- sessione */

function browser_fingerprint(): string
{
    return substr(hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')), 0, 32);
}

function session_bind_browser(): void
{
    $_SESSION['fp'] = browser_fingerprint();
}

/**
 * All'inizio di ogni richiesta: una sessione aperta da un altro browser (cookie di sessione rubato e usato altrove) non vale.
 * Chi ha il "resta collegato" sul proprio dispositivo rientra da solo subito dopo (remember_check).
 */
function session_guard(): void
{
    if (defined('NO_SESSION') || empty($_SESSION['uid'])) {
        return;
    }
    if (!isset($_SESSION['fp'])) {
        session_bind_browser();   // sessione nata prima di questo controllo: la si adotta
        return;
    }
    if (!hash_equals($_SESSION['fp'], browser_fingerprint())) {
        security_log('sessione_altro_browser', device_family(), (int) $_SESSION['uid']);
        $_SESSION = [];
        session_regenerate_id(true);
    }
}

/* ---------------------------------------------------------------- conferma recente della password */

/** La password è stata confermata negli ultimi $seconds secondi? */
function auth_is_recent(int $seconds): bool
{
    return time() - (int) ($_SESSION['auth_at'] ?? 0) <= $seconds;
}

/**
 * Pagina o azione protetta: se la password non è stata confermata di recente, si passa da confirm.php e poi si torna qui.
 * $slide = ogni uso allunga il tempo (per le aree admin: si richiede solo dopo un po' di inattività).
 */
function require_recent_auth(int $seconds = RECENT_AUTH_ADMIN, bool $slide = true): void
{
    if (auth_is_recent($seconds)) {
        if ($slide) {
            mark_authenticated();
        }
        return;
    }
    if (is_post()) {   // i moduli puntano alla pagina stessa: dopo la conferma si torna lì e si ripete l'operazione
        flash('err', 'Per sicurezza conferma la password, poi ripeti l\'operazione.');
    }
    redirect('confirm.php?next=' . urlencode(safe_back($_SERVER['REQUEST_URI'] ?? null)));
}

/* ---------------------------------------------------------------- login: blocco per username */

/** Troppi tentativi falliti su questo username da connessioni diverse (attacco distribuito)? */
function login_name_blocked(string $username): bool
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_MIN * 60);
    return (int) q('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND created_at > ?', [$username, $since])->fetchColumn() >= LOGIN_MAX_PER_NAME;
}

/** Avvisa (al massimo una volta all'ora) il proprietario di uno username sotto attacco. */
function notify_login_attack(string $username): void
{
    $u = q('SELECT id FROM users WHERE LOWER(username) = ?', [$username])->fetch();
    if (!$u || !rate_hit('__attack_mail_' . $u['id'], 1, 3600)) {
        return;
    }
    $uid = (int) $u['id'];
    security_log('username_bloccato', $username, $uid);
    if ($email = verified_email($uid)) {
        push_defer(fn() => send_mail($email, 'Tentativi di accesso al tuo account · ' . APP_NAME, mail_text(mail_name($uid), [
            'Nell\'ultimo quarto d\'ora ci sono stati molti tentativi di entrare nel tuo account con password sbagliate, da connessioni diverse.',
            'Per proteggerti l\'accesso con la password è bloccato per ' . LOGIN_WINDOW_MIN . ' minuti. Se non eri tu, ti conviene scegliere una password più robusta'
                . ' e attivare la verifica in due passaggi: ' . trusted_base_url() . 'account.php',
        ])));
    }
}

/* ---------------------------------------------------------------- moduli pubblici: trappola a tempo */

function form_secret(): string
{
    $s = meta_get('form_secret');
    if (!$s) {
        $s = bin2hex(random_bytes(32));
        meta_set('form_secret', $s);
    }
    return $s;
}

/** Campo nascosto con l'ora in cui il modulo è stato mostrato (firmata: non si può falsificare). */
function form_trap_field(): string
{
    $t = (string) time();
    return '<input type="hidden" name="ft" value="' . $t . '.' . substr(hash_hmac('sha256', $t, form_secret()), 0, 24) . '">';
}

/**
 * Il modulo è stato compilato da una persona? Troppo in fretta (meno di $min secondi), senza firma o vecchio di ore = bot.
 * Registra l'evento; chi lo usa mostra un errore generico.
 */
function form_trap_ok(int $min = 3): bool
{
    $v = is_string($_POST['ft'] ?? null) ? $_POST['ft'] : '';
    $ok = false;
    if (preg_match('/^(\d{9,11})\.([a-f0-9]{24})$/', $v, $m) && hash_equals(substr(hash_hmac('sha256', $m[1], form_secret()), 0, 24), $m[2])) {
        $age = time() - (int) $m[1];
        $ok = $age >= $min && $age <= 7200;
    }
    if (!$ok) {
        security_log('bot', basename($_SERVER['SCRIPT_NAME'] ?? '') . ' · modulo troppo veloce o scaduto');
    }
    return $ok;
}

/* ---------------------------------------------------------------- codici d'invito */

/** Troppi codici d'invito sbagliati da questa connessione? (chi prova codici a caso per entrare nelle leghe) */
function invite_blocked(): bool
{
    return rate_recent('__inv_' . client_ip(), 3600) >= INVITE_MAX_BAD_PER_HOUR;
}

function invite_bad_attempt(string $code): void
{
    rate_record('__inv_' . client_ip());
    security_log('invito', 'codice sbagliato: ' . mb_substr($code, 0, 20));
}
