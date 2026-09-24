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
 * Manda lo stesso messaggio a più abbonamenti e dice com'è andata, uno per uno: serve alla coda (push_queue_run)
 * per decidere se riprovare. $msg = ['title', 'body', 'url' (relativo al sito), 'tag'].
 * Toglie dal database gli abbonamenti scaduti (il browser risponde 404/410) o con dati non validi.
 * Ogni voce di $subs ha una chiave 'id' (che ritorna nel risultato) e 'sub_id' = abbonamento, se diverso.
 * @param array<int, array{id: int|string, sub_id?: int, endpoint: string, p256dh: string, auth: string}> $subs
 * @return array<int|string, array{code: int, error: ?string}> esito per ogni voce (codice 0 = nessuna risposta,
 * -1 = scartata prima dell'invio perché i suoi dati non erano validi)
 */
function webpush_deliver(array $subs, array $msg, string $urgency = 'normal'): array
{
    if (!$subs || !push_supported() || !vapid_keys()) {
        return [];
    }
    $json = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $jobs = [];
    $out = [];
    $auth = [];
    $subOf = [];       // chiave della voce => abbonamento a cui corrisponde
    foreach ($subs as $s) {
        $subOf[$s['id']] = $subId = $s['sub_id'] ?? $s['id'];
        $ua = wp_b64u_dec($s['p256dh']);
        $secret = wp_b64u_dec($s['auth']);
        $body = ($ua !== null && $secret !== null && push_endpoint_ok($s['endpoint'])) ? webpush_encrypt($json, $ua, $secret) : null;
        if ($body === null) {
            q('DELETE FROM push_subscriptions WHERE id = ?', [$subId]);   // dati non validi: inutile tenerlo
            $out[$s['id']] = ['code' => -1, 'error' => null];
            continue;
        }
        $p = parse_url($s['endpoint']);
        $aud = 'https://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $auth[$aud] ??= vapid_authorization($aud);
        if ($auth[$aud] === null) {
            $out[$s['id']] = ['code' => 0, 'error' => 'il server non riesce a firmare la notifica (chiavi VAPID)'];
            continue;
        }
        $jobs[] = ['id' => $s['id'], 'url' => $s['endpoint'], 'body' => $body, 'headers' => [
            'Authorization: ' . $auth[$aud], 'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream',
            'TTL: 86400', 'Urgency: ' . $urgency, 'Content-Length: ' . strlen($body)]];
    }
    $res = $jobs ? (function_exists('curl_multi_init') ? webpush_post_curl($jobs) : webpush_post_streams($jobs)) : [];
    foreach ($res as $id => $r) {
        $code = $r['code'];
        if ($code === 404 || $code === 410) {
            q('DELETE FROM push_subscriptions WHERE id = ?', [$subOf[$id] ?? $id]);   // il dispositivo non è più abbonato
        } elseif ($code < 200 || $code >= 300) {
            error_log("webpush: risposta $code dal servizio push (abbonamento $id)");
        }
        $out[$id] = $r;
    }
    return $out;
}

/**
 * Manda subito lo stesso messaggio a più abbonamenti, senza passare dalla coda (notifica di prova).
 * @return int quante notifiche sono state accettate dal servizio push
 */
function webpush_send(array $subs, array $msg, string $urgency = 'normal'): int
{
    return count(array_filter(webpush_deliver($subs, $msg, $urgency), fn($r) => $r['code'] >= 200 && $r['code'] < 300));
}

/**
 * @return array<int|string, array{code: int, error: ?string}> risposta per ogni abbonamento (codice 0 = non si è
 * riusciti a parlare con il servizio push; in quel caso 'error' dice perché, ed è quello che l'admin legge nel registro)
 */
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
    // il motivo del fallimento sta qui, non in curl_error(): con curl_multi quella resta vuota
    $failed = [];
    $key = fn($h) => is_object($h) ? spl_object_id($h) : (int) $h;   // in PHP 8 i manici di curl sono oggetti
    while ($info = curl_multi_info_read($mh)) {
        if ($info['result'] !== CURLE_OK) {
            $failed[$key($info['handle'])] = curl_strerror($info['result']);
        }
    }
    $out = [];
    foreach ($handles as $id => $ch) {
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $code === 0 ? ($failed[$key($ch)] ?? curl_error($ch) ?: 'nessuna risposta') : null;
        if ($err !== null) {
            error_log("webpush: connessione al servizio push non riuscita: $err");
        }
        $out[$id] = ['code' => $code, 'error' => $err];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/** Se curl non c'è: un invio alla volta con i flussi di PHP. */
function webpush_post_streams(array $jobs): array
{
    $out = [];
    foreach ($jobs as $j) {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $j['headers']),
            'content' => $j['body'], 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0]]);
        @file_get_contents($j['url'], false, $ctx);
        // le intestazioni della risposta: da PHP 8.5 si leggono con la funzione (la variabile magica e' deprecata),
        // prima esisteva solo la variabile, che il motore riempie soltanto se il codice la nomina qui dentro
        if (function_exists('http_get_last_response_headers')) {
            $head = http_get_last_response_headers() ?? [];
        } else {
            $head = $http_response_header ?? [];
        }
        $code = isset($head[0]) && preg_match('#\s(\d{3})\s#', $head[0], $m) ? (int) $m[1] : 0;
        $out[$j['id']] = ['code' => $code, 'error' => $code === 0 ? (($e = error_get_last()) ? $e['message'] : 'nessuna risposta') : null];
    }
    return $out;
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
    // non più di 10 dispositivi per account: i più vecchi lasciano il posto al nuovo (rifiutarlo spegneva
    // le notifiche proprio sul telefono che le stava attivando)
    $old = q('SELECT id FROM push_subscriptions WHERE user_id = ? AND endpoint_hash <> ? ORDER BY created_at DESC LIMIT 9, 50',
        [$userId, hash('sha256', $endpoint)])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($old as $id) {
        q('DELETE FROM push_subscriptions WHERE id = ?', [$id]);
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

/* ---------------------------------------------------------------- coda di spedizione */

/*
 * Le notifiche non partono più "al volo": ogni dispositivo destinatario diventa una riga di push_queue, che
 * viene spedita subito e, se il servizio push non risponde o risponde male, riprovata (dopo 1, 5, 15, 60
 * minuti, poi si arrende). Le righe restano come registro di consegna: Admin -> Notifiche le mostra.
 * Senza coda una sola risposta lenta di Google faceva sparire la notifica per sempre, senza che nessuno lo sapesse.
 */

/** Minuti di attesa prima di riprovare, in base ai tentativi già fatti. */
const PUSH_RETRY_MINUTES = [1, 5, 15, 60];

/** Mette in coda un messaggio per tutti i dispositivi di questi account. Ritorna quante righe ha scritto. */
function push_queue_add(array $userIds, array $msg, string $urgency, string $kind): int
{
    $subs = push_subs_of_users($userIds);
    if (!$subs) {
        return 0;
    }
    $payload = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach ($subs as $s) {
        q('INSERT INTO push_queue (kind, user_id, sub_id, title, payload, urgency) VALUES (?, ?, ?, ?, ?, ?)',
            [mb_substr($kind, 0, 20), $s['user_id'], $s['id'], mb_substr((string) ($msg['title'] ?? ''), 0, 120), $payload, $urgency]);
    }
    return count($subs);
}

/**
 * Spedisce le notifiche in coda pronte a partire (e quelle da riprovare). Più richieste possono girare insieme:
 * ogni esecuzione "prende" le sue righe con un codice suo, così nessuna notifica parte due volte.
 * @return int quante sono state consegnate
 */
function push_queue_run(int $max = 40): int
{
    if (!push_supported()) {
        return 0;
    }
    $claim = bin2hex(random_bytes(6));
    $max = max(1, min(200, $max));
    // il tentativo si conta subito e la riga si sposta avanti: se questa esecuzione muore a metà, la notifica
    // non resta bloccata "in lavorazione" per sempre ma torna disponibile tra qualche minuto
    q("UPDATE push_queue SET claim = ?, attempts = attempts + 1, next_try = DATE_ADD(NOW(), INTERVAL 5 MINUTE)
       WHERE status = 'in_attesa' AND claim IS NULL AND next_try <= NOW() ORDER BY next_try LIMIT $max", [$claim]);
    $rows = q('SELECT p.id, p.attempts, p.payload, p.urgency, p.sub_id, s.endpoint, s.p256dh, s.auth
               FROM push_queue p LEFT JOIN push_subscriptions s ON s.id = p.sub_id
               WHERE p.claim = ?', [$claim])->fetchAll();
    if (!$rows) {
        return 0;
    }
    // un solo invio per messaggio uguale: i destinatari di una stessa notifica partono insieme
    $groups = [];
    foreach ($rows as $r) {
        if ($r['sub_id'] === null || $r['endpoint'] === null) {
            push_queue_close((int) $r['id'], 0, 'fallita', 'il dispositivo non è più abbonato');
            continue;
        }
        $groups[$r['urgency'] . "\n" . $r['payload']][] = $r;
    }
    $done = 0;
    foreach ($groups as $key => $batch) {
        [$urgency, $payload] = explode("\n", $key, 2);
        $msg = json_decode($payload, true);
        if (!is_array($msg)) {
            foreach ($batch as $r) {
                push_queue_close((int) $r['id'], 0, 'fallita', 'messaggio non valido');
            }
            continue;
        }
        $esiti = webpush_deliver($batch, $msg, $urgency);
        foreach ($batch as $r) {
            $id = (int) $r['id'];
            $esito = $esiti[$r['id']] ?? ['code' => 0, 'error' => null];
            $code = (int) $esito['code'];
            $why = push_code_reason($code) . ($esito['error'] !== null && $code === 0 ? ': ' . $esito['error'] : '');
            if ($code >= 200 && $code < 300) {
                push_queue_close($id, $code, 'consegnata', null);
                $done++;
            } elseif (in_array($code, [400, 401, 403, 404, 410, 413, -1], true)) {
                // non ha senso riprovare: abbonamento sparito, chiavi rifiutate o messaggio non accettabile
                push_queue_close($id, $code, 'fallita', $why);
            } elseif ((int) $r['attempts'] >= count(PUSH_RETRY_MINUTES) + 1) {
                push_queue_close($id, $code, 'fallita', $why . ' (dopo ' . (int) $r['attempts'] . ' tentativi)');
            } else {
                $wait = PUSH_RETRY_MINUTES[min((int) $r['attempts'], count(PUSH_RETRY_MINUTES)) - 1];
                q("UPDATE push_queue SET claim = NULL, last_code = ?, last_error = ?, next_try = DATE_ADD(NOW(), INTERVAL $wait MINUTE) WHERE id = ?",
                    [$code, mb_substr($why, 0, 190), $id]);
            }
        }
    }
    return $done;
}

/** Chiude una riga della coda: resta come registro di consegna. */
function push_queue_close(int $id, int $code, string $status, ?string $error): void
{
    q('UPDATE push_queue SET status = ?, last_code = ?, last_error = ?, claim = NULL, sent_at = NOW() WHERE id = ?',
        [$status, $code, $error === null ? null : mb_substr($error, 0, 190), $id]);
}

/** Spiegazione in italiano della risposta del servizio push (è quella che si legge in Admin -> Notifiche). */
function push_code_reason(int $code): string
{
    return [
        -1 => 'dati dell\'abbonamento non validi',
        0 => 'il servizio push non ha risposto (rete o server lento)',
        400 => 'richiesta rifiutata dal servizio push',
        401 => 'chiavi del sito rifiutate (VAPID)',
        403 => 'chiavi del sito rifiutate (VAPID)',
        404 => 'il dispositivo non è più abbonato',
        410 => 'il dispositivo non è più abbonato',
        413 => 'messaggio troppo lungo',
        429 => 'troppe notifiche insieme: il servizio push ha chiesto di rallentare',
    ][$code] ?? ('risposta ' . $code . ' dal servizio push');
}

/** C'è qualcosa da spedire adesso? (una riga su indice: si può chiamare a ogni pagina) */
function push_queue_pending(): bool
{
    return (bool) q("SELECT 1 FROM push_queue WHERE status = 'in_attesa' AND claim IS NULL AND next_try <= NOW() LIMIT 1")->fetchColumn();
}

/** Svuota la coda se serve, a risposta già inviata (lo chiama bootstrap.php a ogni richiesta). */
function push_queue_kick(): void
{
    if (push_queue_pending()) {
        push_queue_run();
    }
}

/** Butta via il registro più vecchio di un mese (lo chiama il cron). */
function push_queue_cleanup(): void
{
    q("DELETE FROM push_queue WHERE status <> 'in_attesa' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
}

/**
 * Manda una notifica a questi account (tutti i loro dispositivi): la mette in coda e prova subito a spedirla.
 * $kind serve solo al registro in Admin -> Notifiche.
 * @return int a quanti dispositivi è stata messa in coda
 */
function push_notify_users(array $userIds, array $msg, string $urgency = 'normal', string $kind = 'notifica'): int
{
    $n = push_queue_add($userIds, $msg, $urgency, $kind);
    if ($n) {
        push_queue_run();
    }
    return $n;
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
        ], 'high', 'nuova partita');
        foreach ($users as $pid => $_) {
            q("INSERT IGNORE INTO push_log (kind, match_id, player_id) VALUES ('new', ?, ?)", [$matchId, $pid]);
        }
    });
}

/**
 * Partita in programma modificata (data, ora, campo, quota, note) da un admin/manager. $old = la partita com'era prima,
 * $reset = true se le risposte "ci sono / non ci sono" sono state azzerate (cambio di giorno): allora lo ricevono tutti
 * e devono rispondere di nuovo; per le altre modifiche lo ricevono solo i giocatori che non hanno già detto di no.
 */
function push_notify_match_changed(int $matchId, array $old, bool $reset, ?int $exceptUser = null): void
{
    push_defer(function () use ($matchId, $old, $reset, $exceptUser) {
        $m = get_match($matchId);
        if (!$m || $m['status'] !== 'programmata') {
            return;
        }
        $changes = [];
        $moved = $m['match_date'] !== $old['match_date'];
        if ($moved) {
            $changes[] = 'Ora si gioca ' . push_when($m['match_date']) . ' (prima: ' . push_when($old['match_date']) . ')';
        }
        if ($m['location'] !== $old['location']) {
            $changes[] = 'Campo: ' . ($m['location'] !== '' ? $m['location'] : 'da definire');
        }
        if (abs((float) $m['fee'] - (float) $old['fee']) > 0.001) {
            $changes[] = 'Quota: ' . number_format((float) $m['fee'], 2, ',', '') . ' €';
        }
        if ((string) $m['notes'] !== (string) $old['notes']) {
            $changes[] = 'Note: ' . ((string) $m['notes'] !== '' ? $m['notes'] : 'nessuna');
        }
        if (!$changes) {
            return;
        }
        $users = push_match_recipients($matchId, $reset ? '1 = 1' : "mp.availability <> 'assente'", $exceptUser);
        $body = implode('. ', $changes) . '.' . ($reset ? ' Le risposte sono state azzerate: ci sei? Rispondi ora.' : '');
        push_notify_users($users, [
            'title' => $moved ? 'Partita spostata' : 'Partita modificata',
            'body' => mb_substr($body, 0, 400),
            'url' => 'match.php?id=' . $matchId, 'tag' => 'match-' . $matchId,
        ], 'high', $moved ? 'partita spostata' : 'partita modificata');
        if ($reset) {
            // hanno appena saputo della nuova data: il promemoria non deve partire subito dopo
            foreach ($users as $pid => $_) {
                q("INSERT INTO push_log (kind, match_id, player_id) VALUES ('new', ?, ?)
                   ON DUPLICATE KEY UPDATE sent_at = CURRENT_TIMESTAMP", [$matchId, $pid]);
            }
        }
    });
}

/**
 * È cambiato chi gioca: un giocatore ha confermato la presenza, oppure chi aveva confermato si è tirato indietro (non c'è, o è tornato
 * "in attesa"). Lo sanno solo i confermati (tolti lui e chi ha fatto la modifica): sono loro che devono sapere in quanti si gioca.
 * Le altre risposte (per esempio un "non ci sono" di chi non aveva ancora risposto) non cambiano la partita e non mandano niente.
 * $old e $new sono la disponibilità prima e dopo.
 */
function push_notify_roster_change(int $matchId, int $playerId, string $old, string $new, ?int $exceptUser = null): void
{
    if (($old === 'confermato') === ($new === 'confermato')) {
        return;
    }
    push_defer(function () use ($matchId, $playerId, $new, $exceptUser) {
        $m = get_match($matchId);
        if (!$m || $m['status'] !== 'programmata' || strtotime($m['match_date']) < time() - 3 * 3600) {
            return;
        }
        $name = (string) (get_player($playerId)['name'] ?? 'Un giocatore');
        $users = push_match_recipients($matchId, "mp.availability = 'confermato' AND mp.player_id <> " . $playerId, $exceptUser);
        if (!$users) {
            return;
        }
        $n = (int) q("SELECT COUNT(*) FROM match_players WHERE match_id = ? AND availability = 'confermato'", [$matchId])->fetchColumn();
        $what = $new === 'confermato' ? $name . ' si è aggiunto' : ($new === 'assente' ? $name . ' non ci sarà' : $name . ' non è più sicuro di esserci');
        push_notify_users($users, [
            'title' => $new === 'confermato' ? 'Un giocatore in più' : 'Un giocatore in meno',
            'body' => $what . ' (' . push_when($m['match_date']) . '). Ora siete in ' . $n . ' confermati.',
            'url' => 'match.php?id=' . $matchId . '#presenze', 'tag' => 'roster-' . $matchId,
        ], 'normal', 'formazione');
    });
}

/**
 * Gol durante la partita (cronaca in diretta, lib/live.php): lo sanno i giocatori del gruppo che NON stanno giocando
 * (chi gioca è in campo e il gol l'ha visto). $scorer = nome, $own = autogol, $team = squadra che ha segnato.
 */
function push_notify_goal(int $matchId, string $scorer, ?string $assist, bool $own, string $team, ?int $exceptUser = null): void
{
    push_defer(function () use ($matchId, $scorer, $assist, $own, $team, $exceptUser) {
        $m = get_match($matchId);
        if (!$m) {
            return;
        }
        $users = push_match_recipients($matchId, "mp.team IS NULL AND mp.availability <> 'confermato'
            AND NOT EXISTS (SELECT 1 FROM players g WHERE g.id = mp.player_id AND g.is_guest = 1)", $exceptUser);
        $score = team_name('A', $m) . ' ' . (int) $m['score_a'] . '–' . (int) $m['score_b'] . ' ' . team_name('B', $m);
        push_notify_users($users, [
            'title' => ($own ? 'Autogol' : 'Gol') . ' ' . team_name($team, $m) . '!',
            'body' => ($own ? 'Autogol di ' . $scorer : $scorer . ($assist ? ' (assist di ' . $assist . ')' : '')) . '. ' . $score . '.',
            'url' => 'match.php?id=' . $matchId . '#diretta', 'tag' => 'live-' . $matchId,
        ], 'high', 'gol');
    });
}

/** Partita appena creata a meno di BET_OPEN_HOURS dall'inizio: le scommesse sono già aperte, l'avviso parte subito. */
function push_notify_bets_open_now(int $matchId): void
{
    push_defer(fn() => push_run_bets($matchId));
}

/**
 * Avvisi delle scommesse, a tutti i giocatori del gruppo della partita (sono loro che possono puntare):
 *  - "Scommesse aperte" quando mancano BET_OPEN_HOURS ore (o subito, se la partita è stata creata a ridosso);
 *  - "Ultima ora per scommettere" quando manca un'ora al calcio d'inizio (se non hanno appena ricevuto il primo).
 * Ognuno si manda una volta per giocatore e partita (push_log). Di notte (23-8) l'apertura aspetta il mattino, se c'è tempo.
 * $onlyMatch = controlla solo quella partita. @return int notifiche mandate
 */
function push_run_bets(?int $onlyMatch = null): int
{
    if (!push_supported() || !(int) q('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn()) {
        return 0;
    }
    $now = time();
    $sql = "SELECT * FROM matches WHERE status = 'programmata' AND match_date > ? AND match_date <= ?";
    $params = [date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + BET_OPEN_HOURS * 3600)];
    if ($onlyMatch !== null) {
        $sql .= ' AND id = ?';
        $params[] = $onlyMatch;
    }
    $hour = (int) date('G', $now);
    $sent = 0;
    foreach (q($sql, $params)->fetchAll() as $m) {
        $mid = (int) $m['id'];
        $left = (strtotime($m['match_date']) - $now) / 3600;
        $pids = array_map('intval', q('SELECT p.id FROM players p JOIN player_groups pg ON pg.player_id = p.id
                                        WHERE pg.group_id = ? AND p.active = 1 AND p.is_guest = 0', [(int) $m['group_id']])->fetchAll(PDO::FETCH_COLUMN));
        $userOf = push_users_of_players($pids);
        if (!$userOf) {
            continue;
        }
        $log = [];
        foreach (q("SELECT kind, player_id, sent_at FROM push_log WHERE match_id = ? AND kind IN ('betsopen', 'bets1h')", [$mid])->fetchAll() as $l) {
            $log[$l['kind']][(int) $l['player_id']] = strtotime($l['sent_at']);
        }
        $open = $close = [];
        foreach ($userOf as $pid => $uid) {
            $opened = $log['betsopen'][$pid] ?? null;
            if ($left <= 1) {
                if (!isset($log['bets1h'][$pid]) && ($opened === null || $now - $opened > 1800)) {
                    $close[$pid] = $uid;
                }
                if ($opened === null) {
                    $log['betsopen'][$pid] = $now;   // a un'ora dall'inizio basta l'ultimo avviso
                    q("INSERT IGNORE INTO push_log (kind, match_id, player_id) VALUES ('betsopen', ?, ?)", [$mid, $pid]);
                }
            } elseif ($opened === null && !(($hour >= 23 || $hour < 8) && $left > 10)) {
                $open[$pid] = $uid;
            }
        }
        $when = push_when($m['match_date']) . ($m['location'] !== '' ? ' · ' . $m['location'] : '');
        if ($open) {
            $sent += push_notify_users(array_values($open), [
                'title' => 'Scommesse aperte',
                'body' => 'Si gioca ' . $when . '. Quote pronte su risultato, marcatori e MVP: punta entro il calcio d\'inizio!',
                'url' => 'bets.php#m' . $mid, 'tag' => 'bets-' . $mid,
            ], 'normal', 'scommesse aperte');
            foreach ($open as $pid => $_) {
                q("INSERT IGNORE INTO push_log (kind, match_id, player_id) VALUES ('betsopen', ?, ?)", [$mid, $pid]);
            }
        }
        if ($close) {
            $sent += push_notify_users(array_values($close), [
                'title' => 'Ultima ora per scommettere',
                'body' => 'Alle ' . fmt_time($m['match_date']) . ' si gioca: le scommesse si chiudono al calcio d\'inizio. Ultima chance per la schedina!',
                'url' => 'bets.php#m' . $mid, 'tag' => 'bets-' . $mid,
            ], 'high', 'scommesse 1h');
        }
        foreach ($close as $pid => $_) {
            q("INSERT IGNORE INTO push_log (kind, match_id, player_id) VALUES ('bets1h', ?, ?)", [$mid, $pid]);
        }
        // chi ha appena ricevuto l'apertura non riceve anche l'ultima ora: si segna come fatto
        if ($left <= 1) {
            foreach ($userOf as $pid => $_) {
                q("INSERT IGNORE INTO push_log (kind, match_id, player_id) VALUES ('bets1h', ?, ?)", [$mid, $pid]);
            }
        }
    }
    return $sent;
}

/**
 * Partita in programma eliminata. Va chiamata PRIMA di cancellarla: i destinatari si calcolano subito (dopo la
 * cancellazione l'elenco dei giocatori non esiste più), l'invio parte a pagina già inviata.
 */
function push_notify_match_cancelled(array $match, ?int $exceptUser = null): void
{
    if ($match['status'] !== 'programmata') {
        return;
    }
    $users = push_match_recipients((int) $match['id'], "mp.availability <> 'assente'", $exceptUser);
    if (!$users) {
        return;
    }
    push_defer(function () use ($users, $match) {
        push_notify_users($users, [
            'title' => 'Partita annullata',
            'body' => 'La partita di ' . push_when($match['match_date']) . ($match['location'] !== '' ? ' · ' . $match['location'] : '') . ' è stata annullata.',
            'url' => 'matches.php', 'tag' => 'match-' . $match['id'],
        ], 'high', 'partita annullata');
    });
}

/**
 * Account a cui associare i dispositivi che attivano le notifiche: chi ha fatto l'accesso oppure chi si è appena iscritto e aspetta
 * l'approvazione (stessa sessione del browser con cui si è iscritto: non può ancora fare il login, ma così l'admin può avvisarlo).
 */
function push_account_id(): ?int
{
    if ($u = current_user()) {
        return (int) $u['id'];
    }
    $id = (int) ($_SESSION['reg_uid'] ?? 0);
    return ($id && q("SELECT 1 FROM users WHERE id = ? AND status = 'in_attesa'", [$id])->fetch()) ? $id : null;
}

/** L'admin ha approvato l'iscrizione: notifica ai dispositivi che l'utente ha attivato mentre aspettava. $groupNames = gruppi in cui è entrato. */
function push_notify_approved(int $userId, array $groupNames): void
{
    push_defer(function () use ($userId, $groupNames) {
        push_notify_users([$userId], [
            'title' => 'Iscrizione approvata!',
            'body' => 'L\'admin ha accettato la tua iscrizione' . ($groupNames ? ' (' . implode(', ', array_map(fn($g) => mb_substr((string) $g, 0, 40), $groupNames)) . ')' : '')
                . ': entra e rispondi alle partite.',
            'url' => 'login.php', 'tag' => 'approved',
        ], 'high', 'approvazione');
    });
}

/** Account degli admin attivi (sono loro ad approvare le iscrizioni). */
function push_admin_users(): array
{
    return array_map('intval', q("SELECT id FROM users WHERE role = 'admin' AND status = 'attivo'")->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Qualcuno si è iscritto e chiede di entrare nella lega: avvisa gli admin (chi ha le notifiche attive), che poi approvano o rifiutano da Admin.
 * $matchName = nome del giocatore già in rosa a cui l'iscrizione è stata abbinata, se c'è.
 */
function push_notify_registration(int $newUserId, string $name, string $username, ?string $matchName = null, ?int $groupId = null): void
{
    push_defer(function () use ($newUserId, $name, $username, $matchName, $groupId) {
        // iscrizione dal link di una lega creata da un utente: la approvano gli admin di quella lega, non l'admin del sito
        $league = $groupId ? league_get($groupId) : null;
        $userLeague = $league && $league['owner_user_id'] !== null;
        $admins = $userLeague ? push_league_admin_users($groupId) : push_admin_users();
        if (!$admins) {
            return;
        }
        $pending = $userLeague ? league_pending_count($groupId) : pending_count();
        push_notify_users($admins, [
            'title' => 'Nuova richiesta di iscrizione',
            'body' => mb_substr($name, 0, 80) . ' (@' . mb_substr($username, 0, 50) . ') vuole entrare in '
                . ($league ? mb_substr($league['name'], 0, 40) : 'lega')
                . ($matchName ? ': è già in rosa come ' . mb_substr($matchName, 0, 80) : '') . '.'
                . ($pending > 1 ? ' Richieste da approvare: ' . $pending . '.' : ' Approvala o rifiutala.'),
            'url' => $userLeague ? 'league.php?id=' . (int) $groupId : 'admin.php', 'tag' => 'reg-' . $newUserId,
        ], 'high', 'iscrizione');
    });
}

/** Account di proprietario e admin di una lega (approvano le richieste di quella lega). */
function push_league_admin_users(int $groupId): array
{
    return array_map('intval', q("SELECT gr.user_id FROM group_roles gr JOIN users u ON u.id = gr.user_id
                                  WHERE gr.group_id = ? AND gr.role IN ('owner', 'admin') AND u.status = 'attivo'", [$groupId])->fetchAll(PDO::FETCH_COLUMN));
}

/** Un account esistente chiede di entrare in una lega: lo sanno i suoi admin. */
function push_notify_league_request(int $groupId, int $userId): void
{
    push_defer(function () use ($groupId, $userId) {
        $league = league_get($groupId);
        $admins = $league ? ($league['owner_user_id'] !== null ? push_league_admin_users($groupId) : push_admin_users()) : [];
        if (!$admins) {
            return;
        }
        $who = q('SELECT COALESCE(p.name, u.username) FROM users u LEFT JOIN players p ON p.id = u.player_id WHERE u.id = ?', [$userId])->fetchColumn();
        push_notify_users($admins, [
            'title' => 'Richiesta per ' . mb_substr($league['name'], 0, 40),
            'body' => mb_substr((string) $who, 0, 80) . ' chiede di entrare nella lega. Accettala o rifiutala.',
            'url' => 'league.php?id=' . $groupId, 'tag' => 'join-' . $groupId . '-' . $userId,
        ], 'high', 'iscrizione');
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
        $users = push_match_recipients($matchId, 'mp.team IS NOT NULL AND NOT EXISTS (SELECT 1 FROM players g WHERE g.id = mp.player_id AND g.is_guest = 1)', $exceptUser);   // gli ospiti non votano
        if ($open) {
            $msg = ['title' => 'Votazioni aperte',
                'body' => team_name('A', $m) . ' ' . (int) $m['score_a'] . '–' . (int) $m['score_b'] . ' ' . team_name('B', $m)
                    . '. Vota i compagni e scegli l\'MVP!'
                    . ($m['voting_ends_at'] ? ' Hai tempo fino a ' . push_when($m['voting_ends_at']) . '.' : '')];
        } else {
            $mvp = match_mvp($matchId);
            $name = $mvp ? (get_player($mvp)['name'] ?? null) : null;
            $msg = ['title' => 'Votazioni chiuse',
                'body' => ($name ? 'L\'MVP è ' . $name . '. ' : '') . 'Guarda i voti della partita.'];
        }
        push_notify_users($users, $msg + ['url' => 'match.php?id=' . $matchId . '#voti', 'tag' => 'voting-' . $matchId],
            'normal', $open ? 'votazioni aperte' : 'votazioni chiuse');
    });
}

/** Ore di anticipo dei promemoria a chi non ha ancora risposto (dal più lontano al più vicino). */
const PUSH_REMINDER_HOURS = [48, 6];

/**
 * Promemoria a chi non ha ancora confermato o disdetto una partita in programma: uno quando mancano 48 ore, uno a 6 ore
 * (se la partita viene creata a ridosso, ne parte uno solo). Non si manda di notte (23-8), né a chi ha appena ricevuto
 * l'avviso di nuova partita.
 * @return int notifiche mandate
 */
function push_run_reminders(): int
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
            ], 'high', 'promemoria');
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

/**
 * Notifiche "a orario": promemoria a chi non ha risposto, apertura delle scommesse e ultima ora per scommettere.
 * Parte da sola mentre qualcuno usa il sito (push_maybe_run) o dal cron (cron.php). @return int notifiche mandate
 */
function push_run_due(): int
{
    return push_run_reminders() + push_run_bets();
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
