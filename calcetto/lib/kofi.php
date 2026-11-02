<?php
/*
 * Ko-fi: il pulsante «Sostienici» e l'elenco delle donazioni (come next-app/src/lib/kofi.ts e kofi-format.ts). Il nome della pagina Ko-fi lo imposta
 * l'admin in «Gestione del sito» e sta in meta.kofi_username, uguale per i due siti. Il webhook che registra le donazioni sta solo sul sito Next
 * (Ko-fi ha un solo indirizzo di webhook): da qui si leggono soltanto, dalla stessa tabella kofi_donations. Senza nome non compare nulla.
 */
function kofi_schema_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (meta_get('kofi_schema') === '1') {
        return;
    }
    db()->exec("CREATE TABLE IF NOT EXISTS kofi_donations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tx_id VARCHAR(64) NOT NULL,
        kind VARCHAR(30) NOT NULL DEFAULT 'Donation',
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        currency CHAR(3) NOT NULL DEFAULT 'EUR',
        from_name VARCHAR(80) NOT NULL DEFAULT '',
        message VARCHAR(300) NOT NULL DEFAULT '',
        is_public TINYINT(1) NOT NULL DEFAULT 0,
        tier_name VARCHAR(60) NOT NULL DEFAULT '',
        is_subscription TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_kofi_tx (tx_id)
    ) DEFAULT CHARSET=utf8mb4");
    meta_set('kofi_schema', '1');
}

function kofi_valid_name(string $s): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]{2,40}$/', $s);
}

function kofi_url(string $name): string
{
    return 'https://ko-fi.com/' . rawurlencode($name);
}

/** Il nome della pagina Ko-fi: quello salvato dall'admin, altrimenti la costante KOFI_USERNAME; null se non c'è (o non è valido). */
function kofi_username(): ?string
{
    static $name = false;
    if ($name === false) {
        try {
            $v = trim((string) (meta_get('kofi_username') ?? (defined('KOFI_USERNAME') ? KOFI_USERNAME : '')));
        } catch (Throwable $e) {
            $v = '';   // il piè di pagina non deve mai rompere una pagina (database non pronto)
        }
        $name = kofi_valid_name($v) ? $v : null;
    }
    return $name;
}

/** Salva il nome della pagina (vuoto = spegne il pulsante). Ritorna un errore o null. */
function kofi_set_username(string $name): ?string
{
    $v = rtrim(preg_replace('#^https?://ko-fi\.com/#i', '', trim($name)), '/');
    if ($v !== '' && !kofi_valid_name($v)) {
        return 'Il nome della pagina Ko-fi può avere lettere, numeri, trattino e trattino basso (da 2 a 40 caratteri): è la parte dopo ko-fi.com/.';
    }
    meta_set('kofi_username', $v);
    return null;
}

function kofi_donation_text($amount, string $currency): string
{
    return number_format((float) $amount, 2, ',', '') . ' ' . $currency;
}

/** Le ultime donazioni, per l'admin (con il messaggio, che non si mostra mai in pubblico). */
function kofi_recent(int $limit = 15): array
{
    kofi_schema_ensure();
    return q('SELECT id, kind, amount, currency, from_name, message, is_public, tier_name, created_at FROM kofi_donations ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)))->fetchAll();
}

/** Chi ci ha sostenuto e ha scelto di essere nominato: solo i nomi, mai importi né messaggi. */
function kofi_supporters(int $limit = 24): array
{
    kofi_schema_ensure();
    return array_map('strval', q("SELECT from_name FROM kofi_donations WHERE is_public = 1 AND from_name <> '' GROUP BY from_name ORDER BY MAX(id) DESC LIMIT " . max(1, min(60, $limit)))->fetchAll(PDO::FETCH_COLUMN));
}

function kofi_totals(): array
{
    kofi_schema_ensure();
    $r = q('SELECT COUNT(*) AS n, SUM(amount) AS s FROM kofi_donations')->fetch();
    return ['n' => (int) ($r['n'] ?? 0), 'sum' => (float) ($r['s'] ?? 0)];
}
