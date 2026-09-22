<?php
/*
 * Email: conferma dell'indirizzo, recupero password e avvisi di sicurezza.
 *
 * Su Altervista si può usare solo la funzione mail() di PHP (le porte SMTP esterne sono bloccate): parte dal server del
 * sito, con mittente noreply@<indirizzo del sito>. I messaggi possono finire nello spam: per questo sono brevi e in testo semplice.
 * Con MAIL_FROM (config.local.php) si sceglie un altro mittente; con MAIL_DEBUG_FILE i messaggi si scrivono in un file
 * invece di essere spediti (prove in locale).
 */

/** Indirizzo del sito da mettere nei link delle email (con la barra finale). Non ci si fida dell'intestazione Host della richiesta. */
function trusted_base_url(): string
{
    if (defined('SITE_URL') && SITE_URL !== '') {
        return rtrim(SITE_URL, '/') . '/';
    }
    $saved = meta_get('base_url');
    return $saved !== null && $saved !== '' ? $saved : site_base_url();
}

/** Un admin che usa il sito (indirizzo affidabile) fissa l'indirizzo per i link delle email. */
function remember_base_url(): void
{
    static $done = false;
    if ($done || !is_admin()) {
        return;
    }
    $done = true;
    $b = site_base_url();
    if ($b !== '' && !str_contains($b, 'localhost') && meta_get('base_url') !== $b) {
        meta_set('base_url', $b);
    }
}

/** Mittente delle email: [indirizzo, "Nome <indirizzo>"]. */
function mail_sender(): array
{
    if (defined('MAIL_FROM') && MAIL_FROM !== '' && filter_var(MAIL_FROM, FILTER_VALIDATE_EMAIL)) {
        $addr = MAIL_FROM;
    } else {
        $host = (string) parse_url(trusted_base_url(), PHP_URL_HOST);
        $addr = 'noreply@' . ($host !== '' ? $host : 'localhost');
    }
    return [$addr, mb_encode_mimeheader(APP_NAME, 'UTF-8', 'B') . ' <' . $addr . '>'];
}

/** Manda un'email di testo semplice. true = accettata dal server di posta (non garantisce che arrivi). */
function send_mail(string $to, string $subject, string $body): bool
{
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject)) {
        return false;
    }
    [$addr, $from] = mail_sender();
    $text = str_replace(["\r\n", "\r"], "\n", $body);
    if (defined('MAIL_DEBUG_FILE') && MAIL_DEBUG_FILE !== '') {
        return file_put_contents(MAIL_DEBUG_FILE, "=== To: $to | Subject: $subject | From: $addr\n$text\n\n", FILE_APPEND) !== false;
    }
    $headers = ['From: ' . $from, 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: quoted-printable', 'Auto-Submitted: auto-generated'];
    if (defined('MAIL_REPLY_TO') && MAIL_REPLY_TO !== '' && filter_var(MAIL_REPLY_TO, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . MAIL_REPLY_TO;
    }
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', quoted_printable_encode(str_replace("\n", "\r\n", $text)), implode("\r\n", $headers));
    if (!$ok) {
        error_log('mail: il server di posta ha rifiutato il messaggio per ' . $to);
    }
    return $ok;
}

/** Corpo standard: saluto, paragrafi, firma. */
function mail_text(string $name, array $paragraphs): string
{
    return "Ciao $name,\n\n" . implode("\n\n", $paragraphs) . "\n\n— " . APP_NAME . "\n";
}

/* ---------------------------------------------------------------- codici monouso nei link */

/** Crea un codice monouso per $uid ($purpose 'verify' o 'reset', scade tra $minutes); ritorna la stringa da mettere nel link. */
function mail_token_create(int $uid, string $purpose, string $email, int $minutes): string
{
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(24));
    q('DELETE FROM mail_tokens WHERE expires_at < ? OR (user_id = ? AND purpose = ?)', [date('Y-m-d H:i:s'), $uid, $purpose]);
    q('INSERT INTO mail_tokens (user_id, purpose, selector, token_hash, email, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$uid, $purpose, $selector, hash('sha256', $validator), $email, date('Y-m-d H:i:s', time() + $minutes * 60)]);
    return $selector . $validator;
}

/** @return array{id: int, user_id: int, email: string}|null il codice, se è valido, dello scopo giusto e non scaduto */
function mail_token_load($t, string $purpose): ?array
{
    if (!is_string($t) || !preg_match('/^([0-9a-f]{18})([0-9a-f]{48})$/', $t, $m)) {
        return null;
    }
    $row = q('SELECT id, user_id, email, token_hash, expires_at FROM mail_tokens WHERE selector = ? AND purpose = ?', [$m[1], $purpose])->fetch();
    if (!$row || !hash_equals($row['token_hash'], hash('sha256', $m[2])) || strtotime($row['expires_at']) < time()) {
        return null;
    }
    return ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'email' => $row['email']];
}

/* ---------------------------------------------------------------- limiti anti-abuso */

/** Quante volte $key è stata registrata nell'ultima finestra (in secondi). */
function rate_recent(string $key, int $window): int
{
    return (int) q('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND created_at > ?',
        [$key, date('Y-m-d H:i:s', time() - $window)])->fetchColumn();
}

function rate_record(string $key): void
{
    q('INSERT INTO login_attempts (ip, username) VALUES (?, ?)', [client_ip(), substr($key, 0, 50)]);
    q('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
}

/** true (e la registra) se $key non ha ancora raggiunto $max azioni nella finestra. */
function rate_hit(string $key, int $max, int $window): bool
{
    if (rate_recent($key, $window) >= $max) {
        return false;
    }
    rate_record($key);
    return true;
}

/* ---------------------------------------------------------------- le email del sito */

/** Indirizzo verificato di un account (null se non c'è). */
function verified_email(int $uid): ?string
{
    $e = q('SELECT email FROM users WHERE id = ?', [$uid])->fetchColumn();
    return $e ? (string) $e : null;
}

/** Nome da usare nel saluto. */
function mail_name(int $uid): string
{
    $r = q('SELECT u.username, u.reg_name, p.name FROM users u LEFT JOIN players p ON p.id = u.player_id WHERE u.id = ?', [$uid])->fetch();
    return $r ? (string) ($r['name'] ?: $r['reg_name'] ?: $r['username']) : '';
}

/**
 * Salva $email come indirizzo "da confermare" e manda il link di conferma (valido 24 ore).
 * @return string|null messaggio d'errore, oppure null se l'email è partita
 */
function start_email_verification(int $uid, string $email): ?string
{
    if (!rate_hit('__mail_verify_' . $uid, 5, 3600)) {
        return 'Hai chiesto troppe email di conferma: riprova tra un po\'.';
    }
    q('UPDATE users SET pending_email = ? WHERE id = ?', [$email, $uid]);
    $link = trusted_base_url() . 'verify_email.php?t=' . mail_token_create($uid, 'verify', $email, 24 * 60);
    $ok = send_mail($email, 'Conferma il tuo indirizzo email · ' . APP_NAME, mail_text(mail_name($uid), [
        'Per collegare questo indirizzo al tuo account su ' . APP_NAME . ' apri il link qui sotto (vale 24 ore):',
        $link,
        'Serve per recuperare la password se la dimentichi. Se non l\'hai chiesto tu, ignora questo messaggio: senza la tua conferma non succede nulla.',
    ]));
    return $ok ? null : 'Non sono riuscito a spedire l\'email: riprova più tardi o chiedi all\'admin.';
}

/** Manda il link per reimpostare la password (valido 60 minuti) all'indirizzo verificato dell'account. */
function send_password_reset(int $uid, string $email): bool
{
    $link = trusted_base_url() . 'reset.php?t=' . mail_token_create($uid, 'reset', $email, 60);
    return send_mail($email, 'Reimposta la tua password · ' . APP_NAME, mail_text(mail_name($uid), [
        'Hai chiesto di reimpostare la password del tuo account su ' . APP_NAME . '. Apri il link qui sotto (vale 60 minuti, si può usare una volta sola):',
        $link,
        'Se non sei stato tu, ignora questo messaggio: la tua password resta com\'è.',
    ]));
}

/** Avviso all'indirizzo verificato: la password è stata cambiata. */
function notify_password_changed(int $uid): void
{
    if ($email = verified_email($uid)) {
        send_mail($email, 'La tua password è stata cambiata · ' . APP_NAME, mail_text(mail_name($uid), [
            'La password del tuo account su ' . APP_NAME . ' è stata cambiata il ' . date('d/m/Y') . ' alle ' . date('H:i') . '. Gli altri dispositivi hanno dovuto rifare l\'accesso.',
            'Se non sei stato tu, apri ' . trusted_base_url() . 'forgot.php per sceglierne una nuova e avvisa l\'admin.',
        ]));
    }
}

/** Avviso al vecchio indirizzo: l'email dell'account è cambiata (o è stata tolta). */
function notify_email_changed(string $oldEmail, int $uid, ?string $newEmail): void
{
    send_mail($oldEmail, 'L\'email del tuo account è cambiata · ' . APP_NAME, mail_text(mail_name($uid), [
        $newEmail !== null
            ? 'L\'indirizzo email del tuo account su ' . APP_NAME . ' è stato cambiato in ' . $newEmail . ' il ' . date('d/m/Y') . ' alle ' . date('H:i') . '.'
            : 'L\'indirizzo email è stato tolto dal tuo account su ' . APP_NAME . ' il ' . date('d/m/Y') . ' alle ' . date('H:i') . '.',
        'Se non sei stato tu, avvisa subito l\'admin.',
    ]));
}

/**
 * Avviso agli admin che qualcuno si è iscritto. Arriva insieme alla notifica push, non al suo posto: è la rete
 * di sicurezza per l'unica cosa che l'admin non può permettersi di perdere, perché l'email non dipende né dal
 * servizio push né dalle impostazioni del telefono. Va solo agli admin con l'email confermata.
 * @return int a quanti admin è partita
 */
function notify_admins_registration(string $name, string $username): int
{
    $sent = 0;
    foreach (q("SELECT id FROM users WHERE role = 'admin' AND status = 'attivo'")->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if (!($email = verified_email((int) $uid))) {
            continue;
        }
        $sent += (int) send_mail($email, 'Nuova iscrizione da approvare · ' . APP_NAME, mail_text(mail_name((int) $uid), [
            $name . ' (@' . $username . ') chiede di entrare in ' . APP_NAME . '.',
            'Approvala o rifiutala da qui: ' . trusted_base_url() . 'admin.php',
        ]));
    }
    return $sent;
}

/**
 * Email all'utente appena approvato dall'admin. Va all'indirizzo confermato, se c'è, altrimenti a quello scritto all'iscrizione
 * (la conferma di solito non è ancora arrivata). Ritorna true se il server di posta l'ha accettata, false se non c'è un indirizzo o non parte.
 */
function send_approval_email(int $uid, array $groupNames): bool
{
    $r = q('SELECT username, email, pending_email FROM users WHERE id = ?', [$uid])->fetch();
    $to = $r ? ($r['email'] ?: $r['pending_email']) : null;
    if (!$to) {
        return false;
    }
    return send_mail((string) $to, 'Iscrizione approvata · ' . APP_NAME, mail_text(mail_name($uid), array_filter([
        'L\'admin ha approvato la tua iscrizione a ' . APP_NAME . ': ora puoi entrare con lo username ' . $r['username'] . ' e la password che hai scelto.',
        $groupNames ? 'Sei entrato in: ' . implode(', ', $groupNames) . '.' : null,
        'Accedi da qui: ' . trusted_base_url() . 'login.php',
        'Se non sei stato tu a iscriverti, ignora questo messaggio.',
    ])));
}
