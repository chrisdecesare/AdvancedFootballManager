<?php
/*
 * "Indovina la funzionalità": una sezione segreta (guess.php, non in menu, si arriva dalla card del countdown in Home) dove
 * ogni giocatore scrive, in un campo di testo, la sua idea su quale sarà la prossima novità del sito. Un round alla volta,
 * un'idea sola a testa (la può cambiare finché non scade). Quando arriva l'uscita, l'admin legge le idee (admin.php) e
 * regala KOIN a chi si è avvicinato di più: un premio goliardico, non una scommessa vera con quote.
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

/*
 * Rivelazione: a un'ora scelta dall'admin (prima dell'uscita) la card in Home smette di essere «top secret» e dice cos'è la novità,
 * con il countdown che continua fino all'uscita. Da quel momento non si mandano più idee: il round è chiuso.
 */
function guess_reveal_at(): ?int
{
    $v = meta_get('drop_reveal_at');
    return $v ? (int) strtotime($v) : null;
}

/** Cosa si svela (es. «È il Personaggio: ...»); vuoto = nessuna rivelazione. */
function guess_reveal_text(): string
{
    return (string) (meta_get('drop_reveal') ?? '');
}

/** La novità è già stata svelata (ma magari non è ancora uscita)? */
function guess_revealed(): bool
{
    $at = guess_reveal_at();
    return guess_reveal_text() !== '' && $at !== null && $at <= time();
}

/** Round chiuso: niente più idee, perché la novità è uscita o è stata svelata. */
function guess_closed(): bool
{
    return guess_drop_passed() || guess_revealed();
}

function guess_set_reveal(?int $at, string $text): void
{
    meta_set('drop_reveal_at', $at ? date('Y-m-d H:i:s', $at) : '');
    meta_set('drop_reveal', $text);
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
    guess_set_reveal(null, '');
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

/* ---------------------------------------------------------------- regali di KOIN dell'admin */

/*
 * Quando l'admin regala KOIN (Admin → Regala KOIN, o il premio di «Indovina la funzionalità») il giocatore riceve una notifica push
 * e, alla prima pagina che apre, una sovraimpressione col KOIN (layout.php) come la spunta dei voti. players.gift_seen_id ricorda
 * fin dove li ha già visti.
 */
/** Push al giocatore premiato (a fine richiesta, per non rallentare la pagina dell'admin). */
function coin_gift_notify(int $playerId, int $amount, string $why = ''): void
{
    push_defer(function () use ($playerId, $amount, $why) {
        $users = array_values(push_users_of_players([$playerId]));
        if ($users) {
            push_notify_users($users, [
                'title' => 'Hai ricevuto ' . $amount . ' KOIN!',
                'body' => 'L\'admin ti ha regalato ' . $amount . ' KOIN' . ($why !== '' ? ' ' . $why : '') . '.',
                'url' => 'bets.php', 'tag' => 'gift-' . $playerId . '-' . time(),
            ], 'normal', 'regalo');
        }
    });
}

/** Regali non ancora mostrati al giocatore: [totale, quanti, ultimo id, uno è un premio di «Indovina»?]. Null se non ce ne sono. */
function coin_gifts_unseen(int $playerId): ?array
{
    try {
        $r = q('SELECT COALESCE(SUM(w.delta), 0) AS tot, COUNT(*) AS n, MAX(w.id) AS last, MAX(w.kind = \'premio\') AS guess
                FROM wallet_moves w JOIN players p ON p.id = w.player_id
                WHERE w.player_id = ? AND w.id > p.gift_seen_id AND w.delta > 0
                  AND (w.kind = \'regalo\' OR (w.kind = \'premio\' AND w.ref LIKE \'guess-%\'))',
            [$playerId])->fetch();
    } catch (Throwable $e) {
        return null;   // colonna non ancora creata
    }
    return $r && (int) $r['n'] > 0 ? [(int) $r['tot'], (int) $r['n'], (int) $r['last'], (bool) $r['guess']] : null;
}

/** Ha dei crediti del Personaggio regalati e non ha ancora visto il messaggio «Il primo giro lo offro io»? Ritorna l'importo, altrimenti null. */
function credits_intro_unseen(int $playerId): ?int
{
    try {
        $amount = q("SELECT w.delta FROM wallet_moves w JOIN players p ON p.id = w.player_id
                     WHERE w.player_id = ? AND w.ref = 'gift-shop-1000' AND w.shop_only = 1 AND p.credits_seen = 0 LIMIT 1", [$playerId])->fetchColumn();
    } catch (Throwable $e) {
        return null;   // colonne non ancora create
    }
    return $amount ? (int) $amount : null;
}

function coin_gifts_seen(int $playerId, int $lastId): void
{
    q('UPDATE players SET gift_seen_id = GREATEST(gift_seen_id, ?) WHERE id = ?', [$lastId, $playerId]);
}
