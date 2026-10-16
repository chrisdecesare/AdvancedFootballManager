<?php
/*
 * Ruota della fortuna (wheel.php): un giro ogni 24 ore. Si vince KOIN (mossa 'premio', eco 0, spendibili), crediti dell'Avatar
 * (solo negozio), un oggetto ESCLUSIVO non acquistabile dal Negozio (reward_items() in lib/daily.php), un giro extra o un
 * gettone ×2 per le scommesse (bet_boosts). Se l'oggetto estratto è già posseduto, al suo posto si ricevono i KOIN di
 * consolazione dello spicchio. Ogni giro è registrato in wheel_spins; il cooldown di 24h parte dall'ultimo giro gratis
 * (wheel_spins.spun_at con extra = 0). Un solo giro per volta: il player viene bloccato in transazione (FOR UPDATE).
 * Il premio lo estrae il server; la pagina (assets/ruota.js) fa girare la ruota fino allo spicchio estratto.
 */

const WHEEL_COOLDOWN = 86400;   // 24 ore, in secondi

// TEST (temporaneo): true = nessuna attesa, giri illimitati per provare le animazioni e i premi.
// RIMETTERE A false prima di pubblicare, così torna il cooldown di 24h.
const WHEEL_TEST_UNLIMITED = true;

/**
 * Icona della Ruota della fortuna: una ruota a spicchi colorati (SVG inline, così ha più colori di un'icona-font).
 * Si dimensiona con la classe .ico-wheel (assets/style.css). $cls aggiunge classi extra se servono.
 */
function wheel_icon(string $cls = ''): string
{
    $wedges = [
        ['M12 12 L22 12 A10 10 0 0 1 19.07 19.07 Z', '#ff5a5f'],
        ['M12 12 L19.07 19.07 A10 10 0 0 1 12 22 Z', '#ffd23f'],
        ['M12 12 L12 22 A10 10 0 0 1 4.93 19.07 Z', '#38d178'],
        ['M12 12 L4.93 19.07 A10 10 0 0 1 2 12 Z', '#4a90e2'],
        ['M12 12 L2 12 A10 10 0 0 1 4.93 4.93 Z', '#ff8c42'],
        ['M12 12 L4.93 4.93 A10 10 0 0 1 12 2 Z', '#a67cf2'],
        ['M12 12 L12 2 A10 10 0 0 1 19.07 4.93 Z', '#ff5a5f'],
        ['M12 12 L19.07 4.93 A10 10 0 0 1 22 12 Z', '#ffd23f'],
    ];
    $paths = '';
    foreach ($wedges as [$d, $fill]) {
        $paths .= '<path d="' . $d . '" fill="' . $fill . '"/>';
    }
    $class = 'ico-wheel' . ($cls !== '' ? ' ' . $cls : '');
    return '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true">'
        . '<g stroke="#fff" stroke-width="0.6" stroke-linejoin="round">' . $paths . '</g>'
        . '<circle cx="12" cy="12" r="10" fill="none" stroke="#2b2540" stroke-width="1.2"/>'
        . '<circle cx="12" cy="12" r="2.4" fill="#2b2540"/>'
        . '<polygon points="12,0.5 9.3,4.2 14.7,4.2" fill="#2b2540"/>'   // lancetta in alto
        . '</svg>';
}

/**
 * Gli spicchi della ruota, in ordine intorno al centro (dall'alto, in senso orario), tutti larghi uguale: la probabilità non dipende
 * dalla larghezza ma dal peso 'w' (probabilità = peso / somma dei pesi). 'type': koin, credits (crediti dell'Avatar), item (oggetto
 * esclusivo, più i KOIN dello spicchio), spin (giro extra), token (gettone ×2 per le scommesse), none. 'short' è la scritta sullo spicchio.
 * PROPOSTA DA RIVEDERE: pesi e premi sono una bozza, da correggere prima di pubblicare.
 */
function wheel_slices(): array
{
    return [
        ['w' => 30, 'type' => 'koin',    'koin' => 20,  'item' => null, 'short' => '20',   'label' => '20 KOIN'],
        ['w' => 10, 'type' => 'credits', 'koin' => 0,   'credits' => 50,  'item' => null, 'short' => '50 cr',  'label' => '50 crediti Avatar'],
        ['w' => 24, 'type' => 'koin',    'koin' => 40,  'item' => null, 'short' => '40',   'label' => '40 KOIN'],
        ['w' => 6,  'type' => 'none',    'koin' => 0,   'item' => null, 'short' => '✕',    'label' => 'Niente, riprova'],
        ['w' => 17, 'type' => 'koin',    'koin' => 75,  'item' => null, 'short' => '75',   'label' => '75 KOIN'],
        ['w' => 5,  'type' => 'token',   'koin' => 0,   'item' => null, 'short' => '×2',   'label' => 'Gettone ×2 per le scommesse'],
        ['w' => 10, 'type' => 'koin',    'koin' => 150, 'item' => null, 'short' => '150',  'label' => '150 KOIN'],
        ['w' => 6,  'type' => 'item',    'koin' => 50,  'item' => ['celebration', 'x_showtime'], 'short' => '★', 'label' => 'Esultanza «Show del fortunato»'],
        ['w' => 4,  'type' => 'koin',    'koin' => 300, 'item' => null, 'short' => '300',  'label' => '300 KOIN'],
        ['w' => 7,  'type' => 'spin',    'koin' => 0,   'item' => null, 'short' => '+1',   'label' => 'Giro extra'],
        ['w' => 5,  'type' => 'item',    'koin' => 60,  'item' => ['hat', 'x_cilindro_v'], 'short' => '★', 'label' => 'Cilindro dorato'],
        ['w' => 4,  'type' => 'credits', 'koin' => 0,   'credits' => 150, 'item' => null, 'short' => '150 cr', 'label' => '150 crediti Avatar'],
        ['w' => 4,  'type' => 'item',    'koin' => 100, 'item' => ['hat', 'x_corona_re'], 'short' => '★', 'label' => 'Corona del Re'],
        ['w' => 6,  'type' => 'none',    'koin' => 0,   'item' => null, 'short' => '✕',    'label' => 'Niente, riprova'],
    ];
}

/** Probabilità (in %, un decimale) dello spicchio $i: peso / somma dei pesi. */
function wheel_percent(int $i): float
{
    $slices = wheel_slices();
    $tot = array_sum(array_column($slices, 'w'));
    return round($slices[$i]['w'] * 100 / max(1, $tot), 1);
}

/** Colore dello spicchio $i della ruota (per tipo; i KOIN si alternano). */
function wheel_color(int $i): string
{
    return match (wheel_slices()[$i]['type']) {
        'credits' => '#53c8f5',
        'item' => '#a67cf2',
        'spin' => '#38d178',
        'token' => '#ffd23f',
        'none' => '#c9c4d6',
        default => $i % 4 === 0 ? '#ff5a5f' : '#ff8c42',
    };
}

/**
 * La ruota grande in SVG: spicchi uguali, con la scritta del premio. La gira il JS (rotazione CSS di tutto l'SVG); il puntale sta fuori,
 * fisso in alto. Lo spicchio $i ha il centro a $i * (360 / n) gradi dall'alto, in senso orario.
 */
function wheel_svg(): string
{
    $n = count(wheel_slices());
    $cx = $cy = 150;
    $r = 140;
    $step = 360 / $n;
    $out = '<svg class="wheel-svg" data-wheel-rot viewBox="0 0 300 300" aria-hidden="true">';
    foreach (wheel_slices() as $i => $s) {
        $a0 = deg2rad($i * $step - 90 - $step / 2);
        $a1 = deg2rad($i * $step - 90 + $step / 2);
        $out .= '<path d="M' . $cx . ' ' . $cy . ' L' . round($cx + $r * cos($a0), 2) . ' ' . round($cy + $r * sin($a0), 2) . ' A' . $r . ' ' . $r . ' 0 0 1 '
            . round($cx + $r * cos($a1), 2) . ' ' . round($cy + $r * sin($a1), 2) . ' Z" fill="' . wheel_color($i) . '" stroke="#1f1a2e" stroke-width="2.5" stroke-linejoin="round"/>';
        $len = mb_strlen($s['short']);
        $cls = $len >= 4 ? ' wheel-t-s4' : ($len === 3 ? ' wheel-t-s3' : ($len <= 1 && !ctype_digit($s['short']) ? ' wheel-t-sym' : ''));
        $out .= '<g transform="rotate(' . round($i * $step, 2) . ' 150 150)"><text x="150" y="50" text-anchor="middle" class="wheel-t' . $cls . '">' . h($s['short']) . '</text></g>';
    }
    return $out . '<circle cx="150" cy="150" r="22" fill="#fff4cf" stroke="#1f1a2e" stroke-width="3"/><circle cx="150" cy="150" r="8" fill="#ff5a5f" stroke="#1f1a2e" stroke-width="2"/></svg>';
}

/** Giri extra e gettoni ×2 del giocatore: ['spins' => n, 'tokens' => n]. */
function wheel_extras(int $playerId): array
{
    q('INSERT IGNORE INTO player_tokens (player_id) VALUES (?)', [$playerId]);
    $r = q('SELECT extra_spins, bet_tokens FROM player_tokens WHERE player_id = ?', [$playerId])->fetch();
    return ['spins' => (int) $r['extra_spins'], 'tokens' => (int) $r['bet_tokens']];
}

/** Timestamp dell'ultimo giro del giocatore, o null se non ha mai giocato. */
function wheel_last_spin(int $playerId): ?int
{
    $t = q('SELECT UNIX_TIMESTAMP(MAX(spun_at)) FROM wheel_spins WHERE player_id = ? AND extra = 0', [$playerId])->fetchColumn();
    return ($t === null || $t === false) ? null : (int) $t;
}

/** Quando il giocatore potrà rigiocare (timestamp): ultimo giro + cooldown, o adesso se non ha mai giocato. */
function wheel_next_at(int $playerId): int
{
    $last = wheel_last_spin($playerId);
    return $last === null ? time() : $last + WHEEL_COOLDOWN;
}

/** true se il giocatore ha il giro gratis (passate le 24 ore dall'ultimo). */
function wheel_free_ready(int $playerId): bool
{
    return WHEEL_TEST_UNLIMITED || time() >= wheel_next_at($playerId);
}

/** true se il giocatore può girare adesso: il giro gratis, oppure un giro extra vinto. */
function wheel_ready(int $playerId): bool
{
    return wheel_free_ready($playerId) || wheel_extras($playerId)['spins'] > 0;
}

/** Quanto manca al prossimo giro, in forma breve ("3h 12m", "45m", "30s"). Stringa vuota se è già pronto. */
function wheel_wait_label(int $playerId): string
{
    $sec = wheel_next_at($playerId) - time();
    if ($sec <= 0) {
        return '';
    }
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    if ($h > 0) {
        return $h . 'h ' . $m . 'm';
    }
    return $m > 0 ? $m . 'm' : max(1, $sec) . 's';
}

/** Estrae uno spicchio secondo i pesi. Ritorna l'indice nello wheel_slices(). */
function wheel_pick(): int
{
    $slices = wheel_slices();
    $tot = 0;
    foreach ($slices as $s) {
        $tot += (int) $s['w'];
    }
    $r = random_int(1, max(1, $tot));
    $acc = 0;
    foreach ($slices as $i => $s) {
        $acc += (int) $s['w'];
        if ($r <= $acc) {
            return $i;
        }
    }
    return array_key_last($slices);
}

/**
 * Gira la ruota, se il cooldown è scaduto (o con un giro extra). Ritorna [bool ok, string messaggio, ?array premio].
 * Il premio è ['slice' => indice dello spicchio, 'type', 'koin' => n, 'credits' => n, 'item' => [tipo, chiave]|null, 'label' => testo,
 * 'itemGiven' => bool, 'extra' => bool (era un giro extra)].
 */
function wheel_spin(int $playerId): array
{
    if (!wheel_ready($playerId)) {
        return [false, 'Devi aspettare ancora ' . wheel_wait_label($playerId) . ' prima di rigirare.', null];
    }
    return bet_atomic(function () use ($playerId) {
        // blocco il giocatore: due invii insieme non fanno due giri
        q('SELECT id FROM players WHERE id = ? FOR UPDATE', [$playerId]);
        if (!wheel_ready($playerId)) {
            return [false, 'Hai già girato da poco: riprova più tardi.', null];
        }
        $isExtra = !wheel_free_ready($playerId);   // si usa prima il giro gratis; il giro extra solo se non c'è
        if ($isExtra) {
            q('UPDATE player_tokens SET extra_spins = extra_spins - 1 WHERE player_id = ? AND extra_spins > 0', [$playerId]);
        }
        $idx = wheel_pick();
        $slice = wheel_slices()[$idx];
        $type = $slice['type'];
        $koin = (int) $slice['koin'];
        $credits = (int) ($slice['credits'] ?? 0);
        $item = $slice['item'] ?? null;         // [tipo, chiave] o null
        $itemGiven = false;

        if ($item) {
            $key = $item[1];
            if (q('SELECT 1 FROM player_items WHERE player_id = ? AND item_key = ?', [$playerId, $key])->fetch()) {
                // già posseduto: niente doppione, restano i KOIN di consolazione dello spicchio
                $item = null;
            } else {
                q('INSERT INTO player_items (player_id, item_key, price, paid_credits, paid_eco) VALUES (?, ?, 0, 0, 0)', [$playerId, $key]);
                $itemGiven = true;
            }
        }

        $parts = [];
        if ($itemGiven) {
            $parts[] = $slice['label'];
        }
        if ($koin > 0) {
            $parts[] = $koin . ' KOIN';
        }
        if ($credits > 0) {
            $parts[] = $credits . ' crediti Avatar';
        }
        if ($type === 'spin') {
            $parts[] = 'un giro extra';
        } elseif ($type === 'token') {
            $parts[] = 'un gettone ×2 per le scommesse';
        }
        $prizeLabel = $parts ? implode(' + ', $parts) : 'niente, stavolta';
        $spinId = (int) (q('INSERT INTO wheel_spins (player_id, koin, credits, prize, extra) VALUES (?, ?, ?, ?, ?)',
            [$playerId, $koin, $credits, $itemGiven ? $slice['label'] : ($type === 'koin' || $type === 'item' ? null : $slice['label']), $isExtra ? 1 : 0]) ? db()->lastInsertId() : 0);

        if ($koin > 0) {
            q("INSERT INTO wallet_moves (player_id, eco, delta, kind, ref) VALUES (?, 0, ?, 'premio', ?)
               ON DUPLICATE KEY UPDATE delta = VALUES(delta)", [$playerId, $koin, 'wheel-' . $spinId]);
        }
        if ($credits > 0) {   // crediti dell'Avatar: valgono in tutte le economie, solo nel negozio (eco 0, shop_only)
            q("INSERT INTO wallet_moves (player_id, eco, delta, kind, ref, shop_only) VALUES (?, 0, ?, 'premio', ?, 1)
               ON DUPLICATE KEY UPDATE delta = VALUES(delta)", [$playerId, $credits, 'wheel-' . $spinId . 'c']);
        }
        if ($type === 'spin') {
            q('UPDATE player_tokens SET extra_spins = extra_spins + 1 WHERE player_id = ?', [$playerId]);
        } elseif ($type === 'token') {
            q('UPDATE player_tokens SET bet_tokens = bet_tokens + 1 WHERE player_id = ?', [$playerId]);
        }
        log_activity('ruota', 'giro ' . $spinId . ($isExtra ? ' (extra)' : '') . ' · ' . $prizeLabel);
        return [true, ($type === 'none' ? 'Niente, stavolta' : 'Hai vinto: ' . $prizeLabel) . '!',
            ['slice' => $idx, 'type' => $type, 'koin' => $koin, 'credits' => $credits, 'item' => $itemGiven ? $item : null, 'label' => $prizeLabel,
             'itemGiven' => $itemGiven, 'extra' => $isExtra]];
    });
}

/* ---------------------------------------------------------------- gettone ×2 per le scommesse */

/** Il giocatore ha già attivato il gettone ×2 per questa partita? Le sue puntate singole su di essa pagano il doppio (colonna fire). */
function bet_boosted(int $playerId, int $matchId): bool
{
    try {
        return (bool) q('SELECT 1 FROM bet_boosts WHERE player_id = ? AND match_id = ?', [$playerId, $matchId])->fetch();
    } catch (Throwable $e) {   // tabella non ancora creata
        return false;
    }
}

/**
 * Attiva un gettone ×2 su una partita ancora aperta alle scommesse: le puntate singole che ha (e che farà) su di essa, se vincono, pagano
 * BET_FIRE_MULT volte (come nel Martedì di fuoco, senza sommarsi). Ritorna il messaggio d'errore oppure null.
 */
function bet_token_use(int $playerId, array $match): ?string
{
    if (!bets_open_for($match)) {
        return 'Le scommesse su questa partita non sono aperte.';
    }
    return bet_atomic(function () use ($playerId, $match) {
        q('SELECT id FROM players WHERE id = ? FOR UPDATE', [$playerId]);
        if (bet_boosted($playerId, (int) $match['id'])) {
            return 'Hai già attivato il gettone su questa partita.';
        }
        if (wheel_extras($playerId)['tokens'] < 1) {
            return 'Non hai gettoni ×2: si vincono con la ruota della fortuna.';
        }
        q('UPDATE player_tokens SET bet_tokens = bet_tokens - 1 WHERE player_id = ?', [$playerId]);
        q('INSERT INTO bet_boosts (player_id, match_id) VALUES (?, ?)', [$playerId, $match['id']]);
        q("UPDATE bets SET fire = 1 WHERE match_id = ? AND player_id = ? AND status = 'aperta'", [$match['id'], $playerId]);
        log_activity('ruota', 'gettone ×2 attivato · partita ' . (int) $match['id']);
        return null;
    });
}
