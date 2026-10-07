<?php
/*
 * Negozio delle personalizzazioni del profilo: si pagano con i KOIN delle scommesse (lib/bets.php).
 *
 *  - copricapi: un cappello simpatico in diagonale su un angolo del riquadro (disegni in lib/hats.php);
 *  - bordi: un anello colorato (o luminoso, o animato) attorno al riquadro del profilo e alla carta nella Rosa;
 *  - sfondi speciali: al posto delle strisce del ruolo, sul riquadro del profilo e sulla carta nella Rosa;
 *  - nickname: compare sotto il nome. Alcuni si comprano, altri si sbloccano da soli raggiungendo un obiettivo (gol, assist, MVP...).
 *
 * Il catalogo sta in lib/shop_items.php (nome, prezzo, obiettivo) e il disegno di sfondi e bordi in assets/style.css; nel database restano
 * solo gli acquisti (player_items) e cosa si indossa adesso (colonne bg_preset, border_key, nick_key, hat_key di players). Le chiavi degli
 * oggetti sono uniche in tutto il catalogo e lunghe al massimo 16 caratteri (finiscono anche nella mossa del portafoglio).
 * Anche gli oggetti dell'Avatar (avatar.php) stanno in questo catalogo e si comprano allo stesso modo: vedi lib/avatar.php.
 */

require_once __DIR__ . '/hats.php';
require_once __DIR__ . '/shop_items_more.php';

/** Tipi del Negozio del profilo (shop.php). Quelli dell'Avatar sono in lib/avatar.php (avatar_kinds). */
function shop_kinds(): array
{
    return ['hat' => 'Copricapi', 'border' => 'Bordi', 'bg' => 'Sfondi', 'nick' => 'Nickname'];
}

/** Colonna di players dove si salva cosa si indossa, per i tipi del profilo (l'Avatar usa avatar_look, lib/avatar.php). */
function shop_column(string $kind): string
{
    return ['bg' => 'bg_preset', 'nick' => 'nick_key', 'hat' => 'hat_key', 'border' => 'border_key'][$kind];
}

/*
 * Sconti del mercato: dal mercoledì pomeriggio (14:00) alla domenica sera i prezzi del Negozio tendono a scendere (shop_price(),
 * fattore 'money': nel weekend girano più puntate e quindi più KOIN in circolo). Non è un prezzo fisso scontato, è lo stesso
 * mercato di sempre: qui sta solo l'avviso che lo segnala ai giocatori.
 */
function promo_active(): bool
{
    $now = time();
    $dow = (int) date('N', $now);   // 1 lun ... 7 dom
    return $dow > 3 || ($dow === 3 && (int) date('G', $now) >= 14);
}

/** Prossimo inizio dello sconto (mercoledì alle 14): se è già iniziato non serve, vedi promo_active(). */
function promo_next_at(): int
{
    $now = time();
    if ((int) date('N', $now) === 3 && (int) date('G', $now) < 14) {
        return strtotime('today 14:00', $now);
    }
    return strtotime('next wednesday 14:00', $now);
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
    // Avatar (lib/avatar.php)
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
    // al lancio dell'Avatar ne esce solo una parte: gli altri diventano un pacchetto «Lancio: ...» come quelli del catalogo esteso
    $keep = shop_launch_keep();
    foreach (shop_launch_kinds() as $kind => $_) {
        $isFlag = $kind === 'flag';
        foreach ($c[$isFlag ? 'hat' : $kind] as $k => $item) {
            if (($isFlag && $item['tpl'] !== 'flag' && strncmp($item['tpl'], 'flag_', 5) !== 0) || $item['price'] === 0 || isset($keep[$k])) {
                continue;
            }
            $c[$isFlag ? 'hat' : $kind][$k]['drop'] = 'l_' . $kind;
        }
    }
    // il catalogo esteso: esce a pacchetti quando lo decide l'admin (drops.php)
    foreach (shop_more_items($c) as $kind => $items) {
        $c[$kind] += $items;
    }
    // i premi del Fanta (lib/fanta.php): non si comprano e si vedono solo da chi li ha vinti
    foreach (fanta_reward_items() as $kind => $items) {
        $c[$kind] += $items;
    }
    return $c;
}

/**
 * Lancio dell'Avatar: dei tipi qui sotto (le bandiere sono copricapi) al lancio esce solo una parte degli oggetti di sempre,
 * quelli gratis più quelli di shop_launch_keep(); gli altri restano «in arrivo» (senza nome) finché l'admin non li fa uscire da drops.php.
 * I copricapi normali no: erano già nel Negozio del profilo.
 */
function shop_launch_kinds(): array
{
    return ['hair' => 'capelli', 'hair_color' => 'colori dei capelli', 'beard' => 'barba', 'glasses' => 'occhiali', 'jersey' => 'maglie',
        'shorts' => 'pantaloncini', 'shoes' => 'scarpette', 'pet' => 'pet', 'pose' => 'pose', 'celebration' => 'esultanze', 'flag' => 'bandiere'];
}

/** Gli oggetti a pagamento che escono subito al lancio (chiave => true). */
function shop_launch_keep(): array
{
    return array_fill_keys([
        'ha_riga', 'ha_sfumato', 'ha_spettinato', 'ha_ricci', 'ha_lunghi', 'ha_coda_alta',
        'hc_blu', 'hc_rosa',
        'be_baffi', 'be_pizzetto',
        'gl_vista', 'gl_sole',
        'j_rossa', 'j_nera', 'j_biancoceleste', 'j_giallorossa', 'j_rossonera', 'j_nerazzurra', 'j_bianconera',
        'n_italia', 'n_brasile', 'n_argentina', 'n_francia', 'n_spagna',
        'p_neri', 'p_blu', 'p_rossi', 'p_verdi', 'p_azzurri',
        's_bianche', 's_rosse', 's_blu', 's_fluo',
        'pe_pallino', 'pe_pulcino', 'pe_cane',
        'po_aperte', 'po_saluto', 'po_vittoria',
        'c_esultanza', 'c_bacio', 'c_saluto', 'c_dita', 'c_inginocchio', 'c_danza', 'c_aeroplano', 'c_ciuccio', 'c_robot',
        'c_marta', 'c_gama', 'c_bonansea',
        'f_italia', 'f_brasile', 'f_argentina',
    ], true);
}

/** Quanti oggetti di un tipo sono ancora «in arrivo» (non usciti): per il riquadro senza nomi di chi non è admin. */
function shop_coming_count(string $kind): int
{
    $n = 0;
    foreach (shop_catalog()[$kind] ?? [] as $k => $item) {
        if (!shop_released((string) $k, $item)) {
            $n++;
        }
    }
    return $n;
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
        'coins' => wallet_total($playerId),
        'items' => (int) q('SELECT COUNT(*) FROM player_items WHERE player_id = ?', [$playerId])->fetchColumn(),
    ];
}

/** Obiettivo raggiunto? (per i nickname da sbloccare) */
function shop_goal_met(array $item, array $progress): bool
{
    return isset($item['goal']) && $progress[$item['goal'][0]] >= $item['goal'][1];
}

/**
 * Oggetti che il giocatore possiede: comprati, nickname sbloccati con gli obiettivi, oggetti dell'Avatar gratis (inclusi per tutti)
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
            if (isset($item['owner_player_id']) ? $item['owner_player_id'] === $playerId : ($item['price'] === 0 && shop_released($k, $item))) {
                $owned[$k] = true;
            }
        }
    }
    return $owned;
}

/* ---------------------------------------------------------------- uscite (drops.php) */

/** Quando esce ogni oggetto del catalogo esteso: chiave => timestamp (solo quelli già decisi dall'admin). */
function shop_release_map(bool $fresh = false): array
{
    static $map = null;
    if ($map === null || $fresh) {
        $map = [];
        foreach (q('SELECT item_key, release_at FROM shop_releases')->fetchAll() as $r) {
            $map[$r['item_key']] = strtotime($r['release_at']);
        }
    }
    return $map;
}

/** In vendita? Gli oggetti di sempre sì; quelli del catalogo esteso solo dopo che l'admin li ha fatti uscire. */
function shop_released(string $key, array $item): bool
{
    if (!isset($item['drop'])) {
        return true;
    }
    $at = shop_release_map()[$key] ?? null;
    return $at !== null && $at <= time();
}

/* ---------------------------------------------------------------- prezzi */

/** Saldo medio di riferimento: con questa media di KOIN a testa i prezzi restano quelli del catalogo. */
const SHOP_PRICE_REF = 300;

/**
 * Il mercato di adesso di un'economia (lib/bets.php: current_eco), una volta per richiesta: quante volte ogni oggetto è nella lista
 * desideri, quanti lo possiedono, i saldi dei giocatori e la loro media. Contano solo i giocatori che hanno un portafoglio in quella
 * economia: chi gonfia i KOIN (o i desideri) nella propria lega non cambia i prezzi delle altre.
 */
function shop_market(bool $fresh = false, ?int $eco = null): array
{
    static $cache = [];
    $eco ??= current_eco();
    if (isset($cache[$eco]) && !$fresh) {
        return $cache[$eco];
    }
    $m = ['wish' => [], 'owners' => [], 'balances' => [], 'avg' => 0.0];
    $inEco = 'EXISTS (SELECT 1 FROM wallet_moves em WHERE em.player_id = t.player_id AND em.eco = ? AND em.shop_only = 0)';
    foreach (q("SELECT t.item_key, COUNT(*) n FROM wishlist t WHERE $inEco GROUP BY t.item_key", [$eco])->fetchAll() as $r) {
        $m['wish'][$r['item_key']] = (int) $r['n'];
    }
    foreach (q("SELECT t.item_key, COUNT(*) n FROM player_items t WHERE $inEco GROUP BY t.item_key", [$eco])->fetchAll() as $r) {
        $m['owners'][$r['item_key']] = (int) $r['n'];
    }
    foreach (q('SELECT player_id, SUM(delta) b FROM wallet_moves WHERE eco = ? AND shop_only = 0 GROUP BY player_id', [$eco])->fetchAll() as $r) {
        $m['balances'][(int) $r['player_id']] = (int) $r['b'];
    }
    $m['avg'] = $m['balances'] ? array_sum($m['balances']) / count($m['balances']) : 0.0;
    return $cache[$eco] = $m;
}

/**
 * Prezzo di adesso di un oggetto per chi lo compra: [prezzo, dettaglio]. Parte dal prezzo del catalogo e lo moltiplica per
 *  - desiderato: +8% per ogni giocatore che l'ha tra gli obiettivi (fino a +80%);
 *  - moda: da -10% (non ce l'ha nessuno) a +50% (ce l'hanno tutti);
 *  - KOIN in circolo: saldo medio / SHOP_PRICE_REF, tra ×0,8 e ×1,6;
 *  - il portafoglio di chi compra: (il suo saldo / la media)^0,25, tra ×0,85 e ×1,3.
 * Gratis e nickname da sbloccare restano come sono. Arrotondato a 5, minimo 5.
 */
function shop_price(string $key, array $item, ?int $buyerId, ?int $eco = null): array
{
    $base = $item['price'];
    if ($base === null || $base === 0 || isset($item['owner_player_id'])) {
        return [$base, null];
    }
    $m = shop_market(false, $eco);
    $wish = $m['wish'][$key] ?? 0;
    $owners = $m['owners'][$key] ?? 0;
    $players = max(1, count($m['balances']));
    $clamp = fn(float $v, float $lo, float $hi) => max($lo, min($hi, $v));
    $f = [
        'wish' => min(1.8, 1 + .08 * $wish),
        'owners' => $clamp(.9 + .6 * $owners / $players, .9, 1.5),
        'money' => $clamp($m['avg'] / SHOP_PRICE_REF, .8, 1.6),
        'mine' => 1.0,
    ];
    if ($buyerId !== null && $m['avg'] > 0) {
        $f['mine'] = $clamp((max(0, $m['balances'][$buyerId] ?? 0) / $m['avg']) ** .25, .85, 1.3);
    }
    $price = max(5, (int) (round($base * array_product($f) / 5) * 5));
    return [$price, $f + ['base' => $base, 'wish_n' => $wish, 'owners_n' => $owners]];
}

/** Il dettaglio del prezzo in una riga, per il pannello dell'oggetto. */
function shop_price_note(?array $d): string
{
    if (!$d) {
        return '';
    }
    $pct = fn(float $f) => ($f >= 1 ? '+' : '−') . abs((int) round(($f - 1) * 100)) . '%';
    $parts = ['prezzo base ' . $d['base']];
    $parts[] = $d['wish_n'] ? 'obiettivo di ' . $d['wish_n'] . ' ' . ($d['wish_n'] === 1 ? 'giocatore' : 'giocatori') . ' ' . $pct($d['wish'])
        : 'nessuno lo desidera';
    $parts[] = ($d['owners_n'] ? 'ce l\'hanno in ' . $d['owners_n'] : 'non ce l\'ha nessuno') . ' ' . $pct($d['owners']);
    $parts[] = 'KOIN in circolo ' . $pct($d['money']);
    if (abs($d['mine'] - 1) >= .005) {
        $parts[] = 'il tuo portafoglio ' . $pct($d['mine']);
    }
    return implode(' · ', $parts);
}

/* ---------------------------------------------------------------- lista desideri */

/** Gli obiettivi di un giocatore. @return array<string, true> */
function shop_wishlist(int $playerId): array
{
    $w = [];
    foreach (q('SELECT item_key FROM wishlist WHERE player_id = ?', [$playerId])->fetchAll(PDO::FETCH_COLUMN) as $k) {
        $w[$k] = true;
    }
    return $w;
}

/** Aggiunge o toglie un oggetto dagli obiettivi. Ritorna true se adesso c'è. */
function shop_wish_toggle(int $playerId, string $key): bool
{
    if (q('DELETE FROM wishlist WHERE player_id = ? AND item_key = ?', [$playerId, $key])->rowCount()) {
        return false;
    }
    q('INSERT IGNORE INTO wishlist (player_id, item_key) VALUES (?, ?)', [$playerId, $key]);
    return true;
}

/** Il tipo di un oggetto dalla sua chiave (le chiavi sono uniche in tutto il catalogo). */
function shop_kind_of(string $key): ?string
{
    foreach (shop_catalog() as $kind => $items) {
        if (isset($items[$key])) {
            return $kind;
        }
    }
    return null;
}

/* ---------------------------------------------------------------- oggetti nuovi */

/*
 * Quando escono oggetti (drops.php, subito o a una data), alla prima richiesta dopo l'uscita parte una notifica push a chi le ha
 * attive (shop_news_notify_due, anche da cron.php). Chi non le ha attive vede invece un pallino rosso sulla scheda del menu
 * (Avatar o Negozio) e sulla categoria, finché non la apre. Per SHOP_NEW_DAYS giorni gli oggetti usciti hanno l'etichetta «Nuovo».
 * Gli oggetti dell'Avatar usciti prima del suo lancio non si annunciano: sarebbe uno spoiler.
 */
const SHOP_NEW_DAYS = 7;

/** Oggetti usciti negli ultimi SHOP_NEW_DAYS giorni: chiave => quando sono usciti (timestamp). */
function shop_new_items(): array
{
    static $new = null;
    if ($new === null) {
        $new = [];
        $from = time() - SHOP_NEW_DAYS * 86400;
        foreach (shop_release_map() as $k => $at) {
            if ($at <= time() && $at > $from) {
                $new[$k] = $at;
            }
        }
    }
    return $new;
}

function shop_is_new(string $key): bool
{
    return isset(shop_new_items()[$key]);
}

/** L'Avatar o il Negozio del profilo: la scheda del menu di un tipo. */
function shop_news_section(string $kind): string
{
    return isset(avatar_kinds()[$kind]) && $kind !== 'hat' ? 'avatar' : 'shop';
}

/**
 * Tipi con oggetti nuovi che l'utente non ha ancora guardato: tipo => true. Vuoto per chi ha le notifiche attive (a loro arriva
 * la notifica) e per chi non può vederli (l'Avatar prima del lancio).
 */
function shop_news_unseen(): array
{
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $out = [];
    $u = current_user();
    if (!$u || is_guest() || !shop_new_items() || push_subs_of_users([(int) $u['id']])) {
        return $out;
    }
    $seen = json_decode((string) (q('SELECT news_seen FROM users WHERE id = ?', [$u['id']])->fetchColumn() ?: ''), true);
    $seen = is_array($seen) ? $seen : [];
    foreach (shop_new_items() as $k => $at) {
        $kind = shop_kind_of((string) $k);
        if ($kind === null || (shop_news_section($kind) === 'avatar' && !avatar_visible())) {
            continue;
        }
        if ($at > (int) ($seen[$kind] ?? 0)) {
            $out[$kind] = true;
        }
    }
    return $out;
}

/** Ha guardato un tipo: il pallino se ne va (anche quello della scheda, se era l'ultimo). */
function shop_news_seen(string $kind): void
{
    $u = current_user();
    if (!$u || !isset(shop_news_unseen()[$kind])) {
        return;
    }
    $seen = json_decode((string) (q('SELECT news_seen FROM users WHERE id = ?', [$u['id']])->fetchColumn() ?: ''), true);
    $seen = is_array($seen) ? $seen : [];
    $seen[$kind] = time();
    q('UPDATE users SET news_seen = ? WHERE id = ?', [json_encode($seen), $u['id']]);
}

/** Le schede del menu col pallino: 'avatar' / 'shop' => true. */
function shop_news_sections(): array
{
    $out = [];
    foreach (shop_news_unseen() as $kind => $_) {
        $out[shop_news_section($kind)] = true;
        if ($kind === 'hat' && avatar_visible()) {   // i copricapi stanno in tutte e due le schede
            $out['avatar'] = true;
        }
    }
    return $out;
}

/** Annuncia con una notifica push gli oggetti usciti dall'ultima volta (una volta sola, anche con più richieste insieme). */
function shop_news_notify_due(): void
{
    $last = meta_get('news_notified_at');
    $now = date('Y-m-d H:i:s');
    if ($last === null) {   // la prima volta si parte da adesso: le uscite vecchie non si annunciano
        meta_set('news_notified_at', $now);
        return;
    }
    $keys = q('SELECT item_key FROM shop_releases WHERE release_at > ? AND release_at <= ?', [$last, $now])->fetchAll(PDO::FETCH_COLUMN);
    if (!$keys || !q("UPDATE meta SET v = ? WHERE k = 'news_notified_at' AND v = ?", [$now, $last])->rowCount()) {
        return;
    }
    $byKind = [];
    foreach ($keys as $k) {
        $kind = shop_kind_of((string) $k);
        if ($kind !== null && (shop_news_section($kind) === 'shop' || avatar_public())) {
            $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;
        }
    }
    if (!$byKind) {
        return;
    }
    arsort($byKind);
    $labels = avatar_kinds() + shop_kinds();
    $n = array_sum($byKind);
    $top = array_key_first($byKind);
    $parts = array_map(fn($kind, $c) => $c . ' ' . mb_strtolower($labels[$kind] ?? $kind), array_keys($byKind), $byKind);
    $msg = ['title' => $n === 1 ? 'È uscito un oggetto nuovo!' : 'Sono usciti ' . $n . ' oggetti nuovi!',
        'body' => implode(', ', array_slice($parts, 0, 4)) . (count($parts) > 4 ? ' e altro' : '') . '. Corri a vederli prima che li prendano tutti.',
        'url' => shop_news_section($top) === 'avatar' && avatar_public() ? 'avatar.php?c=' . $top : 'shop.php?s=' . $top,
        'tag' => 'news-' . date('YmdHi')];
    push_defer(function () use ($msg) {
        if ($users = push_all_subscribed_users()) {
            push_notify_users($users, $msg, 'normal', 'uscite');
        }
    });
}

/* ---------------------------------------------------------------- acquisti */

/** Compra un oggetto con i KOIN di un'economia (null = quella in uso adesso). Ritorna il messaggio d'errore oppure null se è andata. */
function shop_buy(int $playerId, string $kind, string $key, ?int $eco = null, bool $useCredits = false): ?string
{
    $eco ??= current_eco($playerId);
    if (!in_array($eco, player_ecos($playerId), true)) {
        return 'Non hai un portafoglio in questa lega.';
    }
    $item = shop_item($kind, $key);
    if (!$item) {
        return 'Oggetto non trovato.';
    }
    if ($item['price'] === null) {
        return 'Questo non si compra: si sblocca con un obiettivo.';
    }
    if (isset($item['fanta'])) {
        return 'Questo non si compra: è un premio del Fanta.';
    }
    if (isset($item['owner_player_id'])) {
        return 'Questa maglia l\'ha creata un altro giocatore: non si compra.';
    }
    if (!shop_released($key, $item)) {
        return 'Questo oggetto non è ancora uscito.';
    }
    if ($item['price'] === 0) {
        return 'È già tuo: è incluso per tutti.';
    }
    return bet_atomic(function () use ($playerId, $kind, $key, $item, $eco, $useCredits) {
        q('SELECT id FROM players WHERE id = ? FOR UPDATE', [$playerId]);   // due acquisti insieme non possono spendere due volte gli stessi KOIN
        if (q('SELECT 1 FROM player_items WHERE player_id = ? AND item_key = ?', [$playerId, $key])->fetch()) {
            return 'Ce l\'hai già.';
        }
        shop_market(true, $eco);   // il prezzo di adesso, con desideri, possessori e saldi letti ora
        [$price] = shop_price($key, $item, $playerId, $eco);
        $credits = $useCredits || isset(avatar_kinds()[$kind]) ? shop_credit_balance($playerId) : 0;   // i crediti valgono per gli oggetti dell'Avatar (anche i cappelli comprati dal negozio)
        $left = wallet_balance($playerId, $eco) + $credits;
        if ($left < $price) {
            return 'Ti servono ' . $price . ' KOIN, ne hai ' . $left . ($eco ? ' in «' . eco_label($eco) . '»' : '') . '. Vai a scommettere!';
        }
        q('INSERT INTO player_items (player_id, item_key, price) VALUES (?, ?, ?)', [$playerId, $key, $price]);
        $fromCredits = min($credits, $price);   // prima si spendono i crediti del negozio, poi i KOIN
        if ($fromCredits > 0) {
            q("INSERT INTO wallet_moves (player_id, eco, delta, kind, ref, shop_only) VALUES (?, 0, ?, 'acquisto', ?, 1)", [$playerId, -$fromCredits, 'buy-' . $key]);
        }
        if ($price > $fromCredits) {
            q("INSERT INTO wallet_moves (player_id, eco, delta, kind, ref) VALUES (?, ?, ?, 'acquisto', ?)", [$playerId, $eco, $fromCredits - $price, $fromCredits > 0 ? null : 'buy-' . $key]);
        }
        return null;
    });
}

/**
 * Indossa un oggetto (o lo toglie, con $key = null). Ritorna il messaggio d'errore oppure null.
 * Uno sfondo speciale sostituisce quello scelto con colore o immagine (che va rifatto da "Modifica profilo").
 * Gli oggetti dell'Avatar vanno in avatar_look (lib/avatar.php), tranne il copricapo che è lo stesso del profilo.
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
