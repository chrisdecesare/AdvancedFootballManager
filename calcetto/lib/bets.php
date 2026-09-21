<?php
/*
 * Scommesse goliardiche sulle partite: si punta con gettoni finti (nessun euro), per l'onore e per sfottere gli amici.
 *
 * Come funziona (a "totalizzatore", niente quote fisse): per ogni partita e mercato i gettoni puntati finiscono in un
 * montepremi che a fine partita si divide tra chi ha indovinato, in proporzione a quanto aveva puntato. Se nessuno indovina
 * (o se manca il dato, per esempio nessuno ha votato l'MVP) tutti riprendono i propri gettoni.
 *
 * Il portafoglio non è un numero salvato ma la somma delle mosse (tabella wallet_moves): puntata, vincita, rimborso...
 * Così annullare una puntata, cancellare una partita o rifare un pagamento significa solo togliere delle mosse.
 *
 * Mercati (uno per giocatore e partita):
 *  - esito: chi vince (squadra 1, pareggio, squadra 2), si paga a fine partita;
 *  - gol: un giocatore segna almeno un gol, si paga a fine partita;
 *  - mvp: chi sarà l'MVP, si paga alla chiusura delle votazioni.
 */

const BET_START = 100;       // gettoni di benvenuto
const BET_DOLE_BELOW = 20;   // chi scende sotto questa cifra (e non ha puntate in corso)...
const BET_DOLE = 30;         // ...riceve il "sussidio" (una volta a settimana)

function bet_markets(): array
{
    return [
        'esito' => ['label' => 'Chi vince?', 'icon' => 'trophy', 'when' => 'si paga a fine partita'],
        'gol' => ['label' => 'Chi segna?', 'icon' => 'ball-football', 'when' => 'vince chi segna almeno un gol'],
        'mvp' => ['label' => 'Chi sarà l\'MVP?', 'icon' => 'star', 'when' => 'si paga alla chiusura dei voti'],
    ];
}

/* ---------------------------------------------------------------- portafoglio */

/** Gettoni disponibili (le puntate in corso sono già scalate). */
function wallet_balance(int $playerId): int
{
    return (int) q('SELECT COALESCE(SUM(delta), 0) FROM wallet_moves WHERE player_id = ?', [$playerId])->fetchColumn();
}

/** Gettoni puntati su scommesse non ancora decise. */
function wallet_in_play(int $playerId): int
{
    return (int) q("SELECT COALESCE(SUM(stake), 0) FROM bets WHERE player_id = ? AND status = 'aperta'", [$playerId])->fetchColumn();
}

/**
 * Apre il portafoglio (gettoni di benvenuto la prima volta) e dà il sussidio a chi è al verde: una volta a settimana,
 * solo se non ha nulla in gioco. Ritorna un messaggio da mostrare se è appena arrivato qualcosa.
 */
function wallet_open(int $playerId): ?string
{
    $msg = null;
    if (q("INSERT IGNORE INTO wallet_moves (player_id, delta, kind, ref) VALUES (?, ?, 'benvenuto', 'welcome')", [$playerId, BET_START])->rowCount()) {
        $msg = 'Benvenuto al banco! Ti abbiamo regalato ' . BET_START . ' gettoni: spendili male.';
    } elseif (wallet_balance($playerId) + wallet_in_play($playerId) < BET_DOLE_BELOW) {
        $ref = 'dole-' . date('o\WW');
        if (q("INSERT IGNORE INTO wallet_moves (player_id, delta, kind, ref) VALUES (?, ?, 'sussidio', ?)", [$playerId, BET_DOLE, $ref])->rowCount()) {
            $msg = 'Sei al verde: lo Stato del Calcetto ti passa il sussidio di ' . BET_DOLE . ' gettoni. Non farti riconoscere.';
        }
    }
    return $msg;
}

/** Titolo goliardico in base ai gettoni. */
function bet_title(int $balance): string
{
    foreach ([10 => 'Nullatenente', 50 => 'Squattrinato', 100 => 'Scommettitore della domenica', 200 => 'Habitué del bar sport',
              400 => 'Faccendiere', PHP_INT_MAX => 'Lupo di Wall Street'] as $limit => $title) {
        if ($balance < $limit) {
            return $title;
        }
    }
    return 'Lupo di Wall Street';
}

/** Classifica di chi ha un portafoglio: gettoni disponibili + in gioco, dal più ricco. */
function bet_leaderboard(): array
{
    return q('SELECT p.id, p.name, p.photo,
                     (SELECT COALESCE(SUM(delta), 0) FROM wallet_moves w WHERE w.player_id = p.id) AS balance,
                     (SELECT COALESCE(SUM(stake), 0) FROM bets b WHERE b.player_id = p.id AND b.status = \'aperta\') AS in_play,
                     (SELECT COUNT(*) FROM bets b WHERE b.player_id = p.id AND b.status = \'vinta\') AS wins,
                     (SELECT COUNT(*) FROM bets b WHERE b.player_id = p.id AND b.status IN (\'vinta\', \'persa\')) AS decided
              FROM players p
              WHERE EXISTS (SELECT 1 FROM wallet_moves w WHERE w.player_id = p.id) AND ' . player_scope_sql('p.id') . '
              ORDER BY (balance + in_play) DESC, p.name')->fetchAll();
}

/* ---------------------------------------------------------------- puntare */

/** Le scommesse per una partita sono aperte fino al fischio d'inizio. */
function bets_open_for(array $match): bool
{
    return $match['status'] === 'programmata' && strtotime($match['match_date']) > time();
}

/** Giocatori su cui si può puntare (gol, MVP): chi non ha già detto di non esserci. */
function bet_candidates(int $matchId): array
{
    sync_match_players($matchId);
    return array_values(array_filter(match_roster($matchId), fn($r) => $r['availability'] !== 'assente'));
}

/** Puntate di una partita e mercato, con chi le ha fatte. */
function match_bets(int $matchId): array
{
    $out = [];
    foreach (q('SELECT b.*, p.name FROM bets b JOIN players p ON p.id = b.player_id WHERE b.match_id = ? ORDER BY b.created_at', [$matchId])->fetchAll() as $b) {
        $out[$b['market']][] = $b;
    }
    return $out;
}

/** Esegue $fn in una transazione (o dentro quella già aperta). */
function bet_atomic(callable $fn)
{
    if (db()->inTransaction()) {
        return $fn();
    }
    db()->beginTransaction();
    try {
        $r = $fn();
        db()->commit();
        return $r;
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

/**
 * Fa (o rifà, sostituendo la precedente) una puntata. Ritorna il messaggio d'errore oppure null se è andata.
 */
function bet_place(array $match, int $playerId, string $market, string $pick, int $stake): ?string
{
    if (!isset(bet_markets()[$market])) {
        return 'Mercato non valido.';
    }
    if (!bets_open_for($match)) {
        return 'Le scommesse su questa partita sono chiuse: il calcio d\'inizio è passato.';
    }
    if ($market === 'esito') {
        if (!in_array($pick, ['A', 'X', 'B'], true)) {
            return 'Scegli chi vince.';
        }
    } else {
        $ok = array_filter(bet_candidates((int) $match['id']), fn($r) => (string) $r['player_id'] === $pick);
        if (!$ok) {
            return 'Scegli un giocatore della partita.';
        }
    }
    if ($stake < 1) {
        return 'Punta almeno 1 gettone.';
    }
    return bet_atomic(function () use ($match, $playerId, $market, $pick, $stake) {
        q('SELECT id FROM players WHERE id = ? FOR UPDATE', [$playerId]);   // due puntate insieme non possono spendere due volte gli stessi gettoni
        $old = q("SELECT id, stake FROM bets WHERE match_id = ? AND player_id = ? AND market = ? AND status = 'aperta'",
            [$match['id'], $playerId, $market])->fetch();
        $available = wallet_balance($playerId) + ($old ? (int) $old['stake'] : 0);
        if ($stake > $available) {
            return 'Non hai abbastanza gettoni: te ne restano ' . $available . '.';
        }
        if ($old) {
            q('DELETE FROM bets WHERE id = ?', [$old['id']]);   // le sue mosse spariscono con lei (rimborso)
        }
        q('INSERT INTO bets (match_id, player_id, market, pick, stake) VALUES (?, ?, ?, ?, ?)', [$match['id'], $playerId, $market, $pick, $stake]);
        q("INSERT INTO wallet_moves (player_id, bet_id, delta, kind) VALUES (?, ?, ?, 'puntata')", [$playerId, db()->lastInsertId(), -$stake]);
        return null;
    });
}

/** Ritira una puntata prima del fischio d'inizio (i gettoni tornano). Ritorna il messaggio d'errore oppure null. */
function bet_cancel(array $match, int $playerId, string $market): ?string
{
    if (!bets_open_for($match)) {
        return 'Ormai è tardi: le scommesse sono chiuse.';
    }
    q("DELETE FROM bets WHERE match_id = ? AND player_id = ? AND market = ? AND status = 'aperta'", [$match['id'], $playerId, $market]);
    return null;
}

/* ---------------------------------------------------------------- pagare */

/**
 * Cosa ha vinto in un mercato: elenco delle scelte vincenti (anche vuoto), null se ancora non si può decidere.
 * @return string[]|null
 */
function bet_winning_picks(array $match, string $market): ?array
{
    $id = (int) $match['id'];
    if ($match['status'] !== 'giocata') {
        return null;
    }
    if ($market === 'esito') {
        if ($match['score_a'] === null || $match['score_b'] === null) {
            return null;
        }
        return [(int) $match['score_a'] > (int) $match['score_b'] ? 'A' : ((int) $match['score_a'] < (int) $match['score_b'] ? 'B' : 'X')];
    }
    if ($market === 'gol') {
        return array_map('strval', q('SELECT player_id FROM match_players WHERE match_id = ? AND goals > 0', [$id])->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($market === 'mvp') {
        if ($match['voting_open']) {
            return null;   // si decide alla chiusura delle votazioni
        }
        $mvp = match_mvp($id);
        return $mvp ? [(string) $mvp] : [];
    }
    return null;
}

/** Paga le puntate ancora aperte dei mercati indicati (quelli già decisi restano com'è). Si può richiamare senza danni. */
function bets_settle(int $matchId, array $markets = ['esito', 'gol', 'mvp']): void
{
    $match = get_match($matchId);
    if (!$match) {
        return;
    }
    foreach ($markets as $market) {
        $win = bet_winning_picks($match, $market);
        if ($win === null) {
            continue;
        }
        bet_atomic(function () use ($matchId, $market, $win) {
            $bets = q("SELECT * FROM bets WHERE match_id = ? AND market = ? AND status = 'aperta' FOR UPDATE", [$matchId, $market])->fetchAll();
            if (!$bets) {
                return;
            }
            $pool = array_sum(array_column($bets, 'stake'));
            $winners = array_filter($bets, fn($b) => in_array($b['pick'], $win, true));
            $winStake = array_sum(array_column($winners, 'stake'));
            foreach ($bets as $b) {
                if (!$winners) {
                    [$status, $pay, $kind] = ['rimborsata', (int) $b['stake'], 'rimborso'];
                } elseif (in_array($b['pick'], $win, true)) {
                    [$status, $pay, $kind] = ['vinta', intdiv($pool * (int) $b['stake'], $winStake), 'vincita'];
                } else {
                    [$status, $pay, $kind] = ['persa', 0, null];
                }
                q('UPDATE bets SET status = ?, payout = ?, settled_at = NOW() WHERE id = ?', [$status, $pay, $b['id']]);
                if ($kind) {
                    q('INSERT INTO wallet_moves (player_id, bet_id, delta, kind) VALUES (?, ?, ?, ?)', [$b['player_id'], $b['id'], $pay, $kind]);
                }
            }
        });
    }
}

/** Annulla i pagamenti dei mercati indicati (le puntate tornano aperte): serve se la partita o i voti vengono riaperti. */
function bets_unsettle(int $matchId, array $markets = ['esito', 'gol', 'mvp']): void
{
    $in = implode(',', array_fill(0, count($markets), '?'));
    bet_atomic(function () use ($matchId, $markets, $in) {
        q("DELETE w FROM wallet_moves w JOIN bets b ON b.id = w.bet_id
           WHERE b.match_id = ? AND b.market IN ($in) AND w.kind IN ('vincita', 'rimborso')", array_merge([$matchId], $markets));
        q("UPDATE bets SET status = 'aperta', payout = 0, settled_at = NULL WHERE match_id = ? AND market IN ($in) AND status <> 'aperta'",
            array_merge([$matchId], $markets));
    });
}

/** Il risultato è stato salvato o corretto: rifà i pagamenti di "chi vince" e "chi segna". */
function bets_resettle_result(int $matchId): void
{
    bet_atomic(function () use ($matchId) {
        bets_unsettle($matchId, ['esito', 'gol']);
        bets_settle($matchId, ['esito', 'gol']);
    });
}

/* ---------------------------------------------------------------- parole */

/** "Blu", "Pareggio", "Arancio" o il nome del giocatore, a partire dalla scelta salvata. */
function bet_pick_label(array $match, string $market, string $pick, array $names = []): string
{
    if ($market === 'esito') {
        return $pick === 'X' ? 'Pareggio' : team_name($pick, $match);
    }
    return $names[(int) $pick] ?? (get_player((int) $pick)['name'] ?? '?');
}
