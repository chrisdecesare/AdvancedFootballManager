<?php
/*
 * Gruppi (es. YBQ, FANTA). Ogni giocatore può far parte di più gruppi, ogni partita appartiene a un gruppo.
 *
 *  - un giocatore vede solo giocatori, partite, classifiche e statistiche dei SUOI gruppi;
 *  - chi è in più gruppi vede tutto (l'unione) e può restringere la vista a un gruppo con i pulsanti in alto;
 *  - l'admin può vedere e gestire tutti i gruppi.
 *
 * "Ambito" (scope) = elenco dei gruppi visibili ORA (permessi ∩ filtro scelto), oppure null = nessun limite
 * (admin senza filtro). Le funzioni di stats.php e le pagine lo usano per limitare le query.
 */

/** Cache per richiesta, azzerata quando cambiano gruppi o appartenenze. */
function groups_cache(?string $key = null, $value = null, bool $reset = false)
{
    static $c = [];
    if ($reset) {
        $c = [];
        return null;
    }
    if ($value !== null) {
        $c[$key] = $value;
    }
    return $c[$key] ?? null;
}

/** [id => nome] di tutti i gruppi. */
function all_groups(): array
{
    if (($g = groups_cache('all')) !== null) {
        return $g;
    }
    $out = [];
    foreach (q('SELECT id, name FROM squad_groups ORDER BY name')->fetchAll() as $r) {
        $out[(int) $r['id']] = $r['name'];
    }
    groups_cache('all', $out);
    return $out;
}

function group_name(int $id): string
{
    return all_groups()[$id] ?? '?';
}

/** Gruppi di un giocatore (id). */
function player_group_ids(int $player_id): array
{
    return array_map('intval', q('SELECT group_id FROM player_groups WHERE player_id = ? ORDER BY group_id', [$player_id])->fetchAll(PDO::FETCH_COLUMN));
}

/** Sostituisce i gruppi di un giocatore (solo id di gruppi esistenti). */
function set_player_groups(int $player_id, array $group_ids): void
{
    $valid = array_values(array_intersect(array_map('intval', $group_ids), array_keys(all_groups())));
    db()->beginTransaction();
    q('DELETE FROM player_groups WHERE player_id = ?', [$player_id]);
    foreach ($valid as $gid) {
        q('INSERT IGNORE INTO player_groups (player_id, group_id) VALUES (?, ?)', [$player_id, $gid]);
    }
    db()->commit();
    groups_cache(null, null, true);
    // partite programmate: esce da quelle dei gruppi che ha lasciato, entra in quelle dei nuovi gruppi
    q("DELETE mp FROM match_players mp JOIN matches m ON m.id = mp.match_id
       WHERE mp.player_id = ? AND m.status = 'programmata'
         AND NOT EXISTS (SELECT 1 FROM player_groups pg WHERE pg.player_id = mp.player_id AND pg.group_id = m.group_id)", [$player_id]);
    q("INSERT IGNORE INTO match_players (match_id, player_id)
       SELECT m.id, p.id FROM matches m
       JOIN player_groups pg ON pg.group_id = m.group_id
       JOIN players p ON p.id = pg.player_id
       WHERE p.id = ? AND p.active = 1 AND m.status = 'programmata'", [$player_id]);
}

/** Gruppi a cui l'utente può accedere: admin = tutti; giocatore = quelli del suo giocatore; altrimenti nessuno. */
function allowed_group_ids(): array
{
    if (($a = groups_cache('allowed')) !== null) {
        return $a;
    }
    $u = current_user();
    if (!$u) {
        $ids = [];
    } elseif ($u['role'] === 'admin') {
        $ids = array_keys(all_groups());
    } elseif (!empty($u['player_id'])) {
        $ids = player_group_ids((int) $u['player_id']);
    } else {
        $ids = [];
    }
    groups_cache('allowed', $ids);
    return $ids;
}

/** Filtro scelto dall'utente con i pulsanti in alto (0 = tutti i suoi gruppi). */
function group_filter(): int
{
    $f = (int) ($_SESSION['group'] ?? 0);
    return ($f && in_array($f, allowed_group_ids(), true)) ? $f : 0;
}

function set_group_filter(int $id): void
{
    $_SESSION['group'] = in_array($id, allowed_group_ids(), true) ? $id : 0;
    groups_cache(null, null, true);
}

/**
 * Gruppi visibili ora. null = nessun limite (admin senza filtro; oppure lettura pubblica attiva).
 * @return int[]|null
 */
function scope_ids(): ?array
{
    $u = current_user();
    if (!$u) {
        return (defined('PUBLIC_READ') && PUBLIC_READ) ? null : [];
    }
    if ($f = group_filter()) {
        return [$f];
    }
    return is_admin() ? null : allowed_group_ids();
}

function scope_key(?array $scope): string
{
    if ($scope === null) {
        return '*';
    }
    $s = array_map('intval', $scope);
    sort($s);
    return implode(',', $s);
}

/** Condizione SQL sulla colonna del gruppo di una partita (es. "m.group_id"). */
function scope_sql(string $col, ?array $scope = null, bool $explicit = false): string
{
    $s = $explicit ? $scope : scope_ids();
    if ($s === null) {
        return '1=1';
    }
    if (!$s) {
        return '0=1';
    }
    return $col . ' IN (' . implode(',', array_map('intval', $s)) . ')';
}

/** Condizione SQL: il giocatore (colonna id, es. "p.id") fa parte di un gruppo visibile. */
function player_scope_sql(string $col, ?array $scope = null, bool $explicit = false): string
{
    $s = $explicit ? $scope : scope_ids();
    if ($s === null) {
        return '1=1';
    }
    if (!$s) {
        return '0=1';
    }
    return 'EXISTS (SELECT 1 FROM player_groups pg WHERE pg.player_id = ' . $col
        . ' AND pg.group_id IN (' . implode(',', array_map('intval', $s)) . '))';
}

/**
 * Permesso di vedere una partita. Se è di un gruppo permesso ma diverso da quello scelto con i pulsanti in alto,
 * passa a quel gruppo (altrimenti i giocatori e le statistiche mostrati non coinciderebbero con la partita).
 */
function match_access(array $m): bool
{
    if (!current_user()) {
        return defined('PUBLIC_READ') && PUBLIC_READ;
    }
    $gid = (int) ($m['group_id'] ?? 0);
    if (!in_array($gid, allowed_group_ids(), true)) {
        return false;
    }
    $s = scope_ids();
    if ($s !== null && !in_array($gid, $s, true)) {
        set_group_filter($gid);
    }
    return true;
}

/** Permesso di vedere un giocatore: fa parte di almeno un gruppo permesso (l'admin vede tutti). */
function player_access(int $player_id): bool
{
    if (!current_user()) {
        return defined('PUBLIC_READ') && PUBLIC_READ;
    }
    $mine = player_group_ids($player_id);
    if (!is_admin() && !array_intersect($mine, allowed_group_ids())) {
        return false;
    }
    if (group_filter() && !in_array(group_filter(), $mine, true)) {
        set_group_filter(0);   // il giocatore non è nel gruppo scelto: mostra tutti (stat e classifiche coerenti)
    }
    return true;
}

function player_in_group(int $player_id, int $group_id): bool
{
    return in_array($group_id, player_group_ids($player_id), true);
}

/** Etichetta del gruppo, solo se chi guarda ha accesso a più gruppi (altrimenti sarebbe rumore). */
function group_tag(int $group_id): string
{
    if (count(allowed_group_ids()) < 2 && !is_admin()) {
        return '';
    }
    if (count(all_groups()) < 2) {
        return '';
    }
    return '<span class="tag tag-group" title="Gruppo"><i class="ti ti-users-group"></i> ' . h(group_name($group_id)) . '</span>';
}

/** Pulsanti "Tutti / YBQ / FANTA" in cima alle pagine, per chi ha accesso a più gruppi. */
function group_bar(string $back): string
{
    $allowed = allowed_group_ids();
    if (count($allowed) < 2) {
        return '';
    }
    $cur = group_filter();
    $groups = all_groups();
    $btn = function (int $id, string $label) use ($cur) {
        return '<button class="gchip' . ($cur === $id ? ' is-on' : '') . '" name="g" value="' . $id . '"'
            . ($cur === $id ? ' aria-pressed="true"' : ' aria-pressed="false"') . '>' . h($label) . '</button>';
    };
    $out = '<form method="post" action="group.php" class="group-bar" aria-label="Gruppo">' . csrf_field()
        . '<input type="hidden" name="back" value="' . h($back) . '">'
        . '<span class="group-bar-label"><i class="ti ti-users-group"></i> Gruppo</span>' . $btn(0, 'Tutti');
    foreach ($allowed as $gid) {
        $out .= $btn($gid, $groups[$gid] ?? '?');
    }
    return $out . '</form>';
}

/** Gruppi in cui si possono creare partite: l'admin in tutti, un manager solo nei suoi. [id => nome] */
function manageable_groups(): array
{
    return is_admin() ? all_groups() : (is_manager() ? selectable_groups() : []);
}

/** Gruppi tra cui scegliere quando si crea qualcosa (partita, giocatore): quelli permessi, con quello attivo per primo. */
function selectable_groups(): array
{
    $groups = all_groups();
    $out = [];
    foreach (allowed_group_ids() as $gid) {
        if (isset($groups[$gid])) {
            $out[$gid] = $groups[$gid];
        }
    }
    return $out;
}
