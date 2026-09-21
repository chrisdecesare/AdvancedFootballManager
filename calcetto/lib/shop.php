<?php
/*
 * Negozio delle personalizzazioni del profilo: si pagano con i gettoni delle scommesse (lib/bets.php).
 *
 *  - sfondi speciali: al posto delle strisce del ruolo, sul riquadro del profilo e sulla carta nella Rosa;
 *  - nickname: compare sotto il nome. Alcuni si comprano, altri si sbloccano da soli raggiungendo un obiettivo (gol, assist, MVP...);
 *  - copricapi: un cappello simpatico in diagonale su un angolo del riquadro.
 *
 * Il catalogo sta qui nel codice (nome, prezzo, obiettivo); nel database restano solo gli acquisti (player_items) e cosa si indossa
 * adesso (colonne bg_preset, nick_key, hat_key di players). Le chiavi degli oggetti devono essere uniche in tutto il catalogo
 * e al massimo 14 caratteri (finiscono anche nella mossa del portafoglio).
 */

function shop_kinds(): array
{
    return ['bg' => 'Sfondi', 'nick' => 'Nickname', 'hat' => 'Copricapi'];
}

/** Colonna di players dove si salva cosa si indossa, per tipo di oggetto. */
function shop_column(string $kind): string
{
    return ['bg' => 'bg_preset', 'nick' => 'nick_key', 'hat' => 'hat_key'][$kind];
}

function shop_catalog(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $c = ['bg' => [], 'nick' => [], 'hat' => []];
    foreach ([
        ['pois', 'Pois rosa', 60], ['ghiaccio', 'Ghiaccio', 60], ['prato', 'Prato all\'inglese', 80], ['scacchi', 'Scacchiera', 100],
        ['fiamme', 'Fiamme', 120], ['arcobaleno', 'Arcobaleno', 150], ['notte', 'Notte di Champions', 150], ['galassia', 'Galassia', 200],
        ['oro', 'Oro colato', 250],
    ] as [$k, $name, $price]) {
        $c['bg'][$k] = ['name' => $name, 'price' => $price];
    }
    // nickname a pagamento
    foreach ([
        ['treno', 'Il Treno', 40], ['puma', 'Il Puma', 40], ['muro', 'Il Muro', 50], ['furia', 'La Furia', 50], ['fenomeno', 'Il Fenomeno', 60],
        ['maestro', 'Il Maestro', 60], ['sultano', 'Il Sultano', 80], ['mago', 'Il Mago', 80], ['gladiatore', 'Il Gladiatore', 100],
    ] as [$k, $name, $price]) {
        $c['nick'][$k] = ['name' => $name, 'price' => $price];
    }
    // nickname da sbloccare: [statistica, soglia, descrizione dell'obiettivo]
    foreach ([
        ['bomber', 'Il Bomber', ['goals', 10, '10 gol in totale']],
        ['cecchino', 'Il Cecchino', ['goals', 25, '25 gol in totale']],
        ['tripletta', 'Mister Tripletta', ['max_goals', 3, '3 gol in una sola partita']],
        ['assistman', 'L\'Assistman', ['assists', 10, '10 assist in totale']],
        ['regista', 'Il Regista', ['assists', 25, '25 assist in totale']],
        ['mvp', 'Mister MVP', ['mvp', 3, '3 premi MVP']],
        ['leggenda', 'La Leggenda', ['mvp', 10, '10 premi MVP']],
        ['colonna', 'La Colonna', ['apps', 15, '15 partite giocate']],
        ['veterano', 'Il Veterano', ['apps', 30, '30 partite giocate']],
        ['vincente', 'Il Vincente', ['wins', 10, '10 vittorie']],
        ['professore', 'Il Professore', ['top_avg', 7.5, 'media voto di 7,5 dopo almeno 5 partite']],
        ['traditore', 'Il Traditore', ['own_goals', 2, '2 autogol (sì, si sblocca anche così)']],
        ['veggente', 'Il Veggente', ['bets_won', 5, '5 scommesse vinte']],
        ['riccone', 'Il Riccone', ['coins', 500, '500 gettoni in portafoglio']],
    ] as [$k, $name, $goal]) {
        $c['nick'][$k] = ['name' => $name, 'price' => null, 'goal' => $goal];
    }
    foreach ([
        ['cap', 'Cappellino', 40], ['festa', 'Cappello da festa', 50], ['cuoco', 'Cappello da cuoco', 60], ['cowboy', 'Cowboy', 70],
        ['sombrero', 'Sombrero', 70], ['elica', 'Berretto con elica', 80], ['pirata', 'Pirata', 90], ['mago_h', 'Cappello da mago', 100],
        ['vichingo', 'Elmo vichingo', 110], ['cilindro', 'Cilindro', 120], ['babbo', 'Babbo Natale', 120], ['corona', 'Corona', 250],
    ] as [$k, $name, $price]) {
        $c['hat'][$k] = ['name' => $name, 'price' => $price];
    }
    return $c;
}

function shop_item(string $kind, string $key): ?array
{
    return shop_catalog()[$kind][$key] ?? null;
}

/* ---------------------------------------------------------------- obiettivi */

/** Numeri del giocatore che servono a sbloccare i nickname (statistiche di tutti i gruppi in cui gioca). */
function shop_progress(int $playerId): array
{
    $s = compute_stats(null)[$playerId] ?? [];
    return [
        'apps' => (int) ($s['apps'] ?? 0), 'goals' => (int) ($s['goals'] ?? 0), 'assists' => (int) ($s['assists'] ?? 0),
        'mvp' => (int) ($s['mvp'] ?? 0), 'wins' => (int) ($s['wins'] ?? 0), 'own_goals' => (int) ($s['own_goals'] ?? 0),
        'top_avg' => (int) ($s['apps'] ?? 0) >= 5 ? (float) ($s['avg_vote'] ?? 0) : 0.0,
        'max_goals' => (int) q('SELECT COALESCE(MAX(mp.goals), 0) FROM match_players mp JOIN matches m ON m.id = mp.match_id
                                WHERE mp.player_id = ? AND m.status = \'giocata\' AND mp.team IS NOT NULL', [$playerId])->fetchColumn(),
        'bets_won' => (int) q("SELECT COUNT(*) FROM bets WHERE player_id = ? AND status = 'vinta'", [$playerId])->fetchColumn(),
        'coins' => wallet_balance($playerId) + wallet_in_play($playerId),
    ];
}

/** Obiettivo raggiunto? (per i nickname da sbloccare) */
function shop_goal_met(array $item, array $progress): bool
{
    return isset($item['goal']) && $progress[$item['goal'][0]] >= $item['goal'][1];
}

/** Oggetti che il giocatore possiede: comprati + nickname sbloccati con gli obiettivi. @return array<string, true> */
function shop_owned(int $playerId): array
{
    $owned = [];
    foreach (q('SELECT item_key FROM player_items WHERE player_id = ?', [$playerId])->fetchAll(PDO::FETCH_COLUMN) as $k) {
        $owned[$k] = true;
    }
    $progress = null;
    foreach (shop_catalog()['nick'] as $k => $item) {
        if (isset($item['goal'])) {
            $progress ??= shop_progress($playerId);
            if (shop_goal_met($item, $progress)) {
                $owned[$k] = true;
            }
        }
    }
    return $owned;
}

/* ---------------------------------------------------------------- acquisti */

/** Compra un oggetto. Ritorna il messaggio d'errore oppure null se è andata. */
function shop_buy(int $playerId, string $kind, string $key): ?string
{
    $item = shop_item($kind, $key);
    if (!$item) {
        return 'Oggetto non trovato.';
    }
    if ($item['price'] === null) {
        return 'Questo non si compra: si sblocca con un obiettivo.';
    }
    return bet_atomic(function () use ($playerId, $key, $item) {
        q('SELECT id FROM players WHERE id = ? FOR UPDATE', [$playerId]);   // due acquisti insieme non possono spendere due volte gli stessi gettoni
        if (q('SELECT 1 FROM player_items WHERE player_id = ? AND item_key = ?', [$playerId, $key])->fetch()) {
            return 'Ce l\'hai già.';
        }
        $left = wallet_balance($playerId);
        if ($left < $item['price']) {
            return 'Ti servono ' . $item['price'] . ' gettoni, ne hai ' . $left . '. Vai a scommettere!';
        }
        q('INSERT INTO player_items (player_id, item_key, price) VALUES (?, ?, ?)', [$playerId, $key, $item['price']]);
        q("INSERT INTO wallet_moves (player_id, delta, kind, ref) VALUES (?, ?, 'acquisto', ?)", [$playerId, -$item['price'], 'buy-' . $key]);
        return null;
    });
}

/**
 * Indossa un oggetto (o lo toglie, con $key = null). Ritorna il messaggio d'errore oppure null.
 * Uno sfondo speciale sostituisce quello scelto con colore o immagine (che va rifatto da "Modifica profilo").
 */
function shop_equip(int $playerId, string $kind, ?string $key): ?string
{
    if (!isset(shop_kinds()[$kind])) {
        return 'Tipo non valido.';
    }
    if ($key !== null) {
        if (!shop_item($kind, $key)) {
            return 'Oggetto non trovato.';
        }
        if (!isset(shop_owned($playerId)[$key])) {
            return 'Non ce l\'hai ancora.';
        }
    }
    if ($kind === 'bg' && $key !== null) {
        $old = q('SELECT bg_image FROM players WHERE id = ?', [$playerId])->fetchColumn();
        delete_photo_file($old ?: null);
        q('UPDATE players SET bg_preset = ?, bg_color = NULL, bg_image = NULL WHERE id = ?', [$key, $playerId]);
    } else {
        q('UPDATE players SET ' . shop_column($kind) . ' = ? WHERE id = ?', [$key, $playerId]);
    }
    return null;
}

/* ---------------------------------------------------------------- come si vede */

/** Chiave dell'oggetto indossato di quel tipo, se esiste ancora nel catalogo. */
function shop_worn(array $p, string $kind): ?string
{
    $k = $p[shop_column($kind)] ?? null;
    return ($k && shop_item($kind, (string) $k)) ? (string) $k : null;
}

/** Classe CSS dello sfondo speciale ('' se non ce l'ha). */
function bg_preset_class(array $p): string
{
    $k = shop_worn($p, 'bg');
    return $k ? ' bgp-' . $k : '';
}

/** Nickname da mostrare sotto il nome ('' se non ne ha uno). */
function nick_html(array $p, string $cls = 'nick'): string
{
    $k = shop_worn($p, 'nick');
    return $k ? '<span class="' . h($cls) . '">«' . h(shop_item('nick', $k)['name']) . '»</span>' : '';
}

/** Copricapo indossato, in diagonale su un angolo del riquadro ('' se non ne ha uno). */
function hat_html(array $p): string
{
    $k = shop_worn($p, 'hat');
    return $k ? '<span class="hat hat-' . h($k) . '" aria-hidden="true" title="' . h(shop_item('hat', $k)['name']) . '">' . hat_svg($k) . '</span>' : '';
}

/** Disegno del copricapo (fumetto con contorno scuro, stesso stile del sito). */
function hat_svg(string $key): string
{
    $ink = '#1f1a2e';
    $st = 'stroke="' . $ink . '" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"';
    $shapes = [
        'cap' => '<path d="M18 60 C18 24 82 24 82 60 Z" fill="#ff5a5f" ' . $st . '/><path d="M58 56 C78 51 96 56 96 65 C82 67 68 65 58 62 Z" fill="#c93a40" ' . $st . '/><circle cx="50" cy="29" r="3.5" fill="#c93a40" ' . $st . '/>',
        'festa' => '<path d="M50 8 L76 66 H24 Z" fill="#ff6b9a" ' . $st . '/><circle cx="46" cy="38" r="4" fill="#ffd23f"/><circle cx="58" cy="52" r="4" fill="#53c8f5"/><circle cx="40" cy="56" r="3.5" fill="#ffd23f"/><circle cx="52" cy="26" r="3" fill="#38d178"/><circle cx="50" cy="8" r="7" fill="#ffd23f" ' . $st . '/>',
        'cuoco' => '<circle cx="30" cy="34" r="17" fill="#fff" ' . $st . '/><circle cx="70" cy="34" r="17" fill="#fff" ' . $st . '/><circle cx="50" cy="26" r="20" fill="#fff" ' . $st . '/><path d="M28 44 H72 V66 Q72 69 69 69 H31 Q28 69 28 66 Z" fill="#fff" ' . $st . '/><path d="M28 56 H72" fill="none" ' . $st . '/>',
        'cowboy' => '<path d="M4 54 C18 72 82 72 96 54 C92 44 82 52 72 50 L68 22 C62 12 38 12 32 22 L28 50 C18 52 8 44 4 54 Z" fill="#c98a3b" ' . $st . '/><path d="M29 44 C44 51 56 51 71 44 L70 37 C56 44 44 44 30 37 Z" fill="#6b3f14" ' . $st . '/>',
        'sombrero' => '<ellipse cx="50" cy="60" rx="47" ry="11" fill="#ffb000" ' . $st . '/><path d="M32 60 C31 28 38 12 50 12 C62 12 69 28 68 60 Z" fill="#ffd25a" ' . $st . '/><path d="M31.5 46 H68.5 V54 H31.5 Z" fill="#ff5a5f" ' . $st . '/>',
        'elica' => '<path d="M18 62 C18 32 82 32 82 62 Z" fill="#53c8f5" ' . $st . '/><path d="M50 32 V22" fill="none" ' . $st . '/><path d="M26 20 C38 10 46 16 50 22 C54 16 62 10 74 20 C62 30 54 28 50 22 C46 28 38 30 26 20 Z" fill="#ffd23f" ' . $st . '/><path d="M50 32 L34 60 M50 32 L66 60" fill="none" ' . $st . '/>',
        'pirata' => '<path d="M6 54 C18 30 34 20 50 20 C66 20 82 30 94 54 C82 48 66 56 50 56 C34 56 18 48 6 54 Z" fill="#2b2540" ' . $st . '/><circle cx="50" cy="38" r="8" fill="#fff" ' . $st . '/><circle cx="47" cy="37" r="1.8" fill="' . $ink . '"/><circle cx="53" cy="37" r="1.8" fill="' . $ink . '"/><path d="M42 48 L58 58 M58 48 L42 58" stroke="#fff" stroke-width="3" stroke-linecap="round"/>',
        'mago_h' => '<path d="M50 4 L80 64 H20 Z" fill="#7a4fd6" ' . $st . '/><ellipse cx="50" cy="65" rx="44" ry="9" fill="#5b34b0" ' . $st . '/><path d="M50 30 L53 38 L61 38 L55 43 L57 51 L50 46 L43 51 L45 43 L39 38 L47 38 Z" fill="#ffd23f" stroke="' . $ink . '" stroke-width="2" stroke-linejoin="round"/>',
        'vichingo' => '<path d="M22 46 C4 42 2 20 10 8 C13 26 25 30 32 34 Z" fill="#fff3d6" ' . $st . '/><path d="M78 46 C96 42 98 20 90 8 C87 26 75 30 68 34 Z" fill="#fff3d6" ' . $st . '/><path d="M20 62 C20 24 80 24 80 62 Z" fill="#b8bfcc" ' . $st . '/><path d="M20 52 H80 V62 H20 Z" fill="#8a91a0" ' . $st . '/>',
        'cilindro' => '<ellipse cx="50" cy="64" rx="44" ry="9" fill="#2b2540" ' . $st . '/><path d="M26 64 V20 Q26 12 34 12 H66 Q74 12 74 20 V64 Z" fill="#2b2540" ' . $st . '/><path d="M26 48 H74 V57 H26 Z" fill="#ff5a5f" ' . $st . '/>',
        'babbo' => '<path d="M16 62 C14 26 42 8 68 14 C82 18 90 32 86 44 L90 62 Z" fill="#e63946" ' . $st . '/><rect x="8" y="56" width="86" height="16" rx="8" fill="#fff" ' . $st . '/><circle cx="87" cy="42" r="9" fill="#fff" ' . $st . '/>',
        'corona' => '<path d="M12 62 L8 22 L30 40 L50 14 L70 40 L92 22 L88 62 Z" fill="#ffd23f" ' . $st . '/><rect x="12" y="58" width="76" height="12" rx="4" fill="#ffb000" ' . $st . '/><circle cx="50" cy="46" r="4.5" fill="#ff5a5f" ' . $st . '/><circle cx="28" cy="52" r="3.5" fill="#53c8f5" ' . $st . '/><circle cx="72" cy="52" r="3.5" fill="#53c8f5" ' . $st . '/>',
    ];
    return '<svg class="hat-svg" viewBox="0 0 100 80" focusable="false">' . ($shapes[$key] ?? '') . '</svg>';
}
