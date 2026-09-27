<?php
/*
 * Negozio delle personalizzazioni del profilo: si pagano con i gettoni delle scommesse (lib/bets.php).
 *
 *  - copricapi: un cappello simpatico in diagonale su un angolo del riquadro (disegni in lib/hats.php);
 *  - bordi: un anello colorato (o luminoso, o animato) attorno al riquadro del profilo e alla carta nella Rosa;
 *  - sfondi speciali: al posto delle strisce del ruolo, sul riquadro del profilo e sulla carta nella Rosa;
 *  - nickname: compare sotto il nome. Alcuni si comprano, altri si sbloccano da soli raggiungendo un obiettivo (gol, assist, MVP...).
 *
 * Il catalogo sta in lib/shop_items.php (nome, prezzo, obiettivo) e il disegno di sfondi e bordi in assets/style.css; nel database restano
 * solo gli acquisti (player_items) e cosa si indossa adesso (colonne bg_preset, border_key, nick_key, hat_key di players). Le chiavi degli
 * oggetti sono uniche in tutto il catalogo e lunghe al massimo 16 caratteri (finiscono anche nella mossa del portafoglio).
 * Anche gli oggetti del Personaggio (avatar.php) stanno in questo catalogo e si comprano allo stesso modo: vedi lib/avatar.php.
 */

require_once __DIR__ . '/hats.php';

/** Tipi del Negozio del profilo (shop.php). Quelli del Personaggio sono in lib/avatar.php (avatar_kinds). */
function shop_kinds(): array
{
    return ['hat' => 'Copricapi', 'border' => 'Bordi', 'bg' => 'Sfondi', 'nick' => 'Nickname'];
}

/** Colonna di players dove si salva cosa si indossa, per i tipi del profilo (il Personaggio usa avatar_look, lib/avatar.php). */
function shop_column(string $kind): string
{
    return ['bg' => 'bg_preset', 'nick' => 'nick_key', 'hat' => 'hat_key', 'border' => 'border_key'][$kind];
}

function shop_catalog(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $src = require __DIR__ . '/shop_items.php';
    $c = [];
    foreach ($src['hat'] as [$k, $name, $price, $tpl, $a, $b, $col]) {
        $c['hat'][$k] = ['name' => $name, 'price' => $price, 'tpl' => $tpl, 'colors' => ['a' => $a, 'b' => $b, 'c' => $col]];
    }
    foreach (['border', 'bg'] as $kind) {
        foreach ($src[$kind] as [$k, $name, $price]) {
            $c[$kind][$k] = ['name' => $name, 'price' => $price];
        }
    }
    foreach ($src['nick'] as $row) {
        $c['nick'][$row[0]] = ['name' => $row[1], 'price' => $row[2]] + (isset($row[3]) ? ['goal' => $row[3]] : []);
    }
    // Personaggio (lib/avatar.php)
    foreach ($src['hair'] as [$k, $name, $price, $style, $desc]) {
        $c['hair'][$k] = ['name' => $name, 'price' => $price, 'style' => $style, 'desc' => $desc];
    }
    foreach (['hair_color', 'skin', 'shorts', 'shoes'] as $kind) {
        foreach ($src[$kind] as [$k, $name, $price, $color]) {
            $c[$kind][$k] = ['name' => $name, 'price' => $price, 'color' => $color];
        }
    }
    foreach (['beard', 'glasses'] as $kind) {
        foreach ($src[$kind] as [$k, $name, $price, $style]) {
            $c[$kind][$k] = ['name' => $name, 'price' => $price, 'style' => $style];
        }
    }
    foreach ($src['jersey'] as $row) {
        $c['jersey'][$row[0]] = ['name' => $row[1], 'price' => $row[2], 'kind' => $row[3], 'colors' => ['a' => $row[4], 'b' => $row[5]],
            'pattern' => $row[6] ?? 'solid'];
    }
    // le maglie create dai giocatori (jersey_creator.php): gratis, e solo di chi le ha create
    foreach (q('SELECT id, player_id, name, primary_color, secondary_color, pattern_key FROM custom_jerseys ORDER BY id')->fetchAll() as $cj) {
        $c['jersey']['cj' . $cj['id']] = ['name' => $cj['name'], 'price' => 0, 'kind' => 'custom',
            'colors' => ['a' => $cj['primary_color'], 'b' => $cj['secondary_color']], 'pattern' => $cj['pattern_key'],
            'owner_player_id' => (int) $cj['player_id']];
    }
    foreach ($src['pet'] as [$k, $name, $price, $style, $a, $b]) {
        $c['pet'][$k] = ['name' => $name, 'price' => $price, 'style' => $style, 'colors' => ['a' => $a, 'b' => $b]];
    }
    foreach ($src['pose'] as [$k, $name, $price, $pose]) {
        $c['pose'][$k] = ['name' => $name, 'price' => $price, 'pose' => $pose];
    }
    foreach ($src['celebration'] as [$k, $name, $price, $anim]) {
        $c['celebration'][$k] = ['name' => $name, 'price' => $price, 'anim' => $anim];
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
    // serie più lunga di vittorie di fila (la storia è dalla partita più recente alla più vecchia)
    $streak = $run = 0;
    foreach (array_reverse($s['history'] ?? []) as $h) {
        $run = $h['result'] === 'V' ? $run + 1 : 0;
        $streak = max($streak, $run);
    }
    return [
        'apps' => (int) ($s['apps'] ?? 0), 'goals' => (int) ($s['goals'] ?? 0), 'assists' => (int) ($s['assists'] ?? 0),
        'ga' => (int) ($s['goals'] ?? 0) + (int) ($s['assists'] ?? 0),
        'mvp' => (int) ($s['mvp'] ?? 0), 'wins' => (int) ($s['wins'] ?? 0), 'losses' => (int) ($s['losses'] ?? 0),
        'own_goals' => (int) ($s['own_goals'] ?? 0), 'streak' => $streak,
        'top_avg' => (int) ($s['apps'] ?? 0) >= 5 ? (float) ($s['avg_vote'] ?? 0) : 0.0,
        'max_goals' => (int) q('SELECT COALESCE(MAX(mp.goals), 0) FROM match_players mp JOIN matches m ON m.id = mp.match_id
                                WHERE mp.player_id = ? AND m.status = \'giocata\' AND mp.team IS NOT NULL', [$playerId])->fetchColumn(),
        'bets_won' => (int) q("SELECT COUNT(*) FROM bets WHERE player_id = ? AND status = 'vinta'", [$playerId])->fetchColumn()
            + (int) q("SELECT COUNT(*) FROM combo_bets WHERE player_id = ? AND status = 'vinta'", [$playerId])->fetchColumn(),
        'coins' => wallet_balance($playerId) + wallet_in_play($playerId),
        'items' => (int) q('SELECT COUNT(*) FROM player_items WHERE player_id = ?', [$playerId])->fetchColumn(),
    ];
}

/** Obiettivo raggiunto? (per i nickname da sbloccare) */
function shop_goal_met(array $item, array $progress): bool
{
    return isset($item['goal']) && $progress[$item['goal'][0]] >= $item['goal'][1];
}

/**
 * Oggetti che il giocatore possiede: comprati, nickname sbloccati con gli obiettivi, oggetti del Personaggio gratis (inclusi per tutti)
 * e maglie create da lui. @return array<string, true>
 */
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
    foreach (avatar_kinds() as $kind => $_) {
        foreach (shop_catalog()[$kind] as $k => $item) {
            if (isset($item['owner_player_id']) ? $item['owner_player_id'] === $playerId : $item['price'] === 0) {
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
    if (isset($item['owner_player_id'])) {
        return 'Questa maglia l\'ha creata un altro giocatore: non si compra.';
    }
    if ($item['price'] === 0) {
        return 'È già tuo: è incluso per tutti.';
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
 * Gli oggetti del Personaggio vanno in avatar_look (lib/avatar.php), tranne il copricapo che è lo stesso del profilo.
 */
function shop_equip(int $playerId, string $kind, ?string $key): ?string
{
    if (!isset(shop_kinds()[$kind]) && !isset(avatar_kinds()[$kind])) {
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
    if (!isset(shop_kinds()[$kind])) {
        avatar_set($playerId, $kind, $key);
    } elseif ($kind === 'bg' && $key !== null) {
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

/** Classe CSS del bordo speciale ('' se non ce l'ha). */
function border_class(array $p): string
{
    $k = shop_worn($p, 'border');
    return $k ? ' brd-' . $k : '';
}

/** Copricapo indossato, in diagonale su un angolo del riquadro ('' se non ne ha uno). */
function hat_html(array $p): string
{
    $k = shop_worn($p, 'hat');
    return $k ? '<span class="hat" aria-hidden="true" title="' . h(shop_item('hat', $k)['name']) . '">' . hat_svg($k) . '</span>' : '';
}

/** Disegno del copricapo: il modello della sua forma (lib/hats.php) con i suoi colori. */
function hat_svg(string $key): string
{
    $item = shop_item('hat', $key);
    $tpl = $item ? (hat_templates()[$item['tpl']] ?? '') : '';
    $svg = strtr($tpl, ['{a}' => $item['colors']['a'] ?? '', '{b}' => $item['colors']['b'] ?? '', '{c}' => $item['colors']['c'] ?? '',
        '{S}' => 'stroke="#1f1a2e" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"']);
    return '<svg class="hat-svg" viewBox="0 0 100 80" focusable="false">' . $svg . '</svg>';
}

/** Overall del giocatore, in stile videogioco (0-99): il rating (scala 1-10) per dieci. */
function overall($ovr): int
{
    return (int) max(1, min(99, round((float) $ovr * 10)));
}
