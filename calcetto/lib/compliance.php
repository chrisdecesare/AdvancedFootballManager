<?php
/*
 * Google Play e legge: segnalazione e blocco dei contenuti (Play «User Generated Content», DSA art. 16), consenso ai termini e all'età,
 * cancellazione dell'account (Play «Account deletion», GDPR art. 17) ed esportazione dei dati (art. 15 e 20).
 * Copia di next-app/src/lib/compliance.ts e compliance-format.ts: stesse tabelle, stessi limiti, stessi messaggi. Se cambia uno, si aggiorna l'altro.
 */
const TERMS_VERSION = '2026-11-03';
const DELETE_PHRASE = 'ELIMINA';
const REPORT_MAX_PER_HOUR = 10;
const REPORT_DETAILS_MAX = 500;
const FORMER_PLAYER_NAME = 'Ex giocatore';
const REPORT_TYPES = ['player' => 'Giocatore (nome o foto)', 'thought' => 'Pensiero dello spogliatoio', 'league' => 'Lega', 'pitch' => 'Campetto'];
const REPORT_REASONS = [
    'offensivo' => 'Offensivo, odio o molestie', 'spam' => 'Spam o pubblicità', 'falso' => 'Falso o finge di essere un altro',
    'privacy' => 'Foto o dati personali miei senza consenso', 'illegale' => 'Contenuto illegale', 'altro' => 'Altro',
];

/** Esegue una query su una tabella che potrebbe non esserci (copia dello schema «business» più vecchia): se manca, è come se fosse vuota. */
function compliance_try(string $sql, array $params = []): ?PDOStatement
{
    try {
        return q($sql, $params);
    } catch (Throwable $e) {
        return null;
    }
}

/** Crea le tabelle se mancano (una volta, poi lo ricorda in meta). Le stesse le crea Next (lib/billing/schema.ts). */
function compliance_schema_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (meta_get('compliance_schema') === '1') {
        return;
    }
    foreach ([
        "CREATE TABLE IF NOT EXISTS content_reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reporter_user_id INT NULL, target_type VARCHAR(12) NOT NULL, target_id INT NOT NULL,
            reason VARCHAR(20) NOT NULL, details VARCHAR(500) NOT NULL DEFAULT '', status VARCHAR(12) NOT NULL DEFAULT 'aperta',
            outcome VARCHAR(300) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, handled_at DATETIME NULL, handled_by INT NULL,
            KEY ix_cr_status (status, created_at), KEY ix_cr_reporter (reporter_user_id, created_at), KEY ix_cr_target (target_type, target_id)
        ) DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS user_blocks (
            blocker_user_id INT NOT NULL, blocked_player_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (blocker_user_id, blocked_player_id), KEY ix_ub_player (blocked_player_id)
        ) DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS user_consents (
            user_id INT NOT NULL PRIMARY KEY, terms_version VARCHAR(16) NOT NULL, age_ok TINYINT(1) NOT NULL DEFAULT 0,
            accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS deletion_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(60) NOT NULL DEFAULT '', contact VARCHAR(190) NOT NULL,
            message VARCHAR(500) NOT NULL DEFAULT '', status VARCHAR(12) NOT NULL DEFAULT 'aperta',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, handled_at DATETIME NULL, KEY ix_dr_status (status, created_at)
        ) DEFAULT CHARSET=utf8mb4",
    ] as $sql) {
        db()->exec($sql);
    }
    meta_set('compliance_schema', '1');
}

/* ---------------------------------------------------------------- segnalazioni */

/** Il contenuto segnalato (come lo vede l'admin) oppure null se non esiste. */
function report_target(string $type, int $id): ?array
{
    if ($type === 'player') {
        $r = q('SELECT name FROM players WHERE id = ? AND is_guest = 0', [$id])->fetch();
        return $r ? ['label' => 'Giocatore', 'preview' => (string) $r['name']] : null;
    }
    if ($type === 'thought') {
        $r = q("SELECT t.body, p.name FROM match_thoughts t JOIN players p ON p.id = t.player_id WHERE t.id = ? AND t.body <> ''", [$id])->fetch();
        return $r ? ['label' => 'Pensiero', 'preview' => $r['name'] . ': «' . $r['body'] . '»'] : null;
    }
    if ($type === 'league') {
        $r = q('SELECT name FROM squad_groups WHERE id = ?', [$id])->fetch();
        return $r ? ['label' => 'Lega', 'preview' => (string) $r['name']] : null;
    }
    if ($type === 'pitch') {
        biz_schema_ensure();
        $r = compliance_try('SELECT name, address, city FROM pitches WHERE id = ?', [$id])?->fetch();
        return $r ? ['label' => 'Campetto', 'preview' => trim($r['name'] . ', ' . $r['address'] . ' ' . $r['city'])] : null;
    }
    return null;
}

/** Controlla i campi di una segnalazione: [errore|null, dati]. */
function report_parse(array $f): array
{
    $type = (string) ($f['type'] ?? '');
    $reason = (string) ($f['reason'] ?? '');
    $id = (int) ($f['id'] ?? 0);
    if (!isset(REPORT_TYPES[$type]) || $id <= 0) {
        return ['Segnalazione non valida.', null];
    }
    if (!isset(REPORT_REASONS[$reason])) {
        return ['Scegli il motivo della segnalazione.', null];
    }
    $details = trim((string) preg_replace('/\s+/u', ' ', (string) ($f['details'] ?? '')));
    if (mb_strlen($details) > REPORT_DETAILS_MAX) {
        return ['Le note sono troppo lunghe (massimo ' . REPORT_DETAILS_MAX . ' caratteri).', null];
    }
    if ($reason === 'altro' && mb_strlen($details) < 5) {
        return ['Con «Altro» scrivi due parole su cosa non va.', null];
    }
    return [null, ['type' => $type, 'id' => $id, 'reason' => $reason, 'details' => $details]];
}

/** Registra la segnalazione. Ritorna un messaggio d'errore oppure null se è andata bene. */
function report_content(int $uid, array $f): ?string
{
    compliance_schema_ensure();
    [$err, $v] = report_parse($f);
    if ($err) {
        return $err;
    }
    $recent = (int) q('SELECT COUNT(*) FROM content_reports WHERE reporter_user_id = ? AND created_at > ?', [$uid, date('Y-m-d H:i:s', time() - 3600)])->fetchColumn();
    if ($recent >= REPORT_MAX_PER_HOUR) {
        return 'Hai fatto molte segnalazioni in poco tempo: riprova più tardi.';
    }
    if (!report_target($v['type'], $v['id'])) {
        return 'Questo contenuto non esiste più.';
    }
    $mine = (int) q('SELECT player_id FROM users WHERE id = ?', [$uid])->fetchColumn();
    if ($v['type'] === 'player' && $mine && $mine === $v['id']) {
        return 'Non puoi segnalare il tuo profilo.';
    }
    if (q("SELECT 1 FROM content_reports WHERE reporter_user_id = ? AND target_type = ? AND target_id = ? AND status = 'aperta'", [$uid, $v['type'], $v['id']])->fetch()) {
        return 'Hai già segnalato questo contenuto: lo stiamo guardando.';
    }
    q('INSERT INTO content_reports (reporter_user_id, target_type, target_id, reason, details) VALUES (?, ?, ?, ?, ?)', [$uid, $v['type'], $v['id'], $v['reason'], $v['details']]);
    $addr = trim((string) (getenv('REPORTS_EMAIL') ?: ''));
    if ($addr !== '') {
        $t = report_target($v['type'], $v['id']);
        send_mail($addr, 'Nuova segnalazione su Calcetto Manager', "Una segnalazione aspetta una decisione.\n\nTipo: " . REPORT_TYPES[$v['type']] . "\nContenuto: " . ($t['preview'] ?? '(non più disponibile)')
            . "\nMotivo: " . REPORT_REASONS[$v['reason']] . ($v['details'] !== '' ? "\nNote: " . $v['details'] : '') . "\n\nSi gestisce da Piattaforma → Segnalazioni.");
    }
    log_activity('segnalazione', $v['type'] . ' #' . $v['id'] . ' · ' . $v['reason'], null, $uid);
    return null;
}

function reports_list(string $status = 'aperta', int $limit = 100): array
{
    compliance_schema_ensure();
    $where = $status === 'aperta' ? "WHERE r.status = 'aperta'" : '';
    return q("SELECT r.id, r.target_type, r.target_id, r.reason, r.details, r.status, r.outcome, r.created_at, r.handled_at, u.username AS reporter
              FROM content_reports r LEFT JOIN users u ON u.id = r.reporter_user_id $where ORDER BY r.id DESC LIMIT " . max(1, min(500, $limit)))->fetchAll();
}

function reports_open_count(): int
{
    compliance_schema_ensure();
    return (int) q("SELECT COUNT(*) FROM content_reports WHERE status = 'aperta'")->fetchColumn();
}

/** Toglie il contenuto segnalato (ciò che si può togliere da soli). Ritorna cosa è stato fatto. */
function report_remove(string $type, int $id): string
{
    if ($type === 'thought') {
        q("UPDATE match_thoughts SET body = '' WHERE id = ?", [$id]);
        return 'pensiero tolto';
    }
    if ($type === 'player') {
        $p = q('SELECT photo, bg_image, bg_image_v, bg_src FROM players WHERE id = ?', [$id])->fetch();
        if ($p) {
            foreach (array_merge([$p['photo']], bg_files($p)) as $f) {
                delete_photo_file($f);
            }
        }
        q('UPDATE players SET photo = NULL, bg_image = NULL, bg_image_v = NULL, bg_src = NULL WHERE id = ?', [$id]);
        return 'foto e sfondo tolti';
    }
    if ($type === 'pitch') {
        biz_schema_ensure();
        compliance_try('DELETE FROM pitch_ratings WHERE pitch_id = ?', [$id]);
        compliance_try('DELETE FROM pitches WHERE id = ? AND center_id IS NULL', [$id]);
        return 'campetto tolto';
    }
    return 'da valutare a mano (lega)';
}

/** Chiude una segnalazione con il motivo della decisione (lo legge chi ha segnalato). Ritorna un errore o null. */
function report_resolve(int $id, int $adminId, string $action, string $outcome): ?string
{
    compliance_schema_ensure();
    $r = q("SELECT target_type, target_id FROM content_reports WHERE id = ? AND status = 'aperta'", [$id])->fetch();
    if (!$r) {
        return 'Segnalazione non trovata o già chiusa.';
    }
    $note = mb_substr(trim($outcome), 0, 250);
    if ($action === 'rimossa') {
        $note = report_remove($r['target_type'], (int) $r['target_id']) . ($note !== '' ? ' · ' . $note : '');
    }
    q('UPDATE content_reports SET status = ?, outcome = ?, handled_at = ?, handled_by = ? WHERE id = ?', [$action === 'respinta' ? 'respinta' : 'gestita', $note, date('Y-m-d H:i:s'), $adminId, $id]);
    return null;
}

function my_reports(int $uid): array
{
    compliance_schema_ensure();
    return q('SELECT id, target_type, target_id, reason, details, status, outcome, created_at FROM content_reports WHERE reporter_user_id = ? ORDER BY id DESC LIMIT 50', [$uid])->fetchAll();
}

/* ---------------------------------------------------------------- blocchi */

function blocked_player_ids(int $uid): array
{
    compliance_schema_ensure();
    return array_map('intval', q('SELECT blocked_player_id FROM user_blocks WHERE blocker_user_id = ?', [$uid])->fetchAll(PDO::FETCH_COLUMN));
}

function block_player(int $uid, int $playerId): ?string
{
    compliance_schema_ensure();
    $me = (int) q('SELECT player_id FROM users WHERE id = ?', [$uid])->fetchColumn();
    if ($me && $me === $playerId) {
        return 'Non puoi bloccare te stesso.';
    }
    if (!q('SELECT 1 FROM players WHERE id = ? AND is_guest = 0', [$playerId])->fetch()) {
        return 'Giocatore non trovato.';
    }
    if ((int) q('SELECT COUNT(*) FROM user_blocks WHERE blocker_user_id = ?', [$uid])->fetchColumn() >= 200) {
        return 'Hai bloccato troppi giocatori.';
    }
    q('INSERT IGNORE INTO user_blocks (blocker_user_id, blocked_player_id) VALUES (?, ?)', [$uid, $playerId]);
    return null;
}

function unblock_player(int $uid, int $playerId): void
{
    compliance_schema_ensure();
    q('DELETE FROM user_blocks WHERE blocker_user_id = ? AND blocked_player_id = ?', [$uid, $playerId]);
}

function my_blocks(int $uid): array
{
    compliance_schema_ensure();
    return q('SELECT b.blocked_player_id AS player_id, p.name FROM user_blocks b JOIN players p ON p.id = b.blocked_player_id WHERE b.blocker_user_id = ? ORDER BY b.created_at DESC', [$uid])->fetchAll();
}

/* ---------------------------------------------------------------- consenso */

function record_consent(int $uid): void
{
    compliance_schema_ensure();
    q('INSERT INTO user_consents (user_id, terms_version, age_ok) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE terms_version = VALUES(terms_version), age_ok = 1, accepted_at = NOW()', [$uid, TERMS_VERSION]);
}

/* ---------------------------------------------------------------- cancellazione dell'account */

/** Cosa impedisce di cancellare l'account adesso (vuoto = si può). */
function account_blockers(int $uid): array
{
    compliance_schema_ensure();
    biz_schema_ensure();
    $u = q('SELECT role FROM users WHERE id = ?', [$uid])->fetch();
    if (!$u) {
        return ['Account non trovato.'];
    }
    $n = fn (string $sql): int => (int) (compliance_try($sql, [$uid])?->fetchColumn() ?: 0);
    $out = [];
    if ($u['role'] === 'admin') {
        $out[] = 'Un account admin del sito non si elimina da qui: passa prima il ruolo a un altro admin.';
    }
    $leagues = $n('SELECT COUNT(*) FROM squad_groups WHERE owner_user_id = ?');
    if ($leagues > 0) {
        $out[] = $leagues === 1 ? 'Possiedi una lega: cedila a un altro giocatore o eliminala da «La mia lega».' : "Possiedi $leagues leghe: cedile o eliminale da «La mia lega».";
    }
    if ($n('SELECT COUNT(*) FROM sport_centers WHERE owner_user_id = ?') > 0) {
        $out[] = "Gestisci un centro sportivo: cedilo o eliminalo dall'area del centro.";
    }
    if ($n("SELECT COUNT(*) FROM billing_subscriptions WHERE user_id = ? AND status IN ('active','trialing','past_due','unpaid','incomplete')") > 0) {
        $out[] = "Hai un abbonamento attivo: disdicilo prima dalla pagina Abbonamento. Per i dati di fatturazione la legge ci chiede di conservarli, ma l'account si cancella.";
    }
    return $out;
}

/** Elimina l'account e i dati personali (il giocatore resta come «Ex giocatore»). Ritorna gli ostacoli oppure [] se è fatto. */
function delete_own_account(int $uid): array
{
    $blockers = account_blockers($uid);
    if ($blockers) {
        return $blockers;
    }
    $u = q('SELECT player_id, email, pending_email, username FROM users WHERE id = ?', [$uid])->fetch();
    if (!$u) {
        return ['Account non trovato.'];
    }
    $pid = (int) $u['player_id'];
    if ($pid) {
        $p = q('SELECT photo, bg_image, bg_image_v, bg_src FROM players WHERE id = ?', [$pid])->fetch();
        if ($p) {
            foreach (array_merge([$p['photo']], bg_files($p)) as $f) {
                delete_photo_file($f);
            }
        }
        q('DELETE FROM match_thoughts WHERE player_id = ?', [$pid]);
        biz_schema_ensure();
        compliance_try('DELETE FROM pitch_ratings WHERE player_id = ?', [$pid]);
        q('DELETE FROM user_blocks WHERE blocked_player_id = ?', [$pid]);
        q('UPDATE players SET name = ?, photo = NULL, bg_image = NULL, bg_image_v = NULL, bg_src = NULL, bg_color = NULL, nick_key = NULL, guest_email = NULL, active = 0 WHERE id = ?', [FORMER_PLAYER_NAME, $pid]);
    }
    biz_schema_ensure();
    foreach (array_filter([$u['email'], $u['pending_email']]) as $e) {
        compliance_try('DELETE FROM newsletter WHERE email = ?', [$e]);
        compliance_try('DELETE FROM mail_queue WHERE to_addr = ?', [$e]);
    }
    q('DELETE FROM user_blocks WHERE blocker_user_id = ?', [$uid]);
    q('UPDATE content_reports SET reporter_user_id = NULL WHERE reporter_user_id = ?', [$uid]);
    q('DELETE FROM user_consents WHERE user_id = ?', [$uid]);
    q('DELETE FROM activity_log WHERE user_id = ?', [$uid]);
    compliance_try('DELETE FROM login_attempts WHERE username = ?', [$u['username']]);
    q('DELETE FROM users WHERE id = ?', [$uid]);   // sessioni, «resta collegato», notifiche, dispositivi, proposte e codici: a cascata
    return [];
}

/* ---------------------------------------------------------------- richieste dall'esterno */

function deletion_request_create(string $username, string $contact, string $message): void
{
    compliance_schema_ensure();
    q('INSERT INTO deletion_requests (username, contact, message) VALUES (?, ?, ?)', [mb_substr($username, 0, 60), mb_substr($contact, 0, 190), mb_substr($message, 0, 500)]);
}

function deletion_requests_open(): array
{
    compliance_schema_ensure();
    return q("SELECT id, username, contact, message, created_at FROM deletion_requests WHERE status = 'aperta' ORDER BY id DESC LIMIT 100")->fetchAll();
}

function deletion_request_close(int $id): void
{
    compliance_schema_ensure();
    q("UPDATE deletion_requests SET status = 'chiusa', handled_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
}

/* ---------------------------------------------------------------- esportazione dei dati */

/** Tutti i dati dell'utente (art. 15 e 20 GDPR). Niente password né segreti. */
function export_my_data(int $uid): array
{
    compliance_schema_ensure();
    $pid = (int) q('SELECT player_id FROM users WHERE id = ?', [$uid])->fetchColumn();
    $all = fn (string $sql, array $p = []): array => q($sql, $p)->fetchAll();
    return [
        'generato_il' => date('Y-m-d H:i:s'),
        'account' => q('SELECT id, username, role, status, email, created_at, last_seen_at, tour_done FROM users WHERE id = ?', [$uid])->fetch() ?: null,
        'giocatore' => $pid ? (q('SELECT id, name, photo, shirt_number, position, position2, foot, gender, created_at FROM players WHERE id = ?', [$pid])->fetch() ?: null) : null,
        'leghe' => $pid ? $all('SELECT g.id, g.name FROM player_groups pg JOIN squad_groups g ON g.id = pg.group_id WHERE pg.player_id = ?', [$pid]) : [],
        'partite' => $pid ? $all('SELECT m.id, m.match_date, mp.team, mp.availability, mp.goals, mp.assists FROM match_players mp JOIN matches m ON m.id = mp.match_id WHERE mp.player_id = ? ORDER BY m.match_date DESC LIMIT 1000', [$pid]) : [],
        'pensieri' => $pid ? $all("SELECT match_id, body, created_at FROM match_thoughts WHERE player_id = ? AND body <> ''", [$pid]) : [],
        'proposte' => $all('SELECT body, created_at FROM proposals WHERE user_id = ?', [$uid]),
        'segnalazioni' => $all('SELECT target_type, target_id, reason, details, status, outcome, created_at FROM content_reports WHERE reporter_user_id = ?', [$uid]),
        'blocchi' => $all('SELECT blocked_player_id, created_at FROM user_blocks WHERE blocker_user_id = ?', [$uid]),
        'scommesse' => $pid ? $all('SELECT * FROM wallet_moves WHERE player_id = ? ORDER BY id DESC LIMIT 1000', [$pid]) : [],
        'consensi' => $all('SELECT terms_version, age_ok, accepted_at FROM user_consents WHERE user_id = ?', [$uid]),
    ];
}

/* ---------------------------------------------------------------- pulsanti «Segnala» e «Blocca» */

/** HTML di «Segnala» (e, per un giocatore, «Blocca») accanto a un contenuto di altri. Vuoto se la funzione «Segnala e blocca» non è accesa per chi guarda. */
function report_button_html(string $type, int $id, string $back, ?int $blockPlayerId = null): string
{
    if (!feature_on('report_block') || !current_user()) {
        return '';
    }
    $opts = '';
    foreach (REPORT_REASONS as $k => $label) {
        $opts .= '<option value="' . h($k) . '">' . h($label) . '</option>';
    }
    $hid = fn (string $n, $v): string => '<input type="hidden" name="' . $n . '" value="' . h((string) $v) . '">';
    $html = '<span class="report-wrap"><details class="report-box"><summary class="btn btn-ghost btn-sm"><i class="ti ti-flag"></i> Segnala</summary>'
        . '<form method="post" action="segnala.php" class="form report-form">' . csrf_field() . $hid('do', 'report') . $hid('type', $type) . $hid('id', $id) . $hid('back', $back)
        . '<label class="field"><span>Perché lo segnali?</span><select name="reason" required><option value="" disabled selected>Scegli un motivo</option>' . $opts . '</select></label>'
        . '<label class="field"><span>Note (facoltative)</span><textarea name="details" maxlength="500" rows="2"></textarea></label>'
        . '<button class="btn btn-primary btn-sm">Invia la segnalazione</button></form></details>';
    if ($blockPlayerId) {
        $html .= '<form method="post" action="segnala.php" class="inline">' . csrf_field() . $hid('do', 'block') . $hid('player_id', $blockPlayerId) . $hid('back', $back)
            . '<button class="btn btn-ghost btn-sm" data-confirm="Bloccare questo giocatore? Non vedrai più i suoi pensieri."><i class="ti ti-ban"></i> Blocca</button></form>';
    }
    return $html . '</span>';
}

/* ---------------------------------------------------------------- avviso per chi era già iscritto */

/** Avviso non bloccante per chi era iscritto prima dei termini del 3 novembre 2026: finché non preme «Accetto» non c'è un record in user_consents. Se qualcosa va storto, non compare. */
function terms_notice_html(): string
{
    try {
        $u = current_user();
        if (!$u || ($u['role'] ?? '') === 'ospite' || is_guest()) {
            return '';
        }
        compliance_schema_ensure();
        if (q('SELECT 1 FROM user_consents WHERE user_id = ?', [(int) $u['id']])->fetch()) {
            return '';
        }
        $back = (string) ($_SERVER['REQUEST_URI'] ?? 'index.php');
        return '<section class="card terms-notice" role="note"><p><b>Abbiamo aggiornato Termini d\'uso e informativa privacy.</b> Leggili <a class="link" href="' . h(NEXT_SITE) . '/termini" target="_blank" rel="noopener">qui</a> e <a class="link" href="' . h(NEXT_SITE) . '/privacy" target="_blank" rel="noopener">qui</a>: ci sono i nuovi strumenti per segnalare e bloccare, scaricare i tuoi dati ed eliminare l\'account.</p>'
            . '<form method="post" action="accetta.php" class="inline">' . csrf_field() . '<input type="hidden" name="back" value="' . h($back) . '"><button class="btn btn-primary btn-sm">Accetto e ho almeno 14 anni</button></form></section>';
    } catch (Throwable $e) {
        return '';
    }
}
