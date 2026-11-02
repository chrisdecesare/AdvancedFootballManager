<?php
/*
 * «Campo di casa»: il collegamento di una lega a un centro sportivo registrato (tabella center_groups), gestito da chi amministra la lega
 * (come next-app/src/lib/centers.ts: centerLinkedTo, centersLinkable, centerLink, centerUnlink). Un centro si può scegliere solo se ha un piano attivo.
 */

/** Il piano del centro: 'centro_premium', 'centro_plus', 'centro' o null (Plus e Premium valgono per tutte le sedi dello stesso proprietario). */
function center_plan_of(array $c): ?string
{
    biz_schema_ensure();
    $rows = q("SELECT plan, center_id FROM billing_subscriptions WHERE user_id = ? AND plan IN ('centro', 'centro_plus', 'centro_premium')
               AND status IN ('active', 'trialing', 'past_due')", [(int) $c['owner_user_id']])->fetchAll();
    foreach (['centro_premium', 'centro_plus'] as $p) {
        foreach ($rows as $r) {
            if ($r['plan'] === $p) {
                return $p;
            }
        }
    }
    foreach ($rows as $r) {
        if ($r['plan'] === 'centro' && (int) $r['center_id'] === (int) $c['id']) {
            return 'centro';
        }
    }
    return null;
}

/** Il centro a cui è collegata una lega, anche se ora non ha un piano attivo (per gestire il collegamento). */
function center_linked_to(int $gid): ?array
{
    biz_schema_ensure();
    return q('SELECT c.* FROM center_groups cg JOIN sport_centers c ON c.id = cg.center_id WHERE cg.group_id = ?', [$gid])->fetch() ?: null;
}

/** I centri a cui una lega può collegarsi (quelli con un piano attivo), prima quelli della sua città. */
function centers_linkable(string $citySlugHint): array
{
    biz_schema_ensure();
    $out = array_values(array_filter(q('SELECT * FROM sport_centers ORDER BY name')->fetchAll(), fn($c) => center_plan_of($c) !== null));
    usort($out, fn($a, $b) => (int) ($b['city_slug'] === $citySlugHint) <=> (int) ($a['city_slug'] === $citySlugHint));
    return $out;
}

function center_link(int $centerId, int $gid, int $by): void
{
    biz_schema_ensure();
    q('INSERT INTO center_groups (center_id, group_id, linked_by) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE center_id = VALUES(center_id), linked_by = VALUES(linked_by), created_at = CURRENT_TIMESTAMP', [$centerId, $gid, $by]);
}

function center_unlink(int $centerId, int $gid): void
{
    q('DELETE FROM center_groups WHERE center_id = ? AND group_id = ?', [$centerId, $gid]);
}
