<?php
/* «Inserita nel calendario»: la chiama la pagina (assets/app.js) quando si tocca «Aggiungi a Google Calendar» o «non l'ho inserita». Risponde in JSON. */
require __DIR__ . '/lib/bootstrap.php';   // il controllo del token (csrf) delle richieste POST lo fa il bootstrap
header('Content-Type: application/json');
$u = current_user();
if (!is_post() || !$u) {
    http_response_code($u ? 405 : 401);
    echo json_encode(['ok' => false]);
    exit;
}
$id = (int) ($_POST['match'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}
if (($_POST['undo'] ?? '') === '1') {
    cal_unmark((int) $u['id'], $id);
    echo json_encode(['ok' => true]);
    exit;
}
echo json_encode(['ok' => cal_mark((int) $u['id'], $id)]);
