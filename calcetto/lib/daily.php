<?php
/*
 * Daily access: campagna di 30 giorni (lib/daily_items.php). Le ricompense si ritirano IN SEQUENZA, una per ogni giorno di
 * accesso: il primo giorno in cui accedi prendi il regalo 1, il giorno di accesso dopo il 2, e così via. Saltare un giorno non
 * fa perdere nulla, ma si avanza di un solo passo al giorno: chi salta giorni non arriva all'ultimo regalo entro la fine della
 * campagna. Al massimo un ritiro al giorno per giocatore (UNIQUE su claim_date). Ricompense: KOIN (mossa 'premio', eco 0) e
 * oggetti del Negozio sbloccati gratis.
 */

const DAILY_START = '2026-10-19';   // lunedì: primo giorno della campagna
const DAILY_DAYS  = 30;

// TEST (temporaneo): true = campagna sempre aperta, così il Daily access è subito disponibile (e mostra il pallino).
// RIMETTERE A false prima di pubblicare, così vale la finestra che parte da DAILY_START.
const DAILY_TEST_ALWAYS_OPEN = true;

/*
 * Oggetti esclusivi dei premi (Daily access e Ruota della fortuna): NON si comprano al Negozio e si vedono solo da chi li
 * possiede (marker 'reward', come i premi del Fanta). Sono in formato "già pronto" del catalogo (lib/shop.php: shop_catalog li
 * aggiunge come fa con fanta_reward_items). Riusano disegni esistenti (tpl in lib/hats.php, anim in lib/avatar_pixel.php), così
 * si vedono addosso subito. Le chiavi sono uniche in tutto il catalogo e lunghe al massimo 16 caratteri.
 */
function reward_items(): array
{
    $d = 'Premio esclusivo: non si compra al Negozio, si ottiene solo dai regali.';
    return [
        'hat' => [
            'x_alloro'    => ['name' => 'Alloro d\'oro',      'price' => 999, 'tpl' => 'laurel', 'colors' => ['a' => '#ffd23f', 'b' => '', 'c' => ''],                 'reward' => true, 'desc' => $d],
            'x_cilindro_v' => ['name' => 'Cilindro dorato',   'price' => 999, 'tpl' => 'tophat', 'colors' => ['a' => '#2b2540', 'b' => '#ffd23f', 'c' => ''],          'reward' => true, 'desc' => $d],
            'x_mago_stel' => ['name' => 'Cappello stellato',  'price' => 999, 'tpl' => 'wizard', 'colors' => ['a' => '#2b2540', 'b' => '#ffd23f', 'c' => '#53c8f5'],   'reward' => true, 'desc' => $d],
            'x_corona_re' => ['name' => 'Corona del Re',      'price' => 999, 'tpl' => 'crown',  'colors' => ['a' => '#1f1a2e', 'b' => '#ffd23f', 'c' => '#ff5a5f'],   'reward' => true, 'desc' => $d],
        ],
        'celebration' => [
            'x_showtime'  => ['name' => 'Show del fortunato', 'price' => 999, 'anim' => 'dance', 'reward' => true, 'desc' => $d],
            'x_jackpot'   => ['name' => 'Jackpot',            'price' => 999, 'anim' => 'why',   'reward' => true, 'desc' => $d],
        ],
    ];
}

/** Data di oggi (Europe/Rome), 'Y-m-d'. */
function daily_today(): string
{
    return (new DateTimeImmutable('today', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
}

/** true se oggi la campagna è aperta (dal giorno d'inizio per DAILY_DAYS giorni di calendario). */
function daily_window_open(): bool
{
    if (DAILY_TEST_ALWAYS_OPEN) {
        return true;
    }
    $tz = new DateTimeZone('Europe/Rome');
    $start = new DateTimeImmutable(DAILY_START . ' 00:00:00', $tz);
    $today = new DateTimeImmutable('today', $tz);
    $diff = (int) $start->diff($today)->format('%r%a');
    return $diff >= 0 && $diff < DAILY_DAYS;
}

function daily_catalog(): array
{
    static $c = null;
    return $c ??= require __DIR__ . '/daily_items.php';
}

/** Quante ricompense ha già ritirato il giocatore (0..DAILY_DAYS). Il prossimo regalo è questo numero + 1. */
function daily_progress(int $playerId): int
{
    return (int) q('SELECT COUNT(*) FROM daily_claims WHERE player_id = ?', [$playerId])->fetchColumn();
}

/** true se il giocatore ha già ritirato un regalo oggi. */
function daily_claimed_today(int $playerId): bool
{
    return (bool) q('SELECT 1 FROM daily_claims WHERE player_id = ? AND claim_date = ?', [$playerId, daily_today()])->fetch();
}

/** true se oggi il giocatore può ritirare il prossimo regalo. */
function daily_claimable(int $playerId): bool
{
    return daily_window_open()
        && daily_progress($playerId) < DAILY_DAYS
        && !daily_claimed_today($playerId);
}

/**
 * Ritira il prossimo regalo della sequenza. Idempotente e al massimo uno al giorno (due UNIQUE su daily_claims).
 * Ritorna [bool ok, string messaggio, ?array ricompensa].
 */
function daily_claim(int $playerId): array
{
    if (!daily_window_open()) {
        return [false, 'Il daily access non è attivo.', null];
    }
    if (daily_claimed_today($playerId)) {
        return [false, 'Hai già ritirato il regalo di oggi: torna domani.', null];
    }
    $next = daily_progress($playerId) + 1;
    if ($next > DAILY_DAYS) {
        return [false, 'Hai già ritirato tutti i regali della campagna.', null];
    }
    $reward = daily_catalog()[$next] ?? null;
    if (!$reward) {
        return [false, 'Nessuna ricompensa configurata.', null];
    }
    return bet_atomic(function () use ($playerId, $next, $reward) {
        // il numero del regalo e il giorno di calendario sono entrambi unici per giocatore: due richieste insieme
        // (o un doppio ritiro nello stesso giorno) non passano due volte
        $ins = db()->prepare('INSERT IGNORE INTO daily_claims (player_id, day, claim_date) VALUES (?, ?, ?)');
        $ins->execute([$playerId, $next, daily_today()]);
        if (!$ins->rowCount()) {
            return [false, 'Hai già ritirato il regalo di oggi: torna domani.', null];
        }
        if (($koin = (int) ($reward['koin'] ?? 0)) > 0) {
            q("INSERT INTO wallet_moves (player_id, eco, delta, kind, ref) VALUES (?, 0, ?, 'premio', ?)
               ON DUPLICATE KEY UPDATE delta = VALUES(delta)", [$playerId, $koin, 'daily-' . $next . '-' . $playerId]);
        }
        if (!empty($reward['item'])) {
            if (!q('SELECT 1 FROM player_items WHERE player_id = ? AND item_key = ?', [$playerId, $reward['item']])->fetch()) {
                q('INSERT INTO player_items (player_id, item_key, price, paid_credits, paid_eco) VALUES (?, ?, 0, 0, 0)',
                    [$playerId, $reward['item']]);
            }
        }
        log_activity('daily', 'regalo ' . $next . ' · ' . ($reward['label'] ?? ''));
        return [true, 'Ricompensa ritirata: ' . ($reward['label'] ?? ''), $reward];
    });
}
