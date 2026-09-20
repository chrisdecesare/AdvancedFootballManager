<?php
/* Notifiche push: il browser registra (o toglie) qui il proprio abbonamento. Risponde in JSON. */
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function push_reply(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$u = current_user();
if (!$u) {
    push_reply(['ok' => false, 'error' => 'Accedi di nuovo.'], 401);
}
if (!is_post()) {
    push_reply(['ok' => false, 'error' => 'Metodo non valido.'], 405);
}
if (!push_supported() || !vapid_keys()) {
    push_reply(['ok' => false, 'error' => 'Questo server non può mandare notifiche (manca il supporto openssl).'], 501);
}

$do = $_POST['do'] ?? '';
$endpoint = is_string($_POST['endpoint'] ?? null) ? $_POST['endpoint'] : '';

if ($do === 'subscribe') {
    $err = push_save_subscription((int) $u['id'], $endpoint, (string) ($_POST['p256dh'] ?? ''), (string) ($_POST['auth'] ?? ''),
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
    q('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?', [hash('sha256', $endpoint), $u['id']]);
    push_reply(['ok' => true]);
}

if ($do === 'test') {
    $sent = push_notify_users([(int) $u['id']], [
        'title' => 'Notifiche attive',
        'body' => 'Funziona! Riceverai un avviso per le nuove partite e per le votazioni.',
        'url' => 'index.php', 'tag' => 'test',
    ], 'high');
    push_reply(['ok' => $sent > 0, 'sent' => $sent, 'error' => $sent > 0 ? null : 'Nessun dispositivo ha accettato la notifica: riattiva le notifiche e riprova.']);
}

push_reply(['ok' => false, 'error' => 'Azione non valida.'], 400);
