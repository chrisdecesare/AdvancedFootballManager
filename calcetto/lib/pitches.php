<?php
/*
 * Campetti dove si gioca, con le stelle da 1 a 5 (un voto per giocatore, modificabile): come next-app/src/lib/pitches.ts e pitch-format.ts.
 * Li aggiungono gli utenti; i centri registrati compaiono nello stesso elenco da soli (center_id) e si votano come gli altri.
 * Le tabelle sono le stesse del sito Next (si creano alla prima richiesta, flag meta.pitches_schema).
 */
const PITCH_STARS_MAX = 5;

function pitches_schema_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    biz_schema_ensure();   // sport_centers e league_public
    if (meta_get('pitches_schema') === '1') {
        return;
    }
    db()->exec('CREATE TABLE IF NOT EXISTS pitches (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        address VARCHAR(190) NOT NULL DEFAULT \'\',
        city VARCHAR(80) NOT NULL,
        city_slug VARCHAR(80) NOT NULL,
        center_id INT UNSIGNED NULL,
        created_by INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pitch_center (center_id),
        KEY ix_pitch_city (city_slug)
    ) DEFAULT CHARSET=utf8mb4');
    db()->exec('CREATE TABLE IF NOT EXISTS pitch_ratings (
        pitch_id INT UNSIGNED NOT NULL,
        player_id INT NOT NULL,
        stars TINYINT UNSIGNED NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (pitch_id, player_id),
        KEY ix_pr_player (player_id)
    ) DEFAULT CHARSET=utf8mb4');
    meta_set('pitches_schema', '1');
}

/** Un voto valido: intero da 1 a 5 (anche come testo, dal modulo). */
function pitch_valid_stars($n): bool
{
    if (is_string($n)) {
        $n = trim($n);
        if ($n === '' || !ctype_digit($n)) {
            return false;
        }
        $n = (int) $n;
    }
    return is_int($n) && $n >= 1 && $n <= PITCH_STARS_MAX;
}

/** «★★★☆☆»: la media si arrotonda alla stella più vicina ed è sempre tra 0 e 5. */
function pitch_stars_text($avg): string
{
    $n = max(0, min(PITCH_STARS_MAX, (int) round((float) $avg)));
    return str_repeat('★', $n) . str_repeat('☆', PITCH_STARS_MAX - $n);
}

/** «3,6» (virgola, una cifra), o un trattino senza voti. */
function pitch_avg_text($avg, int $votes): string
{
    return !$votes || $avg === null ? '—' : number_format(round((float) $avg, 1), 1, ',', '');
}

/** Le stelle di un campo in HTML: «★★★★☆ 3,6 (12)». */
function pitch_stars_html($avg, int $votes): string
{
    if (!$votes) {
        return '<span class="stars stars-none"><span aria-hidden="true">' . pitch_stars_text(null) . '</span> <span class="muted small">Ancora nessun voto</span></span>';
    }
    return '<span class="stars" aria-label="' . h(pitch_avg_text($avg, $votes) . ' stelle su 5, ' . $votes . ($votes === 1 ? ' voto' : ' voti')) . '">'
        . '<span aria-hidden="true">' . pitch_stars_text($avg) . '</span> <b aria-hidden="true">' . h(pitch_avg_text($avg, $votes)) . '</b> '
        . '<span class="muted small" aria-hidden="true">(' . $votes . ')</span></span>';
}

/** La riga nella scelta del campo quando si crea la partita: «Campo A ★★★☆☆, indirizzo» (senza indirizzo, la città). */
function pitch_option_label(array $p): string
{
    return $p['name'] . ' ' . pitch_stars_text($p['votes'] ? $p['avg'] : null) . ', ' . ($p['address'] ?: $p['city']);
}

/** Messaggio d'errore sui dati del campo, o null se vanno bene. */
function pitch_input_error(string $name, string $address, string $city): ?string
{
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        return 'Il nome del campo va da 2 a 120 caratteri.';
    }
    if (offensive_name($name)) {
        return OFFENSIVE_NAME_MESSAGE;
    }
    if (mb_strlen($city) < 2 || mb_strlen($city) > 80) {
        return 'Scrivi la città del campo.';
    }
    if (mb_strlen($address) > 190) {
        return 'L\'indirizzo è troppo lungo (massimo 190 caratteri).';
    }
    return null;
}

/** I campi delle città date, con la media delle stelle, i voti e il voto di $playerId (se c'è). */
function pitches_in(array $slugs, ?int $playerId = null): array
{
    $list = array_slice(array_values(array_unique(array_filter($slugs))), 0, 20);
    if (!$list) {
        return [];
    }
    pitches_schema_ensure();
    $in = implode(',', array_fill(0, count($list), '?'));
    // i centri della città entrano nell'elenco (una volta sola per centro)
    q("INSERT IGNORE INTO pitches (name, address, city, city_slug, center_id, created_by)
       SELECT c.name, c.address, c.city, c.city_slug, c.id, c.owner_user_id FROM sport_centers c
       WHERE c.city_slug IN ($in) AND NOT EXISTS (SELECT 1 FROM pitches p WHERE p.center_id = c.id)", $list);
    $rows = q("SELECT p.id, p.name, p.address, p.city, p.city_slug, p.center_id,
         (SELECT AVG(r.stars) FROM pitch_ratings r WHERE r.pitch_id = p.id) AS avg,
         (SELECT COUNT(*) FROM pitch_ratings r WHERE r.pitch_id = p.id) AS votes,
         (SELECT r.stars FROM pitch_ratings r WHERE r.pitch_id = p.id AND r.player_id = ?) AS mine
       FROM pitches p WHERE p.city_slug IN ($in) ORDER BY p.name", array_merge([$playerId ?? 0], $list))->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['center_id'] = $r['center_id'] === null ? null : (int) $r['center_id'];
        $r['avg'] = $r['avg'] === null ? null : (float) $r['avg'];
        $r['votes'] = (int) $r['votes'];
        $r['mine'] = $r['mine'] === null ? null : (int) $r['mine'];
    }
    return $rows;
}

function pitch_by_id(int $id): ?array
{
    pitches_schema_ensure();
    return q('SELECT id, name, address, city FROM pitches WHERE id = ?', [$id])->fetch() ?: null;
}

/** Aggiunge un campo (se in quella città ce n'è già uno con lo stesso nome, ritorna quello): [id, esisteva già]. */
function pitch_add(int $userId, string $name, string $address, string $city): array
{
    pitches_schema_ensure();
    $slug = city_slug($city);
    $old = q('SELECT id FROM pitches WHERE city_slug = ? AND LOWER(name) = LOWER(?) LIMIT 1', [$slug, $name])->fetchColumn();
    if ($old) {
        return [(int) $old, true];
    }
    q('INSERT INTO pitches (name, address, city, city_slug, created_by) VALUES (?, ?, ?, ?, ?)', [$name, $address, $city, $slug, $userId]);
    return [(int) db()->lastInsertId(), false];
}

/** Il voto di un giocatore a un campo: uno solo, si può cambiare. */
function pitch_rate(int $pitchId, int $playerId, $stars): bool
{
    if (!pitch_valid_stars($stars) || !pitch_by_id($pitchId)) {
        return false;
    }
    q('INSERT INTO pitch_ratings (pitch_id, player_id, stars) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stars = VALUES(stars)', [$pitchId, $playerId, (int) $stars]);
    return true;
}

/** Le città (slug) delle leghe date, per proporre i campi della zona. */
function pitch_cities_of_groups(array $groupIds): array
{
    $ids = array_values(array_filter(array_map('intval', $groupIds), fn($g) => $g > 0));
    if (!$ids) {
        return [];
    }
    pitches_schema_ensure();
    return array_map('strval', q('SELECT DISTINCT city_slug FROM league_public WHERE group_id IN (' . implode(',', $ids) . ") AND city_slug <> ''")->fetchAll(PDO::FETCH_COLUMN));
}

/** I campi aggiunti dagli utenti (non quelli dei centri registrati, che vengono dai centri), per la gestione dell'admin. */
function pitches_by_users(): array
{
    pitches_schema_ensure();
    return q('SELECT p.id, p.name, p.address, p.city, p.created_at, COALESCE(pl.name, u.username) AS added_by,
         (SELECT AVG(r.stars) FROM pitch_ratings r WHERE r.pitch_id = p.id) AS avg,
         (SELECT COUNT(*) FROM pitch_ratings r WHERE r.pitch_id = p.id) AS votes
       FROM pitches p LEFT JOIN users u ON u.id = p.created_by LEFT JOIN players pl ON pl.id = u.player_id
       WHERE p.center_id IS NULL ORDER BY p.city, p.name')->fetchAll();
}

/** Elimina un campo aggiunto da un utente, con i suoi voti. I campi dei centri non si toccano da qui. Ritorna il nome, o null se non c'è. */
function pitch_delete(int $id): ?string
{
    pitches_schema_ensure();
    $name = q('SELECT name FROM pitches WHERE id = ? AND center_id IS NULL', [$id])->fetchColumn();
    if ($name === false) {
        return null;
    }
    q('DELETE FROM pitch_ratings WHERE pitch_id = ?', [$id]);
    q('DELETE FROM pitches WHERE id = ? AND center_id IS NULL', [$id]);
    return (string) $name;
}
