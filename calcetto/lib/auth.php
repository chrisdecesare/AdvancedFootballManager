<?php
function current_user(): ?array
{
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $u = q('SELECT u.id, u.username, u.role, u.player_id, u.tour_done, u.email, u.pending_email, u.session_version, p.name AS player_name, p.photo, p.guest_match_id
                FROM users u LEFT JOIN players p ON p.id = u.player_id WHERE u.id = ? AND u.status = \'attivo\'',
            [$_SESSION['uid']])->fetch() ?: null;
        if ($u && !isset($_SESSION['sv'])) {
            $_SESSION['sv'] = (int) $u['session_version'];       // sessione nata prima di questo controllo: la si adotta
        } elseif ($u && (int) $_SESSION['sv'] !== (int) $u['session_version']) {
            $u = null;                                            // la password è cambiata da un altro dispositivo: si rifà l'accesso
        }
        if (!$u) {
            unset($_SESSION['uid'], $_SESSION['sv']);
        }
    }
    return $u;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/** Manager: crea e gestisce le partite dei suoi gruppi, ma non è un admin (niente account, gruppi, giocatori, pagamenti). */
function is_manager(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'manager';
}

/**
 * Gestisce le partite di almeno una lega (admin e manager del sito, owner/admin/manager di una lega): serve a mostrare
 * i comandi generali. Su una partita precisa conta can_manage_group() con la sua lega (lib/leagues.php).
 */
function can_manage_matches(): bool
{
    return is_admin() || is_manager() || (bool) my_league_roles();
}

/** Ruolo valido da un valore ricevuto dal browser ('player' se non riconosciuto). */
function clean_role($role): string
{
    return in_array($role, ['admin', 'manager'], true) ? $role : 'player';
}

function role_label(string $role): string
{
    return ['admin' => 'Admin', 'manager' => 'Manager', 'ospite' => 'Ospite'][$role] ?? 'Giocatore';
}

function my_player_id(): ?int
{
    $u = current_user();
    return $u && $u['player_id'] ? (int) $u['player_id'] : null;
}

function require_login(): void
{
    if (!current_user()) {
        redirect('login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? 'index.php'));
    }
    guest_gate();   // un ospite vede solo la sua partita
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        security_log('accesso_negato', (string) ($_SERVER['REQUEST_URI'] ?? ''));
        http_response_code(403);
        layout_start('Accesso negato');
        echo '<div class="card"><h2>Accesso negato</h2><p>Questa pagina è riservata agli admin.</p></div>';
        layout_end();
        exit;
    }
}

/** Pagina riservata a chi gestisce le partite (admin e manager). */
function require_match_manager(): void
{
    require_login();
    if (!can_manage_matches()) {
        security_log('accesso_negato', (string) ($_SERVER['REQUEST_URI'] ?? ''));
        http_response_code(403);
        layout_start('Accesso negato');
        echo '<div class="card"><h2>Accesso negato</h2><p>Questa pagina è riservata a chi gestisce le partite.</p></div>';
        layout_end();
        exit;
    }
}

function require_view(): void
{
    if (!PUBLIC_READ) {
        require_login();
    }
    guest_gate();
}

const PASSWORD_MIN = 8;
const LOGIN_MAX_PER_USER = 5;   // tentativi falliti per IP + username...
const LOGIN_MAX_PER_IP = 20;    // ...e per solo IP, nella finestra sotto
const LOGIN_WINDOW_MIN = 15;

/** Password troppo comuni per essere accettate (in minuscolo). */
const COMMON_PASSWORDS = ['password', 'password1', 'password123', '12345678', '123456789', '1234567890', '11111111', '00000000', 'qwertyui',
    'qwerty123', 'qwertyuiop', 'abcd1234', 'abc12345', 'iloveyou', 'letmein1', 'admin123', 'calcetto', 'calcetto1', 'calcetto123',
    'football', 'juventus', 'password!', 'passw0rd', 'welcome1', 'trustno1', 'asdfghjk', 'zxcvbnm1', 'ciaociao', 'ciao1234', 'italia123'];

/** Messaggio d'errore se la password non è accettabile, altrimenti null. */
function password_error(string $password, ?string $username = null): ?string
{
    if (strlen($password) < PASSWORD_MIN) {
        return 'La password deve avere almeno ' . PASSWORD_MIN . ' caratteri.';
    }
    if (strlen($password) > 72) {
        return 'La password può avere al massimo 72 caratteri.';
    }
    $low = mb_strtolower($password);
    if (in_array($low, COMMON_PASSWORDS, true) || preg_match('/^(.)\1*$/u', $password) || in_array($low, ['01234567', '0123456789', '87654321', 'abcdefgh'], true)) {
        return 'Questa password è troppo semplice: scegline una meno prevedibile.';
    }
    if ($username !== null && $low === mb_strtolower($username)) {
        return 'La password non può essere uguale allo username.';
    }
    return null;
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

/**
 * true se questo IP (o questo IP con questo username) ha fatto troppi tentativi falliti, oppure se lo username
 * è sotto attacco da tante connessioni diverse (lib/security.php).
 */
function login_blocked(string $username): bool
{
    if ($username !== '' && login_name_blocked($username)) {
        notify_login_attack($username);
        return true;
    }
    $since = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_MIN * 60);
    $ip = client_ip();
    $perIp = (int) q('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?', [$ip, $since])->fetchColumn();
    if ($perIp >= LOGIN_MAX_PER_IP) {
        return true;
    }
    $perUser = (int) q('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ? AND created_at > ?',
        [$ip, $username, $since])->fetchColumn();
    return $perUser >= LOGIN_MAX_PER_USER;
}

const REGISTER_MAX_PER_IP_HOUR = 10;     // iscrizioni per IP in un'ora
const REGISTER_MAX_PENDING = 100;        // iscrizioni in attesa di approvazione, in totale

/** Numero di iscrizioni in attesa di approvazione (per l'admin). */
function pending_count(): int
{
    return (int) q("SELECT COUNT(*) FROM users WHERE status = 'in_attesa'")->fetchColumn();
}

/** true se questo IP ha già fatto troppe iscrizioni nell'ultima ora. */
function registration_blocked(): bool
{
    $since = date('Y-m-d H:i:s', time() - 3600);
    return (int) q("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = '__iscrizione__' AND created_at > ?",
        [client_ip(), $since])->fetchColumn() >= REGISTER_MAX_PER_IP_HOUR;
}

function registration_hit(): void
{
    q("INSERT INTO login_attempts (ip, username) VALUES (?, '__iscrizione__')", [client_ip()]);
}

const REMEMBER_DAYS = 180;   // per quanto tempo resta collegato chi non esce dall'account

/**
 * "Resta collegato": oltre alla sessione (che sparisce chiudendo il browser o l'app) si lascia un cookie a lunga scadenza
 * con un codice casuale; nel database c'è solo la sua impronta. Il nome inizia come quello della sessione perché la cache
 * di Altervista toglie gli altri cookie (vedi bootstrap.php).
 */
function remember_cookie_name(): string
{
    return 'wordpress_logged_in_' . md5('calcetto-manager-remember');
}

function remember_set_cookie(string $value, int $expires): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    if (!headers_sent()) {
        setcookie(remember_cookie_name(), $value, ['expires' => $expires, 'path' => '/', 'secure' => $https,
            'httponly' => true, 'samesite' => 'Lax']);
    }
}

/** Crea il codice "resta collegato" per questo dispositivo e lo mette nel cookie. */
function remember_issue(int $uid): void
{
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(24));
    $exp = time() + REMEMBER_DAYS * 86400;
    q('DELETE FROM auth_tokens WHERE expires_at < ?', [date('Y-m-d H:i:s')]);
    q('INSERT INTO auth_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)',
        [$uid, $selector, hash('sha256', $validator), date('Y-m-d H:i:s', $exp)]);
    // al massimo 10 dispositivi per account: i più vecchi escono
    q('DELETE FROM auth_tokens WHERE user_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM auth_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 10) t)',
        [$uid, $uid]);
    remember_set_cookie($selector . ':' . $validator, $exp);
    $_SESSION['remember_tried'] = 1;
}

/** Togli il "resta collegato" di questo dispositivo (uscita dall'account). */
function remember_forget(): void
{
    $c = $_COOKIE[remember_cookie_name()] ?? '';
    if (is_string($c) && preg_match('/^([0-9a-f]{18}):/', $c, $m)) {
        q('DELETE FROM auth_tokens WHERE selector = ?', [$m[1]]);
    }
    remember_set_cookie('', time() - 3600);
}

/** Annulla tutti i "resta collegato" di un account (cambio password); se è l'account in uso, questo dispositivo resta collegato. */
function remember_revoke(int $uid): void
{
    q('DELETE FROM auth_tokens WHERE user_id = ?', [$uid]);
    if ($uid === (int) ($_SESSION['uid'] ?? 0) && !defined('NO_SESSION')) {
        remember_issue($uid);
    }
}

/**
 * Dopo un cambio di password: tutte le sessioni e i "resta collegato" di quell'account decadono (si rifà l'accesso);
 * se è l'account in uso, questo dispositivo resta collegato.
 */
function security_reset_sessions(int $uid): void
{
    q('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$uid]);
    if ($uid === (int) ($_SESSION['uid'] ?? 0) && !defined('NO_SESSION')) {
        $_SESSION['sv'] = (int) q('SELECT session_version FROM users WHERE id = ?', [$uid])->fetchColumn();
    }
    remember_revoke($uid);
}

/**
 * All'inizio di ogni richiesta: se la sessione è scaduta ma il cookie "resta collegato" è valido, ricollega l'utente;
 * chi è già collegato e non ha ancora il cookie (accesso precedente) lo riceve alla prima pagina che apre.
 */
function remember_check(): void
{
    if (defined('NO_SESSION') || defined('NO_REMEMBER')) {
        return;
    }
    $c = $_COOKIE[remember_cookie_name()] ?? '';
    $valid = is_string($c) && preg_match('/^([0-9a-f]{18}):([0-9a-f]{48})$/', $c, $m);
    if (!empty($_SESSION['uid'])) {
        if (!$valid && empty($_SESSION['remember_tried']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            remember_issue((int) $_SESSION['uid']);
        }
        return;
    }
    if (!$valid) {
        return;
    }
    $t = q("SELECT t.id, t.user_id, t.token_hash, t.expires_at, u.status, u.session_version FROM auth_tokens t
            JOIN users u ON u.id = t.user_id WHERE t.selector = ?", [$m[1]])->fetch();
    if ($t && !hash_equals($t['token_hash'], hash('sha256', $m[2]))) {
        q('DELETE FROM auth_tokens WHERE id = ?', [$t['id']]);      // selettore giusto e codice sbagliato: non è chi dice di essere
        $t = null;
    }
    if (!$t || $t['status'] !== 'attivo' || strtotime($t['expires_at']) < time()) {
        remember_set_cookie('', time() - 3600);
        return;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $t['user_id'];
    $_SESSION['sv'] = (int) $t['session_version'];
    $_SESSION['remember_tried'] = 1;
    session_bind_browser();   // niente mark_authenticated(): un dispositivo "ricordato" non vale come password appena confermata
    // la scadenza si sposta in avanti (al massimo una volta al giorno) finché lo si usa
    $exp = time() + REMEMBER_DAYS * 86400;
    if ($exp - strtotime($t['expires_at']) > 86400) {
        q('UPDATE auth_tokens SET expires_at = ? WHERE id = ?', [date('Y-m-d H:i:s', $exp), $t['id']]);
        remember_set_cookie($m[1] . ':' . $m[2], $exp);
    }
}

/** Ritorna 'ok', 'pending' (iscrizione non ancora approvata), 'blocked' (troppi tentativi) o 'fail'. */
function attempt_login(string $username, string $password): string
{
    $username = mb_strtolower(mb_substr(trim($username), 0, 50));
    if (login_blocked($username)) {
        security_log('login_bloccato', $username);
        return 'blocked';
    }
    $u = q('SELECT id, password_hash, status, session_version, totp_secret FROM users WHERE LOWER(username) = ?', [$username])->fetch();
    // se l'utente non esiste verifica comunque un hash bcrypt valido (di una password qualsiasi): tempi simili, così non si scoprono gli username
    $hash = $u['password_hash'] ?? '$2y$10$.vGA1O9wmRjrwAVXD98HNOgsNpDczlqm3Jq7KnEd1rVAGv3Fykk1a';
    $valid = password_verify($password, $hash);
    if ($u && $valid && $u['status'] !== 'attivo') {
        return 'pending';   // password giusta ma l'admin non ha ancora approvato l'iscrizione
    }
    if ($u && $valid) {
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        if (!empty($u['totp_secret'])) {
            // password giusta ma serve anche il codice dell'app: l'accesso non è ancora fatto (login.php chiede il codice)
            session_regenerate_id(true);
            $_SESSION['pending_2fa'] = ['uid' => (int) $u['id'], 'username' => $username, 'at' => time(), 'tries' => 0];
            return '2fa';
        }
        complete_login((int) $u['id'], $username);   // lib/security.php: sessione, "resta collegato", registro, dispositivo nuovo
        return 'ok';
    }
    security_log('login_fallito', $username, $u ? (int) $u['id'] : null);
    q('INSERT INTO login_attempts (ip, username) VALUES (?, ?)', [client_ip(), $username]);
    q('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    usleep(500000);
    return 'fail';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function verify_csrf(): void
{
    if (is_post() && !hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        if (!empty($_SESSION['uid'])) {   // con la sessione scaduta capita anche a chi è in buona fede: si registra solo chi è collegato
            security_log('csrf', (string) ($_SERVER['REQUEST_URI'] ?? ''), (int) $_SESSION['uid']);
        }
        http_response_code(400);
        die('Sessione scaduta: torna indietro e ricarica la pagina.');
    }
}
