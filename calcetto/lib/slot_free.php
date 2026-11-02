<?php
/*
 * Liberare un orario prenotato in un centro (come next-app/src/lib/slot-free.ts). Succede quando la partita prenotata viene eliminata, annullata o
 * spostata (altro campo, altro orario): l'orario torna libero nel calendario del centro e il gestore riceve un'email che lo dice. Un capitano non può
 * prenotare e disdire a raffica: tetto di disdette al giorno. L'admin del sito e il proprietario del centro non hanno tetti.
 */
const SLOT_FREE_PER_DAY = 5;

const SLOT_FREE_WHY = [
    'eliminata' => 'la partita è stata eliminata',
    'annullata' => 'la partita è stata annullata',
    'campo' => 'la lega ha cambiato il campo della partita',
    'orario' => 'la lega ha cambiato l\'orario della partita',
];

/** L'email al gestore: quale orario è tornato libero, di quale lega e perché. Senza dati personali dei giocatori. */
function slot_free_mail(string $centerName, string $leagueName, int $field, string $when, string $reason, string $link): array
{
    return [
        $centerName . ': orario di nuovo libero (' . $when . ')',
        implode("\n", ['Ciao,', '',
            'la prenotazione della lega «' . $leagueName . '» per ' . $when . ' (campo ' . $field . ') non c\'è più: ' . (SLOT_FREE_WHY[$reason] ?? SLOT_FREE_WHY['annullata']) . '.',
            'L\'orario è di nuovo libero nel tuo calendario.', '', 'Calendario: ' . $link, '', '— Calcetto Manager']),
    ];
}

function slot_of_match(int $matchId): ?array
{
    biz_schema_ensure();
    return q('SELECT s.id, s.center_id, s.field_no, s.starts_at, g.name AS group_name FROM center_slots s LEFT JOIN squad_groups g ON g.id = s.booked_group_id WHERE s.match_id = ?', [$matchId])->fetch() ?: null;
}

/**
 * Prima di un'azione che libererebbe un orario (elimina, annulla, cambia campo o orario): se la partita ha un orario prenotato e chi agisce ha già
 * disdetto troppe volte oggi, ritorna il messaggio che blocca l'azione; altrimenti conta questa disdetta e ritorna null.
 */
function slot_free_guard(int $matchId): ?string
{
    $s = slot_of_match($matchId);
    $u = current_user();
    if (!$s || !$u || is_admin()) {
        return null;
    }
    $owner = q('SELECT owner_user_id FROM sport_centers WHERE id = ?', [$s['center_id']])->fetchColumn();
    if ((int) $owner === (int) $u['id']) {
        return null;
    }
    if (!rate_hit('slot_free_' . (int) $u['id'], SLOT_FREE_PER_DAY, 86400)) {
        return 'Hai già disdetto ' . SLOT_FREE_PER_DAY . ' prenotazioni oggi: per non lasciare il centro con orari in aria, riprova domani o scrivi al gestore.';
    }
    return null;
}

/** L'orario prenotato per quella partita torna libero e il gestore del centro lo sa. Ritorna true se c'era un orario da liberare. */
function slot_free_for_match(int $matchId, string $reason): bool
{
    $s = slot_of_match($matchId);
    if (!$s) {
        return false;
    }
    $st = q('UPDATE center_slots SET booked_group_id = NULL, match_id = NULL, booked_by = NULL, booked_at = NULL WHERE id = ? AND match_id = ?', [$s['id'], $matchId]);
    if (!$st->rowCount()) {
        return false;
    }
    try {
        $c = q('SELECT * FROM sport_centers WHERE id = ?', [$s['center_id']])->fetch();
        $to = $c ? ($c['email'] ?: q('SELECT email FROM users WHERE id = ?', [$c['owner_user_id']])->fetchColumn()) : null;
        if ($c && $to) {
            $when = fmt_date_long($s['starts_at']) . ' alle ' . fmt_time($s['starts_at']);
            [$subject, $body] = slot_free_mail($c['name'], (string) ($s['group_name'] ?? 'una lega'), (int) $s['field_no'], mb_strtoupper(mb_substr($when, 0, 1)) . mb_substr($when, 1), $reason,
                NEXT_SITE . '/centro/calendario?c=' . (int) $c['id']);
            send_mail((string) $to, $subject, $body);
        }
    } catch (Throwable $e) {
        error_log('email al centro per l\'orario liberato: ' . $e->getMessage());   // l'orario è già libero: l'email è un avviso in più
    }
    return true;
}
