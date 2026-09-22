<?php
/* Notifiche push: il browser registra (o toglie) qui il proprio abbonamento. Risponde in JSON. */

// Il rinnovo arriva dal service worker (sw.js), che non ha la pagina davanti e quindi nemmeno il codice CSRF:
// al suo posto deve dimostrare di conoscere il vecchio indirizzo dell'abbonamento, che sa solo quel dispositivo.
if (($_POST['do'] ?? '') === 'renew') {
    define('NO_CSRF', true);
}
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function push_reply(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// la chiave pubblica del sito: la chiede il service worker quando deve rifare l'abbonamento da solo
// (è la stessa che sta nell'intestazione di ogni pagina, non è un segreto)
if (($_GET['do'] ?? '') === 'key') {
    push_reply(['ok' => true, 'key' => vapid_public_key()]);
}
if (!is_post()) {
    push_reply(['ok' => false, 'error' => 'Metodo non valido.'], 405);
}
if (!push_supported() || !vapid_keys()) {
    push_reply(['ok' => false, 'error' => 'Questo server non può mandare notifiche (manca il supporto openssl).'], 501);
}

$do = $_POST['do'] ?? '';
$endpoint = is_string($_POST['endpoint'] ?? null) ? $_POST['endpoint'] : '';

if ($do === 'renew') {
    // il telefono ha cambiato abbonamento da solo (succede): sposta la registrazione sul nuovo indirizzo,
    // tenendo l'account di prima, così il dispositivo non resta muto fino alla prossima apertura del sito
    $old = is_string($_POST['old'] ?? null) ? $_POST['old'] : '';
    $row = q('SELECT id, user_id FROM push_subscriptions WHERE endpoint_hash = ?', [hash('sha256', $old)])->fetch();
    if (!$row) {
        push_reply(['ok' => false, 'error' => 'Abbonamento sconosciuto.'], 404);
    }
    $err = push_save_subscription((int) $row['user_id'], $endpoint, (string) ($_POST['p256dh'] ?? ''), (string) ($_POST['auth'] ?? ''),
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($err) {
        push_reply(['ok' => false, 'error' => $err], 422);
    }
    if ($endpoint !== $old) {
        q('DELETE FROM push_subscriptions WHERE id = ?', [$row['id']]);
    }
    push_reply(['ok' => true]);
}

$uid = push_account_id();   // chi ha fatto l'accesso, oppure chi si è appena iscritto e aspetta l'approvazione
if (!$uid) {
    push_reply(['ok' => false, 'error' => 'Accedi di nuovo.'], 401);
}

if ($do === 'subscribe') {
    $err = push_save_subscription($uid, $endpoint, (string) ($_POST['p256dh'] ?? ''), (string) ($_POST['auth'] ?? ''),
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($err) {
        push_reply(['ok' => false, 'error' => $err], 422);
    }
    // l'indirizzo del sito serve a firmare le notifiche anche quando parte il cron (senza una richiesta del browser)
    $base = site_base_url();
    if (str_starts_with($base, 'https://') && meta_get('site_url') !== $base) {
        meta_set('site_url', $base);
    }
    push_reply(['ok' => true]);
}

if ($do === 'unsubscribe') {
    q('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?', [hash('sha256', $endpoint), $uid]);
    push_reply(['ok' => true]);
}

if ($do === 'test') {
    // la prova non passa dalla coda: deve dire subito com'è andata, compreso il motivo se non funziona
    $codes = webpush_deliver(push_subs_of_users([$uid]), [
        'title' => 'Notifiche attive',
        'body' => 'Funziona! Riceverai un avviso per le nuove partite e per le votazioni.',
        'url' => 'index.php', 'tag' => 'test',
    ], 'high');
    $ok = array_filter($codes, fn($r) => $r['code'] >= 200 && $r['code'] < 300);
    $worst = $codes ? (int) min(array_column($codes, 'code')) : 0;
    push_reply(['ok' => (bool) $ok, 'sent' => count($ok), 'error' => $ok ? null
        : ($codes ? 'Il servizio push non ha accettato la notifica: ' . push_code_reason($worst) . '.'
                  : 'Questo account non ha nessun dispositivo con le notifiche attive: riattivale e riprova.')]);
}

push_reply(['ok' => false, 'error' => 'Azione non valida.'], 400);
