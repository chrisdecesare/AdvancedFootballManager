<?php
/*
 * Partita «verificata»: almeno VERIFY_MIN giocatori diversi della formazione l'hanno votata davvero (voti ai compagni, MVP o premi; i voti dati
 * d'ufficio, ratings.is_auto = 1, non contano). Come next-app/src/lib/match-verify.ts. È un indizio forte che la partita è stata giocata,
 * non una prova legale: serve a far vedere ai centri il valore dell'abbonamento e a controllare le prenotazioni.
 */
const VERIFY_MIN = 3;

function match_is_verified(int $voters): bool
{
    return $voters >= VERIFY_MIN;
}

function match_verify_label(int $voters): string
{
    return match_is_verified($voters) ? 'Verificata da ' . $voters . ' giocatori' : 'Da verificare: ' . $voters . ' voti su ' . VERIFY_MIN;
}

/** I voti veri di una partita (giocatori in formazione con squadra che hanno dato un voto, un MVP o un premio), solo se la lega gioca in un centro; altrimenti null. */
function match_verification(int $matchId, ?int $groupId): ?int
{
    if (!$groupId) {
        return null;
    }
    biz_schema_ensure();
    if (!q('SELECT 1 FROM center_groups WHERE group_id = ?', [$groupId])->fetch()) {
        return null;
    }
    return (int) q('SELECT COUNT(DISTINCT v.voter_id) FROM (
            SELECT match_id, voter_id FROM ratings WHERE is_auto = 0
            UNION SELECT match_id, voter_id FROM mvp_votes
            UNION SELECT match_id, voter_id FROM award_votes) v
        JOIN match_players mp ON mp.match_id = v.match_id AND mp.player_id = v.voter_id AND mp.team IS NOT NULL
        WHERE v.match_id = ?', [$matchId])->fetchColumn();
}
