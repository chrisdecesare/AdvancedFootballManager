<?php
/*
 * Notifiche push (Web Push, RFC 8030 + VAPID RFC 8292 + cifratura RFC 8291), senza librerie esterne: serve solo
 * l'estensione openssl di PHP (e curl, se c'è, per mandare più notifiche insieme).
 *
 * Come funziona:
 *  - ogni dispositivo che attiva le notifiche (assets/app.js + sw.js) registra qui il proprio "abbonamento"
 *    (tabella push_subscriptions) tramite push.php;
 *  - le chiavi VAPID (identificano il sito presso Google/Apple/Mozilla) si creano da sole alla prima
 *    necessità e stanno nella tabella meta;
 *  - le notifiche partono quando succede qualcosa (nuova partita, votazioni aperte/chiuse) e come promemoria
 *    per chi non ha ancora risposto (vedi push_run_due).
 */

/* ---------------------------------------------------------------- utilità di codifica */

function wp_b64u_enc(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** Decodifica base64url; null se non valido. */
function wp_b64u_dec(string $s): ?string
{
    $s = strtr(trim($s), '-_', '+/');
    if ($s === '' || preg_match('#[^A-Za-z0-9+/=]#', $s)) {
        return null;
    }
    $d = base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $d === false ? null : $d;
}

function wp_pad32(string $bin): string
{
    return str_pad(ltrim($bin, "\0"), 32, "\0", STR_PAD_LEFT);
}

function wp_pem(string $der, string $label): string
{
    return "-----BEGIN $label-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END $label-----\n";
}

/** Chiave pubblica P-256 (punto non compresso, 65 byte) in formato PEM per openssl. */
function wp_public_pem(string $point): string
{
    return wp_pem(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point, 'PUBLIC KEY');
}

/** Chiave privata P-256 (32 byte) + punto pubblico (65 byte) in formato PEM per openssl. */
function wp_private_pem(string $d, string $point): string
{
    return wp_pem(hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $point, 'EC PRIVATE KEY');
}

/** Nuova coppia di chiavi P-256: [privata 32 byte, pubblica 65 byte] oppure null se openssl non riesce. */
function wp_generate_keypair(): ?array
{
    $k = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ec = $k ? (openssl_pkey_get_details($k)['ec'] ?? null) : null;
    if (!$ec || !isset($ec['d'], $ec['x'], $ec['y'])) {
        return null;
    }
    return [wp_pad32($ec['d']), "\x04" . wp_pad32($ec['x']) . wp_pad32($ec['y'])];
}

/** Firma ES256 formato JWT (r||s, 64 byte) al posto del formato DER di openssl. */
function wp_der_sig_to_raw(string $der): string
{
    $o = 2;
    if (ord($der[1]) & 0x80) {
        $o += ord($der[1]) & 0x7f;
    }
    $rl = ord($der[$o + 1]);
    $r = substr($der, $o + 2, $rl);
    $o += 2 + $rl;
    $sl = ord($der[$o + 1]);
    $s = substr($der, $o + 2, $sl);
    return wp_pad32($r) . wp_pad32($s);
}

/* ---------------------------------------------------------------- chiavi VAPID */

/** @return array{0: string, 1: string}|null [privata 32 byte, pubblica 65 byte] del sito; le crea la prima volta. */
function vapid_keys(): ?array
{
    static $keys = false;
    if ($keys !== false) {
        return $keys;
    }
    $read = function (): ?array {
        $m = q("SELECT k, v FROM meta WHERE k IN ('vapid_priv', 'vapid_pub')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $priv = wp_b64u_dec($m['vapid_priv'] ?? '');
        $pub = wp_b64u_dec($m['vapid_pub'] ?? '');
        return ($priv !== null && strlen($priv) === 32 && $pub !== null && strlen($pub) === 65) ? [$priv, $pub] : null;
    };
    $keys = $read();
    if ($keys === null) {
        $new = wp_generate_keypair();
        if ($new !== null) {
            // se due richieste arrivano insieme vince la prima: l'altra rilegge le chiavi già salvate
            q("INSERT IGNORE INTO meta (k, v) VALUES ('vapid_priv', ?)", [wp_b64u_enc($new[0])]);
            q("INSERT IGNORE INTO meta (k, v) VALUES ('vapid_pub', ?)", [wp_b64u_enc($new[1])]);
            $keys = $read();
        }
    }
    return $keys;
}

/** Chiave pubblica VAPID in base64url (serve al browser per abbonarsi); '' se il server non può crearla. */
function vapid_public_key(): string
{
    $k = vapid_keys();
    return $k ? wp_b64u_enc($k[1]) : '';
}

/** true se questo server sa fare le notifiche push (openssl con curve ellittiche e AES-GCM). */
function push_supported(): bool
{
    return function_exists('openssl_pkey_derive') && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
}

/** Intestazione "Authorization: vapid ..." per un servizio push (origine = schema + host dell'endpoint). */
function vapid_authorization(string $audience): ?string
{
    $keys = vapid_keys();
    if (!$keys) {
        return null;
    }
    $site = (string) meta_get('site_url');
    $claims = ['aud' => $audience, 'exp' => time() + 12 * 3600,
        'sub' => str_starts_with($site, 'https://') ? $site : 'mailto:admin@example.com'];
    $unsigned = wp_b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
        . '.' . wp_b64u_enc(json_encode($claims, JSON_UNESCAPED_SLASHES));
    $pk = openssl_pkey_get_private(wp_private_pem($keys[0], $keys[1]));
    if (!$pk || !openssl_sign($unsigned, $sig, $pk, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    return 'vapid t=' . $unsigned . '.' . wp_b64u_enc(wp_der_sig_to_raw($sig)) . ', k=' . wp_b64u_enc($keys[1]);
}

/* ---------------------------------------------------------------- cifratura del messaggio (RFC 8291, aes128gcm) */

/**
 * Cifra il messaggio per un dispositivo. $uaPublic = chiave pubblica del browser (65 byte), $auth = suo segreto (16 byte).
 * $ephemeral e $salt servono solo ai test (vettore di prova dell'RFC); in uso normale sono casuali.
 * @param array{0: string, 1: string}|null $ephemeral [privata, pubblica] del mittente
 */
function webpush_encrypt(string $payload, string $uaPublic, string $auth, ?array $ephemeral = null, ?string $salt = null): ?string
{
    if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($auth) !== 16 || strlen($payload) > 3000) {
        return null;
    }
    $ephemeral ??= wp_generate_keypair();
    if (!$ephemeral) {
        return null;
    }
    [$asPriv, $asPub] = $ephemeral;
    $peer = @openssl_pkey_get_public(wp_public_pem($uaPublic));
    $own = @openssl_pkey_get_private(wp_private_pem($asPriv, $asPub));
    $secret = ($peer && $own) ? @openssl_pkey_derive($peer, $own, 32) : false;
    if ($secret === false || strlen($secret) !== 32) {
        return null;
    }
    $salt ??= random_bytes(16);
    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPublic . $asPub, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) {
        return null;
    }
    return $salt . pack('N', 4096) . chr(65) . $asPub . $cipher . $tag;
}

/* ---------------------------------------------------------------- invio */

/** Solo i servizi push dei browser: l'indirizzo lo manda il browser, non ci si deve fidare di indirizzi qualsiasi. */
function push_endpoint_ok(string $url): bool
{
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || strlen($url) > 1000) {
        return false;
    }
    $host = strtolower($p['host']);
    return (bool) preg_match('/(^|\.)(fcm\.googleapis\.com|android\.googleapis\.com|push\.services\.mozilla\.com|push\.apple\.com|notify\.windows\.com)$/', $host);
}

/**
 * Manda lo stesso messaggio a più abbonamenti. $msg = ['title', 'body', 'url' (relativo al sito), 'tag'].
 * Toglie dal database gli abbonamenti scaduti (il browser risponde 404/410).
 * @param array<int, array{id: int|string, endpoint: string, p256dh: string, auth: string}> $subs
 * @return int quante notifiche sono state accettate dal servizio push
 */
function webpush_send(array $subs, array $msg, string $urgency = 'normal'): int
{
    if (!$subs || !push_supported() || !vapid_keys()) {
        return 0;
    }
    $json = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $jobs = [];
    $auth = [];
    foreach ($subs as $s) {
        $ua = wp_b64u_dec($s['p256dh']);
        $secret = wp_b64u_dec($s['auth']);
        $body = ($ua !== null && $secret !== null && push_endpoint_ok($s['endpoint'])) ? webpush_encrypt($json, $ua, $secret) : null;
        if ($body === null) {
            q('DELETE FROM push_subscriptions WHERE id = ?', [$s['id']]);   // dati non validi: inutile tenerlo
            continue;
        }
        $p = parse_url($s['endpoint']);
        $aud = 'https://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $auth[$aud] ??= vapid_authorization($aud);
        if ($auth[$aud] === null) {
            return 0;
        }
        $jobs[] = ['id' => $s['id'], 'url' => $s['endpoint'], 'body' => $body, 'headers' => [
            'Authorization: ' . $auth[$aud], 'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream',
            'TTL: 86400', 'Urgency: ' . $urgency, 'Content-Length: ' . strlen($body)]];
    }
    $codes = function_exists('curl_multi_init') ? webpush_post_curl($jobs) : webpush_post_streams($jobs);
    $ok = 0;
    foreach ($codes as $id => $code) {
        if ($code >= 200 && $code < 300) {
            $ok++;
        } elseif ($code === 404 || $code === 410) {
            q('DELETE FROM push_subscriptions WHERE id = ?', [$id]);      // il dispositivo non è più abbonato
        } else {
            error_log("webpush: risposta $code dal servizio push (abbonamento $id)");
        }
    }
    return $ok;
}

/** @return array<int|string, int> codice HTTP per ogni abbonamento (0 = nessuna risposta) */
function webpush_post_curl(array $jobs): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($jobs as $j) {
        $ch = curl_init($j['url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $j['body'], CURLOPT_HTTPHEADER => $j['headers'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        curl_multi_add_handle($mh, $ch);
        $handles[$j['id']] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);
    $codes = [];
    foreach ($handles as $id => $ch) {
        $codes[$id] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($codes[$id] === 0) {
            error_log('webpush: connessione al servizio push non riuscita: ' . curl_error($ch));
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $codes;
}

/** Se curl non c'è: un invio alla volta con i flussi di PHP. */
function webpush_post_streams(array $jobs): array
{
    $codes = [];
    foreach ($jobs as $j) {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $j['headers']),
            'content' => $j['body'], 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0]]);
        @file_get_contents($j['url'], false, $ctx);
        $codes[$j['id']] = isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) ? (int) $m[1] : 0;
    }
    return $codes;
}

/* ---------------------------------------------------------------- abbonamenti */

/** Salva (o aggiorna) il dispositivo di un account. Ritorna un messaggio d'errore oppure null. */
function push_save_subscription(int $userId, string $endpoint, string $p256dh, string $auth, string $ua): ?string
{
    $key = wp_b64u_dec($p256dh);
    $secret = wp_b64u_dec($auth);
    if (!push_endpoint_ok($endpoint) || $key === null || strlen($key) !== 65 || $secret === null || strlen($secret) !== 16) {
        return 'Abbonamento non valido.';
    }
    if ((int) q('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$userId])->fetchColumn() >= 10) {
        return 'Troppi dispositivi con le notifiche attive: disattivane qualcuno.';
    }
    q('INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth, ua) VALUES (?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), ua = VALUES(ua)',
        [$userId, hash('sha256', $endpoint), $endpoint, $p256dh, $auth, mb_substr($ua, 0, 120)]);
    return null;
}

/** Abbonamenti (dispositivi) di questi account. */
function push_subs_of_users(array $userIds): array
{
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) {
        return [];
    }
    return q('SELECT id, user_id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id IN (' . implode(',', $userIds) . ')')->fetchAll();
}

/** Account attivi collegati a questi giocatori. @return array<int, int> id giocatore => id account */
function push_users_of_players(array $playerIds): array
{
    $playerIds = array_values(array_unique(array_map('intval', $playerIds)));
    if (!$playerIds) {
        return [];
    }
    $out = [];
    foreach (q("SELECT id, player_id FROM users WHERE status = 'attivo' AND player_id IN (" . implode(',', $playerIds) . ')')->fetchAll() as $r) {
        $out[(int) $r['player_id']] = (int) $r['id'];
    }
    return $out;
}

/** Manda una notifica a questi account (tutti i loro dispositivi). Ritorna quante ne sono state accettate. */
function push_notify_users(array $userIds, array $msg, string $urgency = 'normal'): int
{
    return webpush_send(push_subs_of_users($userIds), $msg, $urgency);
}

/**
 * Esegue $fn dopo aver mandato la risposta al browser (chi ha premuto il pulsante non aspetta l'invio delle notifiche;
 * sui server senza fastcgi_finish_request l'attesa resta, ma è limitata dai timeout).
 */
function push_defer(callable $fn): void
{
    static $queue = [];
    static $registered = false;
    $queue[] = $fn;
    if ($registered) {
        return;
    }
    $registered = true;
    register_shutdown_function(function () use (&$queue) {
        ignore_user_abort(true);
        @set_time_limit(60);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();      // senza questo la sessione resterebbe bloccata durante l'invio
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        foreach ($queue as $job) {
            try {
                $job();
            } catch (Throwable $e) {
                error_log('webpush: ' . $e->getMessage());
            }
        }
    });
}

/* ---------------------------------------------------------------- le notifiche del sito */

/** "giovedì 25 settembre alle 21:00", oppure "oggi alle 21:00" / "domani alle 21:00". */
function push_when(string $dt): string
{
    $t = strtotime($dt);
    $days = (int) round((strtotime(date('Y-m-d', $t)) - strtotime(date('Y-m-d'))) / 86400);
    $day = $days === 0 ? 'oggi' : ($days === 1 ? 'domani' : fmt_date_long($dt));
    return $day . ' alle ' . fmt_time($dt);
}

/** Giocatori (con l'account) di una partita, filtrati con $where (condizione SQL su mp) e senza l'account che ha fatto l'azione. */
function push_match_recipients(int $matchId, string $where, ?int $exceptUser = null): array
{
    $pids = q("SELECT mp.player_id FROM match_players mp WHERE mp.match_id = ? AND $where", [$matchId])->fetchAll(PDO::FETCH_COLUMN);
    $users = push_users_of_players($pids);
    if ($exceptUser !== null) {
        $users = array_filter($users, fn($uid) => $uid !== $exceptUser);
    }
    return $users;
}

/** Nuova partita: tutti i giocatori del gruppo devono ancora rispondere. */
function push_notify_new_match(int $matchId, ?int $exceptUser = null): void
{
    push_defer(function () use ($matchId, $exceptUser) {
        $m = get_match($matchId);
        if (!$m || $m['status'] !== 'programmata') {
            return;
        }
        $users = push_match_recipients($matchId, "mp.availability = 'in_attesa'", $exceptUser);
        push_notify_users($users, [
            'title' => 'Nuova partita',
            'body' => ucfirst(push_when($m['match_date'])) . ($m['location'] !== '' ? ' · ' . $m['location'] : '') . '. Ci sei? Rispondi ora.',
            'url' => 'match.php?id=' . $matchId, 'tag' => 'match-' . $matchId,
        ], 'high');
        foreach ($users as $pid => $_) {
            q("INSERT IGNORE INTO push_log (kind, match_id, player_id) VALUES ('new', ?, ?)", [$matchId, $pid]);
        }
    });
}

/** Votazioni aperte (o riaperte) o chiuse: le ricevono i giocatori che hanno giocato la partita. */
function push_notify_voting(int $matchId, bool $open, ?int $exceptUser = null): void
{
    push_defer(function () use ($matchId, $open, $exceptUser) {
        $m = get_match($matchId);
        if (!$m || $m['status'] !== 'giocata') {
            return;
        }
        $users = push_match_recipients($matchId, 'mp.team IS NOT NULL', $exceptUser);
        if ($open) {
            $msg = ['title' => 'Votazioni aperte',
                'body' => team_name('A', $m) . ' ' . (int) $m['score_a'] . '–' . (int) $m['score_b'] . ' ' . team_name('B', $m)
                    . '. Vota i compagni e scegli l\'MVP!'];
        } else {
            $mvp = match_mvp($matchId);
            $name = $mvp ? (get_player($mvp)['name'] ?? null) : null;
            $msg = ['title' => 'Votazioni chiuse',
                'body' => ($name ? 'L\'MVP è ' . $name . '. ' : '') . 'Guarda i voti della partita.'];
        }
        push_notify_users($users, $msg + ['url' => 'match.php?id=' . $matchId . '#voti', 'tag' => 'voting-' . $matchId]);
    });
}

/** Ore di anticipo dei promemoria a chi non ha ancora risposto (dal più lontano al più vicino). */
const PUSH_REMINDER_HOURS = [48, 6];

/**
 * Promemoria a chi non ha ancora confermato o disdetto una partita in programma: uno quando mancano 48 ore, uno a 6 ore
 * (se la partita viene creata a ridosso, ne parte uno solo). Non si manda di notte (23-8), né a chi ha appena ricevuto
 * l'avviso di nuova partita. Parte da solo mentre qualcuno usa il sito (push_maybe_run) o dal cron (cron.php).
 * @return int notifiche mandate
 */
function push_run_due(): int
{
    if (!push_supported() || !(int) q('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn()) {
        return 0;
    }
    $now = time();
    $rows = q("SELECT m.id, m.match_date, m.location, mp.player_id FROM matches m
               JOIN match_players mp ON mp.match_id = m.id AND mp.availability = 'in_attesa'
               JOIN players p ON p.id = mp.player_id AND p.active = 1
               WHERE m.status = 'programmata' AND m.match_date > ? AND m.match_date <= ?
               ORDER BY m.match_date, mp.player_id",
        [date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + max(PUSH_REMINDER_HOURS) * 3600)])->fetchAll();
    if (!$rows) {
        return 0;
    }
    $log = [];
    foreach (q('SELECT kind, match_id, player_id, sent_at FROM push_log WHERE match_id IN (' .
        implode(',', array_unique(array_map(fn($r) => (int) $r['id'], $rows))) . ')')->fetchAll() as $l) {
        $log[$l['match_id'] . ':' . $l['player_id']][$l['kind']] = strtotime($l['sent_at']);
    }
    $userOf = push_users_of_players(array_column($rows, 'player_id'));
    $hour = (int) date('G', $now);
    $batches = [];   // (partita, promemoria) => giocatori a cui mandarlo: un solo invio per gruppo
    foreach ($rows as $r) {
        $pid = (int) $r['player_id'];
        $mid = (int) $r['id'];
        $left = (strtotime($r['match_date']) - $now) / 3600;
        $applicable = array_values(array_filter(PUSH_REMINDER_HOURS, fn($h) => $left <= $h));
        if (!isset($userOf[$pid]) || !$applicable) {
            continue;
        }
        $mine = $log[$mid . ':' . $pid] ?? [];
        $kind = 'rem' . min($applicable);
        if (isset($mine[$kind])
            || (isset($mine['new']) && $now - $mine['new'] < 12 * 3600)                    // l'ha appena saputo
            || (($hour >= 23 || $hour < 8) && $left > 2)) {                                // di notte no
            continue;
        }
        $batches[$mid . '|' . $kind]['match'] = $r;
        $batches[$mid . '|' . $kind]['who'][$pid] = $applicable;
    }
    $sent = 0;
    foreach ($batches as $b) {
        $r = $b['match'];
        $mid = (int) $r['id'];
        foreach ($b['who'] as $pid => $applicable) {
            $count = push_notify_users([$userOf[$pid]], [
                'title' => 'Ci sei alla partita?',
                'body' => 'Non hai ancora risposto: si gioca ' . push_when($r['match_date']) . ($r['location'] !== '' ? ' · ' . $r['location'] : '') . '.',
                'url' => 'match.php?id=' . $mid, 'tag' => 'match-' . $mid,
            ], 'high');
            if ($count > 0) {
                $sent += $count;
                foreach ($applicable as $h) {
                    q('INSERT IGNORE INTO push_log (kind, match_id, player_id) VALUES (?, ?, ?)', ['rem' . $h, $mid, $pid]);
                }
            }
        }
    }
    return $sent;
}

/** Fa partire push_run_due() al massimo una volta ogni 10 minuti (chi arriva per primo, dopo aver ricevuto la pagina). */
function push_maybe_run(): void
{
    $now = time();
    $claimed = q("UPDATE meta SET v = ? WHERE k = 'push_last_run' AND CAST(v AS UNSIGNED) < ?", [(string) $now, $now - 600])->rowCount();
    if ($claimed === 1) {
        push_run_due();
    }
}

/** Codice segreto per chiamare cron.php da un pianificatore esterno (si crea al primo uso). */
function push_cron_key(): string
{
    $k = meta_get('cron_key');
    if ($k === null || strlen($k) < 20) {
        q("INSERT IGNORE INTO meta (k, v) VALUES ('cron_key', ?)", [bin2hex(random_bytes(16))]);
        $k = (string) meta_get('cron_key');
    }
    return $k;
}
