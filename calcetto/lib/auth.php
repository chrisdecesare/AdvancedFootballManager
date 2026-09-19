<?php
function current_user(): ?array
{
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $u = q('SELECT u.id, u.username, u.role, u.player_id, p.name AS player_name, p.photo
                FROM users u LEFT JOIN players p ON p.id = u.player_id WHERE u.id = ? AND u.status = \'attivo\'',
            [$_SESSION['uid']])->fetch() ?: null;
        if (!$u) {
            unset($_SESSION['uid']);
        }
    }
    return $u;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
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
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        layout_start('Accesso negato');
        echo '<div class="card"><h2>Accesso negato</h2><p>Questa pagina è riservata agli admin.</p></div>';
        layout_end();
        exit;
    }
}

function require_view(): void
{
    if (!PUBLIC_READ) {
        require_login();
    }
}

const PASSWORD_MIN = 8;
const LOGIN_MAX_PER_USER = 5;   // tentativi falliti per IP + username...
const LOGIN_MAX_PER_IP = 20;    // ...e per solo IP, nella finestra sotto
const LOGIN_WINDOW_MIN = 15;

/** Messaggio d'errore se la password non è accettabile, altrimenti null. */
function password_error(string $password): ?string
{
    if (strlen($password) < PASSWORD_MIN) {
        return 'La password deve avere almeno ' . PASSWORD_MIN . ' caratteri.';
    }
    if (strlen($password) > 72) {
        return 'La password può avere al massimo 72 caratteri.';
    }
    return null;
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

/** true se questo IP (o questo IP con questo username) ha fatto troppi tentativi falliti. */
function login_blocked(string $username): bool
{
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

/** Ritorna 'ok', 'blocked' (troppi tentativi) o 'fail'. */
function attempt_login(string $username, string $password): string
{
    $username = mb_strtolower(mb_substr(trim($username), 0, 50));
    if (login_blocked($username)) {
        return 'blocked';
    }
    $u = q('SELECT id, password_hash, status FROM users WHERE LOWER(username) = ?', [$username])->fetch();
    // se l'utente non esiste verifica comunque un hash bcrypt valido (di una password qualsiasi): tempi simili, così non si scoprono gli username
    $hash = $u['password_hash'] ?? '$2y$10$.vGA1O9wmRjrwAVXD98HNOgsNpDczlqm3Jq7KnEd1rVAGv3Fykk1a';
    $valid = password_verify($password, $hash);
    if ($u && $valid && $u['status'] === 'attivo') {
        q('DELETE FROM login_attempts WHERE ip = ? AND username = ?', [client_ip(), $username]);
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        return 'ok';
    }
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
        authtrace('csrf_fail');   // TEMPORANEO
        http_response_code(400);
        die('Sessione scaduta: torna indietro e ricarica la pagina.');
    }
}
