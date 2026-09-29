<?php
/*
 * "Indovina la funzionalità": una sezione segreta (guess.php, non in menu, si arriva dalla card del countdown in Home) dove
 * ogni giocatore scrive, in un campo di testo, la sua idea su quale sarà la prossima novità del sito. Un round alla volta,
 * un'idea sola a testa (la può cambiare finché non scade). Quando arriva l'uscita, l'admin legge le idee (admin.php) e
 * regala gettoni a chi si è avvicinato di più: un premio goliardico, non una scommessa vera con quote.
 */

/** Se non c'è ancora una data impostata: il prossimo giovedì alle 20, oppure tra 7 giorni se oggi è già giovedì dopo le 20. */
function guess_default_drop_at(): int
{
    $now = time();
    $thu = strtotime('next thursday 20:00', $now);
    if ((int) date('N', $now) === 4 && (int) date('H', $now) < 20) {
        $thu = strtotime('today 20:00', $now);
    }
    return $thu;
}

function guess_round(): int
{
    return (int) (meta_get('drop_round') ?? '1');
}

/** Quando esce la prossima novità (timestamp Unix). */
function guess_drop_at(): int
{
    $v = meta_get('drop_at');
    return $v ? (int) strtotime($v) : guess_default_drop_at();
}

function guess_drop_passed(): bool
{
    return guess_drop_at() <= time();
}

/** Frase facoltativa dell'admin per aiutare (o depistare) i giocatori, es. "C'entra il Negozio...". */
function guess_teaser(): string
{
    return (string) (meta_get('drop_teaser') ?? '');
}

/** L'idea del giocatore per il round attuale (null se non ha ancora provato). */
function guess_mine(int $playerId): ?string
{
    $g = q('SELECT guess FROM feature_guesses WHERE player_id = ? AND round = ?', [$playerId, guess_round()])->fetchColumn();
    return $g === false ? null : (string) $g;
}

/** Scrive o aggiorna l'idea del giocatore per il round attuale. */
function guess_set(int $playerId, string $text): void
{
    q('INSERT INTO feature_guesses (player_id, round, guess) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE guess = VALUES(guess)', [$playerId, guess_round(), $text]);
}

/** Quante idee sono arrivate per il round attuale (senza dire quali: restano segrete finché l'admin non le legge). */
function guess_count(): int
{
    return (int) q('SELECT COUNT(*) FROM feature_guesses WHERE round = ?', [guess_round()])->fetchColumn();
}

/** Tutte le idee del round (per l'admin), più recenti prima. */
function guess_all(int $round): array
{
    return q('SELECT g.*, p.name FROM feature_guesses g JOIN players p ON p.id = g.player_id
              WHERE g.round = ? ORDER BY g.updated_at DESC', [$round])->fetchAll();
}

/** L'admin fissa (o sposta) data e teaser del drop attuale. */
function guess_set_drop(int $at, string $teaser): void
{
    meta_set('drop_at', date('Y-m-d H:i:s', $at));
    meta_set('drop_teaser', $teaser);
}

/** Chiude il round attuale e ne apre uno nuovo (nuova data, teaser azzerato): le vecchie idee restano in archivio. */
function guess_new_round(int $at): void
{
    meta_set('drop_round', (string) (guess_round() + 1));
    meta_set('drop_at', date('Y-m-d H:i:s', $at));
    meta_set('drop_teaser', '');
}

/*
 * Lancio del Personaggio (avatar.php): finché il countdown della Home non scade lo vede solo l'admin; alla scadenza si apre da solo
 * a tutti. La prima volta che qualcuno carica una pagina dopo la scadenza si segna il round del lancio in meta, così resta aperto
 * anche quando l'admin apre un nuovo round con una data futura. Al lancio esce solo una parte degli oggetti (lib/shop.php: shop_launch_keep).
 */
function avatar_launch_round(): ?int
{
    static $r = false;
    if ($r === false) {
        $v = meta_get('avatar_launch_round');
        if ($v === null && guess_drop_passed()) {
            $v = (string) guess_round();
            meta_set('avatar_launch_round', $v);
        }
        $r = $v === null ? null : (int) $v;
    }
    return $r;
}

/** Il Personaggio è aperto a tutti? (l'admin lo vede sempre, anche prima del lancio) */
function avatar_public(): bool
{
    return avatar_launch_round() !== null;
}

function avatar_visible(): bool
{
    return is_admin() || avatar_public();
}

/* ---------------------------------------------------------------- proposte */

/*
 * Proposte dei giocatori per il sito: un campo di testo nella card del countdown in Home. Le legge solo l'admin (admin.php).
 */
const PROPOSAL_MAX = 500;

function proposal_add(int $userId, ?int $playerId, string $text): void
{
    q('INSERT INTO proposals (user_id, player_id, body) VALUES (?, ?, ?)', [$userId, $playerId, $text]);
}

/** Quante proposte ha mandato l'utente nelle ultime 24 ore (per non riempire la tabella). */
function proposal_recent_count(int $userId): int
{
    return (int) q('SELECT COUNT(*) FROM proposals WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 DAY', [$userId])->fetchColumn();
}

/** Tutte le proposte (per l'admin), più recenti prima, con chi le ha scritte. */
function proposal_all(int $limit = 200): array
{
    return q('SELECT pr.*, u.username, p.name FROM proposals pr JOIN users u ON u.id = pr.user_id LEFT JOIN players p ON p.id = pr.player_id
              ORDER BY pr.id DESC LIMIT ' . (int) $limit)->fetchAll();
}

function proposal_unread_count(): int
{
    static $n = null;
    if ($n === null) {
        try {
            $n = (int) q('SELECT COUNT(*) FROM proposals WHERE read_at IS NULL')->fetchColumn();
        } catch (Throwable $e) {
            $n = 0;   // tabella non ancora creata (aggiornamento del database non riuscito): nessun pallino, il resto del sito funziona
        }
    }
    return $n;
}
