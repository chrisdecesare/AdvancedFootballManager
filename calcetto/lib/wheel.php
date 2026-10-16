<?php
/*
 * Ruota della fortuna (wheel.php): un giro ogni 24 ore. Si vince KOIN (mossa 'premio', eco 0, spendibili) oppure un oggetto
 * ESCLUSIVO non acquistabile dal Negozio (reward_items() in lib/daily.php). Se l'oggetto estratto è già posseduto, al suo
 * posto si ricevono i KOIN di consolazione dello spicchio. Ogni giro è registrato in wheel_spins; il cooldown di 24h parte
 * dall'ultimo giro (wheel_spins.spun_at). Un solo giro per volta: il player viene bloccato in transazione (FOR UPDATE).
 */

const WHEEL_COOLDOWN = 86400;   // 24 ore, in secondi

/**
 * Gli spicchi della ruota: 'w' è il peso (probabilità relativa), 'koin' i KOIN dello spicchio, 'item' l'eventuale oggetto
 * esclusivo [tipo, chiave] (se già posseduto si danno i KOIN), 'label' il testo mostrato.
 * PROPOSTA DA RIVEDERE: pesi e premi sono una bozza, da correggere prima di pubblicare.
 */
function wheel_slices(): array
{
    return [
        ['w' => 30, 'koin' => 20,  'item' => null,                        'label' => '20 KOIN'],
        ['w' => 24, 'koin' => 40,  'item' => null,                        'label' => '40 KOIN'],
        ['w' => 17, 'koin' => 75,  'item' => null,                        'label' => '75 KOIN'],
        ['w' => 10, 'koin' => 150, 'item' => null,                        'label' => '150 KOIN'],
        ['w' => 4,  'koin' => 300, 'item' => null,                        'label' => '300 KOIN'],
        ['w' => 6,  'koin' => 50,  'item' => ['celebration', 'x_showtime'], 'label' => 'Esultanza «Show del fortunato»'],
        ['w' => 5,  'koin' => 60,  'item' => ['hat', 'x_cilindro_v'],      'label' => 'Cilindro dorato'],
        ['w' => 4,  'koin' => 100, 'item' => ['hat', 'x_corona_re'],       'label' => 'Corona del Re'],
    ];
}

/** Timestamp dell'ultimo giro del giocatore, o null se non ha mai giocato. */
function wheel_last_spin(int $playerId): ?int
{
    $t = q('SELECT UNIX_TIMESTAMP(MAX(spun_at)) FROM wheel_spins WHERE player_id = ?', [$playerId])->fetchColumn();
    return ($t === null || $t === false) ? null : (int) $t;
}

/** Quando il giocatore potrà rigiocare (timestamp): ultimo giro + cooldown, o adesso se non ha mai giocato. */
function wheel_next_at(int $playerId): int
{
    $last = wheel_last_spin($playerId);
    return $last === null ? time() : $last + WHEEL_COOLDOWN;
}

/** true se il giocatore può girare adesso. */
function wheel_ready(int $playerId): bool
{
    return time() >= wheel_next_at($playerId);
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
 * Gira la ruota, se il cooldown è scaduto. Ritorna [bool ok, string messaggio, ?array premio].
 * Il premio è ['koin' => n, 'item' => [tipo, chiave]|null, 'label' => testo, 'itemGiven' => bool].
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
        $slices = wheel_slices();
        $slice = $slices[wheel_pick()];
        $koin = (int) $slice['koin'];
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

        $prizeLabel = $itemGiven ? $slice['label'] . ' + ' . $koin . ' KOIN' : $koin . ' KOIN';
        $spinId = (int) (q('INSERT INTO wheel_spins (player_id, koin, prize) VALUES (?, ?, ?)',
            [$playerId, $koin, $itemGiven ? $slice['label'] : null]) ? db()->lastInsertId() : 0);

        if ($koin > 0) {
            q("INSERT INTO wallet_moves (player_id, eco, delta, kind, ref) VALUES (?, 0, ?, 'premio', ?)
               ON DUPLICATE KEY UPDATE delta = VALUES(delta)", [$playerId, $koin, 'wheel-' . $spinId]);
        }
        log_activity('ruota', 'giro ' . $spinId . ' · ' . $prizeLabel);
        return [true, 'Hai vinto: ' . $prizeLabel . '!', ['koin' => $koin, 'item' => $itemGiven ? $item : null, 'label' => $prizeLabel, 'itemGiven' => $itemGiven]];
    });
}
