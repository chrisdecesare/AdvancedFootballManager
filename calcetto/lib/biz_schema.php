<?php
/*
 * Tabelle del lato «business» (leghe visibili, centri, calendario, sfide, abbonamenti...): le stesse che crea il sito Next.js
 * (next-app/src/lib/billing/schema.ts). Qui sono una copia generata da quel file: se cambia uno, si aggiorna anche l'altro.
 * Si creano alla prima richiesta che le usa (CREATE TABLE IF NOT EXISTS) e non entrano nella numerazione dello schema (meta.schema).
 */

function biz_schema_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (meta_get('biz_schema') === '1') {
        return;
    }
    $tables = [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS billing_subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_subscription_id VARCHAR(80) NOT NULL,
    stripe_customer_id VARCHAR(80) NOT NULL,
    plan VARCHAR(20) NOT NULL,
    billing_interval VARCHAR(10) NOT NULL,
    status VARCHAR(20) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NULL,
    center_id INT UNSIGNED NULL,
    amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
    current_period_end DATETIME NULL,
    trial_end DATETIME NULL,
    cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bs_sub (stripe_subscription_id),
    KEY ix_bs_user (user_id),
    KEY ix_bs_group (group_id),
    KEY ix_bs_center (center_id)
  ) DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS billing_events (
    id VARCHAR(80) NOT NULL PRIMARY KEY,
    type VARCHAR(80) NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sport_centers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    city VARCHAR(80) NOT NULL DEFAULT '',
    address VARCHAR(190) NOT NULL DEFAULT '',
    phone VARCHAR(40) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL DEFAULT '',
    fields_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    slug VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sc_slug (slug),
    KEY ix_sc_owner (owner_user_id)
  ) DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS center_leads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    center_name VARCHAR(120) NOT NULL,
    city VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(40) NOT NULL DEFAULT '',
    fields_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    message VARCHAR(1000) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'nuova',
    newsletter TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_cl_status (status)
  ) DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS newsletter (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    audience VARCHAR(20) NOT NULL DEFAULT 'giocatori',
    source VARCHAR(40) NOT NULL DEFAULT '',
    token_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    unsubscribed_at DATETIME NULL,
    UNIQUE KEY uq_nl_email (email),
    KEY ix_nl_token (token_hash)
  ) DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS center_groups (
    center_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    linked_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id),
    KEY ix_cg_center (center_id)
  ) DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS center_sponsors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    center_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    url VARCHAR(190) NOT NULL DEFAULT '',
    note VARCHAR(140) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_cs_center (center_id)
  ) DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS center_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    center_id INT UNSIGNED NOT NULL,
    subject VARCHAR(120) NOT NULL,
    body VARCHAR(2000) NOT NULL,
    recipients SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_cm_center (center_id)
  ) DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS tournaments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    center_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    format VARCHAR(20) NOT NULL DEFAULT 'girone',
    status VARCHAR(20) NOT NULL DEFAULT 'iscrizioni',
    starts_on DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_t_center (center_id)
  ) DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS tournament_teams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    group_id INT UNSIGNED NULL,
    KEY ix_tt_t (tournament_id)
  ) DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS tournament_matches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT UNSIGNED NOT NULL,
    round SMALLINT UNSIGNED NOT NULL,
    slot SMALLINT UNSIGNED NOT NULL,
    team_a INT UNSIGNED NULL,
    team_b INT UNSIGNED NULL,
    score_a SMALLINT UNSIGNED NULL,
    score_b SMALLINT UNSIGNED NULL,
    KEY ix_tm_t (tournament_id)
  ) DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS league_payment (
    group_id INT UNSIGNED NOT NULL PRIMARY KEY,
    url VARCHAR(190) NOT NULL DEFAULT '',
    iban VARCHAR(34) NOT NULL DEFAULT '',
    holder VARCHAR(80) NOT NULL DEFAULT '',
    note VARCHAR(140) NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS league_public (
    group_id INT UNSIGNED NOT NULL PRIMARY KEY,
    city VARCHAR(80) NOT NULL,
    city_slug VARCHAR(80) NOT NULL,
    area VARCHAR(120) NOT NULL DEFAULT '',
    lat DECIMAL(9,6) NULL,
    lng DECIMAL(9,6) NULL,
    about VARCHAR(300) NOT NULL DEFAULT '',
    logo VARCHAR(190) NOT NULL DEFAULT '',
    discoverable TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_lp_city (city_slug)
  ) DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS center_slots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    center_id INT UNSIGNED NOT NULL,
    field_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    blocked TINYINT(1) NOT NULL DEFAULT 0,
    note VARCHAR(80) NOT NULL DEFAULT '',
    booked_group_id INT UNSIGNED NULL,
    match_id INT NULL,
    booked_by INT UNSIGNED NULL,
    booked_at DATETIME NULL,
    KEY ix_slot_center (center_id, starts_at),
    KEY ix_slot_match (match_id)
  ) DEFAULT CHARSET=utf8mb4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS league_challenges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_group INT UNSIGNED NOT NULL,
    to_group INT UNSIGNED NOT NULL,
    slot_id INT UNSIGNED NULL,
    proposed_at DATETIME NULL,
    location VARCHAR(120) NOT NULL DEFAULT '',
    message VARCHAR(300) NOT NULL DEFAULT '',
    status VARCHAR(12) NOT NULL DEFAULT 'inviata',
    created_by INT UNSIGNED NOT NULL,
    match_from INT NULL,
    match_to INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_ch_to (to_group, status),
    KEY ix_ch_from (from_group)
  ) DEFAULT CHARSET=utf8mb4
SQL,
    ];
    foreach ($tables as $sql) {
        db()->exec($sql);
    }
    // colonne aggiunte dopo la prima versione (ADD COLUMN IF NOT EXISTS non c'è in MySQL)
    $have = [];
    foreach (q("SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ('sport_centers', 'center_groups')")->fetchAll() as $r) {
        $have[strtolower($r['t'] . '.' . $r['c'])] = true;
    }
    $cols = [
        ['sport_centers', 'logo', "VARCHAR(190) NOT NULL DEFAULT ''"],
        ['sport_centers', 'about', "VARCHAR(1000) NOT NULL DEFAULT ''"],
        ['sport_centers', 'website', "VARCHAR(190) NOT NULL DEFAULT ''"],
        ['sport_centers', 'city_slug', "VARCHAR(80) NOT NULL DEFAULT ''"],
        ['sport_centers', 'lat', "DECIMAL(9,6) NULL"],
        ['sport_centers', 'lng', "DECIMAL(9,6) NULL"],
        ['center_groups', 'kind', "VARCHAR(10) NOT NULL DEFAULT 'ospite'"],
    ];
    foreach ($cols as [$t, $c, $def]) {
        if (!isset($have[strtolower($t . '.' . $c)])) {
            db()->exec("ALTER TABLE $t ADD COLUMN $c $def");
        }
    }
    foreach (q("SELECT id, city FROM sport_centers WHERE city_slug = ''")->fetchAll() as $r) {
        q('UPDATE sport_centers SET city_slug = ? WHERE id = ?', [city_slug((string) $r['city']), $r['id']]);
    }
    meta_set('biz_schema', '1');
}
