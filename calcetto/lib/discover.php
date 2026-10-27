<?php
/*
 * Trovare una lega (come next-app/src/lib/discover.ts): le leghe che il proprietario rende visibili compaiono nella pagina della loro città con chi le
 * amministra, quanti giocatori hanno, quante partite al mese hanno fatto negli ultimi 6 mesi e il bollino NUOVO sotto i 30 giorni. Chi ne trova una
 * manda una richiesta di ingresso (sempre approvata da chi la gestisce) e può sfidarla con una delle sue leghe.
 */

const LEAGUE_NEW_DAYS = 30;

/** Schede delle leghe visibili: tutte, di una città o una sola. */
function league_cards(?string $citySlug = null, ?int $groupId = null): array
{
    biz_schema_ensure();
    $cond = ['lp.discoverable = 1'];
    $par = [];
    if ($citySlug !== null) {
        $cond[] = 'lp.city_slug = ?';
        $par[] = $citySlug;
    }
    if ($groupId !== null) {
        $cond[] = 'lp.group_id = ?';
        $par[] = $groupId;
    }
    $rows = q("SELECT g.id, g.name, g.created_at, lp.city, lp.city_slug, lp.area, lp.about, lp.logo, lp.lat, lp.lng,
        COALESCE((SELECT COALESCE(p.name, u.username) FROM users u LEFT JOIN players p ON p.id = u.player_id WHERE u.id = g.owner_user_id), 'Calcetto Manager') AS admin,
        (SELECT COUNT(*) FROM player_groups pg JOIN players p ON p.id = pg.player_id WHERE pg.group_id = g.id AND p.is_guest = 0 AND p.active = 1) AS players,
        (SELECT COUNT(*) FROM matches m WHERE m.group_id = g.id AND m.status = 'giocata' AND m.match_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)) AS played6,
        (g.created_at > DATE_SUB(NOW(), INTERVAL " . LEAGUE_NEW_DAYS . " DAY)) AS is_new,
        c.slug AS center_slug, c.name AS center_name, cg.kind AS center_kind
      FROM league_public lp JOIN squad_groups g ON g.id = lp.group_id
      LEFT JOIN center_groups cg ON cg.group_id = g.id LEFT JOIN sport_centers c ON c.id = cg.center_id
      WHERE " . implode(' AND ', $cond) . ' ORDER BY is_new DESC, players DESC, g.name', $par)->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        // una lega con meno di 6 mesi di vita si divide per i mesi che ha davvero (almeno 1), non per 6
        $age = max(1.0, min(6.0, (time() - strtotime((string) $r['created_at'])) / (30 * 86400)));
        $out[] = [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'city' => (string) $r['city'], 'city_slug' => (string) $r['city_slug'], 'area' => (string) $r['area'],
            'about' => (string) $r['about'], 'logo' => (string) $r['logo'], 'lat' => $r['lat'] === null ? null : (float) $r['lat'], 'lng' => $r['lng'] === null ? null : (float) $r['lng'],
            'admin' => (string) $r['admin'], 'players' => (int) $r['players'], 'played6' => (int) $r['played6'], 'perMonth' => round(((int) $r['played6']) / $age, 1),
            'isNew' => (int) $r['is_new'] === 1, 'center' => $r['center_slug'] ? ['slug' => (string) $r['center_slug'], 'name' => (string) $r['center_name']] : null,
            'home' => $r['center_kind'] === 'home',
        ];
    }
    return $out;
}

/** Città con almeno una lega visibile o un centro: per l'indice. */
function cities_with_leagues(): array
{
    biz_schema_ensure();
    return q("SELECT slug, MAX(city) AS city, SUM(l) AS leagues, SUM(c) AS centers FROM (
        SELECT city_slug AS slug, city, 1 AS l, 0 AS c FROM league_public WHERE discoverable = 1
        UNION ALL SELECT city_slug, city, 0, 1 FROM sport_centers WHERE city_slug <> ''
      ) t GROUP BY slug ORDER BY leagues DESC, city")->fetchAll();
}

/** Centri di una città con un piano attivo (Centro o Centro Plus): per Centro Plus vale per tutte le sedi del proprietario. */
function centers_in_city(string $citySlug): array
{
    biz_schema_ensure();
    $out = [];
    foreach (q('SELECT * FROM sport_centers WHERE city_slug = ? ORDER BY name', [$citySlug])->fetchAll() as $c) {
        $plans = q("SELECT plan, center_id FROM billing_subscriptions WHERE user_id = ? AND plan IN ('centro', 'centro_plus') AND status IN ('active', 'trialing', 'past_due')", [$c['owner_user_id']])->fetchAll();
        foreach ($plans as $p) {
            if ($p['plan'] === 'centro_plus' || (int) $p['center_id'] === (int) $c['id']) {
                $out[] = $c;
                break;
            }
        }
    }
    return $out;
}

function league_public_get(int $gid): ?array
{
    biz_schema_ensure();
    return q('SELECT city, area, about, logo, discoverable, lat, lng FROM league_public WHERE group_id = ?', [$gid])->fetch() ?: null;
}

function league_public_error(array $v): ?string
{
    $len = fn (string $s) => mb_strlen($s);
    if ($len($v['city']) < 2 || $len($v['city']) > 80) {
        return 'Scrivi la città (da 2 a 80 caratteri).';
    }
    if ($len($v['about']) > 300) {
        return 'La presentazione può avere al massimo 300 caratteri.';
    }
    return null;
}

/** Salva dove gioca la lega e se si può trovare; le coordinate si cercano solo se zona o città sono cambiate (se non rispondono le completa Next). */
function league_public_set(int $gid, array $v): void
{
    biz_schema_ensure();
    $old = q('SELECT city, area, lat FROM league_public WHERE group_id = ?', [$gid])->fetch();
    $picked = isset($v['lat'], $v['lng']);   // punto scelto sulla mappa: vale più della ricerca per nome
    $same = !$picked && $old && $old['city'] === $v['city'] && $old['area'] === $v['area'] && $old['lat'] !== null;
    $p = $picked ? [$v['lat'], $v['lng']] : ($same ? null : geocode($v['area'], $v['city']));
    q('INSERT INTO league_public (group_id, city, city_slug, area, lat, lng, about, discoverable) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE city = VALUES(city), city_slug = VALUES(city_slug), area = VALUES(area), about = VALUES(about), discoverable = VALUES(discoverable),
         lat = IF(? = 1, lat, VALUES(lat)), lng = IF(? = 1, lng, VALUES(lng)), updated_at = CURRENT_TIMESTAMP',
        [$gid, $v['city'], city_slug($v['city']), $v['area'], $p[0] ?? null, $p[1] ?? null, $v['about'], $v['discoverable'] ? 1 : 0, $same ? 1 : 0, $same ? 1 : 0]);
}

/** Richiesta di entrare in una lega trovata sulla mappa: inviata | già | limite | nonvisibile. */
function league_request(int $uid, int $gid): string
{
    biz_schema_ensure();
    if (!q('SELECT 1 FROM league_public WHERE group_id = ? AND discoverable = 1', [$gid])->fetch()) {
        return 'nonvisibile';
    }
    $pid = (int) q('SELECT player_id FROM users WHERE id = ?', [$uid])->fetchColumn();
    if ($pid && player_in_group($pid, $gid)) {
        return 'già';
    }
    if (q('SELECT 1 FROM group_requests WHERE group_id = ? AND user_id = ?', [$gid, $uid])->fetch()) {
        return 'già';
    }
    if (!rate_hit('__join_' . $uid, 10, 86400)) {
        return 'limite';
    }
    ensure_user_player($uid);
    q('INSERT INTO group_requests (group_id, user_id) VALUES (?, ?)', [$gid, $uid]);
    log_activity('lega_richiesta', 'chiede di entrare (trovata sulla mappa)', $gid, $uid);
    push_notify_league_request($gid, $uid);
    return 'inviata';
}

/* ---------------------------------------------------------------- sfide tra leghe */

/** Orari liberi nei calendari dei centri di una città (per proporli in una sfida). */
function free_slots_in_city(string $citySlug, int $limit = 30): array
{
    biz_schema_ensure();
    $rows = q('SELECT s.id, s.starts_at, s.field_no, c.name FROM center_slots s JOIN sport_centers c ON c.id = s.center_id
        WHERE c.city_slug = ? AND s.blocked = 0 AND s.booked_group_id IS NULL AND s.starts_at > ? ORDER BY s.starts_at LIMIT ' . (int) $limit, [$citySlug, date('Y-m-d H:i:s')])->fetchAll();
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'label' => substr($r['starts_at'], 8, 2) . '/' . substr($r['starts_at'], 5, 2) . ' ' . substr($r['starts_at'], 11, 5) . ' · ' . $r['name'] . ', campo ' . $r['field_no']], $rows);
}

/** Anteprima riservata all'admin (come DISCOVER_OPEN sul sito Next): per aprirla a tutti basta mettere true. */
const DISCOVER_OPEN = false;
function discover_visible(): bool
{
    return DISCOVER_OPEN || (current_user() && is_admin());
}

/** Per le pagine dell'anteprima: a chi non può vederle risponde 404. */
function require_discover(): void
{
    if (!discover_visible()) {
        http_response_code(404);
        exit('Pagina non trovata.');
    }
}

const CHALLENGE_SEL = 'SELECT c.*, gf.name AS from_name, gt.name AS to_name FROM league_challenges c JOIN squad_groups gf ON gf.id = c.from_group JOIN squad_groups gt ON gt.id = c.to_group';

/** Crea la sfida; ritorna un messaggio d'errore oppure null. */
function challenge_create(int $from, int $to, ?int $slotId, string $when, string $location, string $message): ?string
{
    biz_schema_ensure();
    $uid = (int) current_user()['id'];
    if ($from === $to) {
        return 'Scegli una lega diversa dalla tua.';
    }
    if (!can_manage_group($from)) {
        return 'Puoi sfidare solo con una lega che gestisci.';
    }
    if (!q('SELECT 1 FROM league_public WHERE group_id = ? AND discoverable = 1', [$to])->fetch()) {
        return 'Questa lega non è visibile e non si può sfidare da qui.';
    }
    if (q("SELECT 1 FROM league_challenges WHERE from_group = ? AND to_group = ? AND status = 'inviata'", [$from, $to])->fetch()) {
        return 'C\'è già una sfida in attesa tra queste due leghe.';
    }
    if (!rate_hit('__sfida_' . $uid, 5, 86400)) {
        return 'Hai già mandato 5 sfide oggi: riprova domani.';
    }
    $location = mb_substr(trim($location), 0, 120);
    $now = date('Y-m-d H:i:s');
    if ($slotId) {
        $s = q('SELECT s.*, c.name AS center_name FROM center_slots s JOIN sport_centers c ON c.id = s.center_id WHERE s.id = ?', [$slotId])->fetch();
        if (!$s || $s['blocked'] || $s['booked_group_id'] || $s['starts_at'] <= $now) {
            return 'Quell\'orario del campo non è più libero.';
        }
        $at = (string) $s['starts_at'];
        $location = $s['center_name'] . ' · campo ' . $s['field_no'];
    } else {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})$/', $when, $m)) {
            return 'Scegli un orario del calendario o indica giorno e ora.';
        }
        $at = $m[1] . ' ' . $m[2] . ':00';
        if ($at <= $now) {
            return 'La data della sfida deve essere nel futuro.';
        }
    }
    q('INSERT INTO league_challenges (from_group, to_group, slot_id, proposed_at, location, message, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$from, $to, $slotId ?: null, $at, $location, mb_substr(trim($message), 0, 300), $uid]);
    $id = (int) db()->lastInsertId();
    log_activity('sfida', 'sfida inviata a un\'altra lega (#' . $id . ')', $from);
    challenge_notify($id);
    return null;
}

/** Email a chi gestisce la lega sfidata. */
function challenge_notify(int $id): void
{
    $c = q(CHALLENGE_SEL . ' WHERE c.id = ?', [$id])->fetch();
    if (!$c) {
        return;
    }
    $to = q("SELECT DISTINCT u.email, COALESCE(p.name, u.username) AS name FROM group_roles gr JOIN users u ON u.id = gr.user_id AND u.status = 'attivo' AND u.email IS NOT NULL
        LEFT JOIN players p ON p.id = u.player_id WHERE gr.group_id = ? AND gr.role IN ('owner', 'admin')", [$c['to_group']])->fetchAll();
    $at = $c['proposed_at'] ? 'per il ' . substr($c['proposed_at'], 8, 2) . '/' . substr($c['proposed_at'], 5, 2) . ' alle ' . substr($c['proposed_at'], 11, 5) : '';
    foreach ($to as $r) {
        send_mail($r['email'], $c['from_name'] . ' vi sfida a calcetto!', implode("\n", [
            'Ciao ' . $r['name'] . ',', '', 'la lega «' . $c['from_name'] . '» sfida la tua «' . $c['to_name'] . '» ' . $at . ($c['location'] ? ' (' . $c['location'] . ')' : '') . '.',
            $c['message'] ? "\nIl loro messaggio: «" . $c['message'] . '»' : '', '', 'Accetta o rifiuta qui: ' . trusted_base_url() . 'sfide', '', '— ' . APP_NAME]));
    }
}

/** Sfide che riguardano le leghe che gestisco: ricevute e inviate. */
function challenges_of(array $managedIds): array
{
    biz_schema_ensure();
    if (!$managedIds) {
        return ['received' => [], 'sent' => []];
    }
    $ids = implode(',', array_map('intval', $managedIds));
    return [
        'received' => q(CHALLENGE_SEL . " WHERE c.to_group IN ($ids) ORDER BY (c.status = 'inviata') DESC, c.id DESC LIMIT 40")->fetchAll(),
        'sent' => q(CHALLENGE_SEL . " WHERE c.from_group IN ($ids) ORDER BY c.id DESC LIMIT 40")->fetchAll(),
    ];
}

function challenge_cancel(int $id): ?string
{
    $c = q(CHALLENGE_SEL . ' WHERE c.id = ?', [$id])->fetch();
    if (!$c || $c['status'] !== 'inviata') {
        return 'Sfida non trovata o già risolta.';
    }
    if (!can_manage_group((int) $c['from_group'])) {
        return 'Non puoi annullare questa sfida.';
    }
    q("UPDATE league_challenges SET status = 'annullata' WHERE id = ?", [$id]);
    return null;
}

function challenges_pending_count(array $managedIds): int
{
    if (!$managedIds) {
        return 0;
    }
    biz_schema_ensure();
    return (int) q("SELECT COUNT(*) FROM league_challenges WHERE status = 'inviata' AND to_group IN (" . implode(',', array_map('intval', $managedIds)) . ')')->fetchColumn();
}

/** Crea una partita programmata (come matches.php) senza controllare i permessi: li ha già controllati chi chiama. Ritorna l'id. */
function match_create_basic(int $gid, string $at, string $location, string $teamA, string $teamB, string $notes): int
{
    q('INSERT INTO matches (match_date, location, team_a_name, team_b_name, fee, notes, group_id, keepers) VALUES (?, ?, ?, ?, 0, ?, ?, ?)',
        [$at, trim($location), clean_team_name($teamA, TEAM_A_NAME), clean_team_name($teamB, TEAM_B_NAME), trim($notes) ?: null, $gid, 'volanti']);
    $id = (int) db()->lastInsertId();
    log_activity('partita_creata', substr($at, 8, 2) . '/' . substr($at, 5, 2) . ' ' . substr($at, 11, 5) . ' · ' . trim($location), $gid);
    sync_match_players($id);
    push_notify_new_match($id, (int) current_user()['id']);
    push_notify_bets_open_now($id);
    return $id;
}

/** Risposta a una sfida: se accetta nascono due partite (una per lega, stesso orario) e l'orario del campo si prenota. Ritorna un errore o null. */
function challenge_answer(int $id, bool $accept): ?string
{
    $c = q(CHALLENGE_SEL . ' WHERE c.id = ?', [$id])->fetch();
    if (!$c || $c['status'] !== 'inviata') {
        return 'Sfida non trovata o già risolta.';
    }
    if (!can_manage_group((int) $c['to_group'])) {
        return 'Questa sfida non è per una lega che gestisci.';
    }
    if (!$accept) {
        q("UPDATE league_challenges SET status = 'rifiutata' WHERE id = ?", [$id]);
        return null;
    }
    $cancel = function (string $why) use ($id): string {
        q("UPDATE league_challenges SET status = 'annullata' WHERE id = ?", [$id]);
        return $why;
    };
    if (!$c['proposed_at'] || $c['proposed_at'] <= date('Y-m-d H:i:s')) {
        return $cancel('L\'orario proposto è già passato: la sfida è stata annullata.');
    }
    $slot = $c['slot_id'] ? q('SELECT * FROM center_slots WHERE id = ?', [$c['slot_id']])->fetch() : null;
    if ($c['slot_id'] && (!$slot || $slot['blocked'] || $slot['booked_group_id'])) {
        return $cancel('L\'orario del campo non è più libero: la sfida è stata annullata.');
    }
    $notes = 'Sfida tra «' . $c['from_name'] . '» e «' . $c['to_name'] . '»' . ($c['message'] ? ': ' . $c['message'] : '');
    $a = match_create_basic((int) $c['from_group'], $c['proposed_at'], (string) $c['location'], mb_substr($c['from_name'], 0, 24), mb_substr($c['to_name'], 0, 24), $notes);
    $b = match_create_basic((int) $c['to_group'], $c['proposed_at'], (string) $c['location'], mb_substr($c['to_name'], 0, 24), mb_substr($c['from_name'], 0, 24), $notes);
    if ($slot) {
        q('UPDATE center_slots SET booked_group_id = ?, match_id = ?, booked_by = ?, booked_at = NOW() WHERE id = ? AND booked_group_id IS NULL', [$c['from_group'], $a, $c['created_by'], $slot['id']]);
    }
    q("UPDATE league_challenges SET status = 'accettata', match_from = ?, match_to = ? WHERE id = ?", [$a, $b, $id]);
    log_activity('sfida', 'sfida accettata: «' . $c['from_name'] . '» contro «' . $c['to_name'] . '»', (int) $c['to_group']);
    return null;
}
