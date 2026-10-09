<?php
/*
 * La Gazzetta del mercoledì: il "giornale" della lega, uno slider a pagine (Home, in basso a destra, e gazzetta.php).
 * Ogni mercoledì esce l'edizione nuova: dalle GAZZETTA_PUSH_HOUR arriva la notifica a tutti i giocatori della lega (una volta per
 * lega ed edizione, gazzetta_push_due). Le pagine si calcolano al momento, così chi la apre il giovedì vede i dati di adesso:
 *  - Prima pagina: la prossima partita, quanti sono confermati e i titoli delle altre pagine;
 *  - Infermeria: chi è segnato infortunato nel profilo e chi si è fatto male in partita nelle ultime 2 settimane;
 *  - Novità: l'ultima partita (risultato, MVP, bomber), i nuovi arrivati nella lega e gli oggetti usciti nel Negozio;
 *  - Mercato KOIN: i passaggi di KOIN tra giocatori della settimana (lib/passaggi.php);
 *  - Posti vacanti: quanti posti mancano per la prossima partita (la misura tipica della lega) e chi non ha ancora risposto;
 *  - Probabili formazioni: se le squadre non ci sono ancora, le prova il bilanciamento (lib/balance.php) con rating e intesa
 *    (lib/chemistry.php) tra i confermati, completati da chi non ha risposto ma gioca più spesso («in dubbio»);
 *  - Turnover: chi entra e chi esce rispetto all'ultima partita, e perché (infortunio, assente, non ha risposto).
 */
const GAZZETTA_WEEKDAY = 3;       // mercoledì (date('N'))
const GAZZETTA_PUSH_HOUR = 10;    // la notifica dell'edizione parte dalle 10
const GAZZETTA_DAYS = 7;          // novità e passaggi della settimana
const GAZZETTA_INJURY_DAYS = 14;  // infortuni in partita delle ultime 2 settimane
const GAZZETTA_SIZE = 10;         // posti a partita se la lega non ha ancora uno storico

/** Data dell'ultima edizione (Y-m-d): oggi se è mercoledì, altrimenti il mercoledì passato. */
function gazzetta_edition(): string
{
    $back = ((int) date('N') - GAZZETTA_WEEKDAY + 7) % 7;
    return date('Y-m-d', strtotime('-' . $back . ' days'));
}

/** La lega di cui mostrare la Gazzetta a chi guarda: quella scelta in alto, altrimenti quella della prossima partita (o dell'ultima). */
function gazzetta_group(): ?int
{
    if ($f = group_filter()) {
        return $f;
    }
    if ($m = next_match() ?: last_played_match()) {
        return (int) $m['group_id'];
    }
    $s = scope_ids();
    return $s ? (int) $s[0] : null;
}

/** Posti tipici di una partita della lega: i moduli scelti per la prossima, altrimenti la mediana delle ultime 5 giocate. */
function gazzetta_size(int $gid, ?array $next): int
{
    if ($next && parse_formation($next['formation_a']) && parse_formation($next['formation_b'])) {
        return formation_size($next['formation_a']) + formation_size($next['formation_b']);
    }
    $ns = q("SELECT COUNT(mp.player_id) FROM matches m JOIN match_players mp ON mp.match_id = m.id AND mp.team IS NOT NULL
             WHERE m.group_id = ? AND m.status = 'giocata' GROUP BY m.id ORDER BY m.match_date DESC LIMIT 5", [$gid])->fetchAll(PDO::FETCH_COLUMN);
    if (!$ns) {
        return GAZZETTA_SIZE;
    }
    sort($ns);
    return max(2, (int) $ns[intdiv(count($ns), 2)]);
}

/**
 * Probabili squadre per la Gazzetta: il bilanciamento con rating e intesa, sempre la soluzione migliore (variety 1) così la pagina
 * non cambia a ogni visita. Con tanti giocatori il calcolo pesa, quindi si tiene in meta (una riga per partita) finché non cambiano
 * i giocatori del pool o le statistiche.
 */
function gazzetta_balance(array $match, array $pool, array $stats, int $gid): array
{
    $ids = array_map(fn($r) => (int) $r['player_id'], $pool);
    sort($ids);
    $hash = md5(implode(',', $ids) . '|' . stats_version() . '|' . $match['formation_a'] . '|' . $match['formation_b']);
    $key = 'gzb_' . (int) $match['id'];
    $saved = json_decode((string) meta_get($key), true);
    if (is_array($saved) && ($saved['h'] ?? '') === $hash) {
        return ['A' => $saved['A'], 'B' => $saved['B']];
    }
    @set_time_limit(90);
    $chem = chemistry([$gid]);
    $bal = array_map(fn($r) => ['id' => (int) $r['player_id'], 'prefs' => player_prefs($r), 'rating' => $stats[(int) $r['player_id']]['ovr'] ?? 6], $pool);
    $res = balance_teams($bal, 1, $match, chemistry_pairs_for($chem, array_column($bal, 'id')));
    $teams = ['A' => array_map('intval', $res['A']), 'B' => array_map('intval', $res['B'])];
    $json = json_encode(['h' => $hash] + $teams);
    if (strlen($json) <= 255) {
        meta_set($key, $json);
    }
    return $teams;
}

/** «1 giocatore» / «3 giocatori» */
function gz_n(int $n, string $one, string $many): string
{
    return $n . ' ' . ($n === 1 ? $one : $many);
}

/**
 * L'edizione della Gazzetta per una lega: ['group' => id, 'edition' => Y-m-d, 'match' => prossima partita o null, 'slides' => [...]].
 * Ogni pagina: ['kind', 'kicker', 'icon', 'title', 'lead', 'items' => [['pid', 'name', 'text', 'tone']], 'teams' => null | [...]].
 */
function gazzetta(int $gid): array
{
    $now = time();
    $since = date('Y-m-d H:i:s', $now - GAZZETTA_DAYS * 86400);
    $next = q("SELECT * FROM matches WHERE group_id = ? AND status = 'programmata' AND match_date >= ? ORDER BY match_date LIMIT 1",
        [$gid, date('Y-m-d H:i:s', $now - 3 * 3600)])->fetch() ?: null;
    $last = q("SELECT * FROM matches WHERE group_id = ? AND status = 'giocata' ORDER BY match_date DESC, id DESC LIMIT 1", [$gid])->fetch() ?: null;
    $stats = compute_stats([$gid]);
    $people = [];   // giocatori attivi della lega (non ospiti): id => riga
    foreach (q('SELECT p.* FROM players p JOIN player_groups pg ON pg.player_id = p.id WHERE pg.group_id = ? AND p.is_guest = 0 AND p.active = 1 ORDER BY p.name', [$gid])->fetchAll() as $p) {
        $people[(int) $p['id']] = $p;
    }
    $item = fn(?int $pid, string $name, string $text = '', string $tone = '') => ['pid' => $pid, 'name' => $name, 'text' => $text, 'tone' => $tone];
    $slides = [];

    /* ---- infermeria */
    $inj = [];
    foreach ($people as $pid => $p) {
        if (!empty($p['injured'])) {
            $inj[$pid] = $item($pid, $p['name'], 'ai box: salta le prossime partite', 'bad');
        }
    }
    foreach (q("SELECT e.player_id, e.note, m.match_date, p.name FROM match_events e JOIN matches m ON m.id = e.match_id JOIN players p ON p.id = e.player_id
                WHERE e.kind = 'infortunio' AND m.group_id = ? AND m.status <> 'annullata' AND m.match_date >= ? ORDER BY m.match_date",
        [$gid, date('Y-m-d H:i:s', $now - GAZZETTA_INJURY_DAYS * 86400)])->fetchAll() as $r) {
        $pid = (int) $r['player_id'];
        $what = 'si è fatto male ' . fmt_date_long($r['match_date']) . ($r['note'] ? ' (' . $r['note'] . ')' : '');
        $inj[$pid] = isset($inj[$pid]) && $inj[$pid]['tone'] === 'bad'
            ? $item($pid, $r['name'], $what . ': ai box', 'bad')
            : $item($pid, $r['name'], $what, 'purple');
    }
    $nInj = count($inj);
    $slides['infermeria'] = ['kind' => 'infermeria', 'kicker' => 'Infermeria', 'icon' => 'first-aid-kit',
        'title' => $nInj ? ($nInj === 1 ? 'Un giocatore in infermeria' : $nInj . ' giocatori in infermeria') : 'Infermeria vuota',
        'lead' => $nInj ? 'Gli acciacchi della settimana: forza e rimettetevi presto!' : 'Nessun infortunio: tutti arruolabili.',
        'items' => array_values($inj), 'teams' => null];

    /* ---- novità */
    $news = [];
    if ($last && strtotime($last['match_date']) >= $now - GAZZETTA_DAYS * 86400) {
        $news[] = $item(null, 'Ultima partita', team_name('A', $last) . ' ' . (int) $last['score_a'] . '–' . (int) $last['score_b'] . ' ' . team_name('B', $last)
            . ' (' . fmt_date_long($last['match_date']) . ')');
        if (!$last['voting_open'] && ($mvp = match_mvp((int) $last['id'])) && ($mp = get_player($mvp))) {
            $news[] = $item($mvp, $mp['name'], 'MVP dell\'ultima partita', 'gold');
        }
        $top = q('SELECT mp.player_id, mp.goals, p.name FROM match_players mp JOIN players p ON p.id = mp.player_id
                  WHERE mp.match_id = ? AND mp.goals > 0 ORDER BY mp.goals DESC, p.name LIMIT 1', [$last['id']])->fetch();
        if ($top) {
            $news[] = $item((int) $top['player_id'], $top['name'], 'bomber di giornata con ' . gz_n((int) $top['goals'], 'gol', 'gol'), 'ok');
        }
    }
    foreach ($people as $pid => $p) {
        if ($p['created_at'] >= $since) {
            $news[] = $item($pid, $p['name'], 'nuovo acquisto: benvenuto in rosa!', 'ok');
        }
    }
    $drops = (int) q('SELECT COUNT(*) FROM shop_releases WHERE release_at >= ? AND release_at <= ?', [$since, date('Y-m-d H:i:s', $now)])->fetchColumn();
    if ($drops) {
        $news[] = $item(null, 'Negozio', gz_n($drops, 'oggetto nuovo uscito', 'oggetti nuovi usciti') . ' questa settimana');
    }
    $slides['novita'] = ['kind' => 'novita', 'kicker' => 'Novità', 'icon' => 'news',
        'title' => $news ? 'Le notizie della settimana' : 'Settimana tranquilla',
        'lead' => $news ? 'Cosa è successo negli ultimi ' . GAZZETTA_DAYS . ' giorni.' : 'Niente di nuovo sotto il sole: la notizia la fate voi giovedì.',
        'items' => $news, 'teams' => null];

    /* ---- mercato KOIN */
    $passes = koin_passes_since(eco_of_group($gid), $since, 12);
    $moved = array_sum(array_column($passes, 'amount'));
    $slides['mercato'] = ['kind' => 'mercato', 'kicker' => 'Mercato KOIN', 'icon' => 'arrows-exchange',
        'title' => $passes ? gz_n(count($passes), 'passaggio', 'passaggi') . ', ' . $moved . ' KOIN di mano' : 'Mercato fermo',
        'lead' => $passes ? 'Chi ha passato KOIN a chi in settimana.' : 'Nessun passaggio di KOIN in settimana: tutti tirchi? Si passano da Scommesse.',
        'items' => array_map(fn($x) => $item((int) $x['to_id'], ($x['from'] ?? '?') . ' → ' . $x['to'],
            $x['amount'] . ' KOIN' . ($x['note'] ? ' «' . $x['note'] . '»' : ''), 'gold'), $passes), 'teams' => null];

    /* ---- prossima partita: posti vacanti, probabili formazioni, turnover */
    if ($next) {
        sync_match_players((int) $next['id']);
        $roster = match_roster((int) $next['id']);
        $size = gazzetta_size($gid, $next);
        $conf = array_values(array_filter($roster, fn($r) => $r['availability'] === 'confermato'));
        $wait = array_values(array_filter($roster, fn($r) => $r['availability'] === 'in_attesa' && isset($people[(int) $r['player_id']])
            && empty($people[(int) $r['player_id']]['injured'])));
        $free = max(0, $size - count($conf));
        $hurt = array_filter($inj, fn($x) => $x['tone'] === 'purple');   // si è fatto male di recente in partita: non lo si mette nelle probabili
        $slides['posti'] = ['kind' => 'posti', 'kicker' => 'Posti vacanti', 'icon' => 'user-plus',
            'title' => $free ? 'Cercasi ' . gz_n($free, 'giocatore', 'giocatori') : (count($conf) > $size ? 'Siete in ' . count($conf) . ': si gioca larghi' : 'Rosa al completo'),
            'lead' => count($conf) . ' confermati su ' . $size . ' posti · ' . ucfirst(push_when($next['match_date'])) . ($next['location'] ? ' · ' . $next['location'] : ''),
            'items' => array_map(fn($r) => $item((int) $r['player_id'], $r['name'], 'non ha ancora risposto' . (isset($hurt[(int) $r['player_id']]) ? ' (acciaccato)' : ''),
                isset($hurt[(int) $r['player_id']]) ? 'purple' : 'warn'), $wait), 'teams' => null];

        // pool delle probabili formazioni: le squadre già fatte, oppure confermati + chi gioca più spesso tra chi non ha risposto
        $official = (bool) array_filter($roster, fn($r) => $r['team']);
        $doubt = [];
        if ($official) {
            $pool = array_values(array_filter($roster, fn($r) => $r['team']));
            $teams = ['A' => array_column(array_filter($pool, fn($r) => $r['team'] === 'A'), 'player_id'),
                      'B' => array_column(array_filter($pool, fn($r) => $r['team'] === 'B'), 'player_id')];
        } else {
            $pool = $conf;
            usort($wait, fn($x, $y) => ($stats[(int) $y['player_id']]['participation_pct'] ?? 0) <=> ($stats[(int) $x['player_id']]['participation_pct'] ?? 0));
            foreach ($wait as $r) {
                if (count($pool) >= $size) {
                    break;
                }
                if (isset($hurt[(int) $r['player_id']])) {
                    continue;
                }
                $pool[] = $r;
                $doubt[(int) $r['player_id']] = true;
            }
            $teams = null;
            if (count($pool) >= 2) {
                $teams = gazzetta_balance($next, $pool, $stats, $gid);
            }
        }
        if ($teams) {
            $chemAll ??= chemistry([$gid]);
            $nameOf = array_column($pool, 'name', 'player_id');
            $tv = [];
            foreach (['A', 'B'] as $t) {
                $tc = team_chemistry($chemAll, $teams[$t]);
                $best = $tc['pairs'][0] ?? null;
                $tv[$t] = ['name' => team_name($t, $next),
                    'players' => array_map(fn($pid) => ['pid' => (int) $pid, 'name' => $nameOf[$pid] ?? '?', 'doubt' => isset($doubt[(int) $pid])], $teams[$t]),
                    'strength' => array_sum(array_map(fn($pid) => (float) ($stats[(int) $pid]['ovr'] ?? 6), $teams[$t])) + $tc['sum'],
                    'pair' => $best && $best['score'] > 0 ? ($nameOf[$best['a']] ?? '?') . ' + ' . ($nameOf[$best['b']] ?? '?') : null];
            }
            $slides['formazioni'] = ['kind' => 'formazioni', 'kicker' => $official ? 'Formazioni ufficiali' : 'Probabili formazioni', 'icon' => 'layout-grid',
                'title' => $tv['A']['name'] . ' – ' . $tv['B']['name'],
                'lead' => $official ? 'Le squadre sono già fatte: eccole.' : 'Bilanciate su rating e intesa' . ($doubt ? ' (in corsivo chi non ha ancora risposto: in dubbio)' : '') . '.',
                'items' => [], 'teams' => $tv];
        }

        // turnover rispetto all'ultima partita
        if ($last) {
            $before = array_map('intval', q('SELECT player_id FROM match_players WHERE match_id = ? AND team IS NOT NULL', [$last['id']])->fetchAll(PDO::FETCH_COLUMN));
            $nowIds = array_map(fn($r) => (int) $r['player_id'], $pool);
            $byId = [];
            foreach ($roster as $r) {
                $byId[(int) $r['player_id']] = $r;
            }
            $tItems = [];
            foreach (array_diff($nowIds, $before) as $pid) {
                $tItems[] = $item($pid, $byId[$pid]['name'] ?? '?', isset($doubt[$pid]) ? 'entra (in dubbio)' : 'entra', 'ok');
            }
            foreach (array_diff($before, $nowIds) as $pid) {
                $p = $people[$pid] ?? get_player($pid);
                $av = $byId[$pid]['availability'] ?? null;
                $why = !empty($p['injured']) ? 'infortunato' : ($av === 'assente' ? 'non ci sarà' : ($av === 'in_attesa' ? 'non ha ancora risposto' : 'fuori lista'));
                $tItems[] = $item($pid, (string) ($p['name'] ?? '?'), 'esce: ' . $why, 'bad');
            }
            $stay = count(array_intersect($nowIds, $before));
            $changes = count($tItems);
            $slides['turnover'] = ['kind' => 'turnover', 'kicker' => 'Turnover', 'icon' => 'refresh',
                'title' => $changes ? gz_n($changes, 'cambio', 'cambi') . ' rispetto all\'ultima' : 'Squadra che vince non si cambia',
                'lead' => gz_n($stay, 'giocatore confermato', 'giocatori confermati') . ' dall\'ultima partita (' . fmt_date_long($last['match_date']) . ').',
                'items' => $tItems, 'teams' => null];
        }
    }

    /* ---- prima pagina */
    $heads = [];
    foreach ($slides as $s) {
        $heads[] = $item(null, $s['kicker'], $s['title']);
    }
    $cover = ['kind' => 'cover', 'kicker' => 'Prima pagina', 'icon' => 'news',
        'title' => $next ? 'Si gioca ' . push_when($next['match_date']) : 'Nessuna partita in calendario',
        'lead' => $next ? (($next['location'] ? $next['location'] . ' · ' : '') . count(array_filter(match_roster((int) $next['id']), fn($r) => $r['availability'] === 'confermato')) . ' confermati finora.')
            : 'Settimana di riposo: ne approfittiamo per le chiacchiere.',
        'items' => $heads, 'teams' => null];
    $order = ['infermeria', 'posti', 'formazioni', 'turnover', 'novita', 'mercato'];
    $out = [$cover];
    foreach ($order as $k) {
        if (isset($slides[$k])) {
            $out[] = $slides[$k];
        }
    }
    return ['group' => $gid, 'edition' => gazzetta_edition(), 'match' => $next, 'slides' => $out];
}

/** HTML della Gazzetta: testata e slider a pagine (stesso slider dei momenti salienti, assets/app.js). */
function gazzetta_html(array $g, bool $page = false): string
{
    $slides = $g['slides'];
    ob_start(); ?>
<section class="card gazzetta<?= $page ? ' gazzetta-page' : '' ?>" id="gazzetta">
  <header class="gz-mast">
    <span class="gz-ed"><?= h(ucfirst(fmt_date_long($g['edition']))) ?></span>
    <span class="gz-title">La Gazzetta del Calcetto</span>
    <span class="gz-sub"><?= h(group_name((int) $g['group'])) ?> · esce ogni mercoledì<?php if (!$page): ?> · <a class="link" href="gazzetta.php?g=<?= (int) $g['group'] ?>">Sfoglia</a><?php endif; ?></span>
  </header>
  <div class="slider" data-slider>
    <div class="slides" data-slides>
      <?php foreach ($slides as $s): ?>
      <article class="slide gz-slide gz-<?= h($s['kind']) ?>">
        <span class="gz-kicker"><i class="ti ti-<?= h($s['icon']) ?>"></i> <?= h($s['kicker']) ?></span>
        <h3 class="gz-head"><?= h($s['title']) ?></h3>
        <p class="gz-lead"><?= h($s['lead']) ?></p>
        <?php if ($s['teams']): ?>
          <div class="gz-teams">
            <?php foreach (['A', 'B'] as $t): $tm = $s['teams'][$t]; ?>
              <div class="gz-team team-<?= strtolower($t) ?>">
                <strong><span class="team-dot team-<?= strtolower($t) ?>"></span><?= h($tm['name']) ?> <span class="muted small">forza <?= fmt_num($tm['strength'], 1) ?></span></strong>
                <ol><?php foreach ($tm['players'] as $p): ?><li class="<?= $p['doubt'] ? 'gz-doubt' : '' ?>"><a href="player.php?id=<?= $p['pid'] ?>"><?= h($p['name']) ?></a><?= $p['doubt'] ? ' ?' : '' ?></li><?php endforeach; ?></ol>
                <?php if ($tm['pair']): ?><span class="gz-pair"><i class="ti ti-heart-handshake"></i> Coppia d'oro: <?= h($tm['pair']) ?></span><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php elseif ($s['items']): ?>
          <ul class="gz-items">
            <?php foreach ($s['items'] as $it): ?>
              <li class="<?= $it['tone'] ? 'gz-' . h($it['tone']) : '' ?>"><b><?= $it['pid'] ? '<a href="player.php?id=' . (int) $it['pid'] . '">' . h($it['name']) . '</a>' : h($it['name']) ?></b><?= $it['text'] !== '' ? ' <span>' . h($it['text']) . '</span>' : '' ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
    <?php if (count($slides) > 1): ?>
      <div class="slider-nav">
        <button type="button" class="icon-btn" data-prev aria-label="Pagina precedente"><i class="ti ti-chevron-left"></i></button>
        <div class="dots" data-dots><?php foreach ($slides as $i => $s): ?><button type="button" class="dot" aria-label="<?= h($s['kicker']) ?>"></button><?php endforeach; ?></div>
        <button type="button" class="icon-btn" data-next aria-label="Pagina successiva"><i class="ti ti-chevron-right"></i></button>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php
    return (string) ob_get_clean();
}

/**
 * Il mercoledì, dalle GAZZETTA_PUSH_HOUR: notifica dell'edizione nuova ai giocatori di ogni lega che ha una partita in programma
 * o ne ha giocata una nell'ultimo mese (le leghe ferme non si disturbano). Una volta per lega ed edizione (segnaposto in meta).
 * La chiama push_run_due (cron o al volo mentre qualcuno usa il sito). @return int notifiche messe in coda
 */
function gazzetta_push_due(): int
{
    if ((int) date('N') !== GAZZETTA_WEEKDAY || (int) date('G') < GAZZETTA_PUSH_HOUR || !push_supported()) {
        return 0;
    }
    $ed = gazzetta_edition();
    $gids = q("SELECT DISTINCT group_id FROM matches WHERE (status = 'programmata' AND match_date >= NOW())
               OR (status = 'giocata' AND match_date >= DATE_SUB(NOW(), INTERVAL 30 DAY))")->fetchAll(PDO::FETCH_COLUMN);
    $sent = 0;
    foreach ($gids as $gid) {
        $gid = (int) $gid;
        if (!q('INSERT IGNORE INTO meta (k, v) VALUES (?, ?)', ['gz_' . $ed . '_' . $gid, (string) time()])->rowCount()) {
            continue;   // già mandata (o la sta mandando un'altra richiesta)
        }
        $g = gazzetta($gid);
        $bits = [];
        foreach ($g['slides'] as $s) {
            if ($s['kind'] !== 'cover' && $s['kind'] !== 'formazioni') {
                $bits[] = $s['title'];
            }
        }
        $pids = q('SELECT p.id FROM players p JOIN player_groups pg ON pg.player_id = p.id WHERE pg.group_id = ? AND p.is_guest = 0 AND p.active = 1', [$gid])->fetchAll(PDO::FETCH_COLUMN);
        $sent += push_notify_users(array_values(push_users_of_players($pids)), [
            'title' => 'È uscita la Gazzetta del mercoledì',
            'body' => implode(' · ', array_slice($bits, 0, 4)) . '. Probabili formazioni e mercato KOIN dentro.',
            'url' => 'gazzetta.php?g=' . $gid, 'tag' => 'gazzetta-' . $gid,
        ], 'normal', 'gazzetta');
    }
    return $sent;
}
